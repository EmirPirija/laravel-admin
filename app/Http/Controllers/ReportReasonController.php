<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Language;
use App\Models\ReportReason;
use App\Models\ReportReasonTranslation;
use App\Models\User;
use App\Models\UserReports;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ReportReasonController extends Controller {

    public function index() {
        ResponseService::noAnyPermissionThenRedirect(['report-reason-list', 'report-reason-create', 'report-reason-update', 'report-reason-delete']);
        $languages = CachingService::getLanguages()->values();
        return view('reports.index',compact('languages'));
    }

    public function store(Request $request) {
        ResponseService::noPermissionThenSendJson('report-reason-create');
        $validator = Validator::make($request->all(), [
            'reason.1' => 'required|string'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            $reasons = $request->input('reason');

                $englishReason = $reasons['1'] ?? null;
                if (!$englishReason) {
                    ResponseService::validationError('English reason is required.');
                }

                $reportReason = ReportReason::create([
                    'reason' => $englishReason
                ]);

                foreach ($reasons as $langId => $reasonText) {

                    if ($langId === '1' || empty($reasonText)) continue;

                    $language = Language::where('id', $langId)->first();

                    if ($language) {
                        ReportReasonTranslation::Create(
                            [
                                'report_reason_id' => $reportReason->id,
                                'language_id' => $language->id,
                                'reason' => $reasonText
                            ],
                        );
                    }
                }

            ResponseService::successResponse('Reason Successfully Added');

        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "ReportReason Controller -> store");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function show(Request $request) {
        ResponseService::noPermissionThenSendJson('report-reason-list');
        $offset = $request->offset ?? 0;
        $limit = $request->limit ?? 10;
        $sort = $request->sort ?? 'id';
        $order = $request->order ?? 'DESC';
        $sql = ReportReason::with('translations')->orderBy($sort, $order);
        if (!empty($request->search)) {
            $sql = $sql->search($request->search);
        }

        $total = $sql->count();
        $sql->skip($offset)->take($limit);
        $result = $sql->get();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;
        foreach ($result as $key => $row) {
            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $operate = '';
            if (Auth::user()->can('report-reason-update')) {
                $operate .= BootstrapTableService::editButton(route('report-reasons.update', $row->id), true,'#editModal', 'reportReasonEvents', $row->id);
            }

            if (Auth::user()->can('report-reason-delete')) {
                $operate .= BootstrapTableService::deleteButton(route('report-reasons.destroy', $row->id));
            }
            $tempRow['operate'] = $operate;
           $tempRow['translations'] = $row->translations->map(function ($t) {
                return [
                    'language_id' => $t->language_id,
                    'reason' => $t->reason,
                ];
            }) ?? [];


            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

   public function update(Request $request, $id) {
    try {
        ResponseService::noPermissionThenSendJson('report-reason-update');

        $reasons = $request->input('reason');

        $englishReason = $reasons['1'] ?? null;
        if (!$englishReason) {
            ResponseService::validationError('English reason is required.');
        }

        $reportReason = ReportReason::findOrFail($id);
        $reportReason->update([
            'reason' => $englishReason
        ]);

        foreach ($reasons as $langId => $reasonText) {
            if ($langId == '1') continue;

            if (!empty($reasonText)) {
                ReportReasonTranslation::updateOrCreate(
                    ['report_reason_id' => $id, 'language_id' => $langId],
                    ['reason' => $reasonText]
                );
            }
        }

        ResponseService::successResponse('Reason Successfully Updated');

    } catch (Throwable $th) {
        ResponseService::logErrorResponse($th, "ReportReason Controller -> update");
        ResponseService::errorResponse('Something Went Wrong');
    }
}


    public function destroy($id)
{
    try {
        ResponseService::noPermissionThenSendJson('report-reason-delete');

        $reportReason = ReportReason::findOrFail($id);

        // ✅ Check if any user report uses this reason
        $isUsed = UserReports::where('report_reason_id', $id)->exists();

        if ($isUsed) {
            return ResponseService::errorResponse(
                __('This reason is associated with existing user reports. Please remove those reports before deleting.'),
                422 // Unprocessable Entity
            );
        }

        // ✅ If not used, safe to delete
        $reportReason->delete();

        return ResponseService::successResponse(__('Reason Deleted Successfully'));
    } catch (Throwable $e) {
        ResponseService::logErrorResponse($e, "ReportReason Controller -> destroy");
        return ResponseService::errorResponse(__('Something Went Wrong'));
    }
}
    public function usersReports() {
        ResponseService::noPermissionThenRedirect('user-reports-list');
        $users = User::select(["id", "name"])->has('user_reports')->get();
        $items = Item::select(["id", "name","image"])->approved()->has('user_reports')->get();
        return view('reports.user_reports', compact('users', 'items'));
    }

    public function userReportsShow(Request $request) {
        try {
            ResponseService::noPermissionThenRedirect('user-reports-list');
            $offset = $request->offset ?? 0;
            $limit = $request->limit ?? 10;
            $sort = $request->sort ?? 'id';
            $order = $request->order ?? 'DESC';
            $sql = UserReports::with(['user' => fn($q) => $q->select(['id', 'name', 'deleted_at'])->withTrashed(),
             'report_reason:id,reason',
             'item' => fn($q) => $q->select(['id', 'name', 'deleted_at','user_id','image'])
             ->withTrashed()
             ->with(['user' => fn($q) => $q->select(['id', 'name', 'deleted_at'])->withTrashed()])])->sort($sort, $order);

            if (!empty($request->search)) {
                $sql = $sql->search($request->search);
            }

            if (!empty($request->filter)) {
                $sql = $sql->filter(json_decode($request->filter, false, 512, JSON_THROW_ON_ERROR));
            }
            $total = $sql->count();
            $sql->skip($offset)->take($limit);
            $res = $sql->get();
            $bulkData = array();
            $bulkData['total'] = $total;
            $rows = [];
            foreach ($res as $row) {
                $tempRow = $row->toArray();
                $tempRow['user_status'] = isset($row->item->user) && empty($row->item->user->deleted_at);
                $tempRow['item_status'] = empty($row->item->deleted_at);
                $tempRow['reason'] = empty($row->report_reason_id) ? $row->other_message : $row->report_reason->reason;
                $rows[] = $tempRow;
            }

            $bulkData['rows'] = $rows;
            return response()->json($bulkData);

        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "ReportReason Controller -> show");
            ResponseService::errorResponse();
        }
    }
}
