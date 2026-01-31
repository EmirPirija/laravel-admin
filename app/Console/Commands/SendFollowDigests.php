<?php

namespace App\Console\Commands;

use App\Models\FollowPreference;
use App\Notifications\NewListingsDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendFollowDigests extends Command
{
  protected $signature = 'follow:digests {--frequency=daily : daily|weekly}';
  protected $description = 'Šalje digest obavijesti o novim oglasima od sačuvanih/propraćenih prodavača.';

  public function handle(): int
  {
    $freq = $this->option('frequency') ?: 'daily';
    if (!in_array($freq, ['daily', 'weekly'], true)) {
      $this->error('Nepoznata frekvencija. Koristi daily ili weekly.');
      return self::FAILURE;
    }

    // NOTE: Ovdje moraš prilagoditi query na tvoju tabelu oglasa/proizvoda.
    // Pretpostavka: tabela 'items' sa kolonama: id, user_id (seller), name/title, created_at, status
    $listingTable = config('services.follow_listings_table', 'items');
    $titleColumn = config('services.follow_listings_title_column', 'name');
    $statusColumn = config('services.follow_listings_status_column', 'status');

    $prefs = FollowPreference::query()
      ->where('enabled', true)
      ->where('frequency', $freq)
      ->get();

    $this->info('Prefs: ' . $prefs->count());

    foreach ($prefs as $pref) {
      $since = $pref->last_checked_at ?? now()->subDays($freq === 'daily' ? 1 : 7);

      $rows = DB::table($listingTable)
        ->where('user_id', $pref->followed_user_id)
        ->where('created_at', '>', $since)
        ->when($statusColumn, fn ($q) => $q->where($statusColumn, '!=', 'deleted'))
        ->orderBy('created_at', 'desc')
        ->limit(10)
        ->get(['id', $titleColumn, 'created_at', 'user_id']);

      if ($rows->isEmpty()) {
        $pref->last_checked_at = now();
        $pref->save();
        continue;
      }

      $user = $pref->user()->first();
      if (!$user) continue;

      $items = $rows->map(function ($r) use ($titleColumn) {
        return [
          'id' => $r->id,
          'title' => $r->{$titleColumn} ?? 'Oglas',
          'seller_id' => $r->user_id,
          'created_at' => $r->created_at,
          'url' => null, // optionally build web url
        ];
      })->values()->all();

      $user->notify(new NewListingsDigest([
        'title' => $freq === 'daily' ? 'Dnevni pregled novih oglasa' : 'Sedmični pregled novih oglasa',
        'count' => count($items),
        'items' => $items,
        'url' => null,
      ]));

      $pref->last_checked_at = now();
      $pref->last_notified_at = now();
      $pref->save();
    }

    $this->info('Gotovo.');
    return self::SUCCESS;
  }
}
