<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FollowPreference;
use Illuminate\Http\Request;

class FollowPreferenceController extends Controller
{
  /**
   * PUT /follow-preferences/{followedUserId}
   */
  public function upsert(Request $request, int $followedUserId)
  {
    $me = $request->user();

    if ($me->id === $followedUserId) {
      return response()->json([
        'error' => true,
        'message' => 'Ne možeš pratiti sam sebe.',
        'data' => null,
      ], 422);
    }

    $request->validate([
      'enabled' => ['nullable', 'boolean'],
      'frequency' => ['nullable', 'in:instant,daily,weekly'],
    ]);

    $pref = FollowPreference::firstOrCreate(
      ['user_id' => $me->id, 'followed_user_id' => $followedUserId],
      ['enabled' => true, 'frequency' => 'daily']
    );

    if ($request->has('enabled')) $pref->enabled = (bool) $request->enabled;
    if ($request->has('frequency')) $pref->frequency = $request->frequency;

    $pref->save();

    return response()->json([
      'error' => false,
      'message' => 'Postavke obavijesti su sačuvane.',
      'data' => $pref,
    ]);
  }

  /**
   * GET /follow-preferences
   */
  public function index(Request $request)
  {
    $me = $request->user();

    $prefs = FollowPreference::query()
      ->where('user_id', $me->id)
      ->with(['followedUser' => function ($u) {
        $u->select('id', 'name', 'profile', 'profile_image');
      }])
      ->latest()
      ->get();

    return response()->json([
      'error' => false,
      'message' => '',
      'data' => $prefs,
    ]);
  }
}
