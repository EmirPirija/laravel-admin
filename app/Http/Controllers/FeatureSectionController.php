<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\FeatureSection;
use App\Models\FeatureSectionTranslation;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\HelperService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Throwable;

class FeatureSectionController extends Controller {

    public function index() {
        ResponseService::noAnyPermissionThenRedirect(['feature-section-list', 'feature-section-create', 'feature-section-update', 'feature-section-delete']);
        $categories = Category::get();
        $languages = CachingService::getLanguages()->values();
        return view('feature_section.index', compact('categories','languages'));
    }

    public function store(Request $request) {
        ResponseService::noPermissionThenSendJson('feature-section-create');
        
        $languages = CachingService::getLanguages();
        $defaultLangId = 1;
        $otherLanguages = $languages->where('id', '!=', $defaultLangId);

        $rules = [
            "title.$defaultLangId" => 'required|string',
            "description.$defaultLangId" => 'nullable|string',
            'slug'        => 'required',
            'filter'      => 'required|in:most_liked,most_viewed,price_criteria,category_criteria,featured_ads',
            'style'       => 'required|in:style_1,style_2,style_3,style_4',
            'min_price'   => 'required_if:filter,price_criteria',
            'max_price'   => 'required_if:filter,price_criteria',
            'category_id' => 'required_if:filter,category_criteria',
        ];

        foreach ($otherLanguages as $lang) {
            $langId = $lang->id;
            $rules["title.$langId"] = 'nullable|string';
            $rules["description.$langId"] = 'nullable|string';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = [
                'title' => $request->input("title.$defaultLangId"),
                'description' => $request->input("description.$defaultLangId"),
                'slug' => $request->slug,
                'filter' => $request->filter,
                'style' => $request->style,
                'sequence' => FeatureSection::max('sequence') + 1
            ];
            
            $data['slug'] = HelperService::generateUniqueSlug(new FeatureSection(), $request->slug);
            
            if ($request->filter == "price_criteria") {
                $data['min_price'] = $request->min_price;
                $data['max_price'] = $request->max_price;
            }

            if ($request->filter == "category_criteria") {
                $data['value'] = !empty($request->category_id) ? implode(',', $request->category_id) : '';
            }
            
            $featureSection = FeatureSection::create($data);
            
            foreach ($otherLanguages as $lang) {
                $langId = $lang->id;
                $translatedTitle = $request->input("title.$langId");
                $translatedDescription = $request->input("description.$langId");

                if (!empty($translatedTitle) || !empty($translatedDescription)) {
                    FeatureSectionTranslation::create([
                        'feature_section_id' => $featureSection->id,
                        'language_id' => $langId,
                        'name' => $translatedTitle ?? '',
                        'description' => $translatedDescription ?? '',
                    ]);
                }
            }
            ResponseService::successResponse('Feature Section Added Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "FeaturedSection Controller -> store");
            ResponseService::errorResponse();
        }

    }

    public function show(Request $request) {
        ResponseService::noPermissionThenSendJson('feature-section-list');
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'sequence');
        $order = $request->input('order', 'ASC');
        $sql = FeatureSection::with('category','translations');
        if (!empty($request->search)) {
            $sql = $sql->search($request->search);
        }

        $total = $sql->count();
        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $result = $sql->get();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        foreach ($result as $row) {
            $tempRow = $row->toArray();
            $operate = '';
            if (Auth::user()->can('feature-section-update')) {
                $operate .= BootstrapTableService::editButton(route('feature-section.update', $row->id), true);
            }

            if (Auth::user()->can('feature-section-delete')) {
                $operate .= BootstrapTableService::deleteButton(route('feature-section.destroy', $row->id));
            }
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function update(Request $request, $id) {
        ResponseService::noPermissionThenSendJson('feature-section-update');
        
        $languages = CachingService::getLanguages();
        $defaultLangId = 1;
        $otherLanguages = $languages->where('id', '!=', $defaultLangId);

        $rules = [
            "title.$defaultLangId" => 'required|string',
            "description.$defaultLangId" => 'nullable|string',
            'slug'        => 'required',
            'filter'      => 'required|in:most_liked,most_viewed,price_criteria,category_criteria,featured_ads',
            'style'       => 'required|in:style_1,style_2,style_3,style_4',
            'min_price'   => 'required_if:filter,price_criteria',
            'max_price'   => 'required_if:filter,price_criteria',
            'category_id' => 'required_if:filter,category_criteria',
        ];

        foreach ($otherLanguages as $lang) {
            $langId = $lang->id;
            $rules["title.$langId"] = 'nullable|string';
            $rules["description.$langId"] = 'nullable|string';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $feature_section = FeatureSection::findOrFail($id);
            
            $data = [
                'title' => $request->input("title.$defaultLangId"),
                'description' => $request->input("description.$defaultLangId"),
                'slug' => $request->slug,
                'filter' => $request->filter,
                'style' => $request->style,
            ];
            
            if ($request->filter == "price_criteria") {
                $data['min_price'] = $request->min_price;
                $data['max_price'] = $request->max_price;
            } else {
                $data['min_price'] = null;
                $data['max_price'] = null;
            }

            if ($request->filter == "category_criteria") {
                $data['value'] = !empty($request->category_id) ? implode(',', $request->category_id) : '';
            } else {
                $data['value'] = null;
            }
            
            $data['slug'] = HelperService::generateUniqueSlug(new FeatureSection(), $request->slug, $feature_section->id);
            $feature_section->update($data);
            
            foreach ($otherLanguages as $lang) {
                $langId = $lang->id;
                $translatedTitle = $request->input("title.$langId");
                $translatedDescription = $request->input("description.$langId");

                FeatureSectionTranslation::updateOrCreate(
                    [
                        'feature_section_id' => $feature_section->id,
                        'language_id' => $langId,
                    ],
                    [
                        'name' => $translatedTitle ?? '',
                        'description' => $translatedDescription ?? '',
                    ]
                );
            }
            ResponseService::successResponse('Feature Section Updated Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "FeaturedSection Controller -> update");
            ResponseService::errorResponse();
        }
    }

    public function destroy($id) {
        try {
            ResponseService::noPermissionThenSendJson('feature-section-delete');
            FeatureSection::findOrFail($id)->delete();
            ResponseService::successResponse('Feature Section delete successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "FeaturedSection Controller -> destroy");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }
}
