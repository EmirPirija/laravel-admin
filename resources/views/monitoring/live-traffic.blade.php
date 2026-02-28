@extends('layouts.main')

@section('title')
    {{ __('Live Traffic') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row align-items-center">
            <div class="col-12 col-md-8">
                <h4 class="mb-0">@yield('title')</h4>
                <small class="text-muted">{{ __('Koliko je korisnika trenutno online i gdje se trenutno nalaze.') }}</small>
            </div>
            <div class="col-12 col-md-4 text-md-end mt-2 mt-md-0">
                <span class="badge bg-light text-dark" id="liveGeneratedAt">
                    {{ __('Ažurirano') }}: {{ $summary['generated_at'] ?? '-' }}
                </span>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="row mb-3">
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">{{ __('Trenutno online') }}</div>
                        <div class="h3 mb-0 text-success" id="onlineNowValue">{{ (int) ($summary['online_now'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">{{ __('Prijavljeni korisnici online') }}</div>
                        <div class="h3 mb-0" id="onlineUsersValue">{{ (int) ($summary['online_users'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">{{ __('Gosti online') }}</div>
                        <div class="h3 mb-0" id="onlineGuestsValue">{{ (int) ($summary['online_guests'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">{{ __('Pregledi stranica (24h)') }}</div>
                        <div class="h3 mb-0" id="views24hValue">{{ (int) ($summary['views_last_24h'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-lg-6 mb-3">
                <div class="card h-100">
                    <div class="card-header border-0 pb-0">
                        <h6 class="mb-0">{{ __('Najposjećenije aktivne stranice (uživo)') }}</h6>
                    </div>
                    <div class="card-body">
                        <div id="activePagesTableWrap"></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 mb-3">
                <div class="card h-100">
                    <div class="card-header border-0 pb-0">
                        <h6 class="mb-0">{{ __('Top stranice (zadnjih 24h)') }}</h6>
                    </div>
                    <div class="card-body">
                        <div id="topPages24hTableWrap"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-lg-6 mb-3">
                <div class="card h-100">
                    <div class="card-header border-0 pb-0">
                        <h6 class="mb-0">{{ __('Uređaji (zadnjih 24h)') }}</h6>
                    </div>
                    <div class="card-body">
                        <div id="deviceBreakdownTableWrap"></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 mb-3">
                <div class="card h-100">
                    <div class="card-header border-0 pb-0">
                        <h6 class="mb-0">{{ __('Referrer izvori (zadnjih 24h)') }}</h6>
                    </div>
                    <div class="card-body">
                        <div id="referrerBreakdownTableWrap"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header border-0 pb-0">
                <h6 class="mb-0">{{ __('Satni trend aktivnosti (24h)') }}</h6>
            </div>
            <div class="card-body">
                <div id="hourlyTrendWrap"></div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div id="toolbar">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <label for="liveStatusFilter" class="mb-0 small text-muted">{{ __('Status') }}</label>
                        <select id="liveStatusFilter" class="form-select form-select-sm" style="max-width: 180px;">
                            <option value="online">{{ __('Samo online') }}</option>
                            <option value="recent">{{ __('Zadnjih 24h') }}</option>
                            <option value="all">{{ __('Sve sesije') }}</option>
                        </select>
                    </div>
                </div>
                <table class="table table-borderless table-striped"
                       id="table_list"
                       data-toggle="table"
                       data-url="{{ route('monitoring.live.show') }}"
                       data-click-to-select="true"
                       data-side-pagination="server"
                       data-pagination="true"
                       data-page-list="[10, 25, 50, 100, 200]"
                       data-search="true"
                       data-show-columns="true"
                       data-show-refresh="true"
                       data-trim-on-search="false"
                       data-toolbar="#toolbar"
                       data-responsive="true"
                       data-sort-name="last_seen_at"
                       data-sort-order="desc"
                       data-pagination-successively-size="3"
                       data-query-params="liveTrafficQueryParams"
                       data-escape="true"
                       data-mobile-responsive="true">
                    <thead class="thead-dark">
                    <tr>
                        <th scope="col" data-field="id" data-sortable="true">{{ __('ID') }}</th>
                        <th scope="col" data-field="user_name">{{ __('Korisnik') }}</th>
                        <th scope="col" data-field="user_email">{{ __('E-mail') }}</th>
                        <th scope="col" data-field="page_path" data-sortable="true">{{ __('Stranica') }}</th>
                        <th scope="col" data-field="device_type" data-sortable="true">{{ __('Uređaj') }}</th>
                        <th scope="col" data-field="ip_address" data-sortable="true">{{ __('IP') }}</th>
                        <th scope="col" data-field="visitor_id">{{ __('Visitor ID') }}</th>
                        <th scope="col" data-field="session_id">{{ __('Session ID') }}</th>
                        <th scope="col" data-field="heartbeat_count" data-sortable="true">{{ __('Heartbeat') }}</th>
                        <th scope="col" data-field="first_seen_at" data-sortable="true">{{ __('Prvi put viđen') }}</th>
                        <th scope="col" data-field="last_seen_at" data-sortable="true">{{ __('Zadnji put viđen') }}</th>
                        <th scope="col" data-field="active_for">{{ __('Aktivan') }}</th>
                        <th scope="col" data-field="idle_for">{{ __('Neaktivan') }}</th>
                    </tr>
                    </thead>
                </table>
            </div>
        </div>
    </section>
@endsection

@section('js')
    <script>
        const liveSummaryUrl = "{{ route('monitoring.live.summary') }}";

        function liveTrafficQueryParams(p) {
            return Object.assign({}, p, {
                status: $('#liveStatusFilter').val() || 'online'
            });
        }

        function renderSimpleTable(rows, colA, colB) {
            if (!Array.isArray(rows) || rows.length === 0) {
                return `<p class="text-muted mb-0">{{ __('Nema podataka.') }}</p>`;
            }

            const escapeHtml = (value) => String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const body = rows.map((row) => {
                const left = escapeHtml(row[colA] ?? '-');
                const right = Number(row[colB] ?? 0);
                return `<tr><td>${left}</td><td class="text-end">${right}</td></tr>`;
            }).join('');

            return `
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <tbody>${body}</tbody>
                    </table>
                </div>
            `;
        }

        function renderHourlyTrend(rows) {
            if (!Array.isArray(rows) || rows.length === 0) {
                return `<p class="text-muted mb-0">{{ __('Nema podataka.') }}</p>`;
            }

            const maxTotal = Math.max(...rows.map((row) => Number(row.total ?? 0)), 1);
            const list = rows.map((row) => {
                const total = Number(row.total ?? 0);
                const ratio = Math.max(2, Math.round((total / maxTotal) * 100));
                const hourLabel = String(row.hour ?? '-')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
                return `
                    <div class="d-flex align-items-center mb-2">
                        <div style="min-width:56px;" class="small text-muted">${hourLabel}</div>
                        <div class="flex-grow-1 mx-2" style="background:#edf1f6;border-radius:999px;height:8px;overflow:hidden;">
                            <div style="width:${ratio}%;height:8px;background:#11b7b0;border-radius:999px;"></div>
                        </div>
                        <div style="min-width:42px;" class="text-end small fw-semibold">${total}</div>
                    </div>
                `;
            }).join('');

            return `<div>${list}</div>`;
        }

        function applySummary(summary) {
            $('#onlineNowValue').text(Number(summary.online_now || 0));
            $('#onlineUsersValue').text(Number(summary.online_users || 0));
            $('#onlineGuestsValue').text(Number(summary.online_guests || 0));
            $('#views24hValue').text(Number(summary.views_last_24h || 0));
            $('#liveGeneratedAt').text(`{{ __('Ažurirano') }}: ${summary.generated_at || '-'}`);

            $('#activePagesTableWrap').html(renderSimpleTable(summary.active_pages || [], 'page_path', 'visitors'));
            $('#topPages24hTableWrap').html(renderSimpleTable(summary.top_pages_last_24h || [], 'page_path', 'views'));
            $('#deviceBreakdownTableWrap').html(renderSimpleTable(summary.device_breakdown_last_24h || [], 'device_type', 'total'));
            $('#referrerBreakdownTableWrap').html(renderSimpleTable(summary.referrer_breakdown_last_24h || [], 'referrer_url', 'total'));
            $('#hourlyTrendWrap').html(renderHourlyTrend(summary.hourly_trend_last_24h || []));
        }

        function refreshLiveSummary() {
            $.ajax({
                url: liveSummaryUrl,
                method: 'GET',
                success: function (response) {
                    if (response && response.error === false && response.data) {
                        applySummary(response.data);
                    }
                }
            });
        }

        $(document).on('change', '#liveStatusFilter', function () {
            $('#table_list').bootstrapTable('refresh');
        });

        $(function () {
            applySummary(@json($summary ?? []));
            setInterval(function () {
                refreshLiveSummary();
                $('#table_list').bootstrapTable('refresh', {silent: true});
            }, 20000);
        });
    </script>
@endsection
