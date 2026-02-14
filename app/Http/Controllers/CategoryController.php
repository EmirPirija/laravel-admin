<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Item;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use App\Models\CategoryTranslation;
use App\Models\CustomField;
use App\Models\CustomFieldCategory;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\ResponseService;
use DB;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;
use function compact;
use function view;

class CategoryController extends Controller {
    private string $uploadFolder;

    public function __construct() {
        $this->uploadFolder = "category";
    }

    public function index() {
        ResponseService::noAnyPermissionThenRedirect(['category-list', 'category-create', 'category-update', 'category-delete']);
        return view('category.index');
    }

    
    

    public function create(Request $request) {
        $languages = CachingService::getLanguages()->values();
        ResponseService::noPermissionThenRedirect('category-create');
        $categories = Category::with('subcategories')->get();
        return view('category.create', compact('categories', 'languages'));
    }

    public function store(Request $request) {
        ResponseService::noPermissionThenSendJson('category-create');
        
        $languages = CachingService::getLanguages();
        $defaultLangId = 1;
        $otherLanguages = $languages->where('id', '!=', $defaultLangId);

        $rules = [
            "name.$defaultLangId" => 'required|string|max:30',
            'image'              => 'required|mimes:jpg,jpeg,png,webp,svg|max:7168',
            'parent_category_id' => 'nullable|integer',
            "description.$defaultLangId" => 'nullable|string',
            'slug' => [
                'nullable',
                'regex:/^[a-zA-Z0-9\-_]+$/'
            ],
            'status'             => 'required|boolean',
        ];

        foreach ($otherLanguages as $lang) {
            $langId = $lang->id;
            $rules["name.$langId"] = 'nullable|string|max:30';
            $rules["description.$langId"] = 'nullable|string';
        }

        $request->validate($rules, [
            'slug.regex' => 'Slug must be only English letters, numbers, hyphens (-), or underscores (_).'
        ]);

        try {
            $data = [
                'name' => $request->input("name.$defaultLangId"),
                'description' => $request->input("description.$defaultLangId"),
                'parent_category_id' => $request->parent_category_id,
                'status' => $request->status,
                'is_job_category' => $request->is_job_category ?? 0,
                'price_optional' => $request->price_optional ?? 0,
            ];
            $slug = trim($request->input('slug') ?? '');
            $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($slug));
            $slug = trim($slug, '-');
            if (empty($slug)) {
                $slug = HelperService::generateRandomSlug();
            }
            $data['slug'] = HelperService::generateUniqueSlug(new Category, $slug);

            if ($request->hasFile('image')) {
                $data['image'] = FileService::compressAndUpload($request->file('image'), $this->uploadFolder);
            }

            $category = Category::create($data);

            foreach ($otherLanguages as $lang) {
                $langId = $lang->id;
                $translatedName = $request->input("name.$langId");
                $translatedDescription = $request->input("description.$langId");

                if (!empty($translatedName) || !empty($translatedDescription)) {
                    $category->translations()->create([
                        'name'        => $translatedName ?? '',
                        'description' => $translatedDescription ?? null,
                        'language_id' => $langId,
                    ]);
                }
            }

            ResponseService::successRedirectResponse("Category Added Successfully");
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th);
            ResponseService::errorRedirectResponse();
        }
    }


    public function show(Request $request, $id) {
        ResponseService::noPermissionThenSendJson('category-list');
    
        $offset = (int) $request->input('offset', 0);
        $limit  = (int) $request->input('limit', 10);
        $sort   = $request->input('sort', 'sequence');
        $order  = $request->input('order', 'ASC');
    
        $base = Category::withCount('subcategories')
            ->withCount('custom_fields')
            ->with('subcategories');
    
        if ($id == "0") {
            $base->whereNull('parent_category_id');
        } else {
            $base->where('parent_category_id', $id);
        }
    
        if (!empty($request->search)) {
            $base->search($request->search);
        }
    
        $total = (clone $base)->count();
    
        // Normal sort: paginate in SQL (FAST)
        if ($sort !== 'advertisements_count') {
            $result = (clone $base)
                ->orderBy($sort, $order)
                ->skip($offset)
                ->take($limit)
                ->get();
        } else {
            // For ad-count sort, we need all, then sort in PHP
            $result = (clone $base)->get();
        }
    
        // Build parent->children map (single query, no translations)
        $allCats = Category::without('translations')->select('id', 'parent_category_id')->get();
        $children = [];
        foreach ($allCats as $c) {
            $p = $c->parent_category_id ?? 0;
            $children[$p][] = $c->id;
        }
    
        // Direct approved + non-expired counts grouped by category_id (single query)
        $directCounts = Item::query()
            ->selectRaw('category_id, COUNT(*) as cnt')
            ->where('status', 'approved')
            ->getNonExpiredItems()
            ->groupBy('category_id')
            ->pluck('cnt', 'category_id')
            ->toArray();
    
        // Memoized subtree sum
        $memo = [];
        $sumSubtree = function ($catId) use (&$sumSubtree, &$memo, $children, $directCounts) {
            if (isset($memo[$catId])) return $memo[$catId];
            $sum = (int)($directCounts[$catId] ?? 0);
            foreach ($children[$catId] ?? [] as $childId) {
                $sum += $sumSubtree($childId);
            }
            return $memo[$catId] = $sum;
        };
    
        // Attach calculated count
        foreach ($result as $r) {
            $r->advertisements_count_calc = $sumSubtree($r->id);
        }
    
        // If sorting by ads count, do it now + slice
        if ($sort === 'advertisements_count') {
            $desc = strtolower($order) === 'desc';
            $result = $result->sortBy('advertisements_count_calc', SORT_REGULAR, $desc)->values();
            $result = $result->slice($offset, $limit)->values();
        }
    
        $rows = [];
        $no = $offset + 1;
    
        foreach ($result as $row) {
            $operate = '';
    
            if (Auth::user()->can('category-update')) {
                $operate .= BootstrapTableService::editButton(route('category.edit', $row->id));
            }
            if (Auth::user()->can('category-delete')) {
                $operate .= BootstrapTableService::deleteButton(route('category.destroy', $row->id));
            }
            if ($row->subcategories_count > 1) {
                $operate .= BootstrapTableService::button('fa fa-list-ol', route('sub.category.order.change', $row->id), ['btn-secondary']);
            }
    
            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['subcategories_count'] = $row->subcategories_count . ' ' . __('Subcategories');
            $tempRow['custom_fields_count'] = $row->custom_fields_count . ' ' . __('Custom Fields');
            $tempRow['operate'] = $operate;
    
            // use calculated
            $tempRow['advertisements_count'] = (int)($row->advertisements_count_calc ?? 0);
    
            $rows[] = $tempRow;
        }
    
        return response()->json([
            'total' => $total,
            'rows'  => $rows,
        ]);
    }

    public function exportPort(Request $request)
{
    ResponseService::noAnyPermissionThenRedirect(['category-list', 'category-update', 'category-create']);

    $languages = CachingService::getLanguages();
    $languagesById = $languages->keyBy('id');

    $defaultLangId = 1; // kod tebe je hardcoded u create/update
    $defaultCode = $languagesById[$defaultLangId]->code ?? 'en';

    $categories = Category::with('translations')
        ->orderByRaw('COALESCE(parent_category_id,0) ASC')
        ->orderBy('sequence', 'ASC')
        ->get();

    $idToSlug = $categories->pluck('slug', 'id');

    $rows = $categories->map(function (Category $c) use ($idToSlug, $languagesById, $defaultCode) {
        $translations = [];

        // default (ide u categories tabelu)
        $translations[$defaultCode] = [
            'name' => $c->name,
            'description' => $c->description,
        ];

        // ostali jezici iz category_translations
        foreach ($c->translations as $tr) {
            $code = $languagesById[$tr->language_id]->code ?? null;
            if (!$code) continue;

            $translations[$code] = [
                'name' => $tr->name,
                'description' => $tr->description,
            ];
        }

        return [
            'slug' => $c->slug,
            'parent_slug' => $c->parent_category_id ? ($idToSlug[$c->parent_category_id] ?? null) : null,
            'sequence' => (int) $c->sequence,
            'status' => (int) $c->status,
            'is_job_category' => (int) $c->is_job_category,
            'price_optional' => (int) $c->price_optional,
            'image' => $c->getRawOriginal('image'),
            'translations' => $translations,
        ];
    })->values();

    $payload = [
        'schema' => 'categories-port/v1',
        'exported_at' => now()->toIso8601String(),
        'default_language_code' => $defaultCode,
        'categories' => $rows,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return response($json, 200, [
        'Content-Type' => 'application/json',
        'Content-Disposition' => 'attachment; filename=categories-port.json',
    ]);
}

public function importPort(Request $request)
{
    ResponseService::noPermissionThenRedirect('category-update');

    $v = Validator::make($request->all(), [
        'file' => 'required|file|max:10240', // 10MB
    ]);
    if ($v->fails()) {
        return redirect()->back()->withErrors($v->errors())->withInput();
    }

    $raw = file_get_contents($request->file('file')->getRealPath());
    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        return redirect()->back()->withErrors(['file' => 'Invalid JSON file.']);
    }

    $rows = $decoded['categories'] ?? null;
    if (!is_array($rows)) {
        return redirect()->back()->withErrors(['file' => 'JSON must contain "categories" array.']);
    }

    $languages = CachingService::getLanguages();
    $languagesById = $languages->keyBy('id');
    $languagesByCode = $languages->keyBy('code');

    $defaultLangId = 1;
    $defaultCode = $languagesById[$defaultLangId]->code ?? 'en';

    $normalizeSlug = function ($s) {
        $s = trim((string) $s);
        if ($s === '') return '';
        $s = preg_replace('/[^a-z0-9\-_]+/i', '-', strtolower($s));
        return trim($s, '-');
    };

    $errors = [];
    $seen = [];
    $clean = [];

    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            $errors[] = "Row #$i is not an object.";
            continue;
        }

        $slug = $normalizeSlug($row['slug'] ?? '');
        if ($slug === '') {
            $errors[] = "Row #$i: missing slug.";
            continue;
        }
        if (isset($seen[$slug])) {
            $errors[] = "Duplicate slug: $slug";
            continue;
        }
        $seen[$slug] = true;

        $parentSlug = $normalizeSlug($row['parent_slug'] ?? '');
        $parentSlug = $parentSlug !== '' ? $parentSlug : null;
        if ($parentSlug === $slug) {
            $errors[] = "Row #$i: slug '$slug' cannot be its own parent.";
        }

        $translations = $row['translations'] ?? [];
        if (!is_array($translations)) $translations = [];

        $defaultT = $translations[$defaultCode] ?? [];
        $name = $row['name'] ?? ($defaultT['name'] ?? null);
        if (!is_string($name) || trim($name) === '') {
            $errors[] = "Row #$i ($slug): missing default name (use translations.$defaultCode.name).";
        }

        $desc = $row['description'] ?? ($defaultT['description'] ?? null);

        $image = isset($row['image']) ? trim((string) $row['image']) : null;
        if ($image === '') $image = null;

        $clean[] = [
            'slug' => $slug,
            'parent_slug' => $parentSlug,
            'sequence' => isset($row['sequence']) ? (int) $row['sequence'] : 0,
            'status' => isset($row['status']) ? (int) !!$row['status'] : 1,
            'is_job_category' => isset($row['is_job_category']) ? (int) !!$row['is_job_category'] : 0,
            'price_optional' => isset($row['price_optional']) ? (int) !!$row['price_optional'] : 0,
            'image' => $image,
            'name_default' => trim((string) $name),
            'description_default' => $desc,
            'translations' => $translations,
        ];
    }

    if (!empty($errors)) {
        return redirect()->back()->withErrors(['file' => implode("\n", $errors)]);
    }

    DB::beginTransaction();
    try {
        $slugs = array_values(array_keys($seen));

        $existing = Category::without('translations')
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy('slug');

        $slugToId = [];

        // PASS 1: upsert categories (no parent yet)
        foreach ($clean as $row) {
            /** @var Category|null $cat */
            $cat = $existing[$row['slug']] ?? null;

            if ($cat) {
                $update = [
                    'name' => $row['name_default'],
                    'description' => $row['description_default'],
                    'status' => $row['status'],
                    'is_job_category' => $row['is_job_category'],
                    'price_optional' => $row['price_optional'],
                    'sequence' => $row['sequence'],
                ];
                if (!empty($row['image'])) {
                    $update['image'] = $row['image'];
                }
                $cat->update($update);
            } else {
                if (empty($row['image'])) {
                    throw new \RuntimeException("Image is required for NEW category: {$row['slug']}");
                }

                $cat = Category::create([
                    'name' => $row['name_default'],
                    'description' => $row['description_default'],
                    'parent_category_id' => null,
                    'status' => $row['status'],
                    'is_job_category' => $row['is_job_category'],
                    'price_optional' => $row['price_optional'],
                    'sequence' => $row['sequence'],
                    'slug' => $row['slug'],
                    'image' => $row['image'],
                ]);
            }

            $slugToId[$row['slug']] = $cat->id;
        }

        // Add parent slugs that might exist in DB but not in file
        $parentSlugs = collect($clean)->pluck('parent_slug')->filter()->unique()->values()->all();
        if (!empty($parentSlugs)) {
            $parentCats = Category::without('translations')
                ->whereIn('slug', $parentSlugs)
                ->get()
                ->keyBy('slug');

            foreach ($parentCats as $pSlug => $pCat) {
                $slugToId[$pSlug] = $pCat->id;
            }
        }

        // PASS 2: set parents
        foreach ($clean as $row) {
            if (empty($row['parent_slug'])) continue;

            $childId = $slugToId[$row['slug']] ?? null;
            $parentId = $slugToId[$row['parent_slug']] ?? null;

            if (!$parentId) {
                throw new \RuntimeException("Unknown parent_slug '{$row['parent_slug']}' for '{$row['slug']}'");
            }
            if ($childId === $parentId) {
                throw new \RuntimeException("Category '{$row['slug']}' cannot be its own parent.");
            }

            Category::without('translations')
                ->where('id', $childId)
                ->update(['parent_category_id' => $parentId]);
        }

        // PASS 3: translations (skip default language code)
        foreach ($clean as $row) {
            $catId = $slugToId[$row['slug']];

            foreach (($row['translations'] ?? []) as $code => $tr) {
                $code = strtolower(trim((string) $code));
                if ($code === $defaultCode) continue;

                $lang = $languagesByCode[$code] ?? null;
                if (!$lang) continue;           // ignore unknown language codes
                if (!is_array($tr)) continue;

                $tName = (string)($tr['name'] ?? '');
                $tDesc = $tr['description'] ?? null;

                if ($tName === '' && empty($tDesc)) continue;

                CategoryTranslation::updateOrCreate(
                    ['category_id' => $catId, 'language_id' => $lang->id],
                    ['name' => $tName, 'description' => $tDesc]
                );
            }
        }

        DB::commit();
        $this->forgetAdminCategoryCaches();

        return ResponseService::successRedirectResponse("Categories imported successfully", route('category.index'));
    } catch (Throwable $e) {
        DB::rollBack();
        ResponseService::logErrorRedirect($e);

        return ResponseService::errorRedirectWithToast($e->getMessage());
    }
}

    


    /**
     * BULK EDIT (Categories + expanded Subcategories)
     */
    public function bulkEdit(Request $request)
{
    ResponseService::noPermissionThenRedirect('category-update');

    $ids = $request->input('ids', '');
    if (is_string($ids)) {
        $ids = array_filter(array_map('intval', explode(',', $ids)));
    }
    if (empty($ids) || !is_array($ids)) {
        return redirect()->route('category.index')->withErrors(['message' => 'Select at least one category.']);
    }

    $languages = CachingService::getLanguages()->values();

    $categories = Category::with('translations')
        ->whereIn('id', $ids)
        ->get();

    $translationsByCategory = [];
    foreach ($categories as $cat) {
        $translationsByCategory[$cat->id][1] = [
            'name' => $cat->name,
            'description' => $cat->description,
        ];

        foreach ($cat->translations as $tr) {
            $translationsByCategory[$cat->id][$tr->language_id] = [
                'name' => $tr->name,
                'description' => $tr->description,
            ];
        }
    }

    $allCategories = Category::orderBy('sequence')->get();

    return view('category.bulk-edit', compact(
        'categories',
        'languages',
        'allCategories',
        'translationsByCategory'
    ));
}

public function bulkUpdate(Request $request)
{
    ResponseService::noPermissionThenRedirect('category-update');

    $payload = $request->input('categories', []);
    if (empty($payload) || !is_array($payload)) {
        return redirect()->back()->withErrors(['message' => 'Nothing to update.']);
    }

    $languages = CachingService::getLanguages();
    $defaultLangId = 1;
    $otherLanguages = $languages->where('id', '!=', $defaultLangId);

    DB::beginTransaction();
    try {
        foreach ($payload as $id => $data) {
            $id = (int)$id;
            $category = Category::findOrFail($id);

            // Build validation rules per category
            $rules = [
                "categories.$id.name.$defaultLangId" => 'required|string|max:30',
                "categories.$id.image" => 'nullable|mimes:jpg,jpeg,png,webp,svg|max:7168',
                "categories.$id.parent_category_id" => 'nullable|integer',
                "categories.$id.description.$defaultLangId" => 'nullable|string',
                "categories.$id.slug" => ['nullable','regex:/^[a-zA-Z0-9\-_]+$/'],
                "categories.$id.status" => 'required|boolean',
                "categories.$id.is_job_category" => 'required|boolean',
                "categories.$id.price_optional" => 'required|boolean',
            ];

            foreach ($otherLanguages as $lang) {
                $langId = $lang->id;
                $rules["categories.$id.name.$langId"] = 'nullable|string|max:30';
                $rules["categories.$id.description.$langId"] = 'nullable|string';
            }

            $v = \Illuminate\Support\Facades\Validator::make($request->all(), $rules, [
                'slug.regex' => 'Slug must be only English letters, numbers, hyphens (-), or underscores (_).'
            ]);
            if ($v->fails()) {
                DB::rollBack();
                return redirect()->back()->withErrors($v->errors())->withInput();
            }

            // prevent self-parent
            $parentId = $data['parent_category_id'] ?? null;
            if (!empty($parentId) && (int)$parentId === $category->id) {
                DB::rollBack();
                return redirect()->back()->withErrors(['parent_category' => 'A category cannot be set as its own parent.'])->withInput();
            }

            $update = [
                'name' => $data['name'][$defaultLangId] ?? $category->name,
                'description' => $data['description'][$defaultLangId] ?? null,
                'parent_category_id' => $parentId ?: null,
                'status' => (int)($data['status'] ?? 0),
                'is_job_category' => (int)($data['is_job_category'] ?? 0),
                'price_optional' => (int)($data['price_optional'] ?? 0),
            ];

            if ($request->hasFile("categories.$id.image")) {
                $update['image'] = FileService::compressAndReplace(
                    $request->file("categories.$id.image"),
                    $this->uploadFolder,
                    $category->getRawOriginal('image')
                );
            }

            $slug = trim($data['slug'] ?? '');
            $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($slug));
            $slug = trim($slug, '-');
            if (empty($slug)) {
                $slug = HelperService::generateRandomSlug();
            }
            $update['slug'] = HelperService::generateUniqueSlug(new Category(), $slug, $category->id);

            $oldJob = (int)$category->is_job_category;
            $oldPriceOpt = (int)$category->price_optional;

            $category->update($update);

            // propagate only if changed (faster)
            if ($oldJob !== (int)$update['is_job_category']) {
                $category->subcategories()->update(['is_job_category' => (int)$update['is_job_category']]);
            }
            if ($oldPriceOpt !== (int)$update['price_optional']) {
                $category->subcategories()->update(['price_optional' => (int)$update['price_optional']]);
            }

            foreach ($otherLanguages as $lang) {
                $langId = $lang->id;
                $translatedName = $data['name'][$langId] ?? '';
                $translatedDescription = $data['description'][$langId] ?? null;

                CategoryTranslation::updateOrCreate(
                    ['category_id' => $category->id, 'language_id' => $langId],
                    ['name' => $translatedName ?? '', 'description' => $translatedDescription]
                );
            }
        }

        DB::commit();
        return ResponseService::successRedirectResponse("Categories Updated Successfully", route('category.index'));
    } catch (Throwable $th) {
        DB::rollBack();
        ResponseService::logErrorRedirect($th);
        return ResponseService::errorRedirectResponse('Something Went Wrong');
    }
}

    public function edit($id) {
        ResponseService::noPermissionThenRedirect('category-update');
        $category_data = Category::findOrFail($id);
        
        // Initialize translations array with English (default) data
        $translations = [];
        $translations[1] = [
            'name' => $category_data->name,
            'description' => $category_data->description,
        ];
        
        // Add other language translations
        foreach ($category_data->translations as $translation) {
            $translations[$translation->language_id] = [
                'name' => $translation->name,
                'description' => $translation->description,
            ];
        }

        $parent_category_data = Category::find($category_data->parent_category_id);
        $parent_category = $parent_category_data->name ?? '';
        $categories = Category::with('subcategories')->get();
        // Fetch all languages including English
        $languages = CachingService::getLanguages()->values();
        return view('category.edit', compact('category_data', 'parent_category_data','parent_category', 'translations', 'languages','categories'));
    }

    public function update(Request $request, $id) {
        ResponseService::noPermissionThenSendJson('category-update');
        try {
            $languages = CachingService::getLanguages();
            $defaultLangId = 1;
            $otherLanguages = $languages->where('id', '!=', $defaultLangId);

            $rules = [
                "name.$defaultLangId" => 'required|string|max:30',
                'image'           => 'nullable|mimes:jpg,jpeg,png,webp,svg|max:7168',
                'parent_category_id' => 'nullable|integer',
                "description.$defaultLangId" => 'nullable|string',
                'slug' => [
                    'nullable',
                    'regex:/^[a-zA-Z0-9\-_]+$/'
                ],
                'status'          => 'required|boolean',
            ];

            foreach ($otherLanguages as $lang) {
                $langId = $lang->id;
                $rules["name.$langId"] = 'nullable|string|max:30';
                $rules["description.$langId"] = 'nullable|string';
            }

            $request->validate($rules, [
                'slug.regex' => 'Slug must be only English letters, numbers, hyphens (-), or underscores (_).'
            ]);
            
            $category = Category::find($id);
            if ($request->parent_category_id == $category->id) {
                return back()->withErrors(['parent_category' => 'A category cannot be set as its own parent.']);
            }
            
            $data = [
                'name' => $request->input("name.$defaultLangId"),
                'description' => $request->input("description.$defaultLangId"),
                'parent_category_id' => $request->parent_category_id,
                'status' => $request->status,
                'is_job_category' => $request->is_job_category ?? 0,
                'price_optional' => $request->price_optional ?? 0,
            ];
            
            if ($request->hasFile('image')) {
                $data['image'] = FileService::compressAndReplace($request->file('image'), $this->uploadFolder, $category->getRawOriginal('image'));
            }
            $slug = trim($request->input('slug') ?? '');
            $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($slug));
            $slug = trim($slug, '-');
            if (empty($slug)) {
                $slug = HelperService::generateRandomSlug();
            }
            $data['slug'] = HelperService::generateUniqueSlug(new Category(), $slug, $category->id);
            $category->update($data);
            
            if ($request->has('is_job_category')) {
                $category->subcategories()->update([
                    'is_job_category' => $request->is_job_category ? 1 : 0,
                ]);
            }
            
            if ($request->has('price_optional')) {
                $category->subcategories()->update([
                    'price_optional' => $request->price_optional ? 1 : 0,
                ]);
            }
            
            foreach ($otherLanguages as $lang) {
                $langId = $lang->id;
                $translatedName = $request->input("name.$langId");
                $translatedDescription = $request->input("description.$langId");

                CategoryTranslation::updateOrCreate(
                    ['category_id' => $category->id, 'language_id' => $langId],
                    [
                        'name' => $translatedName ?? '',
                        'description' => $translatedDescription ?? null
                    ]
                );
            }

            ResponseService::successRedirectResponse("Category Updated Successfully", route('category.index'));
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th);
            ResponseService::errorRedirectResponse('Something Went Wrong');
        }
    }

    public function destroy($id) {
        ResponseService::noPermissionThenSendJson('category-delete');
        try {
           $category = Category::withCount(['subcategories', 'custom_fields'])
            ->with('subcategories')
            ->findOrFail($id);
            if ($category->all_items_count > 0) {
                ResponseService::errorResponse('Cannot delete category. It has associated advertisements.');
            }
           if ($category->other_items_count > 0) {
                ResponseService::errorResponse(
                    'Cannot delete category. Delete non-active items first.'
                );
            }
            if ($category->subcategories_count > 0 || $category->custom_fields_count > 0) {
                ResponseService::errorResponse('Failed to delete category', 'Cannot delete category. Remove associated subcategories and custom fields first.');
            }
            if ($category->delete()) {
                ResponseService::successResponse('Category delete successfully');
            }
        } catch (QueryException $th) {
            ResponseService::logErrorResponse($th, 'Failed to delete category', 'Cannot delete category. Remove associated subcategories and custom fields first.');
            ResponseService::errorResponse('Something Went Wrong');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "CategoryController -> delete");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function getSubCategories($id)
{
    ResponseService::noPermissionThenRedirect('category-list');

    $subcategories = Category::without('translations')
        ->where('parent_category_id', $id)
        ->withCount('custom_fields')
        ->withCount('subcategories')
        ->withCount('items')
        ->orderBy('sequence')
        ->get()
        ->map(function ($subcategory) {
            $subcategory->setAppends([]);

            $operate = '';
            if (Auth::user()->can('category-update')) {
                $operate .= BootstrapTableService::editButton(route('category.edit', $subcategory->id));
            }
            if (Auth::user()->can('category-delete')) {
                $operate .= BootstrapTableService::deleteButton(route('category.destroy', $subcategory->id));
            }
            if ($subcategory->subcategories_count > 1) {
                $operate .= BootstrapTableService::button('fa fa-list-ol', route('sub.category.order.change', $subcategory->id), ['btn-secondary']);
            }

            $subcategory->operate = $operate;
            return $subcategory;
        });

    return response()->json($subcategories);
}


    public function customFields($id) {
        ResponseService::noPermissionThenRedirect('custom-field-list');
        $category = Category::find($id);
        $p_id = $category->parent_category_id;
        $cat_id = $category->id;
        $category_name = $category->name;

        return view('category.custom-fields', compact('cat_id', 'category_name', 'p_id'));
    }

    public function getCategoryCustomFields(Request $request, $id) {
        ResponseService::noPermissionThenSendJson('custom-field-list');
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'ASC');

        $sql = CustomField::whereHas('categories', static function ($q) use ($id) {
            $q->where('category_id', $id);
        })->orderBy($sort, $order);

        if (isset($request->search)) {
            $sql->search($request->search);
        }

        $sql->take($limit);
        $total = $sql->count();
        $res = $sql->skip($offset)->take($limit)->get();
        $bulkData = array();
        $rows = array();
        $tempRow['type'] = '';


        foreach ($res as $row) {
            $tempRow = $row->toArray();
//            $operate = BootstrapTableService::editButton(route('custom-fields.edit', $row->id));
            $operate = BootstrapTableService::deleteButton(route('category.custom-fields.destroy', [$id, $row->id]));
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        $bulkData['total'] = $total;
        return response()->json($bulkData);
    }

    public function destroyCategoryCustomField($categoryID, $customFieldID) {
        try {
            ResponseService::noPermissionThenRedirect('custom-field-delete');
            CustomFieldCategory::where(['category_id' => $categoryID, 'custom_field_id' => $customFieldID])->delete();
            ResponseService::successResponse("Custom Field Deleted Successfully");
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "CategoryController -> destroyCategoryCustomField");
            ResponseService::errorResponse('Something Went Wrong');
        }

    }

    public function categoriesReOrder(Request $request) {
        $categories = Category::whereNull('parent_category_id')->orderBy('sequence')->get();
        return view('category.categories-order', compact('categories'));
    }

    public function subCategoriesReOrder(Request $request ,$id) {
        $categories = Category::with('subcategories')->where('parent_category_id', $id)->orderBy('sequence')->get();
        return view('category.sub-categories-order', compact('categories'));
    }

    public function updateOrder(Request $request) {
            $request->validate([
            'order' => 'required'
            ]);
        try {
       $order = json_decode($request->input('order'), true);
        $data = [];
        foreach ($order as $index => $id) {
            $data[] = [
                'id' => $id,
                'sequence' => $index + 1,
            ];
        }
        Category::upsert($data, ['id'], ['sequence']);
        ResponseService::successResponse("Order Updated Successfully");
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th);
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    private function forgetAdminCategoryCaches(): void
{
    Cache::forget('admin:category:approved_nonexpired_rollup:v1');
    Cache::forget('admin:category:path_map:v1'); // koristi se u custom fields fix-u
    Cache::forget('admin:category:parent_map:v1'); // koristi se u custom fields fix-u
}

private function getApprovedNonExpiredRollupCountMap(): array
{
    return Cache::remember('admin:category:approved_nonexpired_rollup:v1', 300, function () {
        $categories = Category::without('translations')
            ->select('id', 'parent_category_id')
            ->get();

        // children adjacency map
        $children = [];
        foreach ($categories as $c) {
            $pid = $c->parent_category_id ? (int)$c->parent_category_id : 0;
            $children[$pid][] = (int)$c->id;
        }

        // direct counts per category (approved + non-expired)
        $direct = Item::query()
            ->where('status', 'approved')
            ->getNonExpiredItems()
            ->selectRaw('category_id, COUNT(*) as cnt')
            ->groupBy('category_id')
            ->pluck('cnt', 'category_id')
            ->map(fn($v) => (int)$v)
            ->toArray();

        $memo = [];
        $visiting = [];

        $dfs = function (int $id) use (&$dfs, &$children, &$direct, &$memo, &$visiting) {
            if (isset($memo[$id])) return $memo[$id];
            if (isset($visiting[$id])) return $memo[$id] = ($direct[$id] ?? 0); // loop guard

            $visiting[$id] = true;
            $sum = $direct[$id] ?? 0;

            foreach ($children[$id] ?? [] as $childId) {
                $sum += $dfs((int)$childId);
            }

            unset($visiting[$id]);
            return $memo[$id] = $sum;
        };

        foreach ($categories as $c) {
            $dfs((int)$c->id);
        }

        return $memo; // [category_id => totalCountIncludingDescendants]
    });
}


}
