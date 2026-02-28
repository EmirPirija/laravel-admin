<?php

namespace App\Http\Controllers;

use App\Services\UserDeletionService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AdminController extends Controller
{
    public function __construct(private readonly UserDeletionService $userDeletionService)
    {
    }

    /**
     * Dohvati korisnike sa filterima i paginacijom
     * JAVNO DOSTUPNO - bez admin provjere
     */
    public function getUsers(Request $request)
    {
        $perPage = $request->get('per_page', 24);
        $search = $request->get('search', '');
        $role = $request->get('role', '');
        $status = $request->get('status', '');

        // ✅ Dinamički provjeri koje kolone postoje
        $columns = ['id', 'name', 'email', 'created_at', 'updated_at'];
        
        // Dodaj opcionalne kolone ako postoje
        if (Schema::hasColumn('users', 'phone')) {
            $columns[] = 'phone';
        }
        if (Schema::hasColumn('users', 'role')) {
            $columns[] = 'role';
        }
        if (Schema::hasColumn('users', 'status')) {
            $columns[] = 'status';
        }
        if (Schema::hasColumn('users', 'avatar')) {
            $columns[] = 'avatar';
        }
        if (Schema::hasColumn('users', 'svg_avatar')) {
            $columns[] = 'svg_avatar';
        }
        if (Schema::hasColumn('users', 'last_seen')) {
            $columns[] = 'last_seen';
        }
        if (Schema::hasColumn('users', 'is_verified')) {
            $columns[] = 'is_verified';
        }

        $query = User::query()
            ->select($columns)
            ->with(['items' => function($q) {
                $q->select('id', 'user_id', 'status');
            }]);

        // Pretraga
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
                
                // Dodaj phone samo ako kolona postoji
                if (Schema::hasColumn('users', 'phone')) {
                    $q->orWhere('phone', 'like', "%{$search}%");
                }
            });
        }

        // Filter po roli (samo ako kolona postoji)
        if ($role && $role !== 'all' && Schema::hasColumn('users', 'role')) {
            $query->where('role', $role);
        }

        // Filter po statusu (samo ako kolona postoji)
        if ($status && $status !== 'all' && Schema::hasColumn('users', 'status')) {
            $query->where('status', $status);
        }

        // Sortiraj
        $query->orderBy('created_at', 'desc');

        $users = $query->paginate($perPage);

        // Transformiši rezultate
        $users->getCollection()->transform(function ($user) {
            $data = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ];

            // Dodaj opcionalna polja ako postoje
            $data['phone'] = $user->phone ?? null;
            $data['role'] = $user->role ?? 'user';
            $data['status'] = $user->status ?? 'active';
            $data['avatar'] = $user->avatar ?? null;
            $data['svg_avatar'] = $user->svg_avatar ?? null;
            $data['last_seen'] = $user->last_seen ?? $user->updated_at;
            $data['is_verified'] = $user->is_verified ?? false;
            $data['total_ads'] = $user->items->count();
            $data['active_ads'] = $user->items->where('status', 'approved')->count();
            $data['pending_ads'] = $user->items->where('status', 'pending')->count();
            $data['sold_ads'] = $user->items->where('status', 'sold out')->count();

            return $data;
        });

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'per_page' => $users->perPage(),
            'total' => $users->total(),
            'from' => $users->firstItem(),
            'to' => $users->lastItem(),
        ]);
    }

    /**
     * Dohvati jednog korisnika
     */
    public function getUser(Request $request, $id)
    {
        $user = User::with([
            'items' => function($q) {
                $q->where('status', 'approved')
                  ->orderBy('created_at', 'desc')
                  ->limit(10);
            }
        ])->findOrFail($id);

        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];

        // Dodaj opcionalna polja
        $data['phone'] = $user->phone ?? null;
        $data['role'] = $user->role ?? 'user';
        $data['status'] = $user->status ?? 'active';
        $data['avatar'] = $user->avatar ?? null;
        $data['svg_avatar'] = $user->svg_avatar ?? null;
        $data['last_seen'] = $user->last_seen ?? $user->updated_at;
        $data['is_verified'] = $user->is_verified ?? false;
        $data['total_ads'] = $user->items->count();
        $data['active_ads'] = $user->items->where('status', 'approved')->count();
        $data['items'] = $user->items;

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Ažuriraj status (samo admin)
     */
    public function updateUserStatus(Request $request, $id)
    {
        if (!$request->user() || $request->user()->role !== 'admin') {
            return response()->json(['error' => 'Nemate dozvolu'], 403);
        }

        if (!Schema::hasColumn('users', 'status')) {
            return response()->json(['error' => 'Status kolona ne postoji'], 400);
        }

        $request->validate([
            'status' => 'required|in:active,suspended,banned',
        ]);

        $user = User::findOrFail($id);
        $user->status = $request->status;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Status ažuriran',
            'data' => $user
        ]);
    }

    /**
     * Obriši korisnika (samo admin)
     */
    public function deleteUser(Request $request, $id)
    {
        if (!$request->user() || $request->user()->role !== 'admin') {
            return response()->json(['error' => 'Nemate dozvolu'], 403);
        }

        $user = User::withTrashed()->findOrFail($id);
        
        if ($user->id === $request->user()->id) {
            return response()->json(['error' => 'Ne možete obrisati sebe'], 400);
        }

        if (isset($user->role) && $user->role === 'admin') {
            return response()->json(['error' => 'Ne možete obrisati admina'], 400);
        }

        $this->userDeletionService->forceDeleteUser($user);

        return response()->json([
            'success' => true,
            'message' => 'Korisnik trajno obrisan'
        ]);
    }
}
