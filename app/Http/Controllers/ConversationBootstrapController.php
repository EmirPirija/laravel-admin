<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\ItemOffer;
use App\Models\Item;
use App\Models\User;

class ConversationBootstrapController extends Controller
{
    private function ok($data = null, $message = null)
    {
        return response()->json([
            'error' => false,
            'message' => $message,
            'data' => $data,
        ]);
    }

    private function fail($message, $code = 400)
    {
        return response()->json([
            'error' => true,
            'message' => $message,
            'data' => null,
        ], $code);
    }

    public function startItemConversation(Request $request)
    {
        $request->validate([
            'item_id' => ['required', 'integer'],
        ]);

        $userId = Auth::id();
        $itemId = (int) $request->input('item_id');

        $item = Item::query()->find($itemId);
        if (!$item) return $this->fail('Oglas nije pronađen.', 404);

        // pokušaj pokriti oba naming-a u tvojoj bazi
        $sellerId = $item->user_id ?? $item->seller_id ?? null;
        if (!$sellerId) return $this->fail('Prodavač za ovaj oglas nije dostupan.', 422);

        if ((int) $sellerId === (int) $userId) {
            return $this->fail('Ne možete poslati poruku sami sebi.', 422);
        }

        $existing = ItemOffer::query()
            ->where('conversation_type', 'item')
            ->where('item_id', $itemId)
            ->where('buyer_id', $userId)
            ->where('seller_id', $sellerId)
            ->first();

        if ($existing) {
            return $this->ok(['id' => $existing->id]);
        }

        // Kreiramo conversation container (bez ponude)
        // ⚠️ Ako tvoja tabela ima obavezna polja za ponudu, ovdje ih postavi na safe default.
        $offer = new ItemOffer();
        $offer->conversation_type = 'item';
        $offer->item_id = $itemId;
        $offer->buyer_id = $userId;
        $offer->seller_id = $sellerId;

        // Ako postoji status polje – stavi neutralno (prilagodi ako je drugačije u tvojoj bazi)
        if (property_exists($offer, 'status') && empty($offer->status)) {
            $offer->status = 'conversation';
        }

        $offer->save();

        return $this->ok(['id' => $offer->id]);
    }

    public function checkDirectConversation(Request $request)
    {
        $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $me = Auth::id();
        $other = (int) $request->input('user_id');

        if ($other === (int) $me) {
            return $this->fail('Ne možete otvoriti chat sami sa sobom.', 422);
        }

        $exists = ItemOffer::query()
            ->where('conversation_type', 'direct')
            ->whereNull('item_id')
            ->where(function ($q) use ($me, $other) {
                $q->where(function ($qq) use ($me, $other) {
                    $qq->where('buyer_id', $me)->where('seller_id', $other);
                })->orWhere(function ($qq) use ($me, $other) {
                    $qq->where('buyer_id', $other)->where('seller_id', $me);
                });
            })
            ->first();

        return $this->ok($exists ? ['id' => $exists->id] : null);
    }

    public function startDirectConversation(Request $request)
    {
        $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $me = Auth::id();
        $other = (int) $request->input('user_id');

        if ($other === (int) $me) {
            return $this->fail('Ne možete poslati poruku sami sebi.', 422);
        }

        $user = User::query()->find($other);
        if (!$user) return $this->fail('Korisnik nije pronađen.', 404);

        $existing = ItemOffer::query()
            ->where('conversation_type', 'direct')
            ->whereNull('item_id')
            ->where(function ($q) use ($me, $other) {
                $q->where(function ($qq) use ($me, $other) {
                    $qq->where('buyer_id', $me)->where('seller_id', $other);
                })->orWhere(function ($qq) use ($me, $other) {
                    $qq->where('buyer_id', $other)->where('seller_id', $me);
                });
            })
            ->first();

        if ($existing) {
            return $this->ok(['id' => $existing->id]);
        }

        $conv = new ItemOffer();
        $conv->conversation_type = 'direct';
        $conv->item_id = null;
        $conv->buyer_id = $me;     // inicijator
        $conv->seller_id = $other; // primalac

        if (property_exists($conv, 'status') && empty($conv->status)) {
            $conv->status = 'conversation';
        }

        $conv->save();

        return $this->ok(['id' => $conv->id]);
    }
}
