<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\AuthEventLog;
use App\Services\AuditLogService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class MonitoringController extends Controller
{
    public function auditIndex()
    {
        ResponseService::noPermissionThenRedirect('settings-update');
        return view('monitoring.audit-logs');
    }

    public function auditShow(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-update');

        $offset = (int) $request->input('offset', 0);
        $limit = (int) $request->input('limit', 10);
        $sort = (string) $request->input('sort', 'id');
        $order = strtoupper((string) $request->input('order', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $allowedSort = ['id', 'action', 'target_type', 'target_id', 'ip_address', 'created_at'];
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $sql = AuditLog::query()->with('actor:id,name,email');
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $sql->where(function ($q) use ($search) {
                $q->where('action', 'LIKE', "%{$search}%")
                    ->orWhere('target_type', 'LIKE', "%{$search}%")
                    ->orWhere('target_id', 'LIKE', "%{$search}%")
                    ->orWhere('ip_address', 'LIKE', "%{$search}%")
                    ->orWhereHas('actor', function ($actorQ) use ($search) {
                        $actorQ->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($request->filled('action')) {
            $sql->where('action', $request->input('action'));
        }

        $total = $sql->count();
        $rows = $sql->orderBy($sort, $order)->skip($offset)->take($limit)->get()->map(function ($row) {
            $data = $row->toArray();
            $data['actor_name'] = $row->actor?->name ?: __('System');
            $data['actor_email'] = $row->actor?->email ?: '-';
            $data['context_preview'] = !empty($row->context)
                ? Str::limit(json_encode($row->context, JSON_UNESCAPED_UNICODE), 160)
                : '-';
            $data['created_at_human'] = optional($row->created_at)->format('Y-m-d H:i:s');
            return $data;
        })->values();

        return response()->json([
            'total' => $total,
            'rows' => $rows,
        ]);
    }

    public function authEventsIndex()
    {
        ResponseService::noPermissionThenRedirect('settings-update');

        $topIpSignals = collect();
        $topIdentifierSignals = collect();
        $topEndpointSignals = collect();

        if (Schema::hasTable('auth_event_logs')) {
            $topIpSignals = $this->rateLimitSignalsBaseQuery()
                ->selectRaw("COALESCE(ip_address, 'unknown') as ip_address, COUNT(*) as hits, MAX(created_at) as last_seen")
                ->groupBy('ip_address')
                ->orderByDesc('hits')
                ->limit(10)
                ->get();

            $topIdentifierSignals = $this->rateLimitSignalsBaseQuery()
                ->whereNotNull('identifier')
                ->where('identifier', '!=', '')
                ->selectRaw('identifier, COUNT(*) as hits, MAX(created_at) as last_seen')
                ->groupBy('identifier')
                ->orderByDesc('hits')
                ->limit(10)
                ->get();

            $topEndpointSignals = $this->rateLimitSignalsBaseQuery()
                ->selectRaw("COALESCE(endpoint, 'unknown') as endpoint, COUNT(*) as hits, MAX(created_at) as last_seen")
                ->groupBy('endpoint')
                ->orderByDesc('hits')
                ->limit(10)
                ->get();
        }

        return view('monitoring.auth-events', compact('topIpSignals', 'topIdentifierSignals', 'topEndpointSignals'));
    }

    public function authEventsShow(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-update');

        if (!Schema::hasTable('auth_event_logs')) {
            return response()->json([
                'total' => 0,
                'rows' => [],
            ]);
        }

        $offset = (int) $request->input('offset', 0);
        $limit = (int) $request->input('limit', 10);
        $sort = (string) $request->input('sort', 'id');
        $order = strtoupper((string) $request->input('order', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $allowedSort = ['id', 'event_type', 'endpoint', 'identifier', 'status', 'ip_address', 'created_at'];
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $sql = AuthEventLog::query()->with('user:id,name,email');
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $sql->where(function ($q) use ($search) {
                $q->where('event_type', 'LIKE', "%{$search}%")
                    ->orWhere('endpoint', 'LIKE', "%{$search}%")
                    ->orWhere('identifier', 'LIKE', "%{$search}%")
                    ->orWhere('ip_address', 'LIKE', "%{$search}%")
                    ->orWhereHas('user', function ($userQ) use ($search) {
                        $userQ->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($request->filled('event_type')) {
            $sql->where('event_type', $request->input('event_type'));
        }

        if ($request->filled('status')) {
            $sql->where('status', $request->input('status'));
        }

        $total = $sql->count();
        $rows = $sql->orderBy($sort, $order)->skip($offset)->take($limit)->get()->map(function ($row) {
            $data = $row->toArray();
            $data['user_name'] = $row->user?->name ?: __('Gost');
            $data['user_email'] = $row->user?->email ?: '-';
            $data['meta_preview'] = !empty($row->meta)
                ? Str::limit(json_encode($row->meta, JSON_UNESCAPED_UNICODE), 160)
                : '-';
            $data['created_at_human'] = optional($row->created_at)->format('Y-m-d H:i:s');
            return $data;
        })->values();

        return response()->json([
            'total' => $total,
            'rows' => $rows,
        ]);
    }

    public function failedJobsIndex()
    {
        ResponseService::noPermissionThenRedirect('settings-update');
        $failedJobsCount = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        return view('monitoring.failed-jobs', compact('failedJobsCount'));
    }

    public function failedJobsShow(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-update');

        if (!Schema::hasTable('failed_jobs')) {
            return response()->json([
                'total' => 0,
                'rows' => [],
            ]);
        }

        $offset = (int) $request->input('offset', 0);
        $limit = (int) $request->input('limit', 10);
        $sort = (string) $request->input('sort', 'id');
        $order = strtoupper((string) $request->input('order', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $allowedSort = ['id', 'connection', 'queue', 'failed_at'];
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $sql = DB::table('failed_jobs');
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $sql->where(function ($q) use ($search) {
                $q->where('connection', 'LIKE', "%{$search}%")
                    ->orWhere('queue', 'LIKE', "%{$search}%")
                    ->orWhere('exception', 'LIKE', "%{$search}%");
            });
        }

        $total = $sql->count();
        $rows = $sql->orderBy($sort, $order)->skip($offset)->take($limit)->get()->map(function ($row) {
            return [
                'id' => $row->id,
                'connection' => $row->connection,
                'queue' => $row->queue,
                'payload_hash' => substr(hash('sha256', (string) $row->payload), 0, 20),
                'error_preview' => Str::limit(trim((string) $row->exception), 180),
                'failed_at' => $row->failed_at,
                'operate' => '<button type="button" class="btn icon btn-xs btn-rounded btn-icon rounded-pill btn-outline-primary retry-failed-job" data-id="'.$row->id.'" title="'.e(__('Ponovi job')).'"><i class="fa fa-rotate-right"></i></button>',
            ];
        })->values();

        return response()->json([
            'total' => $total,
            'rows' => $rows,
        ]);
    }

    public function retryFailedJob(int $id)
    {
        ResponseService::noPermissionThenSendJson('settings-update');

        try {
            if (!Schema::hasTable('failed_jobs')) {
                ResponseService::errorResponse(__('Tabela failed_jobs ne postoji.'));
            }

            $failedJob = DB::table('failed_jobs')->where('id', $id)->first();
            if (empty($failedJob)) {
                ResponseService::errorResponse(__('Ne postoji neuspjeli job za odabrani ID.'));
            }

            Artisan::call('queue:retry', ['id' => [$id]]);
            AuditLogService::log('queue_failed_job_retry', 'failed_jobs', $id, [
                'connection' => $failedJob->connection ?? null,
                'queue' => $failedJob->queue ?? null,
            ]);

            ResponseService::successResponse(__('Job je uspješno vraćen na retry red.'));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'MonitoringController -> retryFailedJob');
            ResponseService::errorResponse(__('Retry nije uspio. Pokušajte ponovo.'));
        }
    }

    private function rateLimitSignalsBaseQuery()
    {
        $allowedEndpointParts = [
            'user-signup',
            'verify-otp',
            'resolve-login-identifier',
            'get-otp',
        ];

        return AuthEventLog::query()
            ->where('event_type', 'rate_limit_hit')
            ->where(function ($query) use ($allowedEndpointParts) {
                foreach ($allowedEndpointParts as $endpointPart) {
                    $query->orWhere('endpoint', 'LIKE', "%{$endpointPart}%");
                }
            });
    }
}
