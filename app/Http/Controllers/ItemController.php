<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchSellerPushNotificationJob;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\CustomField;
use App\Models\CustomFieldCategory;
use App\Models\Chat;
use App\Models\Item;
use App\Models\ItemCustomFieldValue;
use App\Models\ItemImages;
use App\Models\ItemOffer;
use App\Models\Notifications;
use App\Models\Setting;
use App\Models\State;
use App\Models\User;
use App\Models\UserFcmToken;
use App\Services\AuditLogService;
use App\Services\BootstrapTableService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\NotificationService;
use App\Services\ResponseService;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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
        $defaultMode = 'all';

        return view('items.index', compact('countries', 'defaultMode'));
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
                $filters = json_decode((string) $request->filter, false);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $filters = null;
                }

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

                    if ($customField->type === 'fileinput') {
                        $filePath = $customField['value']->value ?? null;
                        $customField['value'] = ! empty($filePath) ? [url(Storage::url($filePath))] : [];
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
                    $operate .= BootstrapTableService::button('fa fa-history', route('advertisement.timeline', $row->id), ['btn', 'btn-light-secondary', 'view-timeline'], [
                        'title' => __('Moderation Timeline'),
                    ]);
                    $operate .= BootstrapTableService::button('fa fa-comments', route('advertisement.message-seller', $row->id), ['btn', 'btn-light-primary', 'message-seller'], [
                        'title' => __('Message Seller'),
                    ]);
                    $operate .= BootstrapTableService::button('fa fa-bell', route('advertisement.notify-seller', $row->id), ['btn', 'btn-light-info', 'notify-seller'], [
                        'title' => __('Notify Seller'),
                    ]);
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

    public function getBulkDetails(Request $request)
{
    try {
        $ids = $request->input('ids'); // Očekujemo string "1,2,3"

        if (!$ids) {
            return response()->json(['error' => true, 'message' => 'No IDs provided']);
        }

        $idArray = explode(',', $ids);

        // Dohvati oglase sa svim potrebnim relacijama
        $items = Item::whereIn('id', $idArray)
            ->with(['category', 'user', 'area', 'custom_fields', 'gallery_images'])
            ->get();

        return response()->json([
            'error' => false,
            'data' => $items
        ]);

    } catch (\Exception $e) {
        return response()->json(['error' => true, 'message' => $e->getMessage()]);
    }
}

    public function updateItemApproval(Request $request, $id)
    {
        try {
            ResponseService::noPermissionThenSendJson('advertisement-update');
            $item = Item::with('user')->withTrashed()->findOrFail($id);
            $oldStatus = $item->status;
            $item->update([
                ...$request->all(),
                'rejected_reason' => ($request->status == 'soft rejected' || $request->status == 'permanent rejected') ? $request->rejected_reason : '',
            ]);
            AuditLogService::log('advertisement_approval_status_change', Item::class, $item->id, [
                'from' => $oldStatus,
                'to' => $request->status,
                'rejected_reason' => $request->rejected_reason,
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
            AuditLogService::log('advertisement_deleted_by_admin', Item::class, $item->id, [
                'name' => $item->name,
                'user_id' => $item->user_id,
            ]);

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
        $defaultMode = 'requested';

        return view('items.index', compact('countries', 'defaultMode'));
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

    public function edit($id)
    {
        ResponseService::noPermissionThenRedirect('advertisement-update');

        return $this->editForm($id);
    }

    public function moderationTimeline(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('advertisement-update');

        try {
            $limit = (int) $request->input('limit', 40);
            $limit = max(10, min($limit, 150));

            $item = Item::withTrashed()->with('user:id,name,email')->findOrFail($id);
            $auditLogs = collect();
            if (Schema::hasTable('audit_logs')) {
                $auditLogs = AuditLog::query()
                    ->with('actor:id,name,email')
                    ->where('target_type', Item::class)
                    ->where('target_id', (string) $item->id)
                    ->orderByDesc('id')
                    ->take($limit)
                    ->get();
            }

            $events = $auditLogs
                ->map(function (AuditLog $log) {
                    $context = is_array($log->context) ? $log->context : [];
                    $createdAt = optional($log->created_at)->toDateTimeString();

                    return [
                        'id' => (string) $log->id,
                        'action' => (string) $log->action,
                        'label' => $this->timelineActionLabel((string) $log->action),
                        'description' => $this->timelineActionDescription((string) $log->action, $context),
                        'actor_name' => $log->actor?->name ?: __('System'),
                        'actor_email' => $log->actor?->email ?: '-',
                        'ip_address' => $log->ip_address ?: '-',
                        'context' => $this->sanitizeTimelineContext($context),
                        'created_at' => $createdAt,
                        'created_at_human' => optional($log->created_at)->diffForHumans(),
                        'created_at_unix' => optional($log->created_at)->timestamp ?? 0,
                    ];
                });

            $createdAt = optional($item->created_at)->toDateTimeString();
            $events->push([
                'id' => 'item-created-'.$item->id,
                'action' => 'advertisement_created',
                'label' => __('Advertisement Created'),
                'description' => __('Seller created this advertisement'),
                'actor_name' => $item->user?->name ?: __('Seller'),
                'actor_email' => $item->user?->email ?: '-',
                'ip_address' => '-',
                'context' => [
                    'status' => $item->status,
                    'price' => $item->price,
                    'category_id' => $item->category_id,
                ],
                'created_at' => $createdAt,
                'created_at_human' => optional($item->created_at)->diffForHumans(),
                'created_at_unix' => optional($item->created_at)->timestamp ?? 0,
            ]);

            if (! empty($item->deleted_at)) {
                $deletedAt = optional($item->deleted_at)->toDateTimeString();
                $events->push([
                    'id' => 'item-soft-deleted-'.$item->id,
                    'action' => 'advertisement_soft_deleted',
                    'label' => __('Advertisement Deactivated'),
                    'description' => __('Advertisement is currently deactivated (soft deleted)'),
                    'actor_name' => __('System'),
                    'actor_email' => '-',
                    'ip_address' => '-',
                    'context' => [],
                    'created_at' => $deletedAt,
                    'created_at_human' => optional($item->deleted_at)->diffForHumans(),
                    'created_at_unix' => optional($item->deleted_at)->timestamp ?? 0,
                ]);
            }

            $timeline = $events
                ->sortByDesc(fn ($event) => (int) ($event['created_at_unix'] ?? 0))
                ->values()
                ->take($limit)
                ->map(function ($event) {
                    unset($event['created_at_unix']);
                    return $event;
                })
                ->values();

            ResponseService::successResponse('Moderation timeline loaded', [
                'item' => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'status' => $item->status,
                    'user_name' => $item->user?->name ?: '-',
                    'user_email' => $item->user?->email ?: '-',
                ],
                'events' => $timeline,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemController -> moderationTimeline');
            ResponseService::errorResponse('Unable to load moderation timeline');
        }
    }

    public function sendMessageToSeller(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('advertisement-update');

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:255',
            'send_push' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $admin = Auth::user();
            $item = Item::with(['user:id,name,profile'])->withTrashed()->findOrFail($id);
            $seller = $item->user;

            if (! $seller) {
                ResponseService::notFoundResponse('Seller not found');
            }
            if ((int) $seller->id === (int) $admin->id) {
                ResponseService::validationError('Cannot send a seller message to yourself');
            }

            DB::beginTransaction();
            $conversation = ItemOffer::query()
                ->where('item_id', $item->id)
                ->where('seller_id', $seller->id)
                ->where('buyer_id', $admin->id)
                ->latest('id')
                ->first();

            if (! $conversation) {
                $conversation = new ItemOffer();
                $conversation->item_id = $item->id;
                $conversation->seller_id = $seller->id;
                $conversation->buyer_id = $admin->id;
                $conversation->amount = 0;
                $conversation->save();
            }

            $chat = Chat::create([
                'sender_id' => $admin->id,
                'item_offer_id' => $conversation->id,
                'message' => trim((string) $request->input('message')),
                'message_type' => 'text',
                'file' => '',
                'audio' => '',
                'is_read' => 0,
                'status' => 'sent',
            ]);
            DB::commit();

            $pushDispatched = false;
            $tokenCount = 0;
            $shouldSendPush = $request->boolean('send_push', true);
            if ($shouldSendPush) {
                $userTokens = UserFcmToken::where('user_id', $seller->id)->pluck('fcm_token')->filter()->values()->all();
                $tokenCount = count($userTokens);
                if ($tokenCount > 0) {
                    $pushDispatched = $this->queueSellerPushNotification(
                        $userTokens,
                        'Nova poruka od administracije',
                        $chat->message,
                        'chat',
                        [
                            'id' => $chat->id,
                            'type' => 'chat',
                            'chat_id' => $conversation->id,
                            'item_offer_id' => $conversation->id,
                            'sender_id' => $admin->id,
                            'message' => $chat->message,
                            'message_type' => 'text',
                            'message_type_temp' => 'text',
                            'user_id' => $admin->id,
                            'user_name' => $admin->name,
                            'user_profile' => $admin->profile,
                            'user_type' => 'Admin',
                            'item_id' => $item->id,
                            'item_name' => $item->name,
                            'item_image' => $item->image,
                            'item_price' => $item->price,
                            'item_offer_amount' => $conversation->amount ?? 0,
                        ],
                        [
                            'action' => 'advertisement_admin_message_sent_to_seller',
                            'item_id' => $item->id,
                            'seller_id' => $seller->id,
                            'chat_id' => $chat->id,
                        ]
                    );
                }
            }

            AuditLogService::log('advertisement_admin_message_sent_to_seller', Item::class, $item->id, [
                'seller_id' => $seller->id,
                'conversation_id' => $conversation->id,
                'chat_id' => $chat->id,
                'push_dispatched' => $pushDispatched,
                'token_count' => $tokenCount,
            ]);

            if ($shouldSendPush && $tokenCount === 0) {
                ResponseService::warningResponse('Seller has no active push token', [
                    'item_id' => $item->id,
                    'seller_id' => $seller->id,
                    'conversation_id' => $conversation->id,
                    'chat_id' => $chat->id,
                    'push_dispatched' => false,
                ]);
            }

            if ($shouldSendPush && $tokenCount > 0 && ! $pushDispatched) {
                ResponseService::warningResponse('Message saved, but push queue dispatch failed', [
                    'item_id' => $item->id,
                    'seller_id' => $seller->id,
                    'conversation_id' => $conversation->id,
                    'chat_id' => $chat->id,
                    'push_dispatched' => false,
                ]);
            }

            ResponseService::successResponse('Seller message sent successfully', [
                'item_id' => $item->id,
                'seller_id' => $seller->id,
                'conversation_id' => $conversation->id,
                'chat_id' => $chat->id,
                'push_dispatched' => $pushDispatched,
                'token_count' => $tokenCount,
            ]);
        } catch (Throwable $th) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            ResponseService::logErrorResponse($th, 'ItemController -> sendMessageToSeller');
            ResponseService::errorResponse('Unable to send message to seller');
        }
    }

    public function sendNotificationToSeller(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('advertisement-update');

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:120',
            'message' => 'required|string|max:2000',
            'image' => 'nullable|mimes:jpeg,jpg,png|max:7168',
            'send_push' => 'nullable|boolean',
            'store_in_inbox' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        if (! $request->boolean('send_push', true) && ! $request->boolean('store_in_inbox', true)) {
            ResponseService::validationError('Select at least one notification channel');
        }

        try {
            $item = Item::with(['user:id,name'])->withTrashed()->findOrFail($id);
            $seller = $item->user;

            if (! $seller) {
                ResponseService::notFoundResponse('Seller not found');
            }

            $imagePath = '';
            if ($request->hasFile('image')) {
                $imagePath = FileService::compressAndUpload($request->file('image'), 'notification');
            }

            $notificationRecord = null;
            if ($request->boolean('store_in_inbox', true)) {
                $notificationRecord = Notifications::create([
                    'title' => trim((string) $request->input('title')),
                    'message' => trim((string) $request->input('message')),
                    'image' => $imagePath,
                    'item_id' => $item->id,
                    'user_id' => (string) $seller->id,
                    'send_to' => 'selected',
                ]);
            }

            $pushDispatched = false;
            $tokenCount = 0;
            if ($request->boolean('send_push', true)) {
                $userTokens = UserFcmToken::where('user_id', $seller->id)->pluck('fcm_token')->filter()->values()->all();
                $tokenCount = count($userTokens);

                if ($tokenCount > 0) {
                    $pushDispatched = $this->queueSellerPushNotification(
                        $userTokens,
                        trim((string) $request->input('title')),
                        trim((string) $request->input('message')),
                        'notification',
                        [
                            'item_id' => $item->id,
                            'item_name' => $item->name,
                            'item_image' => $item->image,
                            'source' => 'admin_advertisement_action',
                        ],
                        [
                            'action' => 'advertisement_admin_notification_sent_to_seller',
                            'item_id' => $item->id,
                            'seller_id' => $seller->id,
                            'notification_id' => $notificationRecord?->id,
                        ]
                    );
                }
            }

            AuditLogService::log('advertisement_admin_notification_sent_to_seller', Item::class, $item->id, [
                'seller_id' => $seller->id,
                'notification_id' => $notificationRecord?->id,
                'push_dispatched' => $pushDispatched,
                'token_count' => $tokenCount,
            ]);

            if ($request->boolean('send_push', true) && $tokenCount === 0) {
                ResponseService::warningResponse('Seller has no active push token', [
                    'item_id' => $item->id,
                    'seller_id' => $seller->id,
                    'notification_id' => $notificationRecord?->id,
                    'push_dispatched' => false,
                ]);
            }

            if ($request->boolean('send_push', true) && $tokenCount > 0 && ! $pushDispatched) {
                ResponseService::warningResponse('Notification saved, but push queue dispatch failed', [
                    'item_id' => $item->id,
                    'seller_id' => $seller->id,
                    'notification_id' => $notificationRecord?->id,
                    'push_dispatched' => false,
                ]);
            }

            ResponseService::successResponse('Seller notification sent successfully', [
                'item_id' => $item->id,
                'seller_id' => $seller->id,
                'notification_id' => $notificationRecord?->id,
                'push_dispatched' => $pushDispatched,
                'token_count' => $tokenCount,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemController -> sendNotificationToSeller');
            ResponseService::errorResponse('Unable to send notification to seller');
        }
    }

    private function queueSellerPushNotification(
        array $tokens,
        string $title,
        string $message,
        string $type = 'notification',
        array $customBodyFields = [],
        array $meta = []
    ): bool {
        try {
            DispatchSellerPushNotificationJob::dispatch(
                $tokens,
                $title,
                $message,
                $type,
                $customBodyFields,
                $meta
            )->onQueue('notifications')->afterResponse();

            return true;
        } catch (Throwable $th) {
            logger()->warning('Failed to dispatch seller push notification job', [
                'meta' => $meta,
                'error' => $th->getMessage(),
            ]);

            return false;
        }
    }

    private function timelineActionLabel(string $action): string
    {
        return match ($action) {
            'advertisement_created_by_admin' => __('Advertisement Created By Admin'),
            'advertisement_updated_by_admin' => __('Advertisement Updated By Admin'),
            'advertisement_approval_status_change' => __('Status Change'),
            'advertisement_admin_message_sent_to_seller' => __('Seller Message Sent'),
            'advertisement_admin_notification_sent_to_seller' => __('Seller Notification Sent'),
            'advertisement_deleted_by_admin' => __('Advertisement Permanently Deleted'),
            default => Str::headline(str_replace('_', ' ', $action)),
        };
    }

    private function timelineActionDescription(string $action, array $context = []): string
    {
        return match ($action) {
            'advertisement_approval_status_change' => __('Status changed from :from to :to', [
                'from' => $context['from'] ?? '-',
                'to' => $context['to'] ?? '-',
            ]),
            'advertisement_admin_message_sent_to_seller' => __('Admin sent a direct message to seller. Conversation # :conversation', [
                'conversation' => $context['conversation_id'] ?? '-',
            ]),
            'advertisement_admin_notification_sent_to_seller' => __('Admin sent seller notification. Notification # :notification', [
                'notification' => $context['notification_id'] ?? '-',
            ]),
            'advertisement_updated_by_admin' => __('Admin updated this advertisement. Reason: :reason', [
                'reason' => $context['admin_edit_reason'] ?? '-',
            ]),
            'advertisement_deleted_by_admin' => __('Advertisement permanently deleted from admin panel'),
            default => __('Action recorded'),
        };
    }

    private function sanitizeTimelineContext(array $context): array
    {
        return collect($context)
            ->map(function ($value) {
                if (is_string($value) && strlen($value) > 280) {
                    return substr($value, 0, 280).'...';
                }

                if (is_array($value)) {
                    return $this->sanitizeTimelineContext($value);
                }

                return $value;
            })
            ->toArray();
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
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        DB::beginTransaction();
        try {
            $item = Item::findOrFail($id);
            $oldSnapshot = [
                'status' => $item->getRawOriginal('status') ?? $item->status,
                'price' => $item->price,
                'category_id' => $item->category_id,
                'country' => $item->country,
                'state' => $item->state,
                'city' => $item->city,
            ];

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

            $data = array_merge($request->all(), [
                'is_edited_by_admin' => 1,
                'admin_edit_reason' => $request->admin_edit_reason,
            ]);

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
            $newSnapshot = [
                'status' => $item->getRawOriginal('status') ?? $item->status,
                'price' => $item->price,
                'category_id' => $item->category_id,
                'country' => $item->country,
                'state' => $item->state,
                'city' => $item->city,
            ];
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
            $isNonExpired = $item->expiry_date === null || $item->expiry_date > now();
            $isNotDeleted = $item->deleted_at === null;
            $user_token = UserFcmToken::where('user_id', $item->user->id)->pluck('fcm_token')->toArray();
            if (! empty($user_token)) {
                NotificationService::sendFcmNotification($user_token, 'About '.$item->name, 'Your Advertisement is edited by admin', 'item-edit', ['id' => $request->id]);
            }

            AuditLogService::log('advertisement_updated_by_admin', Item::class, $item->id, [
                'admin_edit_reason' => trim((string) $request->input('admin_edit_reason')),
                'from' => $oldSnapshot,
                'to' => $newSnapshot,
            ]);

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
                'status' => 'approved',
                'active' => 'active',
            ];

            if ($request->hasFile('image')) {
                $data['image'] = FileService::compressAndUpload($request->file('image'), 'uploads/items');
            }

            $item = Item::create($data);
            AuditLogService::log('advertisement_created_by_admin', Item::class, $item->id, [
                'seller_id' => $user->id,
                'status' => $item->status,
                'price' => $item->price,
                'category_id' => $item->category_id,
            ]);

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
