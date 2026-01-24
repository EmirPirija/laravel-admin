<?php
 
namespace App\Http\Controllers\Api;
 
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
 
class SaleController extends Controller
{
    /**
     * Zabilježi prodaju
     */
    public function recordSale(Request $request)
    {
        try {
            $request->validate([
                'item_id' => 'required|exists:items,id',
                'buyer_id' => 'nullable|exists:users,id',
                'quantity_sold' => 'nullable|integer|min:1',
                'sale_receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
                'sale_note' => 'nullable|string|max:500',
                'sale_price' => 'nullable|numeric|min:0',
            ]);
 
            $user = $request->user();
            $item = Item::findOrFail($request->item_id);
 
            // Provjeri vlasništvo
            if ($item->user_id !== $user->id) {
                return response()->json([
                    'error' => true,
                    'message' => 'Nemate dozvolu za ovu akciju.'
                ], 403);
            }
 
            $quantitySold = $request->quantity_sold ?? 1;
            $unitPrice = $item->price ?? 0;
            $totalPrice = $request->sale_price ?? ($unitPrice * $quantitySold);
 
            // Provjeri zalihu ako postoji
            if ($item->inventory_count !== null && $quantitySold > $item->inventory_count) {
                return response()->json([
                    'error' => true,
                    'message' => 'Nedovoljna količina na zalihi.'
                ], 400);
            }
 
            DB::beginTransaction();
 
            // Upload računa ako postoji
            $receiptUrl = null;
            if ($request->hasFile('sale_receipt')) {
                $file = $request->file('sale_receipt');
                $filename = 'receipt_' . time() . '_' . $item->id . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('receipts', $filename, 'public');
                $receiptUrl = Storage::disk('public')->url($path);
            }
 
            // Kreiraj zapis o prodaji
            $sale = Sale::create([
                'item_id' => $item->id,
                'seller_id' => $user->id,
                'buyer_id' => $request->buyer_id,
                'quantity' => $quantitySold,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'receipt_url' => $receiptUrl,
                'note' => $request->sale_note,
                'status' => 'completed',
            ]);
 
            // Ažuriraj inventory
            $newInventory = null;
            $newStatus = 'sold out';
 
            if ($item->inventory_count !== null) {
                $newInventory = max(0, $item->inventory_count - $quantitySold);
                
                // Provjeri seller settings
                $continueSellingWhenOutOfStock = false;
                if ($user->sellerSettings) {
                    $continueSellingWhenOutOfStock = $user->sellerSettings->continue_selling_out_of_stock ?? false;
                }
 
                if ($newInventory > 0 || $continueSellingWhenOutOfStock) {
                    $newStatus = 'approved';
                }
                
                $item->inventory_count = $newInventory;
            }
 
            $item->status = $newStatus;
            
            // Ukloni rezervaciju ako postoji
            $item->reservation_status = 'none';
            $item->reserved_for_user_id = null;
            $item->reserved_at = null;
            $item->reservation_note = null;
            
            $item->save();
 
            DB::commit();
 
            return response()->json([
                'error' => false,
                'message' => 'Prodaja uspješno zabilježena!',
                'data' => [
                    'sale' => $sale->load(['item', 'buyer']),
                    'remaining_stock' => $newInventory,
                    'item_status' => $newStatus,
                ]
            ]);
 
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Sale error: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Greška pri bilježenju prodaje: ' . $e->getMessage()
            ], 500);
        }
    }
 
    /**
     * Rezerviraj ili ukloni rezervaciju
     */
    public function handleReservation(Request $request)
    {
        try {
            $request->validate([
                'item_id' => 'required|exists:items,id',
                'reserved_for_user_id' => 'nullable|exists:users,id',
                'reservation_note' => 'nullable|string|max:255',
                'remove_reservation' => 'nullable|boolean',
            ]);
 
            $user = $request->user();
            $item = Item::findOrFail($request->item_id);
 
            if ($item->user_id !== $user->id) {
                return response()->json([
                    'error' => true,
                    'message' => 'Nemate dozvolu za ovu akciju.'
                ], 403);
            }
 
            if ($request->remove_reservation) {
                // $item->status = 'approved';
                $item->reservation_status = 'none';
                $item->reserved_for_user_id = null;
                $item->reserved_at = null;
                $item->reservation_note = null;
                $item->save();
 
                return response()->json([
                    'error' => false,
                    'message' => 'Rezervacija uklonjena.',
                    'data' => $item
                ]);
            } else {
                $item->reservation_status = 'reserved';
                $item->reserved_for_user_id = $request->reserved_for_user_id;
                $item->reserved_at = now();
                $item->reservation_note = $request->reservation_note;
                $item->save();
 
                return response()->json([
                    'error' => false,
                    'message' => 'Oglas je rezerviran.',
                    'data' => $item
                ]);
            }
 
        } catch (\Exception $e) {
            Log::error('Reservation error: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Greška: ' . $e->getMessage()
            ], 500);
        }
    }
 
    /**
     * Moje kupovine (za kupca)
     */
    public function myPurchases(Request $request)
    {
        try {
            $user = $request->user();
 
            $purchases = Sale::where('buyer_id', $user->id)
                ->with(['item', 'seller'])
                ->orderBy('created_at', 'desc')
                ->paginate(10);
 
            return response()->json([
                'error' => false,
                'data' => $purchases
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => true,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}