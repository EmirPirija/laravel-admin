<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\ResponseService;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LanguageController extends Controller
{

    private string $uploadFolder;

    public function __construct()
    {
        $this->uploadFolder = "language";
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'            => 'required',
            'name_in_english' => 'required|regex:/^[\pL\s]+$/u',
            'code'            => 'required|unique:languages,code',
            'rtl'             => 'nullable',
            'image'           => 'required|mimes:jpeg,png,jpg,svg|max:7168',
            'country_code'    => 'nullable'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $data = $request->all();
            $data['rtl'] = $request->rtl == "on";

            if ($request->hasFile('panel_file')) {
                $data['panel_file'] = FileService::uploadLanguageFile($request->file('panel_file'), $request->code);
            }

            if ($request->hasFile('app_file')) {
                $data['app_file'] = FileService::uploadLanguageFile($request->file('app_file'), $request->code . "_app");
            }

            if ($request->hasFile('web_file')) {
                $data['web_file'] = FileService::uploadLanguageFile($request->file('web_file'), $request->code . "_web");
            }

            if ($request->hasFile('image')) {
                $data['image'] = FileService::upload($request->file('image'), $this->uploadFolder);
            }

            Language::create($data);
            CachingService::removeCache(config('constants.CACHE.LANGUAGE'));
            ResponseService::successResponse('Language Successfully Added');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, "Language Controller -> Store");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }
    public function show(Request $request)
    {
        $offset = $request->offset ?? 0;
        $limit = $request->limit ?? 10;
        $sort = $request->sort ?? 'id';
        $order = $request->order ?? 'DESC';

        $sql = Language::orderBy($sort, $order);

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
            $tempRow['rtl_text'] = ($row->rtl == 1) ? "Yes" : "No";
            $operate = '';
            if ($row->code != "en") {
                $operate .= BootstrapTableService::editButton(route('language.update', $row->id), true);
                $operate .= BootstrapTableService::deleteButton(route('language.destroy', $row->id));
            }
            $dropdownItems = [
                [
                    'icon' => '',
                    'url' => route('languageedit', [$row->id, 'type' => 'panel']),
                    'text' =>  trans("Edit Panel Json")
                ],
                [
                    'icon' => '',
                    'url' => route('languageedit', [$row->id, 'type' => 'web']),
                    'text' => trans("Edit Web Json")
                ],
                [
                    'icon' => '',
                    'url' => route('languageedit', [$row->id, 'type' => 'app']),
                    'text' =>  trans("Edit App Json")
                ]
            ];

            $operate .= BootstrapTableService::dropdown('fas fa-ellipsis-v', $dropdownItems);

            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name'            => 'required',
            'name_in_english' => 'required|regex:/^[\pL\s]+$/u',
            'code'            => 'required|unique:languages,code,' . $id,
            'rtl'             => 'required|boolean',
            'app_file'        => 'nullable|mimes:json',
            'panel_file'      => 'nullable|mimes:json',
            'web_file'        => 'nullable|mimes:json',
            'image'           => 'nullable|mimes:jpeg,png,jpg,svg|max:7168',
            'country_code'    => 'nullable'
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $language = Language::findOrFail($id);

            // Only allow fillable attributes, not file objects
            $data = $request->only([
                'name',
                'name_in_english',
                'code',
                'rtl',
                'country_code'
            ]);

            // Handle files separately
            if ($request->hasFile('panel_file')) {
                $data['panel_file'] = FileService::uploadLanguageFile(
                    $request->file('panel_file'),
                    $language->code
                );
            }

            if ($request->hasFile('app_file')) {
                $data['app_file'] = FileService::uploadLanguageFile(
                    $request->file('app_file'),
                    $language->code . "_app"
                );
            }

            if ($request->hasFile('web_file')) {
                $data['web_file'] = FileService::uploadLanguageFile(
                    $request->file('web_file'),
                    $language->code . "_web"
                );
            }

            if ($request->hasFile('image')) {
                $data['image'] = FileService::replace(
                    $request->file('image'),
                    $this->uploadFolder,
                    $language->getRawOriginal('image')
                );
            }
            // Save everything
            $language->update($data);

            CachingService::removeCache(config('constants.CACHE.LANGUAGE'));
            return ResponseService::successResponse('Language Updated successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Language Controller --> update");
            return ResponseService::errorResponse('Something Went Wrong');
        }
    }


    public function destroy($id)
    {
        try {
            // if (!has_permissions('delete', 'property')) {
            //    return redirect()->back()->with('error', PERMISSION_ERROR_MSG);
            //}
            $language = Language::findOrFail($id);
            $setting = \DB::table('settings')->where('name', 'default_language')->first();
            if ($language->code === $setting->value) {
                ResponseService::errorResponse('You can not delete default language');
            }

            $language->delete();

            FileService::deleteLanguageFile($language->app_file);
            FileService::deleteLanguageFile($language->panel_file);
            FileService::deleteLanguageFile($language->web_file);
            FileService::delete($language->getRawOriginal('image'));
            CachingService::removeCache(config('constants.CACHE.LANGUAGE'));
            ResponseService::successResponse('Language Deleted successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, "Language Controller --> Destroy");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function setLanguage($languageCode) {
        $language = Language::where('code', $languageCode)->first();
        if (!empty($language)) {
            Session::put('locale', $language->code);
            Session::put('language', (object)$language->toArray());
            Session::save();
            app()->setLocale($language->code);
        }
        return redirect()->back();
    }
    public function setDefaultLanguage(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-update');
        $request->validate([
            'default_language' => 'required|exists:languages,code',
        ]);

        $languageCode = $request->default_language;

        // Update settings table
        $setting = \DB::table('settings')->where('name', 'default_language')->first();
        if ($setting) {
            \DB::table('settings')->where('name', 'default_language')->update([
                'value' => $languageCode
            ]);
        } else {
            \DB::table('settings')->insert([
                'name' => 'default_language',
                'value' => $languageCode,
                'type' => 'string'
            ]);
        }

        return redirect()->back()->with('success', __('Default language updated successfully.'));
    }

    public function editlanguage(Request $request, $id, $type)
    {
        $language = Language::findOrFail($id);
        $languageCode = $language->code ?? 'en';
        //        $name = $type;

        if ($type == 'panel') {
            $fileName = $language->panel_file ?: "{$languageCode}.json";
            $defaultFile = base_path('resources/lang/en.json');
        } elseif ($type == 'web') {
            $fileName = $language->web_file ?: "{$languageCode}_web.json";
            $defaultFile = base_path('resources/lang/en_web.json');
        } elseif ($type == 'app') {
            $fileName = $language->app_file ?: "{$languageCode}_app.json";
            $defaultFile = base_path('resources/lang/en_app.json');
        } else {
            $fileName = 'en.json';
            $defaultFile = base_path('resources/lang/en.json');
        }

        $jsonFile = base_path("resources/lang/{$fileName}");

        if (!File::exists($jsonFile)) {
            if (File::exists($defaultFile)) {
                $defaultContent = File::get($defaultFile);
            } else {
                $defaultContent = json_encode([]);
            }

            File::put($jsonFile, $defaultContent);

            if ($type == 'panel') {
                $language->panel_file = $fileName;
            } elseif ($type == 'web') {
                $language->web_file = $fileName;
            } elseif ($type == 'app') {
                $language->app_file = $fileName;
            }
            $language->save();
        }

        $jsonContent = File::get($jsonFile);

        $enContent = File::exists($defaultFile) ? json_decode(File::get($defaultFile), true) : [];
        $targetContent = File::exists($jsonFile) ? json_decode(File::get($jsonFile), true) : [];

        foreach ($enContent as $key => $value) {
            if (!array_key_exists($key, $targetContent)) {
                $targetContent[$key] = $value;
            }
        }
        File::put($jsonFile, json_encode($targetContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $enLabels = json_decode($jsonContent, true);

        return view('settings.languageedit', compact('enLabels', 'language', 'type'));
    }

    public function updatelanguage(Request $request, $id, $type)
    {
        $language = Language::findOrFail($id);

        if ($type == 'panel') {
            $jsonFile = base_path('resources/lang/' . $language->panel_file);
        } elseif ($type == 'web') {
            $jsonFile = base_path('resources/lang/' . $language->web_file);
        } elseif ($type == 'app') {
            $jsonFile = base_path('resources/lang/' . $language->app_file);
        } else {
            $jsonFile = base_path('resources/lang/en.json');
        }


        $directory = dirname($jsonFile);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }


        if (!File::exists($jsonFile)) {
            $defaultContent = [];
            File::put($jsonFile, json_encode($defaultContent, JSON_PRETTY_PRINT));
        }
        $jsonContent = File::get($jsonFile);
        $enLabels = json_decode($jsonContent, true);

        $updatedLabels = $request->input('values');
        $keys = array_keys($enLabels);
        foreach ($keys as $index => $key) {
            if (isset($updatedLabels[$index])) {
                $enLabels[$key] = $updatedLabels[$index];
            }
        }
        File::put($jsonFile, json_encode($enLabels, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        ResponseService::successResponse('Json File updated successfully');
    }
    public function downloadJson($id, $type)
    {
        $language = Language::findOrFail($id);
        $languageCode = $language->code ?? 'en';

        switch ($type) {
            case 'panel':
                $fileName = $language->panel_file ?: "{$languageCode}.json";
                $defaultFile = base_path('resources/lang/en.json');
                break;
            case 'web':
                $fileName = $language->web_file ?: "{$languageCode}_web.json";
                $defaultFile = base_path('resources/lang/en_web.json');
                break;
            case 'app':
                $fileName = $language->app_file ?: "{$languageCode}_app.json";
                $defaultFile = base_path('resources/lang/en_app.json');
                break;
            default:
                abort(404);
        }

        $jsonFile = base_path("resources/lang/{$fileName}");

        // If file does not exist, create it from default
        if (!File::exists($jsonFile)) {
            $defaultContent = File::exists($defaultFile)
                ? File::get($defaultFile)
                : json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            File::put($jsonFile, $defaultContent);

            // Save the file path in DB if not already set
            if ($type == 'panel') $language->panel_file = $fileName;
            elseif ($type == 'web') $language->web_file = $fileName;
            elseif ($type == 'app') $language->app_file = $fileName;
            $language->save();
        }

        if (!File::exists($jsonFile)) {
            abort(404, 'File not found');
        }

        return response()->download($jsonFile);
    }
}
