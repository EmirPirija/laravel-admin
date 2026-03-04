<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchSellerPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var int[]
     */
    public array $backoff = [10, 60, 180];

    /**
     * @param string[] $tokens
     * @param array<string, mixed> $customBodyFields
     */
    public function __construct(
        private array $tokens,
        private string $title,
        private string $message,
        private string $type = 'notification',
        private array $customBodyFields = [],
        private array $meta = []
    ) {}

    public function handle(): void
    {
        $tokens = collect($this->tokens)
            ->filter(fn ($token) => is_string($token) && trim($token) !== '')
            ->map(fn ($token) => trim($token))
            ->unique()
            ->values()
            ->all();

        if (empty($tokens)) {
            return;
        }

        $result = NotificationService::sendFcmNotification(
            $tokens,
            $this->title,
            $this->message,
            $this->type,
            $this->customBodyFields,
            false
        );

        if (is_array($result) && ($result['error'] ?? false)) {
            throw new \RuntimeException((string) ($result['message'] ?? 'FCM dispatch failed'));
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('DispatchSellerPushNotificationJob failed', [
            'title' => $this->title,
            'type' => $this->type,
            'meta' => $this->meta,
            'error' => $exception->getMessage(),
        ]);
    }
}

