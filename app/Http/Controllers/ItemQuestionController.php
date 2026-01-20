<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ItemQuestion;
use App\Models\ItemQuestionLike;
use App\Models\ItemQuestionReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ItemQuestionController extends Controller
{
    public function getQuestions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'required|exists:items,id',
            'page' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $itemId = $request->item_id;
        $userId = Auth::id();

        $questions = ItemQuestion::where('item_id', $itemId)
            ->where('is_hidden', false)
            ->with(['user:id,name,profile,is_verified'])
            ->orderByDesc('created_at')
            ->paginate(10);

        if ($userId) {
            $likedQuestionIds = ItemQuestionLike::where('user_id', $userId)
                ->whereIn('question_id', $questions->pluck('id'))
                ->pluck('question_id')
                ->toArray();

            $questions->getCollection()->transform(function ($question) use ($likedQuestionIds) {
                $question->is_liked = in_array($question->id, $likedQuestionIds);
                return $question;
            });
        } else {
            $questions->getCollection()->transform(function ($question) {
                $question->is_liked = false;
                return $question;
            });
        }

        return response()->json([
            'error' => false,
            'message' => 'Pitanja uspješno dohvaćena',
            'data' => $questions,
        ]);
    }

    public function addQuestion(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'required|exists:items,id',
            'question' => 'required|string|min:10|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $userId = Auth::id();
        $itemId = $request->item_id;

        $item = Item::find($itemId);
        if ($item->user_id === $userId) {
            return response()->json([
                'error' => true,
                'message' => 'Ne možete postavljati pitanja na vlastiti oglas',
            ], 422);
        }

        $question = ItemQuestion::create([
            'item_id' => $itemId,
            'user_id' => $userId,
            'question' => $request->question,
        ]);

        $question->load('user:id,name,profile,is_verified');

        return response()->json([
            'error' => false,
            'message' => 'Pitanje je uspješno postavljeno',
            'data' => $question,
        ]);
    }

    public function answerQuestion(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'question_id' => 'required|exists:item_questions,id',
            'answer' => 'required|string|min:5|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $userId = Auth::id();
        $question = ItemQuestion::with('item')->find($request->question_id);

        if ($question->item->user_id !== $userId) {
            return response()->json([
                'error' => true,
                'message' => 'Samo vlasnik oglasa može odgovoriti na pitanja',
            ], 403);
        }

        $question->update([
            'answer' => $request->answer,
            'answered_at' => now(),
            'answered_by' => $userId,
        ]);

        return response()->json([
            'error' => false,
            'message' => 'Odgovor je uspješno poslat',
            'data' => $question,
        ]);
    }

    public function likeQuestion(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'question_id' => 'required|exists:item_questions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $userId = Auth::id();
        $questionId = $request->question_id;

        $existingLike = ItemQuestionLike::where('question_id', $questionId)
            ->where('user_id', $userId)
            ->first();

        if ($existingLike) {
            $existingLike->delete();
            ItemQuestion::where('id', $questionId)->decrement('likes_count');
            $liked = false;
        } else {
            ItemQuestionLike::create([
                'question_id' => $questionId,
                'user_id' => $userId,
            ]);
            ItemQuestion::where('id', $questionId)->increment('likes_count');
            $liked = true;
        }

        $question = ItemQuestion::find($questionId);

        return response()->json([
            'error' => false,
            'message' => $liked ? 'Pitanje lajkovano' : 'Lajk uklonjen',
            'data' => [
                'is_liked' => $liked,
                'likes_count' => $question->likes_count,
            ],
        ]);
    }

    public function deleteQuestion(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'question_id' => 'required|exists:item_questions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $userId = Auth::id();
        $question = ItemQuestion::find($request->question_id);

        if ($question->user_id !== $userId) {
            return response()->json([
                'error' => true,
                'message' => 'Samo autor može obrisati pitanje',
            ], 403);
        }

        $question->delete();

        return response()->json([
            'error' => false,
            'message' => 'Pitanje je obrisano',
        ]);
    }

    public function reportQuestion(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'question_id' => 'required|exists:item_questions,id',
            'reason' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $userId = Auth::id();
        $questionId = $request->question_id;

        $existingReport = ItemQuestionReport::where('question_id', $questionId)
            ->where('user_id', $userId)
            ->first();

        if ($existingReport) {
            return response()->json([
                'error' => true,
                'message' => 'Već ste prijavili ovo pitanje',
            ], 422);
        }

        ItemQuestionReport::create([
            'question_id' => $questionId,
            'user_id' => $userId,
            'reason' => $request->reason,
        ]);

        $reportCount = ItemQuestionReport::where('question_id', $questionId)->count();
        if ($reportCount >= 3) {
            ItemQuestion::where('id', $questionId)->update(['is_hidden' => true]);
        }

        return response()->json([
            'error' => false,
            'message' => 'Pitanje je prijavljeno',
        ]);
    }
}
