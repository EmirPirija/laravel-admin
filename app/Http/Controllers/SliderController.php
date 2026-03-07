<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Item;
use App\Models\Slider;
use App\Services\BootstrapTableService;
use App\Services\FileService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SliderController extends Controller {

    private string $uploadFolder;

    public function __construct() {
        $this->uploadFolder = 'sliders';
    }

    public function index() {
        ResponseService::noAnyPermissionThenRedirect(['slider-list', 'slider-create', 'slider-update', 'slider-delete']);
        $slider = Slider::select(['id', 'image', 'sequence'])->orderBy('sequence', 'ASC')->get();
        return view('slider.index', compact('slider'));
    }

    public function searchItems(Request $request) {
        ResponseService::noAnyPermissionThenSendJson(['slider-list', 'slider-create', 'slider-update', 'slider-delete']);

        $term = trim((string) $request->input('q', $request->input('search', '')));
        $limit = min(50, max(10, (int) $request->input('limit', 20)));

        $query = Item::query()
            ->without(['translations'])
            ->select('id', 'name')
            ->where('status', 'approved')
            ->getNonExpiredItems()
            ->orderByDesc('id');

        if ($term !== '') {
            $query->where(function ($itemQuery) use ($term) {
                $itemQuery->where('name', 'LIKE', '%' . $term . '%');
                if (is_numeric($term)) {
                    $itemQuery->orWhere('id', (int) $term);
                }
            });
        }

        $items = $query->limit($limit)->get();

        $results = $items->map(static function ($item) {
            return [
                'id' => (int) $item->id,
                'text' => sprintf('#%d • %s', (int) $item->id, (string) ($item->name ?? '')),
            ];
        })->values();

        return response()->json([
            'items' => $results,
        ]);
    }

    public function searchCategories(Request $request) {
        ResponseService::noAnyPermissionThenSendJson(['slider-list', 'slider-create', 'slider-update', 'slider-delete']);

        $term = trim((string) $request->input('q', $request->input('search', '')));
        $limit = min(50, max(10, (int) $request->input('limit', 20)));

        $query = Category::without('translations')
            ->select('id', 'name', 'parent_category_id')
            ->where('status', 1)
            ->orderBy('name');

        if ($term !== '') {
            $query->where('name', 'LIKE', '%' . $term . '%');
        }

        $categories = $query->limit($limit)->get();
        $pathMap = $this->getCategoryPathMap();

        $results = $categories->map(static function ($category) use ($pathMap) {
            $id = (int) $category->id;

            return [
                'id' => $id,
                'text' => $pathMap[$id] ?? (string) $category->name,
            ];
        })->values();

        return response()->json([
            'items' => $results,
        ]);
    }

    public function store(Request $request) {

        if (!$request->filled('category_id') && !$request->filled('item') && !$request->filled('link')) {
            ResponseService::validationError('At least one of the fields (Category, Advertisement, or Third Party Link) is required.');
        }

        ResponseService::noPermissionThenRedirect('slider-create');
        $validator = Validator::make($request->all(), [
            'image.*' => 'required|image|mimes:jpg,png,jpeg|max:7168',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            $lastSequence = Slider::max('sequence');
            $nextSequence = $lastSequence + 1;
            $slider = Slider::create([
                'image'            => $request->hasFile('image') ? FileService::compressAndUpload($request->file('image'), $this->uploadFolder) : '',
                'third_party_link' => $request->link ?? '',
                'sequence'         => $nextSequence
            ]);


            if ($request->filled('category_id')) {
                $category = Category::find($request->category_id);
                $slider->model()->associate($category)->save();
            }
            if ($request->filled('item')) {
                $item = Item::find($request->item);
                $slider->model()->associate($item)->save();
            }
            ResponseService::successResponse('Slider created successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Slider Controller -> store");
            ResponseService::errorResponse();
        }
    }

    public function destroy($id) {
        ResponseService::noPermissionThenRedirect('slider-delete');
        try {
            $slider = Slider::find($id);
            if ($slider) {
                $url = $slider->image;
                $relativePath = parse_url($url, PHP_URL_PATH);
                if (Storage::disk(config('filesystems.default'))->exists($relativePath)) {
                    Storage::disk(config('filesystems.default'))->delete($relativePath);
                }
                $slider->delete();
                ResponseService::successResponse('slider delete successfully');
            }

        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Slider Controller -> destroy");
            ResponseService::errorResponse('something is wrong !!!');
        }
    }

    public function show(Request $request) {
        ResponseService::noPermissionThenRedirect('slider-list');
        $offset = $request->offset ?? 0;
        $limit = $request->limit ?? 10;
        $sort = $request->sort ?? 'id';
        $order = $request->order ?? 'DESC';
        $sql = Slider::with('model');
        if (!empty($request->search)) {
            $sql = $sql->search($request->search);
        }
        $total = $sql->count();
        $sql->sort($sort, $order)->skip($offset)->take($limit);
        $result = $sql->get();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        foreach ($result as $key => $row) {
            $tempRow = $row->toArray();
            $operate = '';
            if (Auth::user()->can('slider-delete')) {
                $operate .= BootstrapTableService::deleteButton(route('slider.destroy', $row->id));
            }
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    private function getCategoryPathMap(): array {
        $version = (string) (Category::without('translations')->max('updated_at') ?? 'none');
        $cacheKey = 'admin:slider:category:path_map:' . md5($version);

        return Cache::remember($cacheKey, 600, static function () {
            $categories = Category::without('translations')
                ->select('id', 'name', 'parent_category_id')
                ->get();

            $parent = [];
            $name = [];

            foreach ($categories as $category) {
                $id = (int) $category->id;
                $parent[$id] = $category->parent_category_id ? (int) $category->parent_category_id : null;
                $name[$id] = (string) $category->name;
            }

            $memo = [];
            $visiting = [];

            $buildPath = static function (int $id) use (&$buildPath, &$memo, &$visiting, $parent, $name) {
                if (isset($memo[$id])) {
                    return $memo[$id];
                }
                if (isset($visiting[$id])) {
                    return $memo[$id] = $name[$id] ?? '';
                }

                $visiting[$id] = true;
                $path = $name[$id] ?? '';
                $parentId = $parent[$id] ?? null;

                if ($parentId && isset($name[$parentId])) {
                    $path = $buildPath($parentId) . ' > ' . $path;
                }

                unset($visiting[$id]);

                return $memo[$id] = $path;
            };

            foreach (array_keys($name) as $id) {
                $buildPath((int) $id);
            }

            return $memo;
        });
    }
}
