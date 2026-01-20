<?php

namespace App\Http\Controllers;

use App\Models\SellerRating;
use App\Models\User;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Throwable;
use Validator;

class SellerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['seller-review-list', 'seller-review-update', 'seller-review-delete']);
        return view('seller_review.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        ResponseService::noAnyPermissionThenRedirect(['seller-review-list', 'seller-review-update', 'seller-review-delete']);
        return view('seller_review.report');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request)
    {
    try {
        ResponseService::noPermissionThenSendJson('seler-review-list');
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'ASC');

        $sql = SellerRating::with(['seller:id,name','buyer:id,name','item:id,name']);

        if (!empty($request->search)) {
            $sql = $sql->search($request->search);
        }
        $total = $sql->count();

        $sql = $sql->sort($sort, $order)->skip($offset)->take($limit);

        $result = $sql->get();


        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        foreach ($result as $row) {
            $tempRow = $row->toArray();
            $tempRow['seller_name'] = $row->seller->name ?? '';
            $tempRow['buyer_name'] = $row->buyer->name ?? '';
            $tempRow['item_name'] = $row->item->name;
            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;

        return response()->json($bulkData);

    } catch (Throwable $th) {
        ResponseService::logErrorResponse($th, "ItemController --> show");
        return ResponseService::errorResponse();
    }
}

    public function showReports(Request $request)
    {
        try {
        ResponseService::noPermissionThenSendJson('seler-review-list');
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'ASC');

        $sql = SellerRating::with(['seller:id,name','buyer:id,name','item:id,name'])->whereNotNull('report_status')->withTrashed();

        if (!empty($request->search)) {
            $sql = $sql->search($request->search);
        }

        if (!empty($request->filter)) {
            $sql = $sql->filter(json_decode($request->filter, false, 512, JSON_THROW_ON_ERROR));
        }
        $sql->sort($sort, $order);

        // Pagination
        $total = $sql->count();
        $result = $sql->skip($offset)->take($limit)->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];

        foreach ($result as $row) {
            $tempRow = $row->toArray();
            $tempRow['seller_name'] = $row->seller->name;
            $tempRow['buyer_name'] = $row->buyer->name;
            $tempRow['item_name'] = $row->item->name;
            $tempRow['operate'] = BootstrapTableService::editButton(route('seller-review.update', $row->id), true, '#editStatusModal', 'edit-status', $row->id);


            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "SellerController --> showSellersWithRatings");
            return ResponseService::errorResponse();
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
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'report_status' => 'required|in:approved,rejected',
            'report_rejected_reason' => 'required_if:report_status,==,rejected'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            ResponseService::noPermissionThenSendJson('item-update');
            $seller_rating = SellerRating::withTrashed()->findOrFail($id);
            $seller_rating->update([
                ...$request->all(),
                // 'report_rejected_reason' => ($request->status == "rejected") ? $request->report_rejected_reason : ''
            ]);
            if ($request->report_status == "approved") {
                $seller_rating->forceDelete();
            }
            ResponseService::successResponse('Report Status Updated Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SellerController ->update');
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
