<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\AreaTranslation;
use App\Models\City;
use App\Models\CityTranslation;
use App\Models\Country;
use App\Models\CountryTranslation;
use App\Models\Language;
use App\Models\State;
use App\Models\StateTranslation;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\ResponseService;
use Cerbero\JsonParser\JsonParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PlaceController extends Controller {
    public function countryIndex() {
        ResponseService::noAnyPermissionThenRedirect(['country-list', 'country-create', 'country-update', 'country-delete']);
        $countries = JsonParser::parse(resource_path('countries.json'))->pointers(['/-/name', '/-/id', '/-/emoji'])->toArray();
        $dbCountries = Country::select('name')->get();
        foreach ($countries as $key => $country) {
            $countries[$key]['is_already_exists'] = $dbCountries->contains(static function ($dbCountry) use ($country) {
                return $country['name'] == $dbCountry->name;
            });
        }
        return view('places.country', compact('countries'));
    }

    public function countryShow(Request $request) {
        try {
            ResponseService::noPermissionThenSendJson('country-list');
            $offset = $request->input('offset', 0);
            $limit = $request->input('limit', 15);
            $sort = $request->input('sort', 'id');
            $order = $request->input('order', 'DESC');

            $sql = Country::select(['id', 'name', 'emoji']);

            if (!empty($request->search)) {
                $sql = $sql->search($request->search);
            }
            $total = $sql->count();
            $sql = $sql->orderBy($sort, $order)->skip($offset)->take($limit);
            $result = $sql->get();
            $bulkData = array();
            $bulkData['total'] = $total;
            $rows = array();
            foreach ($result as $key => $row) {
                $tempRow = $row->toArray();
                if (auth()->user()->can('country-delete')) {
                    $tempRow['operate'] = BootstrapTableService::deleteButton(route('countries.destroy', $row->id));
                }

                $rows[] = $tempRow;
            }
            $bulkData['rows'] = $rows;


            return response()->json($bulkData);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "CustomFieldController -> show");
            ResponseService::errorResponse('Something Went Wrong');
        }

    }

    public function destroyCountry($id) {
        try {
            Country::find($id)->delete();
            ResponseService::successResponse("Country deleted Successfully");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "PlaceController -> destroyCountry");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function stateSearch(Request $request) {
        try {
            ResponseService::noPermissionThenRedirect('state-list');
            $states = State::where('country_id', $request->country_id)->select(['id', 'name'])->orderBy('name', 'ASC')->get();
            ResponseService::successResponse("States Fetched Successfully", $states);
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, "PlaceController -> stateSearch");
            ResponseService::errorResponse();
        }
    }

    public function stateIndex() {
        ResponseService::noAnyPermissionThenRedirect(['state-list', 'state-create', 'state-update', 'state-delete']);
        $countries = Country::get();
        return view('places.state', compact('countries'));
    }

    public function stateShow(Request $request) {
        try {
            ResponseService::noPermissionThenSendJson('state-list');
            $offset = $request->input('offset', 0);
            $limit = $request->input('limit', 15);
            $sort = $request->input('sort', 'id');
            $order = $request->input('order', 'DESC');

            $sql = State::with('country:id,name,emoji');

            if (!empty($request->filter)) {
                $sql = $sql->filter(json_decode($request->filter, false, 512, JSON_THROW_ON_ERROR));
            }

            if (!empty($request->search)) {
                $sql = $sql->search($request->search);
            }
            $total = $sql->count();
            $sql = $sql->sort($sort, $order)->skip($offset)->take($limit);
            $result = $sql->get();
            $bulkData = array();
            $bulkData['total'] = $total;
            $rows = array();
            foreach ($result as $key => $row) {
                $tempRow = $row->toArray();
                $tempRow['country_name'] = $row->country->name;
                $rows[] = $tempRow;
            }

            $bulkData['rows'] = $rows;
            return response()->json($bulkData);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "CustomFieldController -> show");
            ResponseService::errorResponse('Something Went Wrong');
        }

    }

    public function citySearch(Request $request) {
        try {
            ResponseService::noPermissionThenRedirect('city-list');
            $cities = City::where('state_id', $request->state_id)->select(['id', 'name'])->orderBy('name', 'ASC')->get();
            ResponseService::successResponse("Cities fetched Successfully", $cities);
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, "PlaceController -> citySearch");
            ResponseService::errorResponse();
        }

    }

    public function cityIndex() {
        ResponseService::noAnyPermissionThenRedirect(['city-list', 'city-create', 'city-update', 'city-delete']);
        $countries = Country::get();
        $states = State::get();
        return view('places.city', compact('countries', 'states'));
    }
     public function addCity(Request $request) {
        ResponseService::noPermissionThenRedirect('city-create');

        $validator = Validator::make($request->all(), [
            'name.*'     => 'required|string',
            'latitude.*' => 'nullable|numeric',
            'longitude.*' => 'nullable|numeric',
            'country_id' => 'required|exists:countries,id',
            'state_id'   => 'required|exists:states,id'
        ], [], [
            'name.*' => 'City name',
            'latitude.*' => 'Latitude',
            'longitude.*' => 'Longitude',
            'country_id' => 'Country',
            'state_id' => 'State',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
           $state = State::findOrFail($request->state_id);
        $country = Country::findOrFail($request->country_id);

        $cityData = [];

        foreach ($request->name as $index => $name) {
            // Check if city already exists
            $exists = City::where('name', $name)
                ->where('state_id', $request->state_id)
                ->where('country_id', $request->country_id)
                ->exists();

            if ($exists) {
                ResponseService::validationError("City '{$name}' already exists in this state and country.");
            }
                $cityData[] = [
                    'name'         => $name,
                    'state_id'     => $request->state_id,
                    'country_id'   => $request->country_id,
                    'state_code'   => $state->state_code,
                    'country_code' => $country->iso2,
                    'latitude'     => $request->latitude[$index] ?? null,
                    'longitude'    => $request->longitude[$index] ?? null,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ];
            }

            City::insert($cityData);
            ResponseService::successResponse('Cities added successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'message',"The city already exists.");
            ResponseService::errorResponse();
        }
    }


    public function cityShow(Request $request) {
        try {
            ResponseService::noPermissionThenSendJson('city-list');
            $offset = $request->input('offset', 0);
            $limit = $request->input('limit', 15);
            $sort = $request->input('sort', 'id');
            $order = $request->input('order', 'DESC');

            $sql = City::with('state:id,name', 'country:id,name,emoji');


            if (!empty($request->search)) {
                $sql = $sql->search($request->search);
            }
            if (!empty($request->filter)) {
                $sql = $sql->filter(json_decode($request->filter, false, 512, JSON_THROW_ON_ERROR));
            }
            $total = $sql->count();
            $sql = $sql->sort($sort, $order)->skip($offset)->take($limit);
            $result = $sql->get();
            $bulkData = array();
            $bulkData['total'] = $total;
            $rows = array();
            foreach ($result as $key => $row) {
                $tempRow = $row->toArray();
                $operate = '';
                if (Auth::user()->can('city-update')) {
                    $operate .= BootstrapTableService::editButton(route('city.update', $row->id), true, '#editModal', 'cityEvents', $row->id);
                }
                if (Auth::user()->can('city-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('city.destroy', $row->id));
                }
                $tempRow['state_name'] = $row->state->name;
                $tempRow['country_name'] = $row->country->name;
                $tempRow['state_id'] = $row->state->id;
                $tempRow['country_id'] = $row->country->id;
                $tempRow['operate'] = $operate;
                $rows[] = $tempRow;
            }
            $bulkData['rows'] = $rows;


            return response()->json($bulkData);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "PlaceController -> show");
            ResponseService::errorResponse('Something Went Wrong');
        }

    }
    public function updateCity(Request $request, $id) {
        ResponseService::noPermissionThenSendJson('city-update');
        $validator = Validator::make($request->all(), [
            'name' => 'Required',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $city = City::findOrFail($id);
            $data = $request->all();
            $city->update($data);
            ResponseService::successResponse('city updated successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Place Controller -> update");
            ResponseService::errorResponse();
        }
    }

    public function destroyCity(string $id) {
        try {
            ResponseService::noPermissionThenSendJson('city-delete');
            City::findOrFail($id)->delete();
            ResponseService::successResponse('city delete successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Place Controller -> destroy");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }
    public function importCountry(Request $request) {
        ResponseService::noPermissionThenSendJson('country-create');
        $validator = Validator::make($request->all(), [
            'countries'   => 'required|array',
            'countries.*' => 'integer',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $country_id = $request->countries;
            DB::beginTransaction();
            foreach (JsonParser::parse(resource_path('world.json')) as $country) {
                if (in_array($country['id'], $country_id, false)) {
                    Country::create([
                        ...$country,
                        'timezones'    => json_encode($country['timezones'], JSON_THROW_ON_ERROR),
                        'translations' => json_encode($country['translations'], JSON_THROW_ON_ERROR),
                        'region_id'    => null,
                        'subregion_id' => null,
                    ]);


                    foreach ($country['states'] as $state) {
                        State::create([
                            ...$state,
                            'country_id' => $country['id']
                        ]);

                        $cities = [];
                        foreach ($state['cities'] as $city) {
                            $cities[] = [
                                ...$city,
                                'state_id'     => $state['id'],
                                'state_code'   => $state['state_code'],
                                'country_id'   => $country['id'],
                                'country_code' => $country['iso2'],
                            ];
                        }

                        City::upsert($cities,['name','state_id','country_id'],['state_code','country_code','latitude','longitude','flag','wikiDataId']);
                    }

                    /*Stop the JSON file reading if country_id array is empty*/
                    unset($country_id[array_search($country['id'], $country_id, true)]);
                    if (empty($country_id)) {
                        break;
                    }
                }
            }
            DB::commit();
            ResponseService::successResponse("Country imported successfully");
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "CustomFieldController -> show");
            ResponseService::errorResponse('Something Went Wrong');
        }

    }

    public function createArea() {
        ResponseService::noAnyPermissionThenRedirect(['area-list', 'area-create', 'area-update', 'area-delete']);
        $countries = Country::all();
        $states = State::get();
        $cities = city::get();
        return view('places.area', compact('countries','states','cities'));
    }

    public function addArea(Request $request) {
        ResponseService::noPermissionThenRedirect('area-create');
        $validator = Validator::make($request->all(), [
            'name.*'     => 'required|string',
            'country_id' => 'required|exists:countries,id',
            'state_id'   => 'required|exists:states,id',
            'city_id'    => 'required|exists:cities,id',
            'latitude.*' => 'nullable|numeric',
            'longitude.*' => 'nullable|numeric',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $state = State::findOrFail($request->state_id);
            $area = [];
            foreach ($request->name as $index => $name) {
                $area[] = [
                    'name'       => $name,
                    'city_id'    => $request->city_id,
                    'state_id'   => $request->state_id,
                    'country_id' => $request->country_id,
                    'state_code' => $state->state_code,
                    'latitude'   => $request->latitude[$index] ?? null,
                    'longitude'  => $request->longitude[$index] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            Area::insert($area);
            ResponseService::successResponse('Area Added Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "place Controller -> store");
            ResponseService::errorResponse();
        }
    }

    public function areaShow(Request $request) {
        try {
            ResponseService::noPermissionThenSendJson('area-list');
            $offset = $request->input('offset', 0);
            $limit = $request->input('limit', 10);
            $sort = $request->input('sort', 'id');
            $order = $request->input('order', 'ASC');

            $sql = Area::with('city:id,name', 'state:id,name', 'country:id,name')->orderBy($sort, $order);

            if (!empty($_GET['search'])) {
                $search = $_GET['search'];
                $sql->where('id', 'LIKE', "%$search%")
                    ->orwhere('name', 'LIKE', "%$search%")
                    ->orwhere('latitude', 'LIKE', "%$search%")
                    ->orwhere('longitude', 'LIKE', "%$search%");
            }
            if (!empty($request->filter)) {
                $sql = $sql->filter(json_decode($request->filter, false, 512, JSON_THROW_ON_ERROR));
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
                if (Auth::user()->can('area-update')) {
                    $operate .= BootstrapTableService::editButton(route('area.update', $row->id), true, '#editModal', 'areaEvents', $row->id);
                }
                if (Auth::user()->can('area-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('area.destroy', $row->id));
                }
                $tempRow['operate'] = $operate;
                $rows[] = $tempRow;
            }

            $bulkData['rows'] = $rows;
            return response()->json($bulkData);

        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "PlaceController --> show");
            ResponseService::errorResponse();
        }
    }

    public function edit(string $id) {

    }

    public function updateArea(Request $request, $id) {
        ResponseService::noPermissionThenSendJson('area-update');
        $validator = Validator::make($request->all(), [
            'name' => 'Required|string',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $area = Area::findOrFail($id);
            $data = $request->all();
            $area->update($data);
            ResponseService::successResponse('Area updated successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Area Controller -> update");
            ResponseService::errorResponse();
        }
    }

    public function destroyArea(string $id) {
        try {
            ResponseService::noPermissionThenSendJson('area-delete');
            Area::findOrFail($id)->delete();
            ResponseService::successResponse('Area delete successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Place Controller -> destroy");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }
    public function showCountryTranslations(Request $request) {
        $countries = Country::with('nameTranslations')->get();
        $languages = CachingService::getLanguages()->where('code', '!=', 'en')->values();

            return view('places.country_translation', compact('countries', 'languages'));
    }

    public function updateCountriesTranslations(Request $request)
    {
        ResponseService::noPermissionThenSendJson('country-update');

        $validator = Validator::make($request->all(), [
            'translations' => 'required|array',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            foreach ($request->translations as $languageId => $translations) {
                foreach ($translations as $countryId => $translatedName) {
                    if (!empty($translatedName)) {
                        CountryTranslation::updateOrCreate(
                            ['country_id' => $countryId, 'language_id' => $languageId],
                            ['name' => $translatedName]
                        );
                    }
                }
            }

            ResponseService::successResponse('Country translations updated successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Country Controller -> updateTranslations");
            ResponseService::errorResponse();
        }
    }
    public function showStatesTranslations(Request $request) {
        $States = State::with('translations')->get();
        $countries = Country::with('nameTranslations')->get();
        $languages = CachingService::getLanguages()->where('code', '!=', 'en')->values();

        return view('places.state_translation', compact('countries', 'States', 'languages'));
    }


    public function updateStatesTranslations(Request $request)
    {
        ResponseService::noPermissionThenSendJson('country-update');

        $validator = Validator::make($request->all(), [
            'translations' => 'required|array',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            foreach ($request->translations as $languageId => $translations) {
                foreach ($translations as $countryId => $translatedName) {
                    if (!empty($translatedName)) {
                        StateTranslation::updateOrCreate(
                            ['state_id' => $countryId, 'language_id' => $languageId],
                            ['name' => $translatedName]
                        );
                    }
                }
            }

            ResponseService::successResponse('State translations updated successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Country Controller -> updateTranslations");
            ResponseService::errorResponse();
        }
    }

        public function showCitiesTranslations()
    {
        //$cities = City::with('translations')->get();
        $countries = Country::with('states')->get();
        $languages = CachingService::getLanguages()->where('code', '!=', 'en')->values();

        return view('places.city_translation', compact('countries', 'languages'));
    }
    public function loadStateCities(Request $request, $stateId)
    {
        $perPage = $request->input('per_page', 50);
        $state = State::findOrFail($stateId);
        $cities = City::where('state_id', $stateId)
            ->with('translations')
            ->get();

        $languages = CachingService::getLanguages()->where('code', '!=', 'en')->values();

        return view('places.city_translation_tab', compact('state', 'cities', 'languages'));
    }


    public function updateCitiesTranslations(Request $request)
    {
        ResponseService::noPermissionThenSendJson('city-update');

        $validator = Validator::make($request->all(), [
            'translations' => 'required|array',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            foreach ($request->translations as $languageId => $translations) {
                foreach ($translations as $cityId => $translatedName) {
                    if (!empty($translatedName)) {
                        CityTranslation::updateOrCreate(
                            ['city_id' => $cityId, 'language_id' => $languageId],
                            ['name' => $translatedName]
                        );
                    }
                }
            }

                ResponseService::successResponse('City translations updated successfully');
            } catch (Throwable $th) {
                ResponseService::logErrorResponse($th, "City Translation -> update");
                ResponseService::errorResponse();
            }
        }
    public function areaTranslation()
{
    $countries = Country::all();
    return view('places.area_translation', compact('countries'));
}
public function loadCityAreas(Request $request, $cityId)
{
    $city = City::findOrFail($cityId);
    $areas = Area::where('city_id', $cityId)
        ->with('translations')
        ->get();

    $languages = CachingService::getLanguages()->where('code', '!=', 'en')->values();

    return view('places.area_translation_tab', compact('city', 'areas', 'languages'));
}
public function updateAreasTranslations(Request $request)
{
    ResponseService::noPermissionThenSendJson('area-update');

    $validator = Validator::make($request->all(), [
        'translations' => 'required|array',
    ]);

    if ($validator->fails()) {
        ResponseService::validationError($validator->errors()->first());
    }

    try {
        foreach ($request->translations as $languageId => $translations) {
            foreach ($translations as $areaId => $translatedName) {
                if (!empty($translatedName)) {
                    AreaTranslation::updateOrCreate(
                        ['area_id' => $areaId, 'language_id' => $languageId],
                        ['name' => $translatedName]
                    );
                }
            }
        }

        ResponseService::successResponse('Area translations updated successfully');
    } catch (Throwable $th) {
        ResponseService::logErrorResponse($th, "Area Translation -> update");
        ResponseService::errorResponse();
    }
}


}
