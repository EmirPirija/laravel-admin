<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedUserList;
use App\Models\SavedUserListItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SavedUserListController extends Controller
{
  /**
   * GET /saved-lists
   * Returns lists with item counts
   */
  public function index(Request $request)
  {
    $me = $request->user();

    $lists = SavedUserList::query()
      ->where('user_id', $me->id)
      ->withCount('items')
      ->orderBy('is_default', 'desc')
      ->orderBy('sort_order', 'asc')
      ->orderBy('id', 'asc')
      ->get();

    // Ensure default collections exist (high-end UX)
    if ($lists->isEmpty()) {
      $this->ensureDefaults($me->id);
      $lists = SavedUserList::query()
        ->where('user_id', $me->id)
        ->withCount('items')
        ->orderBy('is_default', 'desc')
        ->orderBy('sort_order', 'asc')
        ->orderBy('id', 'asc')
        ->get();
    }

    return response()->json([
      'error' => false,
      'message' => '',
      'data' => $lists,
    ]);
  }

  /**
   * POST /saved-lists
   */
  public function store(Request $request)
  {
    $me = $request->user();

    $request->validate([
      'name' => ['required', 'string', 'min:2', 'max:80'],
    ]);

    $name = trim($request->name);

    $exists = SavedUserList::where('user_id', $me->id)->where('name', $name)->exists();
    if ($exists) {
      return response()->json([
        'error' => true,
        'message' => 'Lista sa tim nazivom već postoji.',
        'data' => null,
      ], 422);
    }

    $maxSort = (int) SavedUserList::where('user_id', $me->id)->max('sort_order');

    $list = SavedUserList::create([
      'user_id' => $me->id,
      'name' => $name,
      'is_default' => false,
      'sort_order' => $maxSort + 1,
    ]);

    return response()->json([
      'error' => false,
      'message' => 'Lista je kreirana.',
      'data' => $list->loadCount('items'),
    ]);
  }

  /**
   * PATCH /saved-lists/{list}
   */
  public function update(Request $request, SavedUserList $list)
  {
    $me = $request->user();
    if ($list->user_id !== $me->id) abort(403);

    $request->validate([
      'name' => ['required', 'string', 'min:2', 'max:80'],
    ]);

    $name = trim($request->name);

    $exists = SavedUserList::where('user_id', $me->id)
      ->where('name', $name)
      ->where('id', '!=', $list->id)
      ->exists();

    if ($exists) {
      return response()->json([
        'error' => true,
        'message' => 'Lista sa tim nazivom već postoji.',
        'data' => null,
      ], 422);
    }

    $list->name = $name;
    $list->save();

    return response()->json([
      'error' => false,
      'message' => 'Sačuvano.',
      'data' => $list->loadCount('items'),
    ]);
  }

  /**
   * DELETE /saved-lists/{list}
   * Prevent deleting default lists; allow user to clean items instead.
   */
  public function destroy(Request $request, SavedUserList $list)
  {
    $me = $request->user();
    if ($list->user_id !== $me->id) abort(403);

    if ($list->is_default) {
      return response()->json([
        'error' => true,
        'message' => 'Podrazumijevanu listu nije moguće obrisati.',
        'data' => null,
      ], 422);
    }

    $list->delete();

    return response()->json([
      'error' => false,
      'message' => 'Lista je obrisana.',
      'data' => null,
    ]);
  }

  private function ensureDefaults(int $userId): void
  {
    DB::transaction(function () use ($userId) {
      $existing = SavedUserList::where('user_id', $userId)->count();
      if ($existing > 0) return;

      SavedUserList::create([
        'user_id' => $userId,
        'name' => 'Za kasnije',
        'is_default' => true,
        'sort_order' => 0,
      ]);

      SavedUserList::create([
        'user_id' => $userId,
        'name' => 'Moji agenti',
        'is_default' => false,
        'sort_order' => 1,
      ]);

      SavedUserList::create([
        'user_id' => $userId,
        'name' => 'Provjereni shopovi',
        'is_default' => false,
        'sort_order' => 2,
      ]);
    });
  }
}
