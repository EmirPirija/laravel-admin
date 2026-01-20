<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Faq;
use App\Models\FaqTranslation;
use App\Models\Language;
use App\Services\ResponseService;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Throwable;

class FaqController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['faq-create','faq-list','faq-update','faq-delete']);
        $languages = CachingService::getLanguages()->values();
        return view('faq.create',compact('languages'));
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
        ResponseService::noPermissionThenRedirect('faq-create');

        $question = $request->input('question');
        $answer = $request->input('answer');
            $baseRules = [
                "question.1" => 'required|string',
                "answer.1"   => 'required|string',
                'answer.*' => 'nullable|string|required_with:question.*',
            ];
            $messages = [
                "question.1.required" => "Please enter the question in English.",
                "answer.1.required"   => "Please enter the answer in English.",
                "answer.*.required_with" => "The answer field is required when the question is present."
            ];
            $validator = Validator::make($request->all(), $baseRules, $messages);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }
        try {
            // Store main FAQ in English
            $faq = Faq::create([
                'question' => $question[1],
                'answer' => $answer[1],
            ]);

            // Store other language translations
            foreach ($question as $langId => $qText) {
                if ($langId == 1 || empty($qText)) continue;

                $translatedAnswer = $answer[$langId] ?? null;
                //if (!$translatedAnswer) continue;

                $language = Language::find($langId);
                if ($language) {
                    FaqTranslation::create([
                        'faq_id' => $faq->id,
                        'language_id' => $langId,
                        'question' => $qText,
                        'answer' => $translatedAnswer,
                    ]);
                }
            }

            return ResponseService::successResponse('FAQ created successfully');
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, "Faq Controller -> store");
            return ResponseService::errorResponse();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request)
    {
        try {

            ResponseService::noPermissionThenSendJson('faq-list');
            $offset = $request->input('offset', 0);
            $limit = $request->input('limit', 10);
            $sort = $request->input('sort', 'sequence');
            $order = $request->input('order', 'ASC');

            $sql = Faq::with('translations')->orderBy($sort, $order);

        if (!empty($_GET['search'])) {
            $search = $_GET['search'];
            $sql->where('id', 'LIKE', "%$search%")->orwhere('question', 'LIKE', "%$search%")->orwhere('answer', 'LIKE', "%$search%");
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
                if (Auth::user()->can('faq-update')) {
                    $operate .= BootstrapTableService::editButton(route('faq.update', $row->id), true, '#editModal', 'faqEvents', $row->id);
                }

                if (Auth::user()->can('faq-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('faq.destroy', $row->id));
                }
                $tempRow['operate'] = $operate;
                $tempRow['translations'] = $row->translations->map(function ($t) {
                return [
                    'language_id' => $t->language_id,
                    'question' => $t->question,
                    'answer'   =>$t->answer
                ];
                 }) ?? [];
                $rows[] = $tempRow;
            }

            $bulkData['rows'] = $rows;
            return response()->json($bulkData);

        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "FaqController --> show");
            ResponseService::errorResponse();
        }
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
        ResponseService::noPermissionThenSendJson('faq-update');

        $question = $request->input('question');
        $answer = $request->input('answer');

        // Validate English (language_id = 1)
        $validator = Validator::make($request->all(), [
            'question.1' => 'required|string',
            'answer.1' => 'required|string',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $faq = Faq::findOrFail($id);

            // Update default English values
            $faq->update([
                'question' => $question[1],
                'answer' => $answer[1],
            ]);

            // Loop through translations
            foreach ($question as $langId => $qText) {
                if ($langId == 1) continue;

                $translatedAnswer = $answer[$langId] ?? null;

                // Skip empty translations
                if (empty($qText) || empty($translatedAnswer)) {
                    continue;
                }

                $language = Language::find($langId);
                if ($language) {
                    // Check if translation exists
                    $translation = FaqTranslation::where('faq_id', $faq->id)
                        ->where('language_id', $langId)
                        ->first();

                    if ($translation) {
                        // Update existing
                        $translation->update([
                            'question' => $qText,
                            'answer' => $translatedAnswer,
                        ]);
                    } else {
                        // Create new
                        FaqTranslation::create([
                            'faq_id' => $faq->id,
                            'language_id' => $langId,
                            'question' => $qText,
                            'answer' => $translatedAnswer,
                        ]);
                    }
                }
            }

            return ResponseService::successResponse('FAQ updated successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Faq Controller -> update");
            return ResponseService::errorResponse();
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            ResponseService::noPermissionThenSendJson('faq-delete');
            Faq::findOrFail($id)->delete();
            ResponseService::successResponse('FAQ delete successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Faq Controller -> destroy");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

}

