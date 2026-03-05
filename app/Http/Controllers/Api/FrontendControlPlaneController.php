<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FrontendControlPlaneService;
use Illuminate\Http\Request;

class FrontendControlPlaneController extends Controller
{
    public function index(Request $request, FrontendControlPlaneService $service)
    {
        $snapshot = $service->snapshot();

        return response()->json([
            'error' => false,
            'message' => __('Frontend control plane loaded'),
            'data' => $snapshot,
        ], 200, [
            'Cache-Control' => 'public, max-age=20, stale-while-revalidate=40',
            'X-Control-Plane-Version' => (string) ($snapshot['version'] ?? ''),
        ]);
    }
}

