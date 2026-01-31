<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedUserList;
use App\Models\SavedUserListItem;
use Illuminate\Http\Request;

class SavedUserListItemController extends Controller
{
  /**
   * GET /saved-lists/{list}/items
   */
  public function index(Request $request, SavedUserList $list)
  {
    $me = $request->user();
    if ($list->user_id !== $me->id) abort(403);

    $perPage = (int) ($request->get('per_page', 24));
    $perPage = max(1, min($perPage, 50));

    $q = trim((string) $request->get('q', ''));

    $items = SavedUserListItem::query()
      ->where('list_id', $list->id)
      ->when($q !== '', function ($query) use ($q) {
        $query->whereHas('savedUser', function ($u) use ($q) {
          $u->where('name', 'like', "%{$q}%");
        });
      })
      ->with(['savedUser' => function ($u) {
        // Minimal fields for card; extend as needed
        $u->select('id', 'name', 'profile', 'profile_image', 'created_at', 'is_pro', 'is_shop');
      }])
      ->latest()
      ->paginate($perPage);

    return response()->json([
      'error' => false,
      'message' => '',
      'data' => $items,
    ]);
  }

  /**
   * GET /saved-users/membership?saved_user_id=123
   * Returns list ids in which this seller exists
   */
  public function membership(Request $request)
  {
    $me = $request->user();

    $request->validate([
      'saved_user_id' => ['required', 'integer', 'exists:users,id'],
    ]);

    $savedUserId = (int) $request->saved_user_id;

    $listIds = SavedUserListItem::query()
      ->where('user_id', $me->id)
      ->where('saved_user_id', $savedUserId)
      ->pluck('list_id')
      ->values();

    return response()->json([
      'error' => false,
      'message' => '',
      'data' => [
        'saved_user_id' => $savedUserId,
        'list_ids' => $listIds,
      ],
    ]);
  }

  /**
   * POST /saved-lists/{list}/items
   * Adds seller to list (idempotent). Optional note.
   */
  public function store(Request $request, SavedUserList $list)
  {
    $me = $request->user();
    if ($list->user_id !== $me->id) abort(403);

    $request->validate([
      'saved_user_id' => ['required', 'integer', 'exists:users,id'],
      'note' => ['nullable', 'string', 'max:2000'],
    ]);

    $savedUserId = (int) $request->saved_user_id;
    if ($savedUserId === $me->id) {
      return response()->json([
        'error' => true,
        'message' => 'Ne možeš sačuvati sam sebe.',
        'data' => null,
      ], 422);
    }

    $item = SavedUserListItem::firstOrCreate(
      ['list_id' => $list->id, 'saved_user_id' => $savedUserId],
      ['user_id' => $me->id, 'note' => $request->note]
    );

    // If existed, update note only if provided
    if ($item->wasRecentlyCreated === false && $request->has('note')) {
      $item->note = $request->note;
      $item->save();
    }

    return response()->json([
      'error' => false,
      'message' => 'Sačuvano.',
      'data' => $item->load('savedUser'),
    ]);
  }

  /**
   * DELETE /saved-lists/{list}/items/{savedUserId}
   */
  public function destroy(Request $request, SavedUserList $list, int $savedUserId)
  {
    $me = $request->user();
    if ($list->user_id !== $me->id) abort(403);

    SavedUserListItem::query()
      ->where('list_id', $list->id)
      ->where('saved_user_id', $savedUserId)
      ->delete();

    return response()->json([
      'error' => false,
      'message' => 'Uklonjeno.',
      'data' => null,
    ]);
  }

  /**
   * PATCH /saved-lists/{list}/items/{savedUserId}/note
   */
  public function updateNote(Request $request, SavedUserList $list, int $savedUserId)
  {
    $me = $request->user();
    if ($list->user_id !== $me->id) abort(403);

    $request->validate([
      'note' => ['nullable', 'string', 'max:2000'],
    ]);

    $item = SavedUserListItem::query()
      ->where('list_id', $list->id)
      ->where('saved_user_id', $savedUserId)
      ->firstOrFail();

    $item->note = $request->note;
    $item->save();

    return response()->json([
      'error' => false,
      'message' => 'Bilješka je sačuvana.',
      'data' => [
        'saved_user_id' => $savedUserId,
        'note' => $item->note,
        'updated_at' => $item->updated_at,
      ],
    ]);
  }
}
