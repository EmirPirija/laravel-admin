<?php

namespace App\Jobs;

use App\Models\FollowPreference;
use App\Notifications\NewListingsDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class NotifyFollowersInstant implements ShouldQueue
{
  use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

  public function __construct(
    public int $sellerId,
    public array $listing // minimal payload: id, title, created_at, url
  ) {}

  public function handle(): void
  {
    $prefs = FollowPreference::query()
      ->where('followed_user_id', $this->sellerId)
      ->where('enabled', true)
      ->where('frequency', 'instant')
      ->get();

    if ($prefs->isEmpty()) return;

    foreach ($prefs as $pref) {
      $user = $pref->user()->first();
      if (!$user) continue;

      $user->notify(new NewListingsDigest([
        'title' => 'Novi oglas od prodavača kojeg pratiš',
        'count' => 1,
        'items' => [[
          'id' => $this->listing['id'] ?? null,
          'title' => $this->listing['title'] ?? 'Novi oglas',
          'seller_id' => $this->sellerId,
          'seller_name' => $this->listing['seller_name'] ?? null,
          'url' => $this->listing['url'] ?? null,
          'created_at' => $this->listing['created_at'] ?? null,
        ]],
        'url' => $this->listing['url'] ?? null,
      ]));

      $pref->last_notified_at = now();
      $pref->save();
    }
  }
}
