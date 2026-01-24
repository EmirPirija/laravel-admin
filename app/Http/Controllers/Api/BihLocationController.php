<?php
 
namespace App\Http\Controllers\Api;
 
use App\Http\Controllers\Controller;
use App\Models\BihEntity;
use App\Models\BihRegion;
use App\Models\BihMunicipality;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
 
class BihLocationController extends Controller
{
    /**
     * Dohvati sve entitete
     */
    public function getEntities(): JsonResponse
    {
        $entities = BihEntity::active()
            ->select('id', 'code', 'name', 'short_name')
            ->get();
 
        return response()->json([
            'error' => false,
            'message' => 'Entities retrieved successfully',
            'data' => $entities,
        ]);
    }
 
    /**
     * Dohvati regije/kantone po entitetu
     */
    public function getRegions(Request $request): JsonResponse
    {
        $entityId = $request->input('entity_id');
        $entityCode = $request->input('entity_code');
 
        $query = BihRegion::active()
            ->select('id', 'code', 'name', 'entity_id', 'type');
 
        if ($entityId) {
            $query->where('entity_id', $entityId);
        } elseif ($entityCode) {
            $entity = BihEntity::where('code', $entityCode)->first();
            if ($entity) {
                $query->where('entity_id', $entity->id);
            }
        }
 
        $regions = $query->with('entity:id,code,name,short_name')->get();
 
        return response()->json([
            'error' => false,
            'message' => 'Regions retrieved successfully',
            'data' => $regions,
        ]);
    }
 
    /**
     * Dohvati općine/gradove po regiji
     */
    public function getMunicipalities(Request $request): JsonResponse
    {
        $regionId = $request->input('region_id');
        $regionCode = $request->input('region_code');
        $search = $request->input('search');
        $onlyPopular = $request->boolean('popular');
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 50);
 
        $query = BihMunicipality::active()
            ->select('id', 'code', 'name', 'region_id', 'type', 'latitude', 'longitude', 'is_popular');
 
        if ($regionId) {
            $query->where('region_id', $regionId);
        } elseif ($regionCode) {
            $region = BihRegion::where('code', $regionCode)->first();
            if ($region) {
                $query->where('region_id', $region->id);
            }
        }
 
        if ($search) {
            $query->search($search);
        }
 
        if ($onlyPopular) {
            $query->popular();
        }
 
        $municipalities = $query
            ->with(['region:id,code,name', 'region.entity:id,code,name,short_name'])
            ->orderBy('is_popular', 'desc')
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'page', $page);
 
        return response()->json([
            'error' => false,
            'message' => 'Municipalities retrieved successfully',
            'data' => $municipalities,
        ]);
    }
 
    /**
     * Pretraži općine/gradove (za autocomplete)
     */
    public function searchMunicipalities(Request $request): JsonResponse
    {
        $search = $request->input('search', '');
        $limit = min($request->input('limit', 15), 50);
 
        if (strlen($search) < 2) {
            return response()->json([
                'error' => false,
                'message' => 'Search term too short',
                'data' => [],
            ]);
        }
 
        $municipalities = BihMunicipality::active()
            ->search($search)
            ->with(['region:id,code,name', 'region.entity:id,code,name,short_name'])
            ->orderBy('is_popular', 'desc')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(function ($muni) {
                return [
                    'id' => $muni->id,
                    'code' => $muni->code,
                    'name' => $muni->name,
                    'type' => $muni->type,
                    'region_id' => $muni->region_id,
                    'region_name' => $muni->region?->name,
                    'entity_id' => $muni->region?->entity_id,
                    'entity_name' => $muni->region?->entity?->name,
                    'entity_short' => $muni->region?->entity?->short_name,
                    'full_address' => $muni->full_address,
                    'latitude' => $muni->latitude,
                    'longitude' => $muni->longitude,
                ];
            });
 
        return response()->json([
            'error' => false,
            'message' => 'Search results',
            'data' => $municipalities,
        ]);
    }
 
    /**
     * Dohvati popularne gradove
     */
    public function getPopularCities(): JsonResponse
    {
        $popularCities = BihMunicipality::active()
            ->popular()
            ->with(['region:id,code,name', 'region.entity:id,code,name,short_name'])
            ->orderBy('name')
            ->get()
            ->map(function ($muni) {
                return [
                    'id' => $muni->id,
                    'code' => $muni->code,
                    'name' => $muni->name,
                    'region_id' => $muni->region_id,
                    'region_name' => $muni->region?->name,
                    'entity_short' => $muni->region?->entity?->short_name,
                    'full_address' => $muni->full_address,
                ];
            });
 
        return response()->json([
            'error' => false,
            'message' => 'Popular cities',
            'data' => $popularCities,
        ]);
    }
 
    /**
     * Dohvati detalje općine po code-u ili id-u
     */
    public function getMunicipalityDetails(Request $request): JsonResponse
    {
        $code = $request->input('code');
        $id = $request->input('id');
 
        $municipality = BihMunicipality::query()
            ->when($id, fn($q) => $q->where('id', $id))
            ->when($code, fn($q) => $q->where('code', $code))
            ->with(['region:id,code,name,type', 'region.entity:id,code,name,short_name'])
            ->first();
 
        if (!$municipality) {
            return response()->json([
                'error' => true,
                'message' => 'Municipality not found',
                'data' => null,
            ], 404);
        }
 
        return response()->json([
            'error' => false,
            'message' => 'Municipality details',
            'data' => [
                'municipality' => [
                    'id' => $municipality->id,
                    'code' => $municipality->code,
                    'name' => $municipality->name,
                    'type' => $municipality->type,
                    'latitude' => $municipality->latitude,
                    'longitude' => $municipality->longitude,
                ],
                'region' => [
                    'id' => $municipality->region?->id,
                    'code' => $municipality->region?->code,
                    'name' => $municipality->region?->name,
                    'type' => $municipality->region?->type,
                ],
                'entity' => [
                    'id' => $municipality->region?->entity?->id,
                    'code' => $municipality->region?->entity?->code,
                    'name' => $municipality->region?->entity?->name,
                    'short_name' => $municipality->region?->entity?->short_name,
                ],
                'country' => [
                    'name' => 'Bosna i Hercegovina',
                    'code' => 'BA',
                ],
                'full_address' => $municipality->full_address,
            ],
        ]);
    }
}