<?php

namespace App\Http\Controllers;

use App\Models\ContactUs;
use App\Services\AuditLogService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ContactInboxController extends Controller
{
    public function index()
    {
        ResponseService::noPermissionThenRedirect('user-queries-list');

        $stats = ContactUs::query()
            ->selectRaw("COUNT(*) as total")
            ->selectRaw("SUM(CASE WHEN status = 'new' OR status IS NULL THEN 1 ELSE 0 END) as new_count")
            ->selectRaw("SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count")
            ->selectRaw("SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_count")
            ->first();

        return view('contact-us', compact('stats'));
    }

    public function show(Request $request)
    {
        ResponseService::noPermissionThenSendJson('user-queries-list');

        $offset = (int) $request->input('offset', 0);
        $limit = (int) $request->input('limit', 10);
        $sort = (string) $request->input('sort', 'id');
        $order = strtoupper((string) $request->input('order', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $allowedSort = ['id', 'name', 'email', 'subject', 'status', 'created_at', 'resolved_at'];
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $sql = ContactUs::query()->with('assignedTo:id,name,email');

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $sql->where(function ($q) use ($search) {
                $q->where('id', 'LIKE', "%{$search}%")
                    ->orWhere('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%")
                    ->orWhere('subject', 'LIKE', "%{$search}%")
                    ->orWhere('message', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $sql->where('status', $request->input('status'));
        }

        $total = $sql->count();
        $rows = $sql->orderBy($sort, $order)->skip($offset)->take($limit)->get()->map(function (ContactUs $row) {
            $rowData = $row->toArray();
            $status = (string) ($row->status ?: 'new');

            $statusLabel = match ($status) {
                'in_progress' => __('U obradi'),
                'closed' => __('Zatvoreno'),
                default => __('Novo'),
            };

            $statusClass = match ($status) {
                'in_progress' => 'warning',
                'closed' => 'success',
                default => 'secondary',
            };

            $rowData['status'] = $status;
            $rowData['status_badge'] = '<span class="badge rounded-pill bg-'.$statusClass.'">'.$statusLabel.'</span>';
            $rowData['assigned_to_name'] = $row->assignedTo?->name ?: '-';
            $rowData['resolved_at_human'] = $row->resolved_at ? optional($row->resolved_at)->format('Y-m-d H:i:s') : '-';
            $rowData['created_at_human'] = $row->created_at ? optional($row->created_at)->format('Y-m-d H:i:s') : '-';

            $encodedNote = e((string) ($row->admin_note ?? ''));
            $rowData['operate'] = '<button type="button" class="btn icon btn-xs btn-rounded btn-icon rounded-pill btn-outline-primary contact-update" '
                .'data-id="'.$row->id.'" '
                .'data-status="'.e($status).'" '
                .'data-note="'.$encodedNote.'" '
                .'data-bs-toggle="modal" data-bs-target="#contactStatusModal" '
                .'title="'.e(__('Ažuriraj status')).'">'
                .'<i class="fa fa-pen"></i></button>';

            return $rowData;
        })->values();

        return response()->json([
            'total' => $total,
            'rows' => $rows,
        ]);
    }

    public function updateStatus(Request $request, int $id)
    {
        ResponseService::noPermissionThenSendJson('user-queries-list');

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:new,in_progress,closed',
            'admin_note' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $contact = ContactUs::findOrFail($id);
            $oldStatus = (string) ($contact->status ?: 'new');
            $newStatus = (string) $request->input('status');

            $contact->status = $newStatus;
            $contact->admin_note = $request->input('admin_note');
            $contact->assigned_to = in_array($newStatus, ['in_progress', 'closed'], true) ? Auth::id() : null;
            $contact->resolved_at = $newStatus === 'closed' ? now() : null;
            $contact->save();

            AuditLogService::log('contact_inbox_status_change', ContactUs::class, $contact->id, [
                'from' => $oldStatus,
                'to' => $newStatus,
                'assigned_to' => $contact->assigned_to,
            ]);

            ResponseService::successResponse(__('Status poruke je uspješno ažuriran.'));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ContactInboxController -> updateStatus');
            ResponseService::errorResponse(__('Ažuriranje statusa nije uspjelo.'));
        }
    }
}
