<?php

namespace App\Console\Commands;

use App\Services\FirebaseAuthCleanupService;
use Illuminate\Console\Command;
use Throwable;

class PruneStaleFirebaseUsers extends Command
{
    protected $signature = 'firebase:prune-stale-users
                            {--dry-run : Samo prikaži šta bi bilo obrisano}
                            {--chunk=1000 : Chunk veličina za lokalni scan social_logins}
                            {--allow-empty-source : Dozvoli cleanup i kad Firebase vrati 0 korisnika}';

    protected $description = 'Briše lokalne korisnike i oglase ako njihov Firebase UID više ne postoji u Firebase Auth';

    public function handle(FirebaseAuthCleanupService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(100, (int) $this->option('chunk'));
        $allowEmptySource = (bool) $this->option('allow-empty-source');

        $this->info('Pokrećem Firebase cleanup' . ($dryRun ? ' [DRY RUN]' : '') . '...');

        try {
            $result = $service->pruneStaleUsers($dryRun, $chunk, $allowEmptySource);

            $this->line('Firebase korisnika: ' . $result['firebase_users']);
            $this->line('Lokalnih social_login zapisa: ' . $result['local_social_logins']);
            $this->line('Lokalnih legacy users.firebase_id zapisa: ' . $result['local_legacy_firebase_users']);
            $this->line('Ukupno lokalnih Firebase linkova: ' . $result['local_firebase_links']);
            $this->line('Stale korisnika detektovano: ' . $result['stale_users']);
            $this->line('Stale oglasa detektovano: ' . $result['stale_items']);
            $this->line('Obrisanih korisnika: ' . $result['deleted_users']);
            $this->line('Neuspješnih brisanja: ' . $result['failed_users']);

            if (!empty($result['failures'])) {
                $this->warn('Neuspješna brisanja:');
                foreach ($result['failures'] as $failure) {
                    $this->line('- user_id=' . $failure['user_id'] . ' | ' . $failure['error']);
                }
            }

            $this->info($dryRun ? 'DRY RUN završio.' : 'Firebase cleanup završen.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Firebase cleanup greška: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
