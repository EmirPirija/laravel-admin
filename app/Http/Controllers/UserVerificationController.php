<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\UserFcmToken;
use App\Models\VerificationField;
use App\Models\VerificationFieldsTranslation;
use App\Models\VerificationFieldValue;
use App\Models\VerificationRequest;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\NotificationService;
use App\Services\ResponseService;
use Auth;
use DB;
use Illuminate\Http\Request;
use Storage;
use Throwable;
use Validator;

class UserVerificationController extends Controller {
    private string $uploadFolder;

    public function __construct() {
        $this->uploadFolder = 'seller_verification';
    }

    public function index() {
        ResponseService::noAnyPermissionThenRedirect(['seller-verification-field-list', 'seller-verification-field-create', 'seller-verification-field-update', 'seller-verification-field-delete']);
        $verificationRequests = VerificationRequest::with('verificationFieldValue', 'user');
        return view('seller-verification.index', compact('verificationRequests'));
    }

    public function verificationField() {
        // $verificationRequests = VerificationRequest::all();
        return view('seller-verification.verificationfield');
    }

    public function create() {
        ResponseService::noPermissionThenRedirect('seller-verification-field-create');
         $languages = CachingService::getLanguages()->values();
        return view('seller-verification.create',compact('languages'));
    }

   public function store(Request $request) {
    ResponseService::noPermissionThenSendJson('seller-verification-field-create');
    $defaultValuesCount = count($request->input('values.1', []));
    $validator = Validator::make($request->all(), [
        'name.1'        => 'required',
        'type'          => 'required|in:number,textbox,fileinput,radio,dropdown,checkbox',
        'values.1'      => 'required_if:type,radio,dropdown,checkbox|array',
        'min_length'    => 'nullable|numeric',
        'max_length'    => 'nullable|numeric|gt:min_length',
    ]);

    // Custom validation for other languages
    $validator->after(function ($validator) use ($request, $defaultValuesCount) {
        foreach ($request->input('languages', []) as $langId) {
            if ($langId != 1) {
                $values = $request->input("values.$langId", []);

                // Only validate if values are not empty
                if (!empty($values) && count($values) !== $defaultValuesCount) {
                    $validator->errors()->add(
                        "values.$langId",
                        "The number of values for all languages must be the same as the default language."
                    );
                }
            }
        }
    });

    if ($validator->fails()) {
        ResponseService::validationError($validator->errors()->first());
    }

    try {
        DB::beginTransaction();

        $data = [
            'name'        => $request->input('name')[1],
            'type'        => $request->input('type'),
            'min_length'  => $request->input('min_length'),
            'max_length'  => $request->input('max_length'),
            'is_required' => $request->input('is_required', false),
            'status'      => $request->input('status', true),
        ];

        // Save values for English (default) if needed
        if (in_array($data['type'], ['radio', 'dropdown', 'checkbox'])) {
            $data['values'] = json_encode($request->input('values')[1] ?? []);
        }

        $field = VerificationField::create($data);

        // Store translations
                foreach ($request->input('languages', []) as $langId) {
                    if ($langId != 1) {
                        $translatedName = $request->input("name.$langId");
                        $translatedValues = $request->input("values.$langId", []);

                        if ($translatedName || !empty($translatedValues)) {
                            VerificationFieldsTranslation::create([
                                'verification_field_id' => $field->id,
                                'language_id'           => $langId,
                                'name'                  => $translatedName,
                                'value'                 => json_encode($translatedValues, JSON_THROW_ON_ERROR),
                            ]);
                        }
                    }
                }

                DB::commit();
                ResponseService::successResponse('Seller verification field added successfully');

            } catch (Throwable $th) {
                DB::rollBack();
                ResponseService::logErrorResponse($th);
                ResponseService::errorResponse('Something went wrong');
            }
        }


    public function show(Request $request) {
        try {
            ResponseService::noPermissionThenSendJson('seller-verification-field-list');

            $offset = $request->input('offset', 0);
            $limit = $request->input('limit', 10);
            $sort = $request->input('sort', 'id');
            $order = $request->input('order', 'DESC');

            $query = VerificationRequest::with('user', 'verification_field_values.verification_field');

            if (!empty($request->filter)) {
                $filters = json_decode($request->filter, true, 512, JSON_THROW_ON_ERROR); // Decode as an associative array
                foreach ($filters as $field => $value) {
                    $query->where($field, $value);
                }
            }

            if (!empty($request->search)) {
                $query->where(function ($q) use ($request) {
                    $q->where('status', 'like', '%' . $request->search . '%')
                        ->orWhereHas('user', function ($q) use ($request) {
                            $q->where('name', 'like', '%' . $request->search . '%');
                        });
                });
            }

            $total = $query->count();
            $sql = $query->orderBy('updated_at', 'DESC')->skip($offset)->take($limit);
            $result = $sql->get();
            $no = 1;

            $bulkData = [
                'total' => $total,
                'rows'  => []
            ];

            $verificationFieldValues = VerificationFieldValue::whereIn('verification_request_id', $result->pluck('id'))->get();
            $languages = Language::select('id', 'name')->get();
            foreach ($result as $row) {
               $row->verification_fields = collect($row->verification_fields)->map(function ($verification_field) use ($verificationFieldValues, $row) {
                    // Default (no lang_id = base value)
                    $fieldValue = $verificationFieldValues->first(function ($data) use ($row, $verification_field) {
                        return $data->verification_field_id == $verification_field->id
                            && $data->verification_request_id == $row->id
                            && empty($data->language_id);
                    });

                    $verification_field['default_value'] = $fieldValue ? $fieldValue->value : null;

                    // All translations grouped by language
                    $translations = $verificationFieldValues->where('verification_field_id', $verification_field->id)
                        ->where('verification_request_id', $row->id)
                        ->groupBy('language_id')
                        ->map(function ($items) {
                            return $items->first()->value; // take value per lang
                        });

                    $verification_field['translations'] = $translations;

                    return $verification_field;
                });
                $operate = '';

                if (Auth::user()->can('seller-verification-field-update')) {
                    $operate .= BootstrapTableService::editButton(route('seller_verification.approval', $row->id), true, '#editStatusModal', 'edit-status', $row->id);
                    $operate .= BootstrapTableService::button('fa fa-eye', '#', ['view-verification-fields', 'btn-light-danger  '], ['title' => __("View"), "data-bs-target" => "#editModal", "data-bs-toggle" => "modal",]);
                }
                $tempRow = $row->toArray();
                $tempRow['no'] = $no++;
                $tempRow['operate'] = $operate;
                $tempRow['user_name'] = $row->user->name ?? '';
                $tempRow['languages'] = $languages;
                $bulkData['rows'][] = $tempRow;
            }
            return response()->json($bulkData);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "Controller -> show");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function showVerificationFields(Request $request) {
        try {

            ResponseService::noPermissionThenSendJson('seller-verification-field-list');
            $offset = $request->input('offset', 0);
            $limit = $request->input('limit', 10);
            $sort = $request->input('sort', 'id');
            $order = $request->input('order', 'ASC');

            $sql = VerificationField::orderBy($sort, $order)->withTrashed();

            if (!empty($_GET['search'])) {
                $sql->search($_GET['search']);
//            $sql->where('id', 'LIKE', "%$search%")->orwhere('question', 'LIKE', "%$search%")->orwhere('answer', 'LIKE', "%$search%");

            }
            $total = $sql->count();
            $sql->skip($offset)->take($limit);
            $result = $sql->get();
            $bulkData = array();
            $bulkData['total'] = $total;
            $rows = array();
            foreach ($result as $row) {
                $tempRow = $row->toArray();
                $operate = '';
                if (Auth::user()->can('seller-verification-field-update')) {
                    $operate .= BootstrapTableService::editButton(route('seller-verification.verification-field.edit', $row->id));
                }

                if (Auth::user()->can('seller-verification-field-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('seller-verification.verification-field.delete', $row->id));
                }
                $tempRow['operate'] = $operate;
                $rows[] = $tempRow;
            }

            $bulkData['rows'] = $rows;
            return response()->json($bulkData);

        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "UserVerificationController --> show");
            ResponseService::errorResponse();
        }
    }

    public function edit($id) {
        ResponseService::noPermissionThenRedirect('seller-verification-field-update');

        $verification_field = VerificationField::withTrashed()
            ->with('translations')
            ->findOrFail($id);

        $languages = CachingService::getLanguages()->values();

        return view('seller-verification.edit', compact('verification_field', 'languages'));
    }

   public function update(Request $request, $id) {
    ResponseService::noPermissionThenSendJson('seller-verification-field-update');
    $defaultValuesCount = count($request->input('values.1', []));
    $validator = Validator::make($request->all(), [
        'name.1'       => 'required',
        'type'         => 'required|in:number,textbox,fileinput,radio,dropdown,checkbox',
        'values.1'     => 'required_if:type,radio,dropdown,checkbox|array',
        'min_length'   => 'nullable|numeric',
         'max_length'    => 'nullable|numeric|gt:min_length',
    ]);

        $validator->after(function ($validator) use ($request, $defaultValuesCount) {
        foreach ($request->input('languages', []) as $langId) {
            if ($langId != 1) {
                $values = $request->input("values.$langId", []);

                // Only validate if values are not empty
                if (!empty($values) && count($values) !== $defaultValuesCount) {
                    $validator->errors()->add(
                        "values.$langId",
                        "The number of values for all languages must be the same as the default language."
                    );
                }
            }
        }
    });

    if ($validator->fails()) {
        ResponseService::validationError($validator->errors()->first());
    }

    try {
        DB::beginTransaction();

        $field = VerificationField::withTrashed()->findOrFail($id);

        $data = [
            'name'        => $request->input('name')[1],
            'type'        => $request->input('type'),
            'min_length'  => $request->input('min_length'),
            'max_length'  => $request->input('max_length'),
            'is_required' => $request->input('is_required', false),
            'status'      => $request->input('status', true),
        ];

        if ($data['status']) {
            $data['deleted_at'] = null;
        } else {
            $data['deleted_at'] = now();
        }

        if (in_array($data['type'], ['radio', 'dropdown', 'checkbox'])) {
            $data['values'] = json_encode($request->input('values')[1] ?? []);
        }

        $field->update($data);

        // Save Translations
        VerificationFieldsTranslation::where('verification_field_id', $id)->delete();

        foreach ($request->input('languages', []) as $langId) {
            if ($langId != 1) {
                $translatedName = $request->input("name.$langId");
                $translatedValues = $request->input("values.$langId", []);

                if ($translatedName || !empty($translatedValues)) {
                    VerificationFieldsTranslation::create([
                        'verification_field_id' => $id,
                        'language_id'           => $langId,
                        'name'                  => $translatedName,
                        'value'                 => json_encode($translatedValues, JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        }

        DB::commit();
        ResponseService::successResponse('Verification Field Updated Successfully');
    } catch (Throwable $th) {
        DB::rollBack();
        ResponseService::logErrorResponse($th);
        ResponseService::errorResponse('Something went wrong');
    }
}


    public function destroy($id) {
        try {
            ResponseService::noPermissionThenSendJson('seller-verification-field-delete');
            VerificationField::withTrashed()->find($id)->forceDelete();
            ResponseService::successResponse('seller Verification delete successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Seller Verification Controller -> destroy");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function getSellerVerificationValues(Request $request, $id) {
        ResponseService::noPermissionThenSendJson('seller-verification-field-update');
        $values = VerificationField::where('id', $id)->withTrashed()->first()->values;

        if (!empty($request->search)) {
            $matchingElements = [];
            foreach ($values as $element) {
                $stringElement = (string)$element;

                // Check if the search term is present in the element
                if (str_contains($stringElement, $request->search)) {
                    // If found, add it to the matching elements array
                    $matchingElements[] = $element;
                }
            }
            $values = $matchingElements;
        }


        $bulkData = array();
        $bulkData['total'] = count($values);
        $rows = array();
        foreach ($values as $key => $row) {
            $tempRow['id'] = $key;
            $tempRow['value'] = $row;
            // if (Auth::user()->can('faq-update')) {
            //     $operate .= BootstrapTableService::editButton(route('faq.update', $row->id), true, '#editModal', 'faqEvents', $row->id);
            // }
            $tempRow['operate'] = BootstrapTableService::button('fa fa-edit',route('seller-verification.value.update', $id), ['edit_btn'],["title"=>"Edit", "data-bs-target" => '#editModal', "data-bs-toggle" => "modal"]);
            $tempRow['operate'] .= BootstrapTableService::deleteButton(route('seller-verification.value.delete', [$id, $row]), true);
            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;


        return response()->json($bulkData);
    }

    public function addSellerVerificationValue(Request $request, $id) {
        ResponseService::noPermissionThenSendJson('seller-verification-field-create');
        $validator = Validator::make($request->all(), [
            'values' => 'required',
        ]);

        if ($validator->fails()) {
            ResponseService::errorResponse($validator->errors()->first());
        }
        try {
            $verification_field = VerificationField::findOrFail($id);
            $newValues = explode(',', $request->values);
            $values = [
                ...$verification_field->values,
                ...$newValues,
            ];

            $verification_field->values = json_encode($values, JSON_THROW_ON_ERROR);
            $verification_field->save();
            ResponseService::successResponse('Seller Verification Value added Successfully');
        } catch (Throwable) {
            ResponseService::errorResponse('Something Went Wrong ');
        }
    }

    public function updateSellerVerificationValue(Request $request, $id) {
        ResponseService::noPermissionThenSendJson('seller-verification-field-update');
        $validator = Validator::make($request->all(), [
            'old_verification_field_value' => 'required',
            'new_verification_field_value' => 'required',
        ]);

        if ($validator->fails()) {
            ResponseService::errorResponse($validator->errors()->first());
        }
        try {
            $verification_field = VerificationField::where('id', $id)->withTrashed()->first();
            $values = $verification_field->values;
            if (is_array($values)) {
                $values[array_search($request->old_verification_field_value, $values, true)] = $request->new_verification_field_value;
            } else {
                $values = $request->new_verification_field_value;
            }
            $verification_field->values = $values;
            $verification_field->save();
            ResponseService::successResponse('Verification Field Value Updated Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'UserVerificationController -> updateSellerVerificationValue');
            ResponseService::errorResponse('Something Went Wrong ');
        }
    }

    public function deleteSellerVerificationValue($id, $deletedValue) {
        try {
            ResponseService::noPermissionThenSendJson('seller-verification-field-delete');
            $verification_field = VerificationField::where('id', $id)->withTrashed()->first();
            $values = $verification_field->values;
            unset($values[array_search($deletedValue, $values, true)]);
            $verification_field->values = json_encode($values, JSON_THROW_ON_ERROR);
            $verification_field->save();
            ResponseService::successResponse('Seller Verification Value Deleted Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function updateSellerApproval(Request $request, $id) {
        try {
            ResponseService::noPermissionThenSendJson('seller-verification-request-update');
            $verification_field = VerificationRequest::with('user')->findOrFail($id);
            $newStatus = $request->input('status');
            $rejectionReason = $request->input('rejection_reason'); // Get the rejection reason from the request
            if ($newStatus === 'rejected' && empty($rejectionReason)) {
                ResponseService::validationError('Rejection reason is required when status is rejected.');
            }
            $verification_field->update([
                'status'           => $newStatus,
                'rejection_reason' => $newStatus === 'rejected' ? $rejectionReason : null, // Set the reason if rejected
            ]);

            $verification_field->user->update([
                'is_verified' => $newStatus === 'approved' ? 1 : 0,
                'auto_approve_item' => $newStatus === 'approved' ? 1 : 0,
            ]);

            $user_token = UserFcmToken::where('user_id', $verification_field->user->id)->pluck('fcm_token')->toArray();
            if (!empty($user_token)) {
                NotificationService::sendFcmNotification($user_token, 'About ' , "Your Verfication Request is " . ucfirst($request->status), "verifcation-request-update", ['id' => $id,]);
            }
            ResponseService::successResponse('Seller status updated successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'UserVerificationController -> updateSellerApproval');
            ResponseService::errorResponse('Something went wrong');
        }
    }


    /* NOTE : Why this simple code is done using chatgpt ? */
    public function getVerificationDetails($id) {
        $verificationFieldValues = VerificationFieldValue::with('verificationField')->where('verification_request_id', $id)->get();
        if ($verificationFieldValues->isEmpty()) {
            return response()->json(['error' => 'No details found.'], 404);
        }

        $fieldValues = $verificationFieldValues->map(function ($fieldValue) {
            return [
                'name'  => $fieldValue->verificationField->name ?? 'N/A',
                'value' => $fieldValue->value ?? 'No value provided',
            ];
        });

        return response()->json([
            'verification_field_values' => $fieldValues,
        ]);
    }
}
