<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedSearch;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SavedSearchController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $context = $request->get('context', 'ads');

        $items = SavedSearch::query()
            ->where('user_id', $user->id)
            ->where('context', $context)
            ->orderByDesc('updated_at')
            ->get(['id', 'name', 'context', 'query_string', 'created_at', 'updated_at']);

        return ResponseService::successResponse('Sačuvane pretrage.', $items);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'context' => 'nullable|string|max:50',
            'name' => 'required|string|max:120',
            'query_string' => 'nullable|string',
        ]);

        $context = $data['context'] ?? 'ads';
        $queryString = $data['query_string'] ?? '';
        $hash = hash('sha256', $user->id . '|' . $context . '|' . $queryString);

        // ako već postoji ista pretraga — samo update name/updated_at
        $existing = SavedSearch::query()
            ->where('user_id', $user->id)
            ->where('context', $context)
            ->where('query_hash', $hash)
            ->first();

        if ($existing) {
            $existing->update([
                'name' => $data['name'],
                'query_string' => $queryString,
            ]);

            return ResponseService::successResponse('Pretraga je ažurirana.', $existing);
        }

        $saved = SavedSearch::create([
            'user_id' => $user->id,
            'context' => $context,
            'name' => $data['name'],
            'query_string' => $queryString,
            'query_hash' => $hash,
        ]);

        return ResponseService::successResponse('Pretraga je sačuvana.', $saved);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::user();

        $data = $request->validate([
            'name' => 'required|string|max:120',
        ]);

        $saved = SavedSearch::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$saved) {
            return ResponseService::errorResponse('Pretraga nije pronađena.', 404);
        }

        $saved->update(['name' => $data['name']]);

        return ResponseService::successResponse('Pretraga je preimenovana.', $saved);
    }

    public function destroy($id)
    {
        $user = Auth::user();

        $saved = SavedSearch::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$saved) {
            return ResponseService::errorResponse('Pretraga nije pronađena.', 404);
        }

        $saved->delete();

        return ResponseService::successResponse('Pretraga je obrisana.', true);
    }
}
