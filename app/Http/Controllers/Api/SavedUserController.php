<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedUser;
use App\Models\User;
use Illuminate\Http\Request;

class SavedUserController extends Controller
{
  public function toggle(Request $request)
  {
    $request->validate([
      'saved_user_id' => ['required', 'integer', 'exists:users,id'],
    ]);

    $me = $request->user();
    $targetId = (int) $request->saved_user_id;

    if ($me->id === $targetId) {
      return response()->json([
        'error' => true,
        'message' => 'Ne možeš sačuvati sam sebe.',
        'data' => null,
      ], 422);
    }

    $existing = SavedUser::where('user_id', $me->id)
      ->where('saved_user_id', $targetId)
      ->first();

    if ($existing) {
      $existing->delete();

      return response()->json([
        'error' => false,
        'message' => 'Uklonjeno iz sačuvanih.',
        'data' => [
          'saved' => false,
          'saved_user_id' => $targetId,
        ],
      ]);
    }

    SavedUser::create([
      'user_id' => $me->id,
      'saved_user_id' => $targetId,
    ]);

    return response()->json([
      'error' => false,
      'message' => 'Sačuvano.',
      'data' => [
        'saved' => true,
        'saved_user_id' => $targetId,
      ],
    ]);
  }

  public function check(Request $request)
  {
    $request->validate([
      'saved_user_id' => ['required', 'integer', 'exists:users,id'],
    ]);

    $me = $request->user();
    $targetId = (int) $request->saved_user_id;

    $saved = SavedUser::where('user_id', $me->id)
      ->where('saved_user_id', $targetId)
      ->exists();

    return response()->json([
      'error' => false,
      'message' => '',
      'data' => [
        'saved' => $saved,
        'saved_user_id' => $targetId,
      ],
    ]);
  }

  public function index(Request $request)
  {
    $me = $request->user();
    $perPage = (int) ($request->get('per_page', 20));
    $perPage = max(1, min($perPage, 50));

    $saved = SavedUser::query()
      ->where('user_id', $me->id)
      ->with(['savedUser' => function ($q) {
        // Uzmi minimalno, a dovoljno za karticu
        $q->select('id', 'name', 'profile', 'created_at', 'total_sales', 'seller_level');
      }])
      ->latest()
      ->paginate($perPage);

    // napomena: User model kod tebe već appenda `badges`, to može biti “heavy”.
    // Ako želiš ultra-brzo: napravi Resource i eksplicitno mapiraj šta vraćaš.

    return response()->json([
      'error' => false,
      'message' => '',
      'data' => $saved,
    ]);
  }
}
