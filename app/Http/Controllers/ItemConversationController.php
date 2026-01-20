<?php

namespace App\Http\Controllers;

use App\Models\ItemOffer;
use App\Models\Chat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ItemConversationController extends Controller
{
    public function checkConversation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'required|exists:items,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
                'data' => null,
            ], 422);
        }

        $userId = Auth::id();
        if (!$userId) {
            return response()->json([
                'error' => true,
                'message' => 'Unauthenticated',
                'data' => null,
            ], 401);
        }

        $itemId = (int) $request->item_id;

        // Nađi konverzaciju (ItemOffer) za ovog buyer-a i item
        $conversation = ItemOffer::where('item_id', $itemId)
            ->where('buyer_id', $userId)
            ->with(['item:id,name,image', 'seller:id,name,profile'])
            ->orderByDesc('id')
            ->first();

        if (!$conversation) {
            // Frontend-friendly: nije greška, samo nema konverzacije
            return response()->json([
                'error' => false,
                'message' => 'Nema postojeće konverzacije',
                'data' => null,
            ], 200);
        }

        // Unread count: poruke koje je poslala druga strana i nisu pročitane
        // (koristimo is_read jer ga koristiš u sendMessage)
        $unreadCount = Chat::where('item_offer_id', $conversation->id)
            ->where('sender_id', '!=', $userId)
            ->where('is_read', 0)
            ->count();

        // Zadnja poruka
        $lastChat = Chat::where('item_offer_id', $conversation->id)
            ->orderByDesc('created_at')
            ->first();

        $lastMessageText = null;
        $lastMessageAt = null;

        if ($lastChat) {
            if (!empty($lastChat->message)) {
                $lastMessageText = $lastChat->message;
            } else {
                // fallback kad je file/audio bez teksta
                if ($lastChat->message_type === 'audio') {
                    $lastMessageText = '🎤 Audio poruka';
                } elseif (in_array($lastChat->message_type, ['file', 'file_and_text'])) {
                    $lastMessageText = '📎 Fajl';
                } else {
                    $lastMessageText = '💬 Poruka';
                }
            }

            $lastMessageAt = $lastChat->created_at;
        }

        return response()->json([
            'error' => false,
            'message' => 'Konverzacija pronađena',
            'data' => [
                'id' => $conversation->id,
                'item' => $conversation->item,
                'seller' => $conversation->seller,
                'unread_count' => $unreadCount,
                'last_message' => $lastMessageText,
                'last_message_at' => $lastMessageAt,
            ],
        ], 200);
    }
}
