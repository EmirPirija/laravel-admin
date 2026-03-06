<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Setting;
use App\Models\SocialLogin;
use App\Models\User;
use Google\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class FirebaseAuthCleanupService
{
    /**
     * @param bool $dryRun
     * @param int $scanChunk
     * @param bool $allowEmptySource
     * @param bool $strictUserSync
     * @return array{
     *   firebase_users:int,
     *   local_social_logins:int,
     *   local_legacy_firebase_users:int,
     *   local_firebase_links:int,
     *   strict_orphan_users:int,
     *   detected_stale_users:int,
     *   grace_protected_users:int,
     *   cleanup_grace_minutes:int,
     *   stale_users:int,
     *   stale_items:int,
     *   deleted_users:int,
     *   failed_users:int,
     *   failures:array<int, array{user_id:int,error:string}>
     * }
     */
    public function pruneStaleUsers(
        bool $dryRun = false,
        int $scanChunk = 1000,
        bool $allowEmptySource = false,
        bool $strictUserSync = false
    ): array
    {
        $projectId = $this->resolveProjectId();
        $accessToken = $this->resolveAccessToken();
        $firebaseUidSet = $this->fetchFirebaseUidSet($projectId, $accessToken);

        $localSocialLogins = SocialLogin::query()
            ->whereNotNull('firebase_id')
            ->where('firebase_id', '!=', '')
            ->count();

        $localLegacyFirebaseUsers = User::withTrashed()
            ->whereNotNull('firebase_id')
            ->where('firebase_id', '!=', '')
            ->count();

        $localFirebaseLinks = $localSocialLogins + $localLegacyFirebaseUsers;

        if (!$allowEmptySource && $localFirebaseLinks > 0 && count($firebaseUidSet) === 0) {
            throw new RuntimeException(
                'Firebase vraća 0 korisnika dok lokalno postoje Firebase linkovi korisnika. Prekidam cleanup radi sigurnosti.'
            );
        }

        $hasValidFirebaseLink = [];
        $hasStaleFirebaseLink = [];

        SocialLogin::query()
            ->select(['id', 'user_id', 'firebase_id'])
            ->whereNotNull('firebase_id')
            ->where('firebase_id', '!=', '')
            ->chunkById($scanChunk, function ($rows) use (&$hasValidFirebaseLink, &$hasStaleFirebaseLink, $firebaseUidSet) {
                foreach ($rows as $row) {
                    $userId = (int) $row->user_id;
                    if (isset($firebaseUidSet[$row->firebase_id])) {
                        $hasValidFirebaseLink[$userId] = true;
                    } else {
                        $hasStaleFirebaseLink[$userId] = true;
                    }
                }
            }, 'id');

        User::withTrashed()
            ->select(['id', 'firebase_id'])
            ->whereNotNull('firebase_id')
            ->where('firebase_id', '!=', '')
            ->chunkById($scanChunk, function ($rows) use (&$hasValidFirebaseLink, &$hasStaleFirebaseLink, $firebaseUidSet) {
                foreach ($rows as $row) {
                    $userId = (int) $row->id;
                    if (isset($firebaseUidSet[$row->firebase_id])) {
                        $hasValidFirebaseLink[$userId] = true;
                    } else {
                        $hasStaleFirebaseLink[$userId] = true;
                    }
                }
            }, 'id');

        $candidateUserIds = [];
        foreach (array_keys($hasStaleFirebaseLink) as $userId) {
            if (!isset($hasValidFirebaseLink[$userId])) {
                $candidateUserIds[] = (int) $userId;
            }
        }

        $strictOrphanUsers = 0;
        if ($strictUserSync) {
            $validUserIds = array_map('intval', array_keys($hasValidFirebaseLink));

            $strictOrphanUserIds = User::withTrashed()
                ->role('User')
                ->when(count($validUserIds) > 0, function ($q) use ($validUserIds) {
                    $q->whereNotIn('id', $validUserIds);
                })
                ->when(count($validUserIds) === 0, function ($q) {
                    // When Firebase has no mapped users, strict mode treats all "User" role accounts as orphan.
                    return $q;
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $strictOrphanUsers = count($strictOrphanUserIds);
            $candidateUserIds = array_values(array_unique(array_merge($candidateUserIds, $strictOrphanUserIds)));
        }

        $detectedStaleUsers = count($candidateUserIds);
        $cleanupGraceMinutes = $this->resolveCleanupGraceMinutes();
        $graceProtectedUsers = 0;

        if ($cleanupGraceMinutes > 0 && $detectedStaleUsers > 0) {
            $graceCutoff = Carbon::now()->subMinutes($cleanupGraceMinutes);
            $eligibleUserIds = User::withTrashed()
                ->whereIn('id', $candidateUserIds)
                ->where(function ($query) use ($graceCutoff) {
                    $query
                        ->whereNull('created_at')
                        ->orWhere('created_at', '<=', $graceCutoff);
                })
                ->where(function ($query) use ($graceCutoff) {
                    $query
                        ->whereNull('updated_at')
                        ->orWhere('updated_at', '<=', $graceCutoff);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $graceProtectedUsers = max(0, $detectedStaleUsers - count($eligibleUserIds));
            $candidateUserIds = $eligibleUserIds;
        }

        if (count($candidateUserIds) === 0) {
            return [
                'firebase_users' => count($firebaseUidSet),
                'local_social_logins' => $localSocialLogins,
                'local_legacy_firebase_users' => $localLegacyFirebaseUsers,
                'local_firebase_links' => $localFirebaseLinks,
                'strict_orphan_users' => $strictOrphanUsers,
                'detected_stale_users' => $detectedStaleUsers,
                'grace_protected_users' => $graceProtectedUsers,
                'cleanup_grace_minutes' => $cleanupGraceMinutes,
                'stale_users' => 0,
                'stale_items' => 0,
                'deleted_users' => 0,
                'failed_users' => 0,
                'failures' => [],
            ];
        }

        $staleItems = (int) Item::withTrashed()
            ->whereIn('user_id', $candidateUserIds)
            ->count();

        if ($dryRun) {
            return [
                'firebase_users' => count($firebaseUidSet),
                'local_social_logins' => $localSocialLogins,
                'local_legacy_firebase_users' => $localLegacyFirebaseUsers,
                'local_firebase_links' => $localFirebaseLinks,
                'strict_orphan_users' => $strictOrphanUsers,
                'detected_stale_users' => $detectedStaleUsers,
                'grace_protected_users' => $graceProtectedUsers,
                'cleanup_grace_minutes' => $cleanupGraceMinutes,
                'stale_users' => count($candidateUserIds),
                'stale_items' => $staleItems,
                'deleted_users' => 0,
                'failed_users' => 0,
                'failures' => [],
            ];
        }

        $deletedUsers = 0;
        $failedUsers = 0;
        $failures = [];

        $users = User::withTrashed()
            ->whereIn('id', $candidateUserIds)
            ->get(['id']);

        foreach ($users as $user) {
            try {
                DB::transaction(function () use ($user) {
                    $user->forceDelete();
                });
                $deletedUsers++;
            } catch (Throwable $e) {
                $failedUsers++;
                $failures[] = [
                    'user_id' => (int) $user->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'firebase_users' => count($firebaseUidSet),
            'local_social_logins' => $localSocialLogins,
            'local_legacy_firebase_users' => $localLegacyFirebaseUsers,
            'local_firebase_links' => $localFirebaseLinks,
            'strict_orphan_users' => $strictOrphanUsers,
            'detected_stale_users' => $detectedStaleUsers,
            'grace_protected_users' => $graceProtectedUsers,
            'cleanup_grace_minutes' => $cleanupGraceMinutes,
            'stale_users' => count($candidateUserIds),
            'stale_items' => $staleItems,
            'deleted_users' => $deletedUsers,
            'failed_users' => $failedUsers,
            'failures' => $failures,
        ];
    }

    private function resolveProjectId(): string
    {
        $configuredProjectId = (string) (Setting::query()
            ->where('name', 'firebase_project_id')
            ->value('value') ?? '');

        $serviceJson = $this->readServiceAccountJson();
        $serviceProjectId = (string) ($serviceJson['project_id'] ?? '');

        if (
            $configuredProjectId !== '' &&
            $serviceProjectId !== '' &&
            $configuredProjectId !== $serviceProjectId
        ) {
            throw new RuntimeException(
                'Firebase cleanup je blokiran: firebase_project_id i service_file project_id se ne podudaraju.'
            );
        }

        $resolvedProjectId = $configuredProjectId !== '' ? $configuredProjectId : $serviceProjectId;
        if ($resolvedProjectId === '') {
            throw new RuntimeException('Firebase project ID nije konfigurisan.');
        }

        return $resolvedProjectId;
    }

    private function resolveCleanupGraceMinutes(): int
    {
        $envValue = env('FIREBASE_CLEANUP_GRACE_MINUTES', 1440);
        $minutes = (int) $envValue;

        return max(0, $minutes);
    }

    private function resolveAccessToken(): string
    {
        $serviceFilePath = $this->resolveServiceAccountPath();

        $client = new Client();
        $client->setAuthConfig($serviceFilePath);
        $client->setScopes([
            'https://www.googleapis.com/auth/identitytoolkit',
            'https://www.googleapis.com/auth/cloud-platform',
        ]);

        $tokenPayload = $client->fetchAccessTokenWithAssertion();
        $token = (string) ($tokenPayload['access_token'] ?? '');

        if ($token === '') {
            throw new RuntimeException('Ne mogu dobiti Google access token za Firebase Auth cleanup.');
        }

        return $token;
    }

    /**
     * @return array<string, bool>
     */
    private function fetchFirebaseUidSet(string $projectId, string $accessToken): array
    {
        $url = "https://identitytoolkit.googleapis.com/v1/projects/{$projectId}/accounts:batchGet";
        $uids = [];
        $nextPageToken = null;

        do {
            $query = ['maxResults' => 1000];
            if (!empty($nextPageToken)) {
                $query['nextPageToken'] = $nextPageToken;
            }

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(45)
                ->get($url, $query);

            if (!$response->successful()) {
                $body = $response->body();
                throw new RuntimeException(
                    'Firebase Auth batchGet greška [' . $response->status() . ']: ' . mb_substr($body, 0, 500)
                );
            }

            $payload = $response->json();
            foreach (($payload['users'] ?? []) as $user) {
                $uid = (string) ($user['localId'] ?? '');
                if ($uid !== '') {
                    $uids[$uid] = true;
                }
            }

            $nextPageToken = $payload['nextPageToken'] ?? null;
        } while (!empty($nextPageToken));

        return $uids;
    }

    private function resolveServiceAccountPath(): string
    {
        $serviceFile = (string) (Setting::query()
            ->where('name', 'service_file')
            ->value('value') ?? '');

        if ($serviceFile === '') {
            throw new RuntimeException('Firebase service file nije konfigurisan u settings.');
        }

        $disk = (string) config('filesystems.default');

        if ($disk === 'local' || $disk === 'public') {
            $path = Storage::disk($disk)->path($serviceFile);
        } else {
            $content = Storage::disk($disk)->get($serviceFile);
            $path = storage_path('app/firebase_service_auth_cleanup.json');
            file_put_contents($path, $content);
        }

        if (!file_exists($path)) {
            throw new RuntimeException('Firebase service file nije pronađen na disku.');
        }

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function readServiceAccountJson(): array
    {
        $path = $this->resolveServiceAccountPath();
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
