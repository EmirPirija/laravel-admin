<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\CustomField;
use App\Models\CustomFieldCategory;
use App\Models\CustomFieldsTranslation;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\ResponseService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;


class CustomFieldController extends Controller {

    private string $uploadFolder;

    public function __construct() {
        $this->uploadFolder = 'custom-fields';
    }

    public function index() {
        ResponseService::noAnyPermissionThenRedirect(['custom-field-list', 'custom-field-create', 'custom-field-update', 'custom-field-delete']);
        $categories = Category::get();
        return view('custom-fields.index', compact('categories'));
    }

   public function create(Request $request)
    {
        $languages = CachingService::getLanguages()->values();
        ResponseService::noPermissionThenRedirect('custom-field-create');

        $cat_id = $request->id ?? 0;
        $categories = Category::without('translations')
            ->get()
            ->each->setAppends([]);

        $categories = HelperService::buildNestedChildSubcategoryObject($categories);

        $selected_categories = [];
        $selected_all_categories = [];

        return view(
            "custom-fields.create",
            compact('categories', 'cat_id', 'languages', 'selected_categories', 'selected_all_categories')
        );
    }


    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('custom-field-create');

        $languages = CachingService::getLanguages();
        $defaultLangId = 1;
        $otherLanguages = $languages->where('id', '!=', $defaultLangId);

        $baseRules = [
            "name.$defaultLangId" => 'required|string|max:255',
            'type' => 'required|in:number,textbox,fileinput,radio,dropdown,checkbox',
            'image' => 'required|file|mimes:jpg,jpeg,png,svg',
            'required' => 'required|in:0,1',
            'status' => 'required|in:0,1',
            'selected_categories' => 'required|array|min:1',
        ];

        if (in_array($request->type, ['radio', 'dropdown', 'checkbox'])) {
            $baseRules["values.$defaultLangId"] = 'required|array|min:1';
        }

        if (in_array($request->type, ['number', 'textbox'])) {
            $baseRules['min_length'] = 'nullable|integer|min:0';
            $baseRules['max_length'] = 'nullable|integer|min:0|gt:min_length';
        }

        foreach ($otherLanguages as $lang) {
            $langId = $lang->id;

            $baseRules["name.$langId"] = 'required|string|max:255';

            if (in_array($request->type, ['radio', 'dropdown', 'checkbox'])) {
                $defaultValues = $request->input("values.$defaultLangId", []);
                $baseRules["values.$langId"] = 'required|array|size:' . count($defaultValues);
            }
        }

        $messages = [];
        foreach ($otherLanguages as $lang) {
            $langId = $lang->id;
            $langName = $lang->name;

            $messages["name.$langId.required"] = "Please enter the field name for $langName.";

            if (in_array($request->type, ['radio', 'dropdown', 'checkbox'])) {
                $messages["values.$langId.required"] = "Please enter values for $langName.";
                $messages["values.$langId.size"] = "Number of values for $langName must match the English values.";
            }
        }

        $validator = Validator::make($request->all(), $baseRules, $messages);
        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();

            $customFieldData = [
                'name'       => $request->input("name.$defaultLangId"),
                'type'       => $request->type,
                'min_length' => $request->min_length ?? null,
                'max_length' => $request->max_length ?? null,
                'required'   => $request->required,
                'status'     => $request->status,
                'image'      => $request->hasFile('image')
                    ? FileService::compressAndUpload($request->file('image'), $this->uploadFolder)
                    : null,
            ];

            if (in_array($request->type, ['radio', 'dropdown', 'checkbox'])) {
                $customFieldData['values'] = json_encode($request->input("values.$defaultLangId"), JSON_THROW_ON_ERROR);
            }

            $customField = CustomField::create($customFieldData);

            $categoryMappings = collect($request->selected_categories)->map(function ($categoryId) use ($customField) {
                return [
                    'category_id'      => $categoryId,
                    'custom_field_id'  => $customField->id
                ];
            })->toArray();

            CustomFieldCategory::upsert($categoryMappings, ['custom_field_id', 'category_id']);

            foreach ($otherLanguages as $lang) {
                $langId = $lang->id;
                $translatedName = $request->input("name.$langId");
                $translatedValues = $request->input("values.$langId", null);

                CustomFieldsTranslation::create([
                    'custom_field_id' => $customField->id,
                    'language_id'     => $langId,
                    'name'            => $translatedName,
                    'value'           => $translatedValues ? json_encode($translatedValues, JSON_THROW_ON_ERROR) : null,
                ]);
            }

            DB::commit();

            return ResponseService::successResponse('Custom Field Added Successfully');
        } catch (\Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse('Something went wrong while saving the custom field.');
        }
    }



   public function show(Request $request) {
        try {
            ResponseService::noPermissionThenSendJson('custom-field-list');
            $offset = $request->input('offset', 0);
            $limit = $request->input('limit', 15);
            $sort = $request->input('sort', 'id');
            $order = $request->input('order', 'DESC');

            $sql = CustomField::orderBy($sort, $order);
            $sql->with(['categories:id,name,parent_category_id']);
              if (!empty($request->filter)) {
            // Fix escaped JSON if middleware or frontend sent &quot; instead of "
            $filterString = html_entity_decode($request->filter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        
            try {
                $filterData = json_decode($filterString, false, 512, JSON_THROW_ON_ERROR);
                $sql = $sql->filter($filterData);
            } catch (\JsonException $e) {
                return response()->json(['error' => 'Invalid JSON format in filter parameter'], 400);
            }
        }

            if (!empty($request->search)) {
                $sql = $sql->search($request->search);
            }
            $total = $sql->count();
            $result = $sql->skip($offset)->take($limit)->get();
            $result->each(function ($field) {
                $field->categories->each(function ($cat) {
                    $cat->full_path = $cat->full_path; // accessor already generates it
                });
            });
            $bulkData = array();
            $bulkData['total'] = $total;
            $rows = array();

            foreach ($result as $row) {
                $operate = '';
                if (Auth::user()->can('custom-field-update')) {
                    $operate .= BootstrapTableService::editButton(route('custom-fields.edit', $row->id));
                }

                if (Auth::user()->can('custom-field-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('custom-fields.destroy', $row->id));
                }
                $tempRow = $row->toArray();
                $tempRow['operate'] = $operate;
                $tempRow['category_names'] = array_column($row->categories->toArray(), 'full_path');



                $rows[] = $tempRow;
            }
            $bulkData['rows'] = $rows;


            return response()->json($bulkData);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "CustomFieldController -> show");
            ResponseService::errorResponse('Something Went Wrong');
        }

    }


   public function edit($id) {
        ResponseService::noPermissionThenRedirect('custom-field-update');
        $custom_field = CustomField::with('custom_field_category','translations')->findOrFail($id);

        $translations = [];
        // Add English (default) name and values
        $translations[1] = [
            'name'  => $custom_field->name,
            'value' => $custom_field->values,
        ];
        
        // Add other language translations
        foreach ($custom_field->translations as $translation) {
            $translations[$translation->language_id] = [
                'name'  => $translation->name,
                'value' => $translation->value,
            ];
        }
       $selected_categories = $custom_field->custom_field_category->pluck('category_id')->toArray();

        $selected_all_categories = $selected_categories;

        foreach ($selected_categories as $catId) {
            $categoryId = $catId;
            while ($categoryId) {
                $parent = Category::without('translations')->where('id', $categoryId)->value('parent_category_id');
                if ($parent) {
                    $selected_all_categories[] = $parent;
                    $categoryId = $parent;
                } else {
                    $categoryId = null;
                }
            }
        }

        $selected_all_categories = array_unique($selected_all_categories);
         $categories = Category::without('translations')
            ->get()
            ->each->setAppends([]);

        $categories = HelperService::buildNestedChildSubcategoryObject($categories);

        // Get all languages including English
       $languages = CachingService::getLanguages()->values();
        return view('custom-fields.edit', compact('custom_field', 'categories', 'selected_categories','selected_all_categories','languages','translations'));
    }
    public function update(Request $request, $id) {
        ResponseService::noPermissionThenSendJson('custom-field-update');
        
        $languages = CachingService::getLanguages();
        $defaultLangId = 1;
        $otherLanguages = $languages->where('id', '!=', $defaultLangId);

        $baseRules = [
            "name.$defaultLangId" => 'required|string|max:255',
            'type' => 'required|in:number,textbox,fileinput,radio,dropdown,checkbox',
            'image' => 'nullable|file|mimes:jpg,jpeg,png,svg',
            'required' => 'required|in:0,1',
            'status' => 'required|in:0,1',
            'selected_categories' => 'required|array|min:1',
        ];

        if (in_array($request->type, ['radio', 'dropdown', 'checkbox'])) {
            $baseRules["values.$defaultLangId"] = 'required|array|min:1';
        }

        if (in_array($request->type, ['number', 'textbox'])) {
            $baseRules['min_length'] = 'nullable|integer|min:0';
            $baseRules['max_length'] = 'nullable|integer|min:0|gt:min_length';
        }

        foreach ($otherLanguages as $lang) {
            $langId = $lang->id;
            $baseRules["name.$langId"] = 'required|string|max:255';

            if (in_array($request->type, ['radio', 'dropdown', 'checkbox'])) {
                $defaultValues = $request->input("values.$defaultLangId", []);
                $baseRules["values.$langId"] = 'required|array|size:' . count($defaultValues);
            }
        }

        $messages = [];
        foreach ($otherLanguages as $lang) {
            $langId = $lang->id;
            $langName = $lang->name;

            $messages["name.$langId.required"] = "Please enter the field name for $langName.";

            if (in_array($request->type, ['radio', 'dropdown', 'checkbox'])) {
                $messages["values.$langId.required"] = "Please enter values for $langName.";
                $messages["values.$langId.size"] = "Number of values for $langName must match the English values.";
            }
        }

        $validator = Validator::make($request->all(), $baseRules, $messages);
        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $custom_fields = CustomField::with('custom_field_category')->findOrFail($id);
            
            $data = [
                'name'       => $request->input("name.$defaultLangId"),
                'type'       => $request->type,
                'min_length' => in_array($request->type, ['number', 'textbox']) ? ($request->min_length ?? null) : null,
                'max_length' => in_array($request->type, ['number', 'textbox']) ? ($request->max_length ?? null) : null,
                'required'   => $request->required,
                'status'     => $request->status,
            ];

            if ($request->hasFile('image')) {
                $data['image'] = FileService::compressAndReplace($request->file('image'), $this->uploadFolder, $custom_fields->getRawOriginal('image'));
            }

            if (in_array($request->type, ['radio', 'dropdown', 'checkbox'])) {
                $data['values'] = json_encode($request->input("values.$defaultLangId"), JSON_THROW_ON_ERROR);
            } else {
                // Clear values if type doesn't require them
                $data['values'] = null;
            }

            $custom_fields->update($data);

            $old_selected_category = $custom_fields->custom_field_category->pluck('category_id')->toArray();
            $new_selected_category = $request->selected_categories;

            // If category exists in old category array but not in new category array then we need to delete that category
            if ($new_selected_category) {
                foreach (array_diff($old_selected_category, $new_selected_category) as $category_id) {
                    $custom_fields->custom_field_category->first(function ($data) use ($category_id) {
                        return $data->category_id == $category_id;
                    })->delete();
                }

                $newSelectedCategory = [];
                //If category exists in new category array but not existing in old category array then we need to add that category
                foreach (array_diff($new_selected_category, $old_selected_category) as $category_id) {
                    $newSelectedCategory[] = [
                        'category_id'     => $category_id,
                        'custom_field_id' => $id,
                        'created_at'      => time(),
                        'updated_at'      => time(),
                    ];
                }

                if (count($newSelectedCategory) > 0) {
                    CustomFieldCategory::insert($newSelectedCategory);
                }
            }

            $originalValues = $request->input("values.$defaultLangId", []);
            
            foreach ($otherLanguages as $language) {
                $langId = $language->id;
                $langName = $language->name;

                $translatedName = $request->input("name.$langId");
                $translatedValues = $request->input("values.$langId", []);

                if (empty($translatedName)) {
                    DB::rollBack();
                    return ResponseService::validationError("Please enter name for $langName");
                }
                
                if (in_array($request->type, ['dropdown', 'radio', 'checkbox'])) {
                    if (empty($translatedValues)) {
                        DB::rollBack();
                        return ResponseService::validationError("Please enter values for $langName");
                    }

                    if (count($translatedValues) !== count($originalValues)) {
                        DB::rollBack();
                        return ResponseService::validationError("Number of values for $langName must match the base field values.");
                    }
                }

                CustomFieldsTranslation::updateOrCreate(
                    [
                        'custom_field_id' => $custom_fields->id,
                        'language_id'     => $langId,
                    ],
                    [
                        'name'  => $translatedName,
                        'value' => in_array($request->type, ['dropdown', 'radio', 'checkbox']) 
                            ? json_encode($translatedValues, JSON_THROW_ON_ERROR) 
                            : null,
                    ]
                );
            }

            DB::commit();
            ResponseService::successResponse("Custom Fields Updated Successfully");
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th, "CustomField Controller -> update ");
            ResponseService::errorResponse('Something Went Wrong ');
        }
    }

    public function destroy($id) {
        try {
            ResponseService::noPermissionThenSendJson('custom-field-delete');
            CustomField::find($id)->delete();
            ResponseService::successResponse('Custom Field delete successfully');
        } catch (QueryException $th) {
            ResponseService::logErrorResponse($th, "Custom Field Controller -> destroy");
            ResponseService::errorResponse('Cannot delete custom field! Remove associated subcategories first');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Custom Field Controller -> destroy");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function getCustomFieldValues(Request $request, $id) {
        ResponseService::noPermissionThenSendJson('custom-field-update');
        $values = CustomField::findOrFail($id)->values;

        if (!empty($request->search)) {
            $matchingElements = [];
            foreach ($values as $element) {
                // Convert the element to a string for easy searching
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
//            $tempRow['operate'] = BootstrapTableService::editButton(route('custom-fields.value.update', $id), true);
            $tempRow['operate'] = BootstrapTableService::button('fa fa-edit',route('custom-fields.value.update', $id), ['edit_btn'],["title"=>"Edit", "data-bs-target" => '#editModal', "data-bs-toggle" => "modal"]);
            $tempRow['operate'] .= BootstrapTableService::deleteButton(route('custom-fields.value.delete', [$id, $row]), true);
            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;


        return response()->json($bulkData);
    }

    public function addCustomFieldValue(Request $request, $id) {
        ResponseService::noPermissionThenSendJson('custom-field-create');
        $validator = Validator::make($request->all(), [
            'values' => 'required',
        ]);

        if ($validator->fails()) {
            ResponseService::errorResponse($validator->errors()->first());
        }
        try {
            $customField = CustomField::findOrFail($id);
            $newValues = explode(',', $request->values);
            $values = [
                ...$customField->values,
                ...$newValues,
            ];

            $customField->values = json_encode($values, JSON_THROW_ON_ERROR);
            $customField->save();
            ResponseService::successResponse('Custom Field Value added Successfully');
        } catch (Throwable) {
            ResponseService::errorResponse('Something Went Wrong ');
        }
    }

    public function updateCustomFieldValue(Request $request, $id) {
        ResponseService::noPermissionThenSendJson('custom-field-update');
        $validator = Validator::make($request->all(), [
            'old_custom_field_value' => 'required',
            'new_custom_field_value' => 'required',
        ]);

        if ($validator->fails()) {
            ResponseService::errorResponse($validator->errors()->first());
        }
        try {
            $customField = CustomField::findOrFail($id);
            $values = $customField->values;
            if (is_array($values)) {
                $values[array_search($request->old_custom_field_value, $values, true)] = $request->new_custom_field_value;
            } else {
                $values = $request->new_custom_field_value;
            }
            $customField->values = $values;
            $customField->save();
            ResponseService::successResponse('Custom Field Value Updated Successfully');
        } catch (Throwable) {
            ResponseService::errorResponse('Something Went Wrong ');
        }
    }

    public function deleteCustomFieldValue($id, $deletedValue) {
        try {
            ResponseService::noPermissionThenSendJson('custom-field-delete');
            $customField = CustomField::findOrFail($id);
            $values = $customField->values;
            unset($values[array_search($deletedValue, $values, true)]);
            $customField->values = json_encode($values, JSON_THROW_ON_ERROR);
            $customField->save();
            ResponseService::successResponse('Custom Field Value Deleted Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function bulkUpload() {
        ResponseService::noPermissionThenRedirect('custom-field-create');
        return view('custom-fields.bulk-upload');
    }

    public function downloadExample() {
        ResponseService::noPermissionThenRedirect('custom-field-create');
        
        try {
            $filename = 'custom-fields-bulk-upload-example.csv';
            
            // Clear any previous output
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            // Set headers for CSV download
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            header('Pragma: public');
            
            // Open output stream
            $output = fopen('php://output', 'w');
            
            // Add BOM for UTF-8 Excel compatibility (helps Excel recognize UTF-8)
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Headers
            $headers = ['Type', 'Name', 'Image', 'Required', 'Status', 'Min Length', 'Max Length', 'Values', 'Categories'];
            fputcsv($output, $headers);
            
            // Example rows
            $examples = [
                ['number', 'Price', 'custom-fields/example.jpg', '1', '1', '0', '1000000', '', '1,2'],
                ['textbox', 'Description', 'custom-fields/example.jpg', '1', '1', '10', '500', '', '1,2'],
                ['fileinput', 'Document Upload', 'custom-fields/example.jpg', '0', '1', '', '', '', '1,2'],
                ['radio', 'Condition', 'custom-fields/example.jpg', '1', '1', '', '', 'New|Used|Refurbished', '1,2'],
                ['dropdown', 'Size', 'custom-fields/example.jpg', '1', '1', '', '', 'Small|Medium|Large|XLarge', '1,2'],
                ['checkbox', 'Features', 'custom-fields/example.jpg', '0', '1', '', '', 'WiFi|Bluetooth|GPS', '1,2']
            ];
            
            foreach ($examples as $example) {
                fputcsv($output, $example);
            }
            
            fclose($output);
            exit;
            
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, "CustomFieldController -> downloadExample");
            return ResponseService::errorResponse('Error generating CSV file: ' . $th->getMessage());
        }
    }
    public function processBulkUpload(Request $request) {
        ResponseService::noPermissionThenSendJson('custom-field-create');

        // Validation - Accept CSV and Excel files
        $validator = Validator::make($request->all(), [
            'excel_file' => [
                'required',
                'file',
                'max:10240',
                function ($attribute, $value, $fail) {
                    $extension = strtolower($value->getClientOriginalExtension());
                    $mimeType = $value->getMimeType();
                    
                    $allowedExtensions = ['xlsx', 'xls', 'csv'];
                    $allowedMimeTypes = [
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
                        'application/vnd.ms-excel', // .xls
                        'text/csv',
                        'text/plain',
                        'application/csv',
                    ];
                    
                    if (!in_array($extension, $allowedExtensions) && !in_array($mimeType, $allowedMimeTypes)) {
                        $fail('The ' . $attribute . ' must be a CSV or Excel file (CSV, XLSX or XLS format).');
                    }
                }
            ]
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            if (!class_exists(Spreadsheet::class)) {
                return ResponseService::errorResponse('PhpSpreadsheet library is not installed. Please run: composer require phpoffice/phpspreadsheet');
            }

            $file = $request->file('excel_file');
            $extension = strtolower($file->getClientOriginalExtension());
            $filePath = $file->getRealPath();
            
            // Validate file exists and is readable
            if (!file_exists($filePath) || !is_readable($filePath)) {
                return ResponseService::errorResponse('File is not readable. Please try uploading again.');
            }

            $rows = [];
            try {
                // Explicitly set the reader based on file extension
                if ($extension === 'csv') {
                    $reader = new Csv();
                    // Set CSV options
                    $reader->setInputEncoding('UTF-8');
                    $reader->setDelimiter(',');
                    $reader->setEnclosure('"');
                    $reader->setSheetIndex(0);
                } elseif ($extension === 'xlsx') {
                    $reader = new Xlsx();
                } elseif ($extension === 'xls') {
                    $reader = new Xls();
                } else {
                    return ResponseService::errorResponse('Unsupported file format. Only CSV, XLSX and XLS files are supported.');
                }

                // Set reader options
                $reader->setReadDataOnly(false);
                $reader->setReadEmptyCells(true);
                
                // Load the spreadsheet
                $spreadsheet = $reader->load($filePath);
                $worksheet = $spreadsheet->getActiveSheet();
                
                // Get all rows as array
                $rows = $worksheet->toArray(null, true, true, true);
                
                // Convert associative array to indexed array
                $rows = array_values($rows);
                
                // Clean up
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);

            } catch (ReaderException $ex) {
                return ResponseService::errorResponse('Error reading file: ' . $ex->getMessage() . '. Please ensure the file is a valid CSV or Excel file.');
            } catch (\Throwable $ex) {
                return ResponseService::errorResponse('Error processing file: ' . $ex->getMessage());
            }
            // Remove header row - better detection
            $headerRemoved = false;
            if (count($rows) > 0) {
                $firstRow = $rows[0];
                
                // Convert associative array to indexed if needed
                if (isset($firstRow['A'])) {
                    $firstRow = [
                        $firstRow['A'] ?? '',
                        $firstRow['B'] ?? '',
                        $firstRow['C'] ?? '',
                        $firstRow['D'] ?? '',
                        $firstRow['E'] ?? '',
                        $firstRow['F'] ?? '',
                        $firstRow['G'] ?? '',
                        $firstRow['H'] ?? '',
                        $firstRow['I'] ?? ''
                    ];
                }
                
                // Check if first row looks like headers
                // If first cell is "Type" (case-insensitive), it's likely a header
                $firstCell = isset($firstRow[0]) ? strtolower(trim((string)$firstRow[0])) : '';
                
                if ($firstCell === 'type') {
                    array_shift($rows);
                    $headerRemoved = true;
                }
            }

            if (count($rows) < 1) {
                return ResponseService::errorResponse('File must contain at least one data row (excluding header)');
            }

            $languages = CachingService::getLanguages();
            $defaultLangId = 1;
            $otherLanguages = $languages->where('id', '!=', $defaultLangId);
            $successCount = 0;
            $errorCount = 0;
            $errors = [];
            DB::beginTransaction();
            foreach ($rows as $rowIndex => $row) {
                // Calculate row number: +1 for 1-based, +1 if header was removed
                $rowNumber = $rowIndex + 1 + ($headerRemoved ? 1 : 0);

                try {
                    // Convert associative array to indexed if needed
                    if (isset($row['A'])) {
                        $row = [
                            $row['A'] ?? '',
                            $row['B'] ?? '',
                            $row['C'] ?? '',
                            $row['D'] ?? '',
                            $row['E'] ?? '',
                            $row['F'] ?? '',
                            $row['G'] ?? '',
                            $row['H'] ?? '',
                            $row['I'] ?? ''
                        ];
                    }
                    
                    // Ensure row is an array and has at least 9 elements
                    if (!is_array($row)) {
                        $row = [];
                    }
                    while (count($row) < 9) {
                        $row[] = '';
                    }
                    
                    // Skip completely empty rows
                    if (empty(array_filter($row, function($val) { 
                        return $val !== null && trim((string)$val) !== ''; 
                    }))) {
                        continue;
                    }
                    
                    $type = trim((string)($row[0] ?? ''));
                    $name = trim((string)($row[1] ?? ''));
                    $image = trim((string)($row[2] ?? ''));
                    $required = trim((string)($row[3] ?? '0'));
                    $status = trim((string)($row[4] ?? '1'));
                    $minLength = !empty($row[5]) ? trim((string)$row[5]) : null;
                    $maxLength = !empty($row[6]) ? trim((string)$row[6]) : null;
                    $values = trim((string)($row[7] ?? ''));
                    $categories = trim((string)($row[8] ?? ''));

                    // Skip header rows that might have been missed
                    // If type is exactly "Type" (case-insensitive), it's a header row
                    $firstCellLower = strtolower($type);
                    if ($firstCellLower === 'type') {
                        continue; // Skip this row, it's a header
                    }

                    // Validation - Check required fields first with specific error messages
                    $missingFields = [];
                    if (empty($type)) {
                        $missingFields[] = 'Type';
                    }
                    if (empty($name)) {
                        $missingFields[] = 'Name';
                    }
                    if (empty($image)) {
                        $missingFields[] = 'Image';
                    }
                    if (empty($categories)) {
                        $missingFields[] = 'Categories';
                    }
                    
                    if (!empty($missingFields)) {
                        $errors[] = "Row $rowNumber: Missing required field(s): " . implode(', ', $missingFields);
                        $errorCount++;
                        continue;
                    }

                    // Validate type only if it's not empty
                    $validTypes = ['number', 'textbox', 'fileinput', 'radio', 'dropdown', 'checkbox'];
                    if (!in_array($type, $validTypes)) {
                        $errors[] = "Row $rowNumber: Invalid type '$type'. Must be one of: " . implode(', ', $validTypes);
                        $errorCount++;
                        continue;
                    }
                    
                    // Validate required field
                    if (!in_array($required, ['0', '1'])) {
                        $errors[] = "Row $rowNumber: Invalid required value '$required'. Must be 0 (Optional) or 1 (Required)";
                        $errorCount++;
                        continue;
                    }
                    
                    // Validate status field
                    if (!in_array($status, ['0', '1'])) {
                        $errors[] = "Row $rowNumber: Invalid status value '$status'. Must be 0 (Inactive) or 1 (Active)";
                        $errorCount++;
                        continue;
                    }
                    $valuesTypes = ['radio', 'dropdown', 'checkbox'];
                    if (in_array($type, $valuesTypes)) {
                        if (empty($values)) {
                            $errors[] = "Row $rowNumber: Values are required for type '$type'";
                            $errorCount++;
                            continue;
                        }
                    } else {
                        if (!empty($values)) {
                            $errors[] = "Row $rowNumber: Values should be empty for type '$type'";
                            $errorCount++;
                            continue;
                        }
                    }
                    if (in_array($type, ['number', 'textbox'])) {
                        if ($minLength !== null && $minLength !== '' && (!is_numeric($minLength) || $minLength < 0)) {
                            $errors[] = "Row $rowNumber: Min length must be a non-negative number";
                            $errorCount++;
                            continue;
                        }
                        if ($maxLength !== null && $maxLength !== '' && (!is_numeric($maxLength) || $maxLength < 0)) {
                            $errors[] = "Row $rowNumber: Max length must be a non-negative number";
                            $errorCount++;
                            continue;
                        }
                        if ($minLength !== null && $minLength !== '' && $maxLength !== null && $maxLength !== '' && $maxLength <= $minLength) {
                            $errors[] = "Row $rowNumber: Max length must be greater than min length";
                            $errorCount++;
                            continue;
                        }
                    } else {
                        if (($minLength !== null && $minLength !== '') || ($maxLength !== null && $maxLength !== '')) {
                            $errors[] = "Row $rowNumber: Min/Max length should be empty for type '$type'";
                            $errorCount++;
                            continue;
                        }
                    }
                    $categoryIds = array_filter(array_map('trim', explode(',', $categories)));
                    if (empty($categoryIds)) {
                        $errors[] = "Row $rowNumber: At least one category ID is required";
                        $errorCount++;
                        continue;
                    }
                    foreach ($categoryIds as $catId) {
                        if (!is_numeric($catId) || !Category::where('id', $catId)->exists()) {
                            $errors[] = "Row $rowNumber: Invalid category ID: $catId";
                            $errorCount++;
                            continue 2; // Continue outer loop
                        }
                    }

                    // Validate image path exists
                    if (!Storage::disk(config('filesystems.default'))->exists($image)) {
                        $errors[] = "Row $rowNumber: Image path does not exist: $image";
                        $errorCount++;
                        continue;
                    }

                    // Create custom field
                    $customFieldData = [
                        'name' => $name,
                        'type' => $type,
                        'image' => $image,
                        'required' => $required,
                        'status' => $status,
                        'min_length' => ($minLength !== null && $minLength !== '') ? $minLength : null,
                        'max_length' => ($maxLength !== null && $maxLength !== '') ? $maxLength : null,
                    ];

                    if (in_array($type, $valuesTypes) && !empty($values)) {
                        $valuesArray = array_filter(array_map('trim', explode('|', $values)));
                        $customFieldData['values'] = json_encode($valuesArray, JSON_THROW_ON_ERROR);
                    }

                    $customField = CustomField::create($customFieldData);

                    // Create category mappings
                    $categoryMappings = collect($categoryIds)->map(function ($categoryId) use ($customField) {
                        return [
                            'category_id' => $categoryId,
                            'custom_field_id' => $customField->id
                        ];
                    })->toArray();

                    CustomFieldCategory::upsert($categoryMappings, ['custom_field_id', 'category_id']);
                    foreach ($otherLanguages as $lang) {
                        CustomFieldsTranslation::create([
                            'custom_field_id' => $customField->id,
                            'language_id' => $lang->id,
                            'name' => $name,
                            'value' => in_array($type, $valuesTypes) && !empty($values) 
                                ? json_encode(array_filter(array_map('trim', explode('|', $values))), JSON_THROW_ON_ERROR) 
                                : null,
                        ]);
                    }

                    $successCount++;
                } catch (\Throwable $th) {
                    $errors[] = "Row $rowNumber: " . $th->getMessage();
                    $errorCount++;
                    ResponseService::logErrorResponse($th, "CustomFieldController -> processBulkUpload Row $rowNumber");
                }
            }
            if ($errorCount > 0 && $successCount === 0) {
                DB::rollBack();
                $errorMessage = "All rows failed to process. ";
                if (count($errors) <= 10) {
                    $errorMessage .= "Errors: " . implode('; ', $errors);
                } else {
                    $errorMessage .= "First 10 errors: " . implode('; ', array_slice($errors, 0, 10)) . " (and " . (count($errors) - 10) . " more)";
                }
                return ResponseService::errorResponse($errorMessage);
            }
            
            DB::commit();
            
            // Build appropriate success/error message
            if ($successCount > 0 && $errorCount > 0) {
                $message = "Bulk upload partially completed. ";
                $message .= "$successCount row(s) processed successfully. ";
                $message .= "$errorCount row(s) failed. ";
                if (count($errors) <= 5) {
                    $message .= "Errors: " . implode('; ', $errors);
                } else {
                    $message .= "First 5 errors: " . implode('; ', array_slice($errors, 0, 5)) . " (and " . (count($errors) - 5) . " more)";
                }
                return ResponseService::successResponse($message);
            } elseif ($successCount > 0) {
                $message = "Bulk upload completed successfully. $successCount custom field(s) created.";
                return ResponseService::successResponse($message);
            } else {
                return ResponseService::errorResponse('No rows were processed. Please check your file format.');
            }

        } catch (\Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th, "CustomFieldController -> processBulkUpload");
            return ResponseService::errorResponse('Error processing file: ' . $th->getMessage());
        }
    }

    public function uploadGalleryImage(Request $request) {
        ResponseService::noPermissionThenSendJson('custom-field-create');

        $validator = Validator::make($request->all(), [
            'images.*' => 'required|file|mimes:jpg,jpeg,png,svg|max:5120'
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $uploadedImages = [];
            $files = $request->file('images');

            foreach ($files as $file) {
                $path = FileService::compressAndUpload($file, $this->uploadFolder);
                $uploadedImages[] = [
                    'path' => $path,
                    'url' => url(Storage::url($path))
                ];
            }

            return response()->json([
                'status' => 'success',
                'message' => count($uploadedImages) . ' image(s) uploaded successfully',
                'images' => $uploadedImages
            ]);

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, "CustomFieldController -> uploadGalleryImage");
            return ResponseService::errorResponse('Error uploading images: ' . $th->getMessage());
        }
    }

    public function getGalleryImages() {
        ResponseService::noPermissionThenSendJson('custom-field-list');

        try {
            $files = Storage::disk(config('filesystems.default'))->files($this->uploadFolder);
            $images = [];

            foreach ($files as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($extension, ['jpg', 'jpeg', 'png', 'svg', 'gif'])) {
                    $images[] = [
                        'path' => $file,
                        'url' => url(Storage::url($file))
                    ];
                }
            }

            // Sort by modification time, newest first
            usort($images, function($a, $b) {
                $timeA = Storage::disk(config('filesystems.default'))->lastModified($a['path']);
                $timeB = Storage::disk(config('filesystems.default'))->lastModified($b['path']);
                return $timeB <=> $timeA;
            });

            return response()->json([
                'status' => 'success',
                'images' => $images
            ]);

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, "CustomFieldController -> getGalleryImages");
            return ResponseService::errorResponse('Error loading images: ' . $th->getMessage());
        }
    }
    public function downloadInstructionsPdf() {
        ResponseService::noPermissionThenRedirect('custom-field-create');
        
        $pdfPath = public_path('custom-fields-bulk-upload-instructions.pdf');
        
        if (!file_exists($pdfPath)) {
            return redirect()->back()->with('error', 'Instructions PDF not found.');
        }
        
        return response()->download($pdfPath, 'custom-fields-bulk-upload-instructions.pdf');
    }

}
