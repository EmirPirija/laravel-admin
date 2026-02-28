<?php

namespace App\Services;

use App\Models\BlockUser;
use App\Models\Setting;
use App\Models\SocialLogin;
use App\Models\User;
use App\Models\UserFcmToken;
use Google\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UserDeletionService
{
    public function forceDeleteUser(User $user): void
    {
        $firebaseIds = $this->extractFirebaseIds($user);
        $this->deleteFirebaseAccounts($firebaseIds);

        DB::transaction(function () use ($user) {
            $userId = (int) $user->id;

            // block_users ima RESTRICT FK i mora se očistiti prije brisanja korisnika
            BlockUser::query()
                ->where('user_id', $userId)
                ->orWhere('blocked_user_id', $userId)
                ->delete();

            // Dodatni cleanup za instance bez FK kaskada
            SocialLogin::query()->where('user_id', $userId)->delete();
            UserFcmToken::query()->where('user_id', $userId)->delete();

            if (Schema::hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->where('tokenable_id', $userId)
                    ->delete();
            }

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $userId)->delete();
            }

            if (Schema::hasTable('model_has_roles')) {
                DB::table('model_has_roles')
                    ->where('model_type', User::class)
                    ->where('model_id', $userId)
                    ->delete();
            }

            if (Schema::hasTable('model_has_permissions')) {
                DB::table('model_has_permissions')
                    ->where('model_type', User::class)
                    ->where('model_id', $userId)
                    ->delete();
            }

            $user->forceDelete();
        });
    }

    private function extractFirebaseIds(User $user): array
    {
        $ids = [];

        if (!empty($user->firebase_id)) {
            $ids[] = (string) $user->firebase_id;
        }

        $socialIds = SocialLogin::query()
            ->where('user_id', (int) $user->id)
            ->whereNotNull('firebase_id')
            ->where('firebase_id', '!=', '')
            ->pluck('firebase_id')
            ->all();

        foreach ($socialIds as $socialId) {
            $ids[] = (string) $socialId;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function deleteFirebaseAccounts(array $firebaseIds): void
    {
        if (empty($firebaseIds)) {
            return;
        }

        try {
            $projectId = (string) (Setting::query()->where('name', 'firebase_project_id')->value('value') ?? '');
            $serviceFile = (string) (Setting::query()->where('name', 'service_file')->value('value') ?? '');

            if ($projectId === '' || $serviceFile === '') {
                return;
            }

            $serviceFilePath = $this->resolveServiceAccountPath($serviceFile);
            if ($serviceFilePath === null) {
                return;
            }

            $client = new Client();
            $client->setAuthConfig($serviceFilePath);
            $client->setScopes([
                'https://www.googleapis.com/auth/identitytoolkit',
                'https://www.googleapis.com/auth/cloud-platform',
            ]);

            $tokenPayload = $client->fetchAccessTokenWithAssertion();
            $accessToken = (string) ($tokenPayload['access_token'] ?? '');
            if ($accessToken === '') {
                return;
            }

            $url = "https://identitytoolkit.googleapis.com/v1/projects/{$projectId}/accounts:delete";

            foreach ($firebaseIds as $firebaseId) {
                $response = Http::withToken($accessToken)
                    ->acceptJson()
                    ->timeout(20)
                    ->post($url, ['localId' => $firebaseId]);

                if ($response->successful()) {
                    continue;
                }

                $message = (string) ($response->json('error.message') ?? '');
                if (str_contains($message, 'USER_NOT_FOUND')) {
                    continue;
                }

                Log::warning('UserDeletionService firebase delete failed', [
                    'firebase_id' => $firebaseId,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 400),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('UserDeletionService firebase account cleanup skipped', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveServiceAccountPath(string $serviceFile): ?string
    {
        $disk = (string) config('filesystems.default');

        if ($disk === 'local' || $disk === 'public') {
            $path = Storage::disk($disk)->path($serviceFile);
            return file_exists($path) ? $path : null;
        }

        if (!Storage::disk($disk)->exists($serviceFile)) {
            return null;
        }

        $content = Storage::disk($disk)->get($serviceFile);
        $path = storage_path('app/firebase_service_user_deletion.json');
        file_put_contents($path, $content);

        return file_exists($path) ? $path : null;
    }
}
