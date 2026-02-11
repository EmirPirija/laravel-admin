<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserRealtimeNotification implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $userId;
    public string $category;
    public string $type;
    public string $title;
    public string $message;
    public array $payload;
    public string $createdAt;

    public function __construct(
        int $userId,
        string $category = 'notification',
        string $type = 'general',
        string $title = 'Obavijest',
        string $message = '',
        array $payload = []
    ) {
        $this->userId = $userId;
        $this->category = $category;
        $this->type = $type;
        $this->title = $title;
        $this->message = $message;
        $this->payload = $payload;
        $this->createdAt = now()->toISOString();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.' . $this->userId);
    }

    public function broadcastAs(): string
    {
        return 'RealtimeNotification';
    }

    public function broadcastWith(): array
    {
        return [
            'category' => $this->category,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'payload' => $this->payload,
            'created_at' => $this->createdAt,
        ];
    }
}
