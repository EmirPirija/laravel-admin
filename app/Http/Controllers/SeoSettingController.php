<?php

namespace App\Http\Controllers;
use App\Models\SeoSetting;
use App\Models\SeoSettingsTranslation;
use App\Services\BootstrapTableService;
use App\Services\FileService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class SeoSettingController extends Controller
{
    private string $uploadFolder;
    private const OG_TYPES = [
        'website',
        'article',
        'product',
        'profile',
        'business.business',
    ];

    private const TWITTER_CARDS = [
        'summary',
        'summary_large_image',
        'app',
        'player',
    ];

    public function __construct() {
        $this->uploadFolder = "seo-setting";
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $baseRules = [
            'canonical_url'       => 'nullable|string|max:1024',
            'site_name'           => 'nullable|string|max:120',
            'search_path'         => 'nullable|string|max:255',
            'knowledge_graph_type'=> 'nullable|string|max:80',
            'organization_name'   => 'nullable|string|max:180',
            'organization_logo'   => 'nullable|string|max:1024',
            'organization_phone'  => 'nullable|string|max:120',
            'organization_email'  => 'nullable|email|max:180',
            'organization_address'=> 'nullable|string|max:1024',
            'social_profiles_json'=> 'nullable|json',
            'og_title'            => 'nullable|string|max:255',
            'og_description'      => 'nullable|string|max:1000',
            'og_image'            => 'nullable|string|max:1024',
            'og_type'             => ['nullable', Rule::in(self::OG_TYPES)],
            'twitter_title'       => 'nullable|string|max:255',
            'twitter_description' => 'nullable|string|max:1000',
            'twitter_image'       => 'nullable|string|max:1024',
            'twitter_card'        => ['nullable', Rule::in(self::TWITTER_CARDS)],
            'robots_index'        => 'nullable|boolean',
            'robots_follow'       => 'nullable|boolean',
            'robots_noarchive'    => 'nullable|boolean',
            'robots_nosnippet'    => 'nullable|boolean',
            'schema_json'         => 'nullable|json',
        ];

        $validator = Validator::make(
            $request->all(),
            array_merge([
                'page'          => 'required|unique:seo_settings,page',
                'title.1'       => 'required|string',
                'description.1' => 'required|string',
                'keywords.1'    => 'nullable|string',
                'image'         => 'nullable|mimes:jpeg,png,jpg,svg|max:7168',
                'languages'     => 'required|array',
                'languages.*'   => 'exists:languages,id',
            ], $baseRules),
            [
                'page.unique'              => 'This page already has SEO settings.',
                'title.1.required'         => 'The English title field is required.',
                'description.1.required'   => 'The English description field is required.',
            ]
        );

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $request->all();

            // Handle image upload
            if ($request->hasFile('image')) {
                $data['image'] = FileService::upload($request->file('image'), $this->uploadFolder);
            }

            // Store main SEO setting (language_id = 1)
            $seoSetting = SeoSetting::create($this->buildSeoPayload($request, [
                'page'        => $data['page'],
                'title'       => $data['title'][1],
                'description' => $data['description'][1],
                'keywords'    => $data['keywords'][1] ?? null,
                'image'       => $data['image'] ?? null,
            ]));

            // Store translations for other languages
            foreach ($data['languages'] as $langId) {
                if ($langId == 1) continue; // Skip default language

                $title = $data['title'][$langId] ?? null;
                $description = $data['description'][$langId] ?? null;
                $keywords = $data['keywords'][$langId] ?? null;

                // Skip empty translations
                if (empty($title) && empty($description) && empty($keywords)) {
                    continue;
                }

                SeoSettingsTranslation::create([
                    'seo_setting_id' => $seoSetting->id,
                    'language_id'    => $langId,
                    'title'          => $title,
                    'description'    => $description,
                    'keywords'       => $keywords,
                ]);
            }

            return ResponseService::successResponse('SEO Setting Successfully Added');

        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, "SeoSetting Controller -> Store");
            return ResponseService::errorResponse('Something Went Wrong');
        }
    }


    /**
     * Display the specified resource.
     */
    public function show(Request $request)
    {
        $offset = $request->offset ?? 0;
        $limit = $request->limit ?? 10;
        $sort = $request->sort ?? 'id';
        $order = $request->order ?? 'DESC';

        $sql = SeoSetting::with('translations')->orderBy($sort, $order);

        if (!empty($_GET['search'])) {
            $search = $_GET['search'];
            $sql->where('id', 'LIKE', "%$search%")->orwhere('code', 'LIKE', "%$search%")->orwhere('name', 'LIKE', "%$search%");
        }
        $total = $sql->count();
        $sql->skip($offset)->take($limit);
        $result = $sql->get();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        foreach ($result as $key => $row) {
            $tempRow = $row->toArray();
            $operate = '';
            if ($row->code != "en") {
                $operate .= BootstrapTableService::editButton(route('seo-setting.update', $row->id), true);
                $operate .= BootstrapTableService::deleteButton(route('seo-setting.destroy', $row->id));
            }
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
        public function update(Request $request, $id)
        {
            $baseRules = [
                'canonical_url'       => 'nullable|string|max:1024',
                'site_name'           => 'nullable|string|max:120',
                'search_path'         => 'nullable|string|max:255',
                'knowledge_graph_type'=> 'nullable|string|max:80',
                'organization_name'   => 'nullable|string|max:180',
                'organization_logo'   => 'nullable|string|max:1024',
                'organization_phone'  => 'nullable|string|max:120',
                'organization_email'  => 'nullable|email|max:180',
                'organization_address'=> 'nullable|string|max:1024',
                'social_profiles_json'=> 'nullable|json',
                'og_title'            => 'nullable|string|max:255',
                'og_description'      => 'nullable|string|max:1000',
                'og_image'            => 'nullable|string|max:1024',
                'og_type'             => ['nullable', Rule::in(self::OG_TYPES)],
                'twitter_title'       => 'nullable|string|max:255',
                'twitter_description' => 'nullable|string|max:1000',
                'twitter_image'       => 'nullable|string|max:1024',
                'twitter_card'        => ['nullable', Rule::in(self::TWITTER_CARDS)],
                'robots_index'        => 'nullable|boolean',
                'robots_follow'       => 'nullable|boolean',
                'robots_noarchive'    => 'nullable|boolean',
                'robots_nosnippet'    => 'nullable|boolean',
                'schema_json'         => 'nullable|json',
            ];

            $validator = Validator::make(
                $request->all(),
                array_merge([
                   
                    'title.1'       => 'required|string',
                    'description.1' => 'required|string',
                    'image'         => 'nullable|mimes:jpeg,png,jpg,svg|max:7168',
                ], $baseRules),
                [
                  
                    'title.1.required'         => 'The English title field is required.',
                    'description.1.required'   => 'The English description field is required.',
                ]
            );

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            try {
                $seo = SeoSetting::findOrFail($id);

                $data = $request->only('page');
                if ($request->hasFile('image')) {
                    $data['image'] = FileService::upload($request->file('image'), $this->uploadFolder);
                }

                // Save base (main) SEO setting
                $seo->update($this->buildSeoPayload($request, $data));

                // Update translation for each language
                foreach ($request->input('languages', []) as $langId) {
                    $translatedTitle = $request->input("title.$langId");
                    $translatedDescription = $request->input("description.$langId");
                    $translatedKeywords = $request->input("keywords.$langId");

                    if ($langId == 1) {
                        // English (default)
                        $seo->update([
                            'title'       => $translatedTitle,
                            'description' => $translatedDescription,
                            'keywords'    => $translatedKeywords,
                        ]);
                    } else {
                        $seo->translations()->updateOrCreate(
                            ['language_id' => $langId],
                            [
                                'title'       => $translatedTitle,
                                'description' => $translatedDescription,
                                'keywords'    => $translatedKeywords,
                            ]
                        );
                    }
                }

                return ResponseService::successResponse('SEO Setting Updated Successfully');
            } catch (Throwable $th) {
                return ResponseService::logErrorRedirect($th, "SeoSetting Controller -> Update");
                return ResponseService::errorResponse('Something Went Wrong');
            }
        }

    private function buildSeoPayload(Request $request, array $baseData = []): array
    {
        $payload = $baseData;

        $textFields = [
            'canonical_url',
            'site_name',
            'search_path',
            'knowledge_graph_type',
            'organization_name',
            'organization_logo',
            'organization_phone',
            'organization_email',
            'organization_address',
            'social_profiles_json',
            'og_title',
            'og_description',
            'og_image',
            'og_type',
            'twitter_title',
            'twitter_description',
            'twitter_image',
            'twitter_card',
            'schema_json',
        ];

        foreach ($textFields as $field) {
            if ($request->exists($field)) {
                $value = $request->input($field);
                if (is_string($value)) {
                    $value = trim($value);
                }
                $payload[$field] = $value === '' ? null : $value;
            }
        }

        $booleanDefaults = [
            'robots_index' => true,
            'robots_follow' => true,
            'robots_noarchive' => false,
            'robots_nosnippet' => false,
        ];

        foreach ($booleanDefaults as $field => $defaultValue) {
            if ($request->exists($field)) {
                $payload[$field] = $request->boolean($field);
            } elseif (!array_key_exists($field, $payload)) {
                $payload[$field] = $defaultValue;
            }
        }

        return $payload;
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
                $seo_setting = SeoSetting::findOrFail($id);
                $seo_setting->delete();
                FileService::delete($seo_setting->getRawOriginal('image'));
                ResponseService::successResponse('Seo Setting Deleted successfully');
            } catch (Throwable $th) {
                ResponseService::logErrorRedirect($th, "Language Controller --> Destroy");
                ResponseService::errorResponse('Something Went Wrong');
            }
    }
}
