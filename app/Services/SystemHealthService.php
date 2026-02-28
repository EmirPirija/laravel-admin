<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

class SystemHealthService
{
    public function check(): array
    {
        $startedAt = microtime(true);
        $checks = [
            'db' => $this->checkDb(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'mail' => $this->checkMail(),
            'storage' => $this->checkStorage(),
        ];

        $responseMs = (int) round((microtime(true) - $startedAt) * 1000);
        $status = $this->resolveOverallStatus($checks);

        return [
            'status' => $status,
            'response_time_ms' => $responseMs,
            'checks' => $checks,
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    private function resolveOverallStatus(array $checks): string
    {
        $statuses = array_column($checks, 'status');
        if (in_array('down', $statuses, true)) {
            return 'red';
        }

        if (in_array('warning', $statuses, true)) {
            return 'yellow';
        }

        return 'green';
    }

    private function checkDb(): array
    {
        try {
            DB::select('SELECT 1 as ok');
            return ['status' => 'up', 'message' => 'DB connected'];
        } catch (\Throwable $e) {
            return ['status' => 'down', 'message' => $e->getMessage()];
        }
    }

    private function checkRedis(): array
    {
        try {
            if (config('database.redis.client') === null) {
                return ['status' => 'warning', 'message' => 'Redis not configured'];
            }

            $pong = Redis::connection()->ping();
            $ok = is_string($pong) ? str_contains(strtolower($pong), 'pong') : (bool) $pong;
            return ['status' => $ok ? 'up' : 'warning', 'message' => $ok ? 'Redis OK' : 'Redis responded unexpectedly'];
        } catch (\Throwable $e) {
            return ['status' => 'warning', 'message' => 'Redis unavailable: '.$e->getMessage()];
        }
    }

    private function checkQueue(): array
    {
        try {
            $driver = (string) config('queue.default', 'sync');
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

            if ($driver === 'sync') {
                return ['status' => 'warning', 'message' => 'Queue driver is sync'];
            }

            if ($failed > 0) {
                return ['status' => 'warning', 'message' => "{$failed} failed jobs"];
            }

            return ['status' => 'up', 'message' => 'Queue healthy'];
        } catch (\Throwable $e) {
            return ['status' => 'warning', 'message' => 'Queue check failed: '.$e->getMessage()];
        }
    }

    private function checkMail(): array
    {
        try {
            $mailer = config('mail.default');
            $host = config('mail.mailers.smtp.host');
            $from = config('mail.from.address');

            if (empty($mailer) || empty($from)) {
                return ['status' => 'warning', 'message' => 'Mail partially configured'];
            }

            if ($mailer === 'smtp' && empty($host)) {
                return ['status' => 'warning', 'message' => 'SMTP host missing'];
            }

            // force resolving mailer service to catch basic config errors
            Mail::mailer($mailer);
            return ['status' => 'up', 'message' => 'Mail configured'];
        } catch (\Throwable $e) {
            return ['status' => 'warning', 'message' => 'Mail check failed: '.$e->getMessage()];
        }
    }

    private function checkStorage(): array
    {
        try {
            $path = storage_path('app');
            if (!is_dir($path)) {
                return ['status' => 'down', 'message' => 'Storage path missing'];
            }

            if (!is_writable($path)) {
                return ['status' => 'down', 'message' => 'Storage not writable'];
            }

            return ['status' => 'up', 'message' => 'Storage writable'];
        } catch (\Throwable $e) {
            return ['status' => 'down', 'message' => $e->getMessage()];
        }
    }
}
