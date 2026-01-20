/**
 * Bootstrap Table Helper Functions
 * Reusable JavaScript functions for Bootstrap Table functionality
 */

// Global formatters that can be used across all tables
window.BootstrapTableFormatters = {
    // Truncate text with tooltip
    truncateText: function(value, row, index, field) {
        if (!value) return '<span class="text-muted">' + window.trans('No data') + '</span>';

        const maxLength = field && field.maxLength ? field.maxLength : 100;
        if (value.length > maxLength) {
            return '<span title="' + value + '">' + value.substring(0, maxLength) + '...</span>';
        }
        return value;
    },

    // Image formatter with thumbnail
    imageFormatter: function(value, row, index) {
        if (value) {
            return '<img src="' + value + '" class="img-thumbnail" style="width: 50px; height: 50px; object-fit: cover;" alt="' + window.trans('Image') + '">';
        }
        return '<span class="text-muted">' + window.trans('No image') + '</span>';
    },

    // Status formatter with badges
    statusFormatter: function(value, row, index) {
        const statusMap = {
            'active': { class: 'success', text: window.trans('Active') },
            'inactive': { class: 'secondary', text: window.trans('Inactive') },
            'pending': { class: 'warning', text: window.trans('Pending') },
            'approved': { class: 'success', text: window.trans('Approved') },
            'rejected': { class: 'danger', text: window.trans('Rejected') },
            'published': { class: 'success', text: window.trans('Published') },
            'draft': { class: 'secondary', text: window.trans('Draft') },
            'deleted': { class: 'danger', text: window.trans('Deleted') }
        };

        const status = statusMap[value] || { class: 'secondary', text: value };
        return '<span class="badge bg-' + status.class + '">' + status.text + '</span>';
    },

    // Date formatter
    dateFormatter: function(value, row, index) {
        if (!value) return '<span class="text-muted">' + window.trans('No date') + '</span>';

        const date = new Date(value);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    },

    // Price formatter
    priceFormatter: function(value, row, index) {
        if (!value) return '<span class="text-muted">' + window.trans('No price') + '</span>';

        const currency = window.currency || '$';
        return currency + parseFloat(value).toFixed(2);
    },

    // Action buttons formatter
    actionFormatter: function(value, row, index) {
        let actions = '';

        // Edit button
        if (window.canEdit !== false) {
            actions += '<a href="' + (row.edit_url || '#') + '" class="btn btn-sm btn-outline-primary me-1" title="' + window.trans('Edit') + '">';
            actions += '<i class="fas fa-edit"></i>';
            actions += '</a>';
        }

        // Delete button
        if (window.canDelete !== false) {
            actions += '<button class="btn btn-sm btn-outline-danger" onclick="deleteItem(' + row.id + ')" title="' + window.trans('Delete') + '">';
            actions += '<i class="fas fa-trash"></i>';
            actions += '</button>';
        }

        // View button
        if (row.view_url) {
            actions += '<a href="' + row.view_url + '" class="btn btn-sm btn-outline-info me-1" title="' + window.trans('View') + '">';
            actions += '<i class="fas fa-eye"></i>';
            actions += '</a>';
        }

        return actions || '<span class="text-muted">' + window.trans('No actions') + '</span>';
    },

    // Checkbox formatter
    checkboxFormatter: function(value, row, index) {
        return '<input type="checkbox" class="form-check-input" value="' + row.id + '">';
    },

    // Link formatter
    linkFormatter: function(value, row, index, field) {
        if (!value) return '<span class="text-muted">' + window.trans('No link') + '</span>';

        const url = field && field.url ? field.url : value;
        const target = field && field.target ? field.target : '_blank';
        const text = field && field.text ? field.text : value;

        return '<a href="' + url + '" target="' + target + '" class="text-decoration-none">' + text + '</a>';
    }
};

// Global query parameters function
window.queryParams = function(params) {
    return {
        limit: params.limit,
        offset: params.offset,
        order: params.order,
        search: params.search,
        sort: params.sort,
        // Add any additional parameters
        ...window.tableParams
    };
};

// Global delete function
window.deleteItem = function(id) {
    if (confirm(window.trans('Are you sure you want to delete this item?'))) {
        // This should be overridden in each view
        console.log('Delete item with ID:', id);
    }
};

// Global table refresh function
window.refreshTable = function(tableId = 'table_list') {
    $('#' + tableId).bootstrapTable('refresh');
};

// Global search function
window.searchTable = function(tableId = 'table_list', searchText) {
    $('#' + tableId).bootstrapTable('filterBy', {
        // This will be overridden by the actual search functionality
    });
};

// Initialize all tables on page load
$(document).ready(function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();

    // Initialize all bootstrap tables
    $('[data-toggle="table"]').each(function() {
        const tableId = $(this).attr('id');
        if (tableId) {
            // Add search functionality if search input exists
            const searchInput = $('#searchInput_' + tableId);
            if (searchInput.length) {
                searchInput.on('keyup', function() {
                    const value = $(this).val();
                    $('#' + tableId).bootstrapTable('filterBy', {
                        // This will be overridden by the actual search functionality
                    });
                });
            }
        }
    });
});

// Translation helper (if not already defined)
if (typeof window.trans === 'undefined') {
    window.trans = function(key) {
        // This should be replaced with actual translation function
        return key;
    };
}
