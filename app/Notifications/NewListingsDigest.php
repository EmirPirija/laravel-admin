<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class NewListingsDigest extends Notification implements ShouldQueue
{
  use Queueable;

  public function __construct(
    public array $payload
  ) {}

  public function via($notifiable): array
  {
    // Database always; Mail optional if you want.
    return ['database'];
  }

  public function toDatabase($notifiable): array
  {
    return [
      'type' => 'follow_digest',
      'title' => $this->payload['title'] ?? 'Novi oglasi',
      'count' => $this->payload['count'] ?? 0,
      'items' => $this->payload['items'] ?? [],
      'url' => $this->payload['url'] ?? null,
      'generated_at' => now()->toISOString(),
    ];
  }

  // Optional email channel (enable by adding 'mail' to via())
  public function toMail($notifiable): MailMessage
  {
    $m = (new MailMessage)
      ->subject($this->payload['title'] ?? 'Novi oglasi')
      ->greeting('Zdravo!')
      ->line('Imaš nove oglase od prodavača koje pratiš.');

    foreach (($this->payload['items'] ?? []) as $it) {
      $m->line('• ' . ($it['title'] ?? 'Oglas') . ' — ' . ($it['seller_name'] ?? 'Prodavač'));
    }

    if (!empty($this->payload['url'])) {
      $m->action('Pogledaj', $this->payload['url']);
    }

    return $m;
  }
}
