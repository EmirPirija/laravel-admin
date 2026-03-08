<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RuntimeAnnouncement;
use App\Models\User;
use App\Services\ResponseService;
use App\Services\RuntimeControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class RuntimeConfigController extends Controller
{
    public function show(Request $request, RuntimeControlService $runtimeControlService)
    {
        $user = $this->resolveUserFromRequest($request);

        $payload = $runtimeControlService->getRuntimeConfig($user);
        $etagValue = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $etagHeader = '"' . $etagValue . '"';

        $incomingEtag = trim((string) $request->header('If-None-Match', ''));
        if ($incomingEtag !== '' && $incomingEtag === $etagHeader) {
            return response('', 304, [
                'ETag' => $etagHeader,
                'Cache-Control' => $user
                    ? 'private, max-age=15, stale-while-revalidate=30'
                    : 'public, max-age=30, stale-while-revalidate=90',
                'Vary' => 'Authorization, Content-Language',
            ]);
        }

        return response()->json([
            'error' => false,
            'message' => __('Runtime config fetched successfully.'),
            'data' => $payload,
            'code' => config('constants.RESPONSE_CODE.SUCCESS'),
        ], 200, [
            'ETag' => $etagHeader,
            'Cache-Control' => $user
                ? 'private, max-age=15, stale-while-revalidate=30'
                : 'public, max-age=30, stale-while-revalidate=90',
            'Vary' => 'Authorization, Content-Language',
        ]);
    }

    public function markAnnouncementRead(Request $request, RuntimeControlService $runtimeControlService, int $id)
    {
        $announcement = RuntimeAnnouncement::query()->find($id);
        if (!$announcement) {
            ResponseService::notFoundResponse(__('Announcement not found.'));
        }

        $user = Auth::user();
        if (!$user) {
            ResponseService::unauthorizedResponse(__('Unauthorized'));
        }

        $runtimeControlService->markAnnouncementRead((int) $announcement->id, (int) $user->id);
        ResponseService::successResponse(__('Announcement marked as read.'));
    }

    private function resolveUserFromRequest(Request $request): ?User
    {
        if (Auth::check()) {
            $authUser = Auth::user();
            return $authUser instanceof User ? $authUser : null;
        }

        $token = trim((string) $request->bearerToken());
        if ($token === '') {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken || !($accessToken->tokenable instanceof User)) {
            return null;
        }

        $user = $accessToken->tokenable;
        if (method_exists($user, 'trashed') && $user->trashed()) {
            return null;
        }

        return $user;
    }
}
