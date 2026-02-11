<?php
// app/Events/NewMessage.php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewMessage implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    public function __construct($message)
    {
        $this->message = $message;
    }

    public function broadcastOn()
    {
        return new Channel('chat.' . $this->message['chat_id']);
    }

    public function broadcastAs()
    {
        return 'NewMessage';
    }

    public function broadcastWith()
    {
        return [
            'type' => 'new_message',
            'message' => $this->message,
        ];
    }
}
