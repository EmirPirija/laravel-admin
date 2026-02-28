<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use Illuminate\Http\Request;

class SystemHealthController extends Controller
{
    public function index(Request $request, SystemHealthService $healthService)
    {
        $health = $healthService->check();

        return response()->json([
            'error' => false,
            'message' => 'OK',
            'data' => $health,
        ]);
    }
}

