$(document).ready(function() {
    $('[data-toggle="tooltip"]').tooltip();

    // Function to apply translations to a table
    function applyTableTranslations($table) {
        $table.attr({
            'data-search-text': window.trans('Search'),
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
    }

    // Apply translations to all translatable tables
    $('.translatable-table').each(function() {
        applyTableTranslations($(this));
    });

    // Listen for language change events (if you have a language switcher)
    $(document).on('languageChanged', function() {
        // Reload translations and update tables
        if (typeof loadTableTranslations === 'function') {
            loadTableTranslations();
        }
    });
});
