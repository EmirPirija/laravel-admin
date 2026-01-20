<script type="text/javascript" src="{{ asset('assets/js/apexcharts.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/jquery.min.js')}}"></script>
<script type="text/javascript" src="{{ asset('assets/js/popper.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/bootstrap.min.js')}}"></script>
<script type="text/javascript" src="{{ asset('assets/js/app.js') }}"></script>

{{-- Firebasejs 8.10.0--}}
{{--<script type="text/javascript" src="{{ asset('assets/js/firebase-app.js')}}"></script>--}}
{{--<script type="text/javascript" src="{{ asset('assets/js/firebase-messaging.js')}}"></script>--}}


{{--Sweet Alert --}}
<script type="text/javascript" src="{{ asset('assets/extensions/sweetalert2/sweetalert2.min.js') }}"></script>

{{--Tiny MCE--}}
<script type="text/javascript" src="{{ asset('assets/extensions/tinymce/tinymce.min.js') }}"></script>

{{--Jquery Vector Map--}}
<script type="text/javascript" src="{{ asset('assets/extensions/jquery-vector-map/jquery-jvectormap-2.0.5.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/jquery-vector-map/jquery-jvectormap-asia-merc.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/jquery-vector-map/jquery-jvectormap-world-mill-en.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/jquery-vector-map/jquery-jvectormap-world-mill.js') }}"></script>

{{--Toastify--}}
<script type="text/javascript" src="{{ asset('assets/extensions/toastify-js/toastify.js') }}"></script>

{{--Parsley--}}
<script type="text/javascript" src="{{ asset('assets/js/parsley.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/pages/parsley.js') }}"></script>


{{--Magnific Popup--}}
<script type="text/javascript" src="{{ asset('assets/extensions/magnific-popup/jquery.magnific-popup.min.js') }}"></script>

{{--Select2--}}
<script type="text/javascript" src="{{ asset('assets/extensions/select2/select2.min.js') }}"></script>

{{--Jquery UI--}}
<script type="text/javascript" src="{{ asset('assets/extensions/jquery-ui/jquery-ui.min.js') }}"></script>

{{--Clipboard JS--}}
<script type="text/javascript" src="{{ asset('assets/js/clipboard.min.js') }}"></script>

{{--Filepond--}}
<script type="text/javascript" src="{{ asset('assets/extensions/filepond/filepond.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/filepond/filepond.jquery.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/filepond/filepond-plugin-image-preview.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/filepond/filepond-plugin-pdf-preview.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/filepond/filepond-plugin-file-validate-size.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/filepond/filepond-plugin-file-validate-type.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/filepond/filepond-plugin-image-validate-size.min.js') }}"></script>

{{--JS Tree--}}
<script src="{{asset("assets/extensions/jstree/jstree.min.js")}}"></script>


{{-- Custom JS --}}
<script type="text/javascript" src="{{ asset('assets/js/custom/common.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/custom/custom.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/custom/function.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/custom/bootstrap-table/formatter.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/custom/bootstrap-table/queryParams.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/custom/bootstrap-table/actionEvents.js') }}"></script>


{{--Bootstrap Table--}}
<script type="text/javascript" src="{{ asset('assets/extensions/bootstrap-table/bootstrap-table.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/bootstrap-table/fixed-columns/bootstrap-table-fixed-columns.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/bootstrap-table/mobile/bootstrap-table-mobile.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/bootstrap-table/jquery.tablednd.min.js')}}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/bootstrap-table/bootstrap-table.min.js')}}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/bootstrap-table/bootstrap-table-reorder-rows.min.js')}}"></script>
<script type="text/javascript" src="{{asset('assets/extensions/bootstrap-table/export/bootstrap-table-export.min.js')}}"></script>
<script type="text/javascript" src="{{asset('assets/extensions/bootstrap-table/export/tableExport.min.js')}}"></script>
<script type="text/javascript" src="{{asset('assets/extensions/bootstrap-table/export/jspdf.umd.min.js')}}"></script>
<script type="text/javascript" src="{{asset('assets/extensions/bootstrap-table/mobile/bootstrap-table-mobile.min.js')}}"></script>
<script type="text/javascript" src="{{asset('assets/extensions/bootstrap-table/filter/bootstrap-table-filter-control.min.js')}}"></script>

{{--Language Translation--}}
<script src="{{route('common.language.read')}}"></script>

<script src="{{ asset('assets/js/leaflet.js') }}"></script>
<script src="{{ asset('assets/js/map.js') }}"></script>
<script src="{{ asset('assets/js/bundle.min.js') }}"></script>
{{-- <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/2.2.2/jquery.min.js"></script> --}}
{{--<script src="https://harvesthq.github.io/chosen/chosen.jquery.js"></script>--}}
{{--<script src="https://bevacqua.github.io/dragula/dist/dragula.js"></script>--}}
<script type="text/javascript">
    window.baseurl = "{{ URL::to('/') }}/";
    @if (Session::has('success'))
    showSuccessToast("{{ Session::get('success') }}")
    @endif

    {{--    @if (Session::has('errors'))--}}
    {{--    @if(is_array(Session::get('errors')))--}}
    {{--    @foreach ($errors->all() as $error)--}}

    {{--    showErrorToast("{{ $error }}")--}}
    {{--    @endforeach--}}
    {{--    @else--}}
    {{--    @dd(Session::get('errors'))--}}
    {{--    console.log("{{ Session::get('errors') }}")--}}
    {{--    showErrorToast("{{ Session::get('errors')->message }}")--}}
    {{--    @endif--}}
    {{--    @endif--}}

    @if ($errors->any())
    @foreach ($errors->all() as $error)
    showErrorToast("{!! $error !!}");
    @endforeach
    @endif
    @if (Session::has('error'))
    showErrorToast('{!!  Session::get('error') !!}')
    @endif

</script>
<script>
    // Dynamic translation loading function
    function loadTableTranslations() {
        @php
            $tableKeys = [
                "Search...",
                "Refresh",
                "Toggle",
                "Columns",
                "Detail",
                "Detail Formatter",
                "Previous",
                "Next",
                "First",
                "Last",
                "Showing {ctx.start} to {ctx.end} of {ctx.total} entries",
                "Export Data",
                "Toggle Columns",
                "No description",
                "No image",
                "No date",
                "No price",
                "Active",
                "Inactive",
                "Pending",
                "Approved",
                "Rejected",
                "Published",
                "Draft",
                "No matching records found",
                "rows per page" // Add this new key
            ];

            // Get current language from session or default
            $currentLang = session('locale', config('app.locale', 'en'));

            // Load translations for current language
            $translations = [];
            foreach ($tableKeys as $key) {
                $translations[$key] = __($key);
            }
        @endphp

        window.tableTranslations = @json($translations);

        // Force update all translatable tables
        $('.translatable-table').each(function() {
            const $table = $(this);
            const tableId = $table.attr('id');

            // Update table attributes with new translations
            $table.attr({
                'data-search-placeholder': window.trans('Search...'),
                'data-refresh-text': window.trans('Refresh'),
                'data-toggle-text': window.trans('Toggle'),
                'data-columns-text': window.trans('Columns'),
                'data-detail-view-text': window.trans('Detail'),
                'data-detail-formatter-text': window.trans('Detail Formatter'),
                'data-pagination-pre-text': window.trans('Previous'),
                'data-pagination-next-text': window.trans('Next'),
                'data-pagination-first-text': window.trans('First'),
                'data-pagination-last-text': window.trans('Last'),
                'data-pagination-info-text': window.trans('Showing {ctx.start} to {ctx.end} of {ctx.total} entries'),
                'data-pagination-info-formatted': window.trans('Showing {ctx.start} to {ctx.end} of {ctx.total} entries')
            });

            // Force complete table refresh
            if ($table.hasClass('bootstrap-table')) {
                // Get current table options
                const tableOptions = $table.bootstrapTable('getOptions');

                // Destroy the table
                $table.bootstrapTable('destroy');

                // Re-initialize with new translations
                $table.bootstrapTable(tableOptions);
            }
        });

        // Manually update search placeholder, pagination text, and no records message
        setTimeout(function() {
            updateSearchAndPagination();
            updateNoRecordsMessage();
            updateRowsPerPageText(); // Add this new function call
        }, 500);
    }

    // Function to manually update search and pagination
    function updateSearchAndPagination() {
        // Update search placeholder
        $('.search-input').attr('placeholder', window.trans('Search...'));

        // Update pagination info text
        $('.pagination-info').each(function() {
            const $info = $(this);
            const currentText = $info.text();

            // Extract numbers from current text (e.g., "Showing 1 to 4 of 4 rows")
            const match = currentText.match(/Showing (\d+) to (\d+) of (\d+) rows/);
            if (match) {
                const start = match[1];
                const end = match[2];
                const total = match[3];

                // Replace with translated text
                const translatedText = window.trans('Showing {ctx.start} to {ctx.end} of {ctx.total} entries')
                    .replace('{ctx.start}', start)
                    .replace('{ctx.end}', end)
                    .replace('{ctx.total}', total);

                $info.text(translatedText);
            }
        });

        // Update refresh button title
        $('button[name="refresh"]').attr('title', window.trans('Refresh'));

        // Update columns button title
        $('button[aria-label="Columns"]').attr('title', window.trans('Columns'));

        // Update export button title
        $('button[aria-label="Export data"]').attr('title', window.trans('Export Data'));
    }

    // Function to update "No matching records found" message
    function updateNoRecordsMessage() {
        $('.no-records-found td').each(function() {
            const $cell = $(this);
            const currentText = $cell.text();

            if (currentText === 'No matching records found') {
                $cell.text(window.trans('No matching records found'));
            }
        });
    }

    // Add this new function to handle "rows per page" text
    // function updateRowsPerPageText() {
    //     $('.page-list').each(function() {
    //         const $pageList = $(this);
    //         const currentText = $pageList.text();

    //         if (currentText.includes('rows per page')) {
    //             const translatedText = currentText.replace('rows per page', window.trans('rows per page'));
    //             $pageList.text(translatedText);
    //         }
    //     });
    // }
      function updateRowsPerPageText() {
        $('.page-list').each(function() {
            const $pageList = $(this);

            // Use a more specific selector to find the text after the dropdown
            const $dropdown = $pageList.find('.btn-group');
            if ($dropdown.length) {
                // Get all text nodes after the dropdown
                let found = false;
                $pageList.contents().each(function() {
                    if (this.nodeType === 3 && !found) { // Text node
                        const text = this.textContent.trim();
                        if (text === 'rows per page') {
                            this.textContent = window.trans('rows per page');
                            found = true;
                        }
                    }
                });
            }
        });
    }
    // Translation helper
    window.trans = function(key) {
        return window.tableTranslations && window.tableTranslations[key] ? window.tableTranslations[key] : key;
    };

    // Load translations on page load
    loadTableTranslations();

    // Also update when table is refreshed
    $(document).on('post-body.bs.table', function() {
        setTimeout(function() {
            updateSearchAndPagination();
            updateNoRecordsMessage();
            updateRowsPerPageText(); // Add this new function call
        }, 100);
    });

    // Also update when table data is loaded
    $(document).on('load-success.bs.table', function() {
        setTimeout(function() {
            updateSearchAndPagination();
            updateNoRecordsMessage();
            updateRowsPerPageText(); // Add this new function call
        }, 100);
    });
</script>
<script src="{{ asset('assets/js/custom/table-translations.js') }}"></script>

