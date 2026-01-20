<?php

namespace App\Jobs;

use App\Services\NotificationService;
use App\Models\UserFcmToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendFcmBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $title;
    protected $message;
    protected $type;
    protected $customBodyFields;
    protected $sendToAll;
    protected $userIds;

    public function __construct($title, $message, $type = 'default', $customBodyFields = [], $sendToAll = false, $userIds = [])
    {
        $this->title = $title;
        $this->message = $message;
        $this->type = $type;
        $this->customBodyFields = $customBodyFields;
        $this->sendToAll = $sendToAll;
        $this->userIds = $userIds;
    }

    public function handle()
    {
        Log::info("🔔 SendFcmBatchJob started");

        // ✅ If sendToAll = true
        if ($this->sendToAll) {
            // Fetch tokens with user preference
            $tokens = UserFcmToken::with('user')
                ->whereHas('user', fn($q) => $q->where('notification', 1))
                ->get(['fcm_token', 'platform_type']);

            // Split tokens by platform
            $androidIosTokens = $tokens->whereIn('platform_type', ['Android', 'iOS'])->pluck('fcm_token')->toArray();
            $otherTokens = $tokens->whereNotIn('platform_type', ['Android', 'iOS'])->pluck('fcm_token')->toArray();

            // ✅ Send Android/iOS via Topic
            if (!empty($androidIosTokens)) {
                NotificationService::sendFcmNotification(
                    [], $this->title, $this->message, $this->type, $this->customBodyFields, true
                );
                Log::info("📱 Topic-based notification sent to Android/iOS users.");
            }

            // ✅ Send Others via Chunk (if any)
            if (!empty($otherTokens)) {
                collect($otherTokens)->chunk(500)->each(function ($chunk) {
                    NotificationService::sendFcmNotification(
                        $chunk->toArray(), $this->title, $this->message, $this->type, $this->customBodyFields, false
                    );
                });
                Log::info("💻 Chunk-based notification sent to other platform users.");
            }

        } else {
            // ✅ Send to specific selected users
            UserFcmToken::with('user')
                ->whereIn('user_id', $this->userIds)
                ->whereHas('user', fn($q) => $q->where('notification', 1))
                ->chunk(500, function ($tokens) {
                    $fcmTokens = $tokens->pluck('fcm_token')->toArray();
                    NotificationService::sendFcmNotification(
                        $fcmTokens, $this->title, $this->message, $this->type, $this->customBodyFields, false
                    );
                });

            Log::info("👥 Notifications sent to selected users.");
        }

        Log::info("✅ SendFcmBatchJob finished");
    }
}
