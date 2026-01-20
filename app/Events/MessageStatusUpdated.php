<?php
// app/Events/MessageStatusUpdated.php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $messageId;
    public $chatId;
    public $status;

    public function __construct($messageId, $chatId, $status)
    {
        $this->messageId = $messageId;
        $this->chatId = $chatId;
        $this->status = $status;
    }

    public function broadcastOn()
    {
        return new Channel('chat.' . $this->chatId);
    }

    public function broadcastAs()
    {
        return 'MessageStatusUpdated';
    }

    public function broadcastWith()
    {
        return [
            'type' => 'message_status',
            'message_id' => $this->messageId,
            'chat_id' => $this->chatId,
            'status' => $this->status,
        ];
    }
}