<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ItemOffer;
use App\Models\Chat;
use App\Events\UserTyping;
use App\Events\NewMessage;
use App\Events\MessageStatusUpdated;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function chatList(Request $request)
{
    $validated = $request->validate([
        'type' => 'required|in:buyer,seller,archived',
        'page' => 'sometimes|integer|min:1',
    ]);

    $userId = Auth::id();
    $type = $validated['type'];
    $page = $validated['page'] ?? 1;
    $perPage = 10;

    $query = ItemOffer::query()
        ->with(['buyer', 'seller', 'item']);

    if ($type === 'archived') {
        // Samo arhivirani chatovi za ovog korisnika
        $query->where(function($q) use ($userId) {
            $q->where('buyer_id', $userId)
              ->orWhere('seller_id', $userId);
        })
        ->whereRaw("JSON_CONTAINS(COALESCE(archived_by, '[]'), ?)", [json_encode($userId)]);
    } else {
        // Filter by buyer/seller
        if ($type === 'buyer') {
            $query->where('buyer_id', $userId);
        } else {
            $query->where('seller_id', $userId);
        }
        
        // Isključi arhivirane
        $query->where(function($q) use ($userId) {
            $q->whereNull('archived_by')
              ->orWhereRaw("NOT JSON_CONTAINS(COALESCE(archived_by, '[]'), ?)", [json_encode($userId)]);
        });
        
        // Isključi obrisane
        $query->where(function($q) use ($userId) {
            $q->whereNull('deleted_by')
              ->orWhereRaw("NOT JSON_CONTAINS(COALESCE(deleted_by, '[]'), ?)", [json_encode($userId)]);
        });
    }

    $chats = $query->orderBy('updated_at', 'desc')->paginate($perPage);

    $chats->getCollection()->transform(function ($chat) use ($userId) {
        $lastChat = Chat::where('item_offer_id', $chat->id)
            ->latest()
            ->first();

        $unreadCount = Chat::where('item_offer_id', $chat->id)
            ->where('sender_id', '!=', $userId)
            ->where('status', '!=', 'seen')
            ->count();

        $chat->last_message = $lastChat->message ?? '';
        $chat->last_message_type = $lastChat->message_type ?? 'text';
        $chat->last_message_time = $lastChat->created_at ?? $chat->updated_at;
        $chat->last_message_sender_id = $lastChat->sender_id ?? null;
        $chat->last_message_status = $lastChat->status ?? 'sent';
        $chat->unread_chat_count = $unreadCount;
        
        // Dodaj is_pinned i is_archived
        $pinnedBy = $chat->pinned_by ?? [];
        if (is_string($pinnedBy)) {
            $pinnedBy = json_decode($pinnedBy, true) ?? [];
        }
        $chat->is_pinned = in_array($userId, $pinnedBy);
        
        $archivedBy = $chat->archived_by ?? [];
        if (is_string($archivedBy)) {
            $archivedBy = json_decode($archivedBy, true) ?? [];
        }
        $chat->is_archived = in_array($userId, $archivedBy);

        if ($chat->buyer) {
            $chat->buyer->is_online = false;
            $chat->buyer->is_typing = false;
        }
        if ($chat->seller) {
            $chat->seller->is_online = false;
            $chat->seller->is_typing = false;
        }

        return $chat;
    });

    return response()->json([
        'error' => false,
        'data' => $chats,
    ]);
}

    public function sendTypingIndicator(Request $request)
    {
        $validated = $request->validate([
            'chat_id' => 'required|integer',
            'is_typing' => 'required|boolean',
        ]);
    
        broadcast(new \App\Events\UserTyping(
            $validated['chat_id'],
            Auth::id(),
            $validated['is_typing']
        ))->toOthers();
    
        return response()->json(['success' => true]);
    }

    public function sendMessage(Request $request)
{
    $validated = $request->validate([
        'item_offer_id' => 'required|integer|exists:item_offers,id',
        'message' => 'nullable|string',
        'file' => 'nullable|file|mimes:jpeg,png,jpg|max:5120',
        'audio' => 'nullable|file|mimes:mp3,mpeg|max:5120',
    ]);

    $messageType = 'text';
    $filePath = null;
    $audioPath = null;

    if ($request->hasFile('file')) {
        $filePath = $request->file('file')->store('chat_files', 'public');
        $messageType = $request->filled('message') ? 'file_and_text' : 'file';
    }

    if ($request->hasFile('audio')) {
        $audioPath = $request->file('audio')->store('chat_audio', 'public');
        $messageType = 'audio';
    }

    $message = Chat::create([
        'item_offer_id' => $validated['item_offer_id'],
        'sender_id' => Auth::id(),
        'message' => $validated['message'] ?? '',
        'message_type' => $messageType,
        'file' => $filePath,
        'audio' => $audioPath,
        'status' => 'sent',
    ]);

// =========================
// ✅ UPDATE SELLER response_time_avg (minutes)
// =========================
$sellerId = (int) $itemOffer->seller_id;
$buyerId  = (int) $itemOffer->buyer_id;

// računamo samo kad SELLER šalje i nije auto-reply
$isSellerSending = ((int)$user->id === $sellerId);
$isAuto = (bool)($chat->is_auto_reply ?? false);

if ($isSellerSending && !$isAuto) {

    // zadnja seller poruka prije ove (non-auto)
    $prevSellerMsg = \App\Models\Chat::where('item_offer_id', $itemOffer->id)
        ->where('sender_id', $sellerId)
        ->where(function ($q) {
            $q->whereNull('is_auto_reply')->orWhere('is_auto_reply', false);
        })
        ->where('created_at', '<', $chat->created_at)
        ->orderByDesc('created_at')
        ->first();

    // zadnja buyer poruka poslije zadnje seller poruke (da ne uzmeš neku staru)
    $buyerQuery = \App\Models\Chat::where('item_offer_id', $itemOffer->id)
        ->where('sender_id', $buyerId)
        ->where('created_at', '<', $chat->created_at);

    if ($prevSellerMsg) {
        $buyerQuery->where('created_at', '>', $prevSellerMsg->created_at);
    }

    $lastBuyerMsg = $buyerQuery->orderByDesc('created_at')->first();

    if ($lastBuyerMsg) {
        $diffMin = $chat->created_at->diffInMinutes($lastBuyerMsg->created_at);

        // clamp (0..7 dana)
        $diffMin = max(0, min((int)$diffMin, 10080));

        $seller = \App\Models\User::find($sellerId);
        if ($seller) {
            $old = $seller->response_time_avg;

            // EMA 80/20
            $seller->response_time_avg = $old === null
                ? $diffMin
                : (int) round(((float)$old * 0.8) + ($diffMin * 0.2));

            $seller->save();
        }
    }
}


    $itemOffer = ItemOffer::find($validated['item_offer_id']);
    $senderId = Auth::id();
    $receiverId = $itemOffer->buyer_id == $senderId ? $itemOffer->seller_id : $itemOffer->buyer_id;

    // --- LOGIKA ZA VRAĆANJE OBRISANOG CHATA ---
    $deletedBy = $itemOffer->deleted_by ?? [];
    if (is_string($deletedBy)) {
        $deletedBy = json_decode($deletedBy, true) ?? [];
    }

    if ($itemOffer && (int)$itemOffer->seller_id === (int)$senderId) {

        // Nađi zadnju buyer poruku prije ove seller poruke u istom chatu
        $lastBuyerMsg = Chat::where('item_offer_id', $itemOffer->id)
            ->where('sender_id', $receiverId) // buyer
            ->where('created_at', '<', $message->created_at)
            ->orderByDesc('created_at')
            ->first();
    
        if ($lastBuyerMsg) {
            $diffMin = Carbon::parse($lastBuyerMsg->created_at)->diffInMinutes($message->created_at);
    
            // Clamp da ne ode u besmisao (npr. 0..10080 = 7 dana)
            $diffMin = max(0, min($diffMin, 10080));
    
            $seller = User::find($senderId);
            if ($seller) {
                $old = $seller->response_time_avg;
    
                // EMA: 80% stari prosjek + 20% novo mjerenje
                $seller->response_time_avg = $old === null
                    ? $diffMin
                    : (int) round(((float)$old * 0.8) + ($diffMin * 0.2));
    
                $seller->save();
            }
        }
    }

    // Ako je primatelj obrisao chat, ukloni ga iz deleted liste (restore)
    if (in_array($receiverId, $deletedBy)) {
        $deletedBy = array_values(array_diff($deletedBy, [$receiverId]));
        $itemOffer->deleted_by = $deletedBy; // Model cast se brine za JSON
        
        // Ukloni i timestamp brisanja
        $deletedAtBy = $itemOffer->deleted_at_by ?? [];
        if (is_string($deletedAtBy)) {
            $deletedAtBy = json_decode($deletedAtBy, true) ?? [];
        }
        unset($deletedAtBy[$receiverId]);
        $itemOffer->deleted_at_by = $deletedAtBy; // Model cast se brine za JSON
        
        $itemOffer->save();
    }

    $itemOffer->touch(); // Update updated_at

    // --- 🔥 OVDJE IDE LOGIKA ZA NOTIFIKACIJE I MUTE ---
    
    // 1. Dohvati ko je mutirao chat
    $mutedBy = $itemOffer->muted_by ?? [];
    // Sigurnosna provjera ako model cast ne odradi posao ili je null
    if (is_string($mutedBy)) {
        $mutedBy = json_decode($mutedBy, true) ?? [];
    }

    // 2. Ako primatelj NIJE u listi onih koji su mutirali, pošalji notifikaciju
    if (!in_array($receiverId, $mutedBy)) {
        // Pozivamo pomoćnu funkciju (moraš je imati definisanu u ovom fajlu ili servisu)
        $this->sendPushNotification($receiverId, $message, $itemOffer);
    }
    
    // --------------------------------------------------

    $messageData = [
        'id' => $message->id,
        'chat_id' => $validated['item_offer_id'],
        'sender_id' => $message->sender_id,
        'message' => $message->message,
        'message_type' => $message->message_type,
        'file' => $message->file ? asset('storage/' . $message->file) : null,
        'audio' => $message->audio ? asset('storage/' . $message->audio) : null,
        'created_at' => $message->created_at->toISOString(),
        'status' => 'sent',
    ];

    // Real-time broadcast (ovo radi nezavisno od push notifikacija)
    broadcast(new NewMessage($messageData))->toOthers();

    return response()->json([
        'error' => false,
        'data' => $messageData,
    ]);
}

    // Dodajemo $id kao parametar funkcije jer ga ruta šalje (chat/seen/{id})
    // U App\Http\Controllers\Api\ChatController.php

    public function markAsSeen(Request $request, $id)
{
    $userId = Auth::id();
    
    \Log::info("markAsSeen called", ['chat_id' => $id, 'user_id' => $userId]);

    $messages = Chat::where('item_offer_id', $id)
        ->where('sender_id', '!=', $userId)
        ->where(function($q) {
            $q->where('status', '!=', 'seen')
              ->orWhere('is_read', 0);
        })
        ->get();

    if ($messages->isEmpty()) {
        \Log::info("No messages to mark as seen");
        return response()->json(['message' => 'No messages to mark', 'count' => 0]);
    }

    foreach ($messages as $message) {
        $message->update([
            'status' => 'seen',
            'is_read' => 1
        ]);
        
        \Log::info("Marked message as seen", ['message_id' => $message->id]);

        broadcast(new MessageStatusUpdated(
            $message->id,
            $id,
            'seen'
        ))->toOthers();
    }

    return response()->json([
        'error' => false,
        'message' => 'Messages marked as seen',
        'count' => $messages->count()
    ]);
}

public function muteChat($id)
    {
        $userId = Auth::id();
        $chat = ItemOffer::find($id);

        if (!$chat) {
            return response()->json(['error' => true, 'message' => 'Chat nije pronađen.'], 404);
        }

        // Provjeri da li korisnik pripada ovom chatu
        if ($chat->seller_id != $userId && $chat->buyer_id != $userId) {
            return response()->json(['error' => true, 'message' => 'Nemate pristup ovom chatu.'], 403);
        }

        // Uzmi trenutni niz ili prazan niz ako je null
        $mutedBy = $chat->muted_by ?? [];

        // Ako korisnik već nije u nizu, dodaj ga
        if (!in_array($userId, $mutedBy)) {
            $mutedBy[] = $userId;
            $chat->muted_by = $mutedBy;
            $chat->save();
        }

        return response()->json([
            'error' => false, 
            'message' => 'Notifikacije isključene.',
            'is_muted' => true
        ]);
    }

    public function unmuteChat($id)
    {
        $userId = Auth::id();
        $chat = ItemOffer::find($id);

        if (!$chat) {
            return response()->json(['error' => true, 'message' => 'Chat nije pronađen.'], 404);
        }

        $mutedBy = $chat->muted_by ?? [];

        // Ako je korisnik u nizu, izbaci ga
        if (in_array($userId, $mutedBy)) {
            // array_diff vraća novi niz bez navedenih vrijednosti
            $chat->muted_by = array_values(array_diff($mutedBy, [$userId]));
            $chat->save();
        }

        return response()->json([
            'error' => false, 
            'message' => 'Notifikacije uključene.',
            'is_muted' => false
        ]);
    }

    public function chatMessages(Request $request)
    {
        $validated = $request->validate([
            'item_offer_id' => 'required|integer',
            'page' => 'sometimes|integer|min:1',
        ]);

        $messages = Chat::where('item_offer_id', $validated['item_offer_id'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $messages->getCollection()->transform(function ($message) {
            if ($message->file) {
                $message->file = asset('storage/' . $message->file);
            }
            if ($message->audio) {
                $message->audio = asset('storage/' . $message->audio);
            }
            return $message;
        });

        return response()->json([
            'error' => false,
            'data' => $messages,
        ]);
    }

    public function heartbeat(Request $request)
{
    $userId = Auth::id();
    $cacheKey = 'user-online-' . $userId;
    
    // Osvježi online status
    Cache::put($cacheKey, true, now()->addMinutes(5));
    
    return response()->json(['status' => 'ok']);
}

public function archiveChat(Request $request, $id)
{
    try {
        $userId = Auth::id();
        $itemOffer = ItemOffer::findOrFail($id);
        
        // Check if user is part of this chat
        if ($itemOffer->buyer_id !== $userId && $itemOffer->seller_id !== $userId) {
            return response()->json([
                'error' => true,
                'message' => 'Unauthorized'
            ], 403);
        }
        
        // Get current archived_by array or initialize empty
        $archivedBy = $itemOffer->archived_by ?? [];
        if (is_string($archivedBy)) {
            $archivedBy = json_decode($archivedBy, true) ?? [];
        }
        
        // Add current user to archived_by if not already there
        if (!in_array($userId, $archivedBy)) {
            $archivedBy[] = $userId;
        }
        
        $itemOffer->archived_by = $archivedBy;
        $itemOffer->save();
        
        return response()->json([
            'error' => false,
            'message' => 'Chat archived successfully'
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => true,
            'message' => 'Error archiving chat',
            'details' => $e->getMessage()
        ], 500);
    }
}

/**
 * Unarchive a chat for current user
 */
public function unarchiveChat(Request $request, $id)
{
    try {
        $userId = Auth::id();
        $itemOffer = ItemOffer::findOrFail($id);
        
        if ($itemOffer->buyer_id !== $userId && $itemOffer->seller_id !== $userId) {
            return response()->json([
                'error' => true,
                'message' => 'Unauthorized'
            ], 403);
        }
        
        $archivedBy = $itemOffer->archived_by ?? [];
        if (is_string($archivedBy)) {
            $archivedBy = json_decode($archivedBy, true) ?? [];
        }
        
        // Remove current user from archived_by
        $archivedBy = array_values(array_diff($archivedBy, [$userId]));
        
        $itemOffer->archived_by = $archivedBy;
        $itemOffer->save();
        
        return response()->json([
            'error' => false,
            'message' => 'Chat unarchived successfully'
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => true,
            'message' => 'Error unarchiving chat',
            'details' => $e->getMessage()
        ], 500);
    }
}

/**
 * Soft delete a chat for current user
 */
public function deleteChat(Request $request, $id)
{
    try {
        $userId = Auth::id();
        $itemOffer = ItemOffer::findOrFail($id);
        
        if ($itemOffer->buyer_id !== $userId && $itemOffer->seller_id !== $userId) {
            return response()->json([
                'error' => true,
                'message' => 'Unauthorized'
            ], 403);
        }
        
        // Dodaj u deleted_by
        $deletedBy = $itemOffer->deleted_by ?? [];
        if (is_string($deletedBy)) {
            $deletedBy = json_decode($deletedBy, true) ?? [];
        }
        if (!in_array($userId, $deletedBy)) {
            $deletedBy[] = $userId;
        }
        $itemOffer->deleted_by = $deletedBy;
        
        $deletedAtBy = $itemOffer->deleted_at_by ?? [];
        if (is_string($deletedAtBy)) {
            $deletedAtBy = json_decode($deletedAtBy, true) ?? [];
        }
        $deletedAtBy[$userId] = now()->toISOString();
        $itemOffer->deleted_at_by = $deletedAtBy;
        
        $itemOffer->save();
        
        return response()->json([
            'error' => false,
            'message' => 'Chat deleted successfully'
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => true,
            'message' => 'Error deleting chat',
            'details' => $e->getMessage()
        ], 500);
    }
}

/**
 * Mark chat as unread for current user
 */
public function markAsUnread(Request $request, $id)
{
    try {
        $userId = Auth::id();
        $itemOffer = ItemOffer::findOrFail($id);
        
        if ($itemOffer->buyer_id !== $userId && $itemOffer->seller_id !== $userId) {
            return response()->json([
                'error' => true,
                'message' => 'Unauthorized'
            ], 403);
        }
        
        // Označi zadnju poruku od drugog korisnika kao nepročitanu
        $updated = Chat::where('item_offer_id', $itemOffer->id)
            ->where('sender_id', '!=', $userId)
            ->latest()
            ->first();
            
        if ($updated) {
            $updated->update([
                'is_read' => 0,
                'status' => 'delivered'
            ]);
        }
        
        return response()->json([
            'error' => false,
            'message' => 'Chat marked as unread'
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => true,
            'message' => 'Error marking as unread',
            'details' => $e->getMessage()
        ], 500);
    }
}

/**
 * Pin/Unpin a chat for current user
 */
public function pinChat(Request $request, $id)
{
    try {
        $userId = Auth::id();
        $shouldPin = $request->input('pin', true);
        $itemOffer = ItemOffer::findOrFail($id);
        
        if ($itemOffer->buyer_id !== $userId && $itemOffer->seller_id !== $userId) {
            return response()->json([
                'error' => true,
                'message' => 'Unauthorized'
            ], 403);
        }
        
        $pinnedBy = $itemOffer->pinned_by ?? [];
        if (is_string($pinnedBy)) {
            $pinnedBy = json_decode($pinnedBy, true) ?? [];
        }
        
        if ($shouldPin && !in_array($userId, $pinnedBy)) {
            $pinnedBy[] = $userId;
        } elseif (!$shouldPin) {
            $pinnedBy = array_values(array_diff($pinnedBy, [$userId]));
        }
        
        $itemOffer->pinned_by = $pinnedBy;
        $itemOffer->save();
        
        return response()->json([
            'error' => false,
            'message' => $shouldPin ? 'Chat pinned' : 'Chat unpinned'
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => true,
            'message' => 'Error pinning chat',
            'details' => $e->getMessage()
        ], 500);
    }
}

/**
 * Get chat list with archive/delete filtering
 * Updated version of existing chatList method
 * 
 * @param type: 'seller', 'buyer', 'archived'
 */
public function getChatList(Request $request)
{
    try {
        $userId = Auth::id();
        $type = $request->input('type', 'seller');
        $page = $request->input('page', 1);
        $perPage = 20;
        
        $query = ItemOffer::with(['buyer', 'seller', 'item'])
            ->where(function($q) use ($userId) {
                $q->where('buyer_id', $userId)
                  ->orWhere('seller_id', $userId);
            });
        
        if ($type === 'archived') {
            // Get only archived chats for this user
            $query->whereRaw("JSON_CONTAINS(COALESCE(archived_by, '[]'), ?)", [json_encode($userId)]);
        } else {
            // Exclude archived and deleted chats
            $query->where(function($q) use ($userId) {
                $q->whereNull('archived_by')
                  ->orWhereRaw("NOT JSON_CONTAINS(COALESCE(archived_by, '[]'), ?)", [json_encode($userId)]);
            })
            ->where(function($q) use ($userId) {
                $q->whereNull('deleted_by')
                  ->orWhereRaw("NOT JSON_CONTAINS(COALESCE(deleted_by, '[]'), ?)", [json_encode($userId)]);
            });
            
            // Filter by seller/buyer
            if ($type === 'seller') {
                $query->where('seller_id', $userId);
            } else {
                $query->where('buyer_id', $userId);
            }
        }
        
        // Get chats with computed fields
        $chats = $query->orderByDesc('updated_at')
            ->paginate($perPage, ['*'], 'page', $page);
        
        // Transform data to include is_pinned and is_archived
        $chats->getCollection()->transform(function($chat) use ($userId) {
            $pinnedBy = $chat->pinned_by ?? [];
            if (is_string($pinnedBy)) {
                $pinnedBy = json_decode($pinnedBy, true) ?? [];
            }
            
            $archivedBy = $chat->archived_by ?? [];
            if (is_string($archivedBy)) {
                $archivedBy = json_decode($archivedBy, true) ?? [];
            }
            
            $chat->is_pinned = in_array($userId, $pinnedBy);
            $chat->is_archived = in_array($userId, $archivedBy);
            
            // Calculate unread count
            $chat->unread_chat_count = Chat::where('item_offer_id', $chat->id)
                ->where('sender_id', '!=', $userId)
                ->where('is_read', 0)
                ->count();
            
            // Get last message info
            $lastMessage = Chat::where('item_offer_id', $chat->id)
                ->latest()
                ->first();
                
            $chat->last_message = $lastMessage?->message;
            $chat->last_message_type = $lastMessage?->message_type ?? 'text';
            $chat->last_message_time = $lastMessage?->created_at;
            $chat->last_message_sender_id = $lastMessage?->sender_id;
            
            return $chat;
        });
        
        return response()->json([
            'error' => false,
            'message' => 'Chat list fetched successfully',
            'data' => $chats
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => true,
            'message' => 'Error fetching chat list',
            'details' => $e->getMessage()
        ], 500);
    }
}

/**
 * Set offline - kada korisnik zatvori stranicu
 */
public function setOffline(Request $request)
{
    $userId = Auth::id();
    $cacheKey = 'user-online-' . $userId;
    
    // Obriši iz cache-a
    Cache::forget($cacheKey);
    
    // Broadcast offline status
    broadcast(new \App\Events\UserOnlineStatus($userId, false))->toOthers();
    
    return response()->json(['status' => 'ok']);
}

/**
 * Provjeri online status korisnika
 */
public function checkOnlineStatus(Request $request)
{
    $userIds = $request->input('user_ids', []);
    
    $onlineStatus = [];
    foreach ($userIds as $userId) {
        $cacheKey = 'user-online-' . $userId;
        $onlineStatus[$userId] = Cache::has($cacheKey);
    }
    
    return response()->json([
        'error' => false,
        'data' => $onlineStatus
    ]);
}

/**
     * Pomoćna funkcija za slanje Firebase (FCM) notifikacije
     */
    private function sendPushNotification($receiverId, $message, $chatInfo)
    {
        try {
            // Dohvati podatke korisnika kojem šalješ (treba ti njegov FCM token)
            $receiver = \App\Models\User::find($receiverId);
            
            if (!$receiver || !$receiver->fcm_id) {
                return; // Korisnik nema token za notifikacije
            }

            // Ovdje ide tvoja logika za slanje (koristiš li neki paket ili cURL?)
            // Ovo je primjer kako to obično izgleda:
            
            $title = "Nova poruka od " . Auth::user()->name;
            $body = $message->message_type == 'text' ? $message->message : 'Poslao vam je prilog.';

            // Primjer poziva tvog servisa za notifikacije (prilagodi ovo svom sistemu)
            // \App\Helpers\PushNotification::send($receiver->fcm_id, $title, $body, [
            //    'type' => 'chat',
            //    'chat_id' => $chatInfo->id,
            //    'sender_id' => Auth::id()
            // ]);
            
            // ILI ako koristiš Laravel Notifications:
            // $receiver->notify(new \App\Notifications\NewChatMessage($message));
            
            \Log::info("Push notifikacija poslana korisniku: " . $receiverId);

        } catch (\Exception $e) {
            \Log::error("Greška pri slanju push notifikacije: " . $e->getMessage());
        }
    }
}