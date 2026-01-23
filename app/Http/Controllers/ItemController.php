<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\CustomField;
use App\Models\CustomFieldCategory;
use App\Models\Item;
use App\Models\ItemCustomFieldValue;
use App\Models\ItemImages;
use App\Models\Setting;
use App\Models\State;
use App\Models\User;
use App\Models\UserFcmToken;
use App\Services\BootstrapTableService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\NotificationService;
use App\Services\ResponseService;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Str;
use Throwable;
use Validator;

class ItemController extends Controller
{
    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['advertisement-list', 'advertisement-update', 'advertisement-delete']);
        $countries = Country::all();

        return view('items.index', compact('countries'));
    }

    public function show($status, Request $request)
    {
        try {
            ResponseService::noPermissionThenSendJson('advertisement-list');
            $offset = $request->input('offset', 0);
            $limit = $request->input('limit', 10);
            $sort = $request->input('sort', 'id');
            $order = $request->input('order', 'ASC');
            $sql = Item::with(['custom_fields', 'category:id,name', 'user:id,name,profile', 'gallery_images', 'featured_items'])->withTrashed();
            if (! empty($request->search)) {
                $sql = $sql->search($request->search);
            }
            if (! empty($request->filter)) {
                $filters = json_decode($request->filter, false, 512, JSON_THROW_ON_ERROR);
                if (is_object($filters) && count((array)$filters) > 0) {
                    // Handle status_not separately if present
                    $hasStatusNot = isset($filters->status_not);
                    $statusNotValue = null;
                    
                    if ($hasStatusNot) {
                        $statusNotValue = $filters->status_not;
                        $sql = $sql->where('status', '!=', $statusNotValue);
                    }
                    
                    // Build remaining filters object (excluding status_not)
                    $remainingFilters = [];
                    foreach ($filters as $key => $value) {
                        if ($key !== 'status_not') {
                            $remainingFilters[$key] = $value;
                        }
                    }
                    
                    // Apply remaining filters (status, country, state, city, featured_status, etc.)
                    if (! empty($remainingFilters)) {
                        $sql = $sql->filter((object)$remainingFilters);
                    }
                }
            }

            $total = $sql->count();
            $sql = $sql->sort($sort, $order)->skip($offset)->take($limit);
            $result = $sql->get();
            $bulkData = [];
            $bulkData['total'] = $total;
            $rows = [];

            $itemCustomFieldValues = ItemCustomFieldValue::whereIn('item_id', $result->pluck('id'))->get();
            foreach ($result as $row) {
                /* Merged ItemCustomFieldValue's data to main data */
                $itemCustomFieldValue = $itemCustomFieldValues->filter(function ($data) use ($row) {
                    return $data->item_id == $row->id;
                });
                $featured_status = $row->featured_items->isNotEmpty() ? 'Featured' : 'Premium';
                $row->custom_fields = collect($row->custom_fields)->map(function ($customField) use ($itemCustomFieldValue) {
                    $customField['value'] = $itemCustomFieldValue->first(function ($data) use ($customField) {
                        return $data->custom_field_id == $customField->id;
                    });

                    if ($customField->type == 'fileinput' && ! empty($customField['value']->value)) {
                        if (! is_array($customField->value)) {
                            $customField['value'] = ! empty($customField->value) ? [url(Storage::url($customField->value))] : [];
                        } else {
                            $customField['value'] = null;
                        }
                    }

                    return $customField;
                });
                $tempRow = $row->toArray();
                $operate = '';
                if (count($row->custom_fields) > 0 && Auth::user()->can('advertisement-list')) {
                    // View Custom Field
                    $operate .= BootstrapTableService::button('fa fa-eye', '#', ['editdata', 'btn-light-danger  '], ['title' => __('View'), 'data-bs-target' => '#editModal', 'data-bs-toggle' => 'modal']);
                }

                if ($row->status !== 'sold out' && Auth::user()->can('advertisement-update')) {
                    $operate .= BootstrapTableService::editButton(route('advertisement.approval', $row->id), true, '#editStatusModal', 'edit-status', $row->id);
                }
                if (Auth::user()->can('advertisement-update')) {
                    $operate .= BootstrapTableService::button('fa fa-wrench', route('advertisement.edit', $row->id), ['btn', 'btn-light-warning'], ['title' => __('Advertisement Update')]);
                }
                if (Auth::user()->can('advertisement-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('advertisement.destroy', $row->id));
                }
                $tempRow['active_status'] = empty($row->deleted_at); //IF deleted_at is empty then status is true else false
                $tempRow['featured_status'] = $featured_status;
                $tempRow['operate'] = $operate;

                $rows[] = $tempRow;
            }
            $bulkData['rows'] = $rows;

            return response()->json($bulkData);

        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemController --> show');
            ResponseService::errorResponse();
        }
    }

    public function updateItemApproval(Request $request, $id)
    {
        try {
            ResponseService::noPermissionThenSendJson('advertisement-update');
            $item = Item::with('user')->withTrashed()->findOrFail($id);
            $item->update([
                ...$request->all(),
                'rejected_reason' => ($request->status == 'soft rejected' || $request->status == 'permanent rejected') ? $request->rejected_reason : '',
            ]);
            $user_token = UserFcmToken::where('user_id', $item->user->id)->pluck('fcm_token')->toArray();
            if (! empty($user_token)) {
                NotificationService::sendFcmNotification($user_token, 'About '.$item->name, 'Your Advertisement is '.ucfirst($request->status), 'item-update', ['id' => $request->id]);
            }
            ResponseService::successResponse('Advertisement Status Updated Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemController ->updateItemApproval');
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson('advertisement-delete');

        try {
            $item = Item::with('gallery_images')->withTrashed()->findOrFail($id);
            foreach ($item->gallery_images as $gallery_image) {
                FileService::delete($gallery_image->getRawOriginal('image'));
            }
            FileService::delete($item->getRawOriginal('image'));

            $item->forceDelete();

            ResponseService::successResponse('Advertisement deleted successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse('Something went wrong');
        }
    }

    public function requestedItem()
    {
        ResponseService::noAnyPermissionThenRedirect(['advertisement-list', 'advertisement-update', 'advertisement-delete']);
        $countries = Country::all();
        $cities = City::all();

        return view('items.requested_item', compact('countries', 'cities'));
    }

    public function searchState(Request $request)
    {
        $countryName = trim($request->query('country_name'));
        if ($countryName == 'All') {
            return response()->json(['message' => 'Success', 'data' => []]);
        }
        $country = Country::where('name', $countryName)->first();
        if (! $country) {
            return response()->json(['message' => 'Success', 'data' => []]);
        }
        $states = State::where('country_id', $country->id)->get();

        return response()->json(['message' => 'Success', 'data' => $states]);
    }

    public function searchCities(Request $request)
    {
        $stateName = trim($request->query('state_name'));
        if ($stateName == 'All') {
            return response()->json(['message' => 'Success', 'data' => []]);
        }
        $state = State::where('name', $stateName)->first();
        if (! $state) {
            return response()->json(['message' => 'Success', 'data' => []]);
        }
        $cities = City::where('state_id', $state->id)->get();

        return response()->json(['message' => 'Success', 'data' => $cities]);
    }

    public function editForm($id)
    {
        $item = Item::with(
            'user:id,name,email,mobile,profile,country_code',
            'category.custom_fields', // get custom fields from category
            'gallery_images:id,image,item_id',
            'featured_items',
            'favourites',
            'item_custom_field_values.custom_field',
            'area'
        )->findOrFail($id);
        $categories = Category::whereNull('parent_category_id')
            ->with([
                'custom_fields',
                'subcategories',
                'subcategories.custom_fields',
                'subcategories.subcategories',
                'subcategories.subcategories.custom_fields',
                'subcategories.subcategories.subcategories',
                'subcategories.subcategories.subcategories.custom_fields',
                'subcategories.subcategories.subcategories.subcategories',
                'subcategories.subcategories.subcategories.subcategories.custom_fields',
                'subcategories.subcategories.subcategories.subcategories.subcategories',
                'subcategories.subcategories.subcategories.subcategories.subcategories.custom_fields',
                'subcategories.subcategories.subcategories.subcategories.subcategories.subcategories',
                'subcategories.subcategories.subcategories.subcategories.subcategories.subcategories.custom_fields',
                'subcategories.subcategories.subcategories.subcategories.subcategories.subcategories.subcategories',
                'subcategories.subcategories.subcategories.subcategories.subcategories.subcategories.subcategories.custom_fields',
            ])
            ->get();
        // $categories=[];

        $all_categories_till_parent = [];

        $categoryId = $item->category_id; // assume it's integer
        if ($categoryId) {
            $all_categories_till_parent[] = $categoryId;
        }

        while ($categoryId) {
            $parent = Category::where('id', $categoryId)->value('parent_category_id');
            if ($parent) {
                $all_categories_till_parent[] = $parent;
                $categoryId = $parent;
            } else {
                $categoryId = null;
            }
        }

        $all_categories_till_parent = array_unique($all_categories_till_parent);

        $customFieldCategories = CustomFieldCategory::with('custom_fields')
            ->whereIn('category_id', $all_categories_till_parent)
            ->get();

        $savedValues = ItemCustomFieldValue::where('item_id', $item->id)->get()->keyBy('custom_field_id');
        $custom_fields = $customFieldCategories->map(function ($relation) use ($savedValues) {
            $field = $relation->custom_fields;
            if (! $field) {
                return null;
            }

            $value = $savedValues->get($field->id)->value ?? null;

            if ($field->type === 'fileinput') {
                $field->value = $value ? [url(Storage::url($value))] : [];
            } else {
                if (is_array($value)) {
                    if (in_array($field->type, ['textbox', 'number'])) {
                        $field->value = implode(', ', $value);
                    } else {
                        $field->value = $value;
                    }
                } elseif (is_string($value)) {
                    $decodedValue = json_decode($value, true);
                    if (is_array($decodedValue)) {
                        if (in_array($field->type, ['textbox', 'number'])) {
                            $field->value = implode(', ', $decodedValue);
                        } else {
                            $field->value = $decodedValue;
                        }
                    } else {
                        $field->value = $decodedValue ?? $value;
                    }
                } else {
                    $field->value = '';
                }
            }
            if (in_array($field->type, ['dropdown', 'radio'])) {
                if (is_array($field->value)) {
                    $field->value = count($field->value) > 0 ? (string) $field->value[0] : '';
                } elseif (is_object($field->value)) {
                    $field->value = '';
                }
            }

            return $field;
        })->filter();
        $countries = Country::all();
        // $states = State::get();
        // $cities = city::get();
        $selected_category = [$item->category_id];

        return view('items.update', compact('item', 'categories', 'custom_fields', 'selected_category', 'countries'));
    }

    public function update(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('advertisement-update');
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|regex:/^[a-z0-9-]+$/',
            'description' => 'nullable|string',
            'latitude' => 'nullable',
            'longitude' => 'nullable',
            'address' => 'nullable',
            'contact' => 'nullable',
            'image' => 'nullable|mimes:jpeg,jpg,png|max:7168',
            'custom_fields' => 'nullable',
            'custom_field_files' => 'nullable|array',
            'custom_field_files.*' => 'nullable|mimes:jpeg,png,jpg,pdf,doc|max:7168',
            'gallery_images' => 'nullable|array',
            'admin_edit_reason' => 'required|string|max:1000',
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        DB::beginTransaction();
        try {
            $item = Item::findOrFail($id);

            $category = Category::findOrFail($request->category_id);
            $isJobCategory = $category->is_job_category;
            $isPriceOptional = $category->price_optional;

            if ($isJobCategory || $isPriceOptional) {
                $validator = Validator::make($request->all(), [
                    'min_salary' => 'nullable|numeric|min:0',
                    'max_salary' => 'nullable|numeric|gte:min_salary',
                ]);
            } else {
                $validator = Validator::make($request->all(), [
                    'price' => 'required|numeric|min:0',
                ]);
            }

            $customFieldCategories = CustomFieldCategory::with('custom_fields')
                ->where('category_id', $request->category_id)
                ->get();

            $customFieldErrors = [];
            foreach ($customFieldCategories as $relation) {
                $field = $relation->custom_fields;
                if (empty($field) || $field->required != 1) {
                    continue;
                }
                $fieldId = $field->id;
                $fieldLabel = $field->name;

                if (in_array($field->type, ['textbox', 'number', 'dropdown', 'radio'])) {
                    if (empty($request->input("custom_fields.$fieldId"))) {
                        $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field is required.";
                    }
                }

                if ($field->type === 'checkbox') {
                    if (! is_array($request->input("custom_fields.$fieldId")) || empty($request->input("custom_fields.$fieldId"))) {
                        $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field is required.";
                    }
                }

                if ($field->type === 'fileinput') {
                    $existing = ItemCustomFieldValue::where([
                        'item_id' => $id,
                        'custom_field_id' => $fieldId,
                    ])->first();

                    if (! $request->hasFile("custom_field_files.$fieldId") && empty($existing?->value)) {
                        $customFieldErrors["custom_field_files.$fieldId"] = "The $fieldLabel file is required.";
                    }
                }
            }
            if (! empty($customFieldErrors)) {
                return back()->withErrors($customFieldErrors)->withInput();
            }

            // Determine scheduling status
            $scheduledAt = null;
            $newStatus = null;
            
            if ($request->has('enable_scheduling') && $request->enable_scheduling && $request->filled('scheduled_at')) {
                $scheduledAt = $request->scheduled_at;
                $newStatus = 'scheduled';
            } else if ($request->has('enable_scheduling') && !$request->enable_scheduling && $item->status === 'scheduled') {
                // If scheduling was disabled, publish immediately (set to approved)
                $scheduledAt = null;
                $newStatus = 'approved';
            }

            $data = array_merge($request->all(), [
                'is_edited_by_admin' => 1,
                'admin_edit_reason' => $request->admin_edit_reason,
                'scheduled_at' => $scheduledAt,
            ]);
            
            // Only update status if scheduling changed
            if ($newStatus !== null) {
                $data['status'] = $newStatus;
            }

            // $data['slug'] = $uniqueSlug;
            // Address data from map selection
            $data['address'] = $request->input('address') ?? $request->input('address_input') ?? '';
            $data['country'] = $request->input('country_input') ?? '';
            $data['state'] = $request->input('state_input') ?? '';
            $data['city'] = $request->input('city_input') ?? '';
            $data['latitude'] = $request->input('latitude');
            $data['longitude'] = $request->input('longitude');

            if ($request->hasFile('image')) {
                $data['image'] = FileService::compressAndReplace($request->file('image'), 'uploads/items', $item->getRawOriginal('image'));
            }

            $oldCategoryId = $item->category_id;
            $newCategoryId = $request->category_id;

            $isCategoryChanged = $oldCategoryId != $newCategoryId;
            $oldCustomFieldValues = ItemCustomFieldValue::where('item_id', $item->id)->get();
            foreach ($oldCustomFieldValues as $fieldValue) {
                $customField = CustomField::find($fieldValue->custom_field_id);
                if ($customField && $customField->type === 'file') {
                    $rawFilePath = $fieldValue->getRawOriginal('value');
                    if ($customField && $customField->type === 'file' && ! empty($rawFilePath)) {
                        FileService::delete($rawFilePath);
                    }
                }
            }
            if ($isCategoryChanged) {
                ItemCustomFieldValue::where('item_id', $item->id)->delete();
            }
            $item->update($data);
            if ($request->custom_fields) {
                foreach ($request->custom_fields as $key => $custom_field) {
                    $value = is_array($custom_field) ? $custom_field : [$custom_field];
                    ItemCustomFieldValue::updateOrCreate(
                        [
                            'item_id' => $item->id,
                            'custom_field_id' => $key,
                        ],
                        [
                            'value' => json_encode($value, JSON_THROW_ON_ERROR),
                            'updated_at' => now(),
                        ]
                    );
                }
            }
            if ($request->hasFile('custom_field_files')) {
                $itemCustomFieldValues = [];
                foreach ($request->file('custom_field_files') as $key => $file) {
                    $value = ItemCustomFieldValue::where(['item_id' => $item->id, 'custom_field_id' => $key])->first();

                    $path = $value
                        ? FileService::replace($file, 'custom_fields_files', $value->getRawOriginal('value'))
                        : FileService::upload($file, 'custom_fields_files');

                    $itemCustomFieldValues[] = [
                        'item_id' => $item->id,
                        'custom_field_id' => $key,
                        'value' => $path,
                        'updated_at' => now(),
                    ];
                }

                if (! empty($itemCustomFieldValues)) {
                    ItemCustomFieldValue::upsert($itemCustomFieldValues, ['item_id', 'custom_field_id'], ['value', 'updated_at']);
                }
            }
            if ($request->hasFile('gallery_images')) {
                $galleryImages = [];
                foreach ($request->file('gallery_images') as $file) {
                    $galleryImages[] = [
                        'image' => FileService::compressAndUpload($file, 'uploads/items'),
                        'item_id' => $item->id,
                        'created_at' => time(),
                        'updated_at' => time(),
                    ];
                }
                if (count($galleryImages) > 0) {
                    ItemImages::insert($galleryImages);
                }
            }

            // Custom field files
            foreach ($request->allFiles() as $key => $file) {
                if (Str::startsWith($key, 'custom_fields.')) {
                    $customFieldId = Str::after($key, 'custom_fields.');
                    $value = ItemCustomFieldValue::where(['item_id' => $item->id, 'custom_field_id' => $customFieldId])->first();
                    if ($value) {
                        $filePath = FileService::replace($file, 'custom_fields_files', $value->getRawOriginal('value'));
                    } else {
                        $filePath = FileService::upload($file, 'custom_fields_files');
                    }
                    ItemCustomFieldValue::updateOrCreate(
                        ['item_id' => $item->id, 'custom_field_id' => $customFieldId],
                        ['value' => $filePath, 'updated_at' => now()]
                    );
                }
            }
            if (! empty($request->delete_item_image_id)) {
                $itemImageIds = explode(',', $request->delete_item_image_id);
                foreach (ItemImages::whereIn('id', $itemImageIds)->get() as $itemImage) {
                    FileService::delete($itemImage->getRawOriginal('image'));
                    $itemImage->delete();
                }
            }

            DB::commit();
            $isApproved = $item->status === 'approved';
            $isNonExpired = $item->expired_at === null || $item->expired_at > now();
            $isNotDeleted = $item->deleted_at === null;
            $user_token = UserFcmToken::where('user_id', $item->user->id)->pluck('fcm_token')->toArray();
            if (! empty($user_token)) {
                NotificationService::sendFcmNotification($user_token, 'About '.$item->name, 'Your Advertisement is edited by admin', 'item-edit', ['id' => $request->id]);
            }

            if ($isApproved && $isNonExpired && $isNotDeleted) {
                ResponseService::successRedirectResponse('Advertisement Updated Successfully', route('advertisement.index'));
            } else {
                ResponseService::successRedirectResponse('Advertisement Updated Successfully', route('advertisement.requested.index'));
            }
        } catch (Throwable $th) {
            DB::rollBack();
            report($th);

            return redirect()->back()->with('error', 'An error occurred while updating the Advertisement.');
        }
    }

    public function getCustomFields(Request $request, $categoryId)
    {

        $categoryIds = $this->getParentCategoryIds($categoryId);
        $category = Category::find($categoryId);
        $customFields = CustomField::with('translations')
            ->whereHas('custom_field_category', function ($q) use ($categoryIds) {
                $q->whereIn('category_id', $categoryIds);
            })
            ->where('status', 1)
            ->get();

        return response()->json([
            'fields' => $customFields,
            'is_job_category' => $category->is_job_category,
            'price_optional' => $category->price_optional,
            'category_ids' => $categoryIds,
        ]);
    }

    protected function getParentCategoryIds($categoryId, &$ids = [])
    {
        $category = Category::find($categoryId);

        if ($category) {
            $ids[] = $category->id;
            if ($category->parent_category_id) {
                $this->getParentCategoryIds($category->parent_category_id, $ids);
            }
        }

        return array_reverse($ids);
    }

    public function create()
    {
        ResponseService::noAnyPermissionThenRedirect(['advertisement-create']);

        // No need to load categories here, they'll be loaded via AJAX
        $countries = Country::all();
        $adminUserEmail = Setting::where('name', 'admin_user_email')->value('value');
        $adminUserPassword = Setting::where('name', 'admin_user_password')->value('value');

        return view('items.create', compact('countries','adminUserEmail','adminUserPassword'));
    }

    public function getParentCategories(Request $request)
    {
        ResponseService::noPermissionThenSendJson('advertisement-create');

        try {
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 10);

            $categories = Category::whereNull('parent_category_id')
                ->where('status', 1)
                ->orderBy('sequence', 'ASC')
                ->withCount(['subcategories' => function ($q) {
                    $q->where('status', 1);
                }])
                ->skip(($page - 1) * $perPage)
                ->take($perPage + 1)
                ->get(['id', 'name', 'status', 'image']);

            $hasMore = $categories->count() > $perPage;
            $categories = $categories->take($perPage);

            return response()->json([
                'message' => 'Success',
                'data' => $categories,
                'has_more' => $hasMore,
                'current_page' => $page,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemController -> getParentCategories');

            return response()->json(['message' => 'Error loading categories'], 500);
        }
    }

    public function getSubCategories(Request $request)
    {
        ResponseService::noPermissionThenSendJson('advertisement-create');

        $validator = Validator::make($request->all(), [
            'category_id' => 'required|integer',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        try {
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 10);

            $subcategories = Category::where('parent_category_id', $request->category_id)
                ->where('status', 1)
                ->orderBy('sequence', 'ASC')
                ->withCount(['subcategories' => function ($q) {
                    $q->where('status', 1);
                }])
                ->skip(($page - 1) * $perPage)
                ->take($perPage + 1)
                ->get(['id', 'name', 'parent_category_id', 'status', 'image']);

            $hasMore = $subcategories->count() > $perPage;
            $subcategories = $subcategories->take($perPage);

            return response()->json([
                'message' => 'Success',
                'data' => $subcategories,
                'has_more' => $hasMore,
                'current_page' => $page,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemController -> getSubCategories');

            return response()->json(['message' => 'Error loading subcategories'], 500);
        }
    }

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('advertisement-create');

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|regex:/^[a-z0-9-]+$/',
            'description' => 'required|string',
            'latitude' => 'required',
            'longitude' => 'required',
            'address' => 'nullable',
            'contact' => 'nullable',
            'image' => 'required|mimes:jpeg,jpg,png|max:7168',
            'custom_fields' => 'nullable',
            'custom_field_files' => 'nullable|array',
            'custom_field_files.*' => 'nullable|mimes:jpeg,png,jpg,pdf,doc|max:7168',
            'gallery_images' => 'nullable|array',
            'gallery_images.*' => 'nullable|mimes:jpeg,png,jpg|max:7168',
            'video_link' => 'nullable|url',
            'category_id' => 'required|integer',
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            $errorMessage = $validator->errors()->first();
            return ResponseService::errorRedirectWithToast($errorMessage, $request->all());
        }

        DB::beginTransaction();
        try {
            $category = Category::findOrFail($request->category_id);
            $isJobCategory = $category->is_job_category;
            $isPriceOptional = $category->price_optional;

            if ($isJobCategory || $isPriceOptional) {
                $validator = Validator::make($request->all(), [
                    'min_salary' => 'nullable|numeric|min:0',
                    'max_salary' => 'nullable|numeric|gte:min_salary',
                ]);
            } else {
                $validator = Validator::make($request->all(), [
                    'price' => 'required|numeric|min:0',
                ]);
            }

            if ($validator->fails()) {
                DB::rollBack();
                $errorMessage = $validator->errors()->first();
                return ResponseService::errorRedirectWithToast($errorMessage, $request->all());
            }

            $customFieldCategories = CustomFieldCategory::with('custom_fields')
                ->where('category_id', $request->category_id)
                ->get();

            $customFieldErrors = [];
            foreach ($customFieldCategories as $relation) {
                $field = $relation->custom_fields;
                if (empty($field) || $field->required != 1 || $field->status != 1) {
                    continue;
                }

                $fieldId = $field->id;
                $fieldLabel = $field->name;

                if (in_array($field->type, ['textbox', 'number', 'dropdown', 'radio'])) {
                    if (empty($request->input("custom_fields.$fieldId"))) {
                        $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field is required.";
                    }
                }

                if ($field->type === 'checkbox') {
                    if (! is_array($request->input("custom_fields.$fieldId")) || empty($request->input("custom_fields.$fieldId"))) {
                        $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field is required.";
                    }
                }

                if ($field->type === 'fileinput') {
                    if (! $request->hasFile("custom_field_files.$fieldId")) {
                        $customFieldErrors["custom_field_files.$fieldId"] = "The $fieldLabel file is required.";
                    }
                }
            }

            if (! empty($customFieldErrors)) {
                DB::rollBack();
                $errorMessage = reset($customFieldErrors); // Get first error message
                return ResponseService::errorRedirectWithToast($errorMessage, $request->all());
            }

            $slug = trim($request->input('slug') ?? '');
            $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($slug));
            $slug = trim($slug, '-');
            if (empty($slug)) {
                $slug = HelperService::generateRandomSlug();
            }
            $uniqueSlug = HelperService::generateUniqueSlug(new Item, $slug);

            $userEmail = Setting::where('name', 'admin_user_email')->value('value');
            $userPassword = Setting::where('name', 'admin_user_password')->value('value');
            if (empty($userEmail) && empty($userPassword)) {
                DB::rollBack();
                return ResponseService::errorRedirectWithToast('Add user details in the setting first.', $request->all());
            }
            $user = User::withTrashed()->where('email', $userEmail)->first();

            if (!$user || $user->trashed()) {
                DB::rollBack();
                return ResponseService::errorRedirectWithToast('User not found.', $request->all());
            }

            // Determine status based on scheduling
            $scheduledAt = null;
            $status = 'approved';
            
            if ($request->has('enable_scheduling') && $request->enable_scheduling && $request->filled('scheduled_at')) {
                $scheduledAt = $request->scheduled_at;
                $status = 'scheduled';
            }

            $data = [
                'name' => $request->name,
                'slug' => $uniqueSlug,
                'description' => $request->description,
                'address' => $request->input('address') ?? $request->input('address_input') ?? '',
                'country' => $request->input('country_input') ?? '',
                'state' => $request->input('state_input') ?? '',
                'city' => $request->input('city_input') ?? '',
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'contact' => $request->contact ?? $user->contact,
                'category_id' => $request->category_id,
                'price' => $request->price,
                'min_salary' => $request->min_salary,
                'max_salary' => $request->max_salary,
                'video_link' => $request->video_link,
                'user_id' => $user->id,
                'status' => $status,
                'scheduled_at' => $scheduledAt,
                'active' => 'active',
            ];

            if ($request->hasFile('image')) {
                $data['image'] = FileService::compressAndUpload($request->file('image'), 'uploads/items');
            }

            $item = Item::create($data);

            if ($request->custom_fields) {
                foreach ($request->custom_fields as $key => $custom_field) {
                    $value = is_array($custom_field) ? $custom_field : [$custom_field];
                    ItemCustomFieldValue::create([
                        'item_id' => $item->id,
                        'custom_field_id' => $key,
                        'value' => json_encode($value, JSON_THROW_ON_ERROR),
                    ]);
                }
            }

            if ($request->hasFile('custom_field_files')) {
                foreach ($request->file('custom_field_files') as $key => $file) {
                    $path = FileService::upload($file, 'custom_fields_files');
                    ItemCustomFieldValue::create([
                        'item_id' => $item->id,
                        'custom_field_id' => $key,
                        'value' => $path,
                    ]);
                }
            }

            if ($request->hasFile('gallery_images')) {
                $galleryImages = [];
                foreach ($request->file('gallery_images') as $file) {
                    $galleryImages[] = [
                        'image' => FileService::compressAndUpload($file, 'uploads/items'),
                        'item_id' => $item->id,
                        'created_at' => time(),
                        'updated_at' => time(),
                    ];
                }
                if (count($galleryImages) > 0) {
                    ItemImages::insert($galleryImages);
                }
            }

            // Custom field files from direct custom_fields input
            foreach ($request->allFiles() as $key => $file) {
                if (Str::startsWith($key, 'custom_fields.')) {
                    $customFieldId = Str::after($key, 'custom_fields.');
                    $filePath = FileService::upload($file, 'custom_fields_files');
                    ItemCustomFieldValue::create([
                        'item_id' => $item->id,
                        'custom_field_id' => $customFieldId,
                        'value' => $filePath,
                    ]);
                }
            }

            DB::commit();
            ResponseService::successRedirectResponse('Advertisement Created Successfully', route('advertisement.index'));
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th, 'ItemController -> store', 'An error occurred while creating the Advertisement.', false);
            return ResponseService::errorRedirectWithToast('An error occurred while creating the Advertisement.', $request->all());
        }
    }
}
