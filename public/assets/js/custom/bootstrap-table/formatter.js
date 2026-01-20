function imageFormatter(value) {
    if (value) {
        return '<a class="image-popup-no-margins one-image" href="' + value + '">' +
            '<img class="rounded avatar-md shadow img-fluid " alt="" src="' + value + '" style="height: 55px !important;" width="55" onerror="onErrorImage(event)">' +
            '</a>'
    } else {
        return '-'
    }
}

function galleryImageFormatter(value) {
    if (value) {
        let html = '<div class="gallery">';
        $.each(value, function (index, data) {
            html += '<a href="' + data.image + '"><img class="rounded avatar-md shadow img-fluid m-1" alt="" src="' + data.image + '" width="55" onerror="onErrorImage(event)"></a>';
        })
        html += "</div>"
        return html;
    } else {
        return '-'
    }
}

function subCategoryFormatter(value, row) {
     const subcategoriesLabel = window?.languageLabels["Subcategories"] || "Subcategories";
    let url = `/category/${row.id}/subcategories`;
    return '<span> <div class="category_count">' + value + ' '+ subcategoriesLabel + '</div></span>';
}

function customFieldFormatter(value, row) {
      const customFieldsLabel =window?.languageLabels["Custom Fields"] || "Custom Fields";
    let url = `/category/${row.id}/custom-fields`;
   return '<a href="' + url + '"><div class="category_count">' + value + ' ' + customFieldsLabel + '</div></a>';

}

function statusSwitchFormatter(value, row) {
    return `<div class="form-check form-switch">
        <input class = "form-check-input switch1 update-status" id="${row.id}" type = "checkbox" role = "switch${status}" ${value ? 'checked' : ''}>
    </div>`
}
function autoApproveItemSwitchFormatter(value, row) {
    return `<div class="form-check form-switch">
        <input class="form-check-input switch1 update-auto-approve-status" id="${row.id}" type="checkbox" role="switch" ${value ? 'checked' : ''}>
    </div>`;
}

function itemStatusSwitchFormatter(value, row) {
    return `<div class="form-check form-switch">
        <input class = "form-check-input switch1 update-item-status" id="${row.item_id}" type = "checkbox" role = "switch${status}" ${value ? 'checked' : ''}>
    </div>`
}

function userStatusSwitchFormatter(value, row) {
    return `<div class="form-check form-switch">
        <input class = "form-check-input switch1 update-user-status" id="${row.item.user_id}" type = "checkbox" role = "switch${status}" ${value ? 'checked' : ''}>
    </div>`
}

function trans(label) {
    // return window.languageLabels.hasOwnProperty(label) ? window.languageLabels[label] : label;
    return window?.languageLabels[label] || label;
}

function itemStatusFormatter(value) {
    const statusMap = {
        "review": { badge: "primary", text: window?.languageLabels?.["Under Review"] || "Under Review" },
        "approved": { badge: "success", text: window?.languageLabels?.["Approved"] || "Approved" },
        "permanent rejected": { badge: "danger", text: window?.languageLabels?.["Permanent Rejected"] || "Permanent Rejected" },
        "sold out": { badge: "warning", text: window?.languageLabels?.["Sold Out"] || "Sold Out" },
        "featured": { badge: "black", text: window?.languageLabels?.["Featured"] || "Featured" },
        "inactive": { badge: "danger", text: window?.languageLabels?.["Inactive"] || "Inactive" },
        "expired": { badge: "danger", text: window?.languageLabels?.["Expired"] || "Expired" },
        "soft rejected": { badge: "black", text: window?.languageLabels?.["Soft Rejected"] || "Soft Rejected" },
        "resubmitted": { badge: "primary", text: window?.languageLabels?.["Resubmitted"] || "Resubmitted" },
    };

    const status = statusMap[value] || { badge: "secondary", text: value || "Unknown" };
    return `<span class="badge rounded-pill bg-${status.badge}">${status.text}</span>`;
}
function featuredItemStatusFormatter(value) {
    let badgeClass = '';
    let badgeText = '';
    
    if (value == "Premium") {
        badgeClass = 'primary';
        badgeText = (window.languageLabels && window.languageLabels["Premium"]) ? window.languageLabels["Premium"] : "Premium";
    } else if (value == "Featured") {
        badgeClass = 'success';
        badgeText = (window.languageLabels && window.languageLabels["Featured"]) ? window.languageLabels["Featured"] : "Featured";
    }
    
    if (!badgeClass) {
        return '';
    }
    
    return '<span class="badge rounded-pill bg-' + badgeClass + '">' + badgeText + '</span>';
}
function status_badge(value, row) {
    let badgeClass, badgeText;
    if (value == '0') {
        badgeClass = 'danger';
        badgeText = 'OFF';
    } else {
        badgeClass = 'success';
        badgeText = 'ON';
    }
    return '<span class="badge rounded-pill bg-' + badgeClass + '">' + badgeText + '</span>';
}

function userStatusBadgeFormatter(value, row) {
    let badgeClass, badgeText;
    if (value == '0') {
        badgeClass = 'danger';
        badgeText = 'Inactive';
    } else {
        badgeClass = 'success';
        badgeText = 'Active';
    }
    return '<span class="badge rounded-pill bg-' + badgeClass +'">' + badgeText + '</span>';
}
function styleImageFormatter(value, row) {
    return '<a class="image-popup-no-margins" href="images/app_styles/' + value + '.png"><img src="images/app_styles/' + value + '.png" alt="style_4"  height="60" width="60" class="rounded avatar-md shadow img-fluid"></a>';
}

function filterTextFormatter(value) {
    let filter;
    if (value == "most_liked") {
        filter = "Most Liked";
    } else if (value == "price_criteria") {
        filter = "Price Criteria";
    } else if (value == "category_criteria") {
        filter = "Category Criteria";
    } else if (value == "most_viewed") {
        filter = "Most Viewed";
    }
    return filter;
}

function adminFile(value, row) {
    return "<a href='languages/" + row.code + ".json ' )+' > View File < /a>";
}

function appFile(value, row) {
    return "<a href='lang/" + row.code + ".json ' )+' > View File < /a>";
}

function textReadableFormatter(value, row) {
    let string = value.replace("_", " ");
    return string.charAt(0).toUpperCase() + string.slice(1);
}

function userPackageStatusBadgeFormatter(value) {
    let badgeClass, badgeText;
    if (value == 'Expired') {
        badgeClass = 'danger';
        badgeText = 'Expired';
    } else {
        badgeClass = 'success';
        badgeText = 'Active';
    }
    return '<span class="badge rounded-pill bg-' + badgeClass +'">' + badgeText + '</span>';
}

function unlimitedBadgeFormatter(value) {
    if (!value) {
        return 'Unlimited';
    }
    return value;
}

function detailFormatter(index, row) {
    let html = []

    if (row.translations && row.translations.length > 0) {
        $.each(row.translations, function (key, value) {
            html.push('<p><b>' + value.language.name + ':</b> ' + value.description + '</p>')
        })
    } else {
        const noTranslations = window?.languageLabels?.["No translations available"] || "No translations available";
        html.push('<p class="text-muted"><i>' + noTranslations + '</i></p>')
    }

    return html.join('')
}


function truncateDescription(value, row, index) {
    if (!value) {
        return '<span class="no-description">No Description Available</span>';
    }

    // Create a temporary DOM element to handle HTML safely
    let tempDiv = document.createElement("div");
    tempDiv.innerHTML = value;

    let textContent = tempDiv.textContent || tempDiv.innerText || "";
    if (textContent.length > 100) {
        let shortText = textContent.substring(0, 50);

        return `
            <div class="short-description">
                ${shortText}...
                <a href="#" class="view-more" data-index="${index}">${window?.languageLabels?.["View More"] || "View More"}</a>
            </div>
            <div class="full-description" style="display:none;">
                ${value}
                <a href="#" class="view-more" data-index="${index}">${window?.languageLabels?.["View Less"] || "View Less"}</a>
            </div>
        `;
    } else {
        return value;
    }
}
function videoLinkFormatter(value, row, index) {
    if (!value) {
        return '';
    }
    const maxLength = 20;
    const displayText = value.length > maxLength ? value.substring(0, maxLength) + '...' : value;
    return `<a href="${value}" target="_blank">${displayText}</a>`;
}

function dateFormatter(value, row, index) {
    if (!value) {
        return '<span class="text-muted">-</span>';
    }
    
    try {
        const date = new Date(value);
        if (isNaN(date.getTime())) {
            return '<span class="text-muted">-</span>';
        }
        
        // Format: MM/DD/YYYY HH:MM AM/PM (US format)
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const year = date.getFullYear();
        let hours = date.getHours();
        const minutes = String(date.getMinutes()).padStart(2, '0');
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12; // the hour '0' should be '12'
        const formattedHours = String(hours).padStart(2, '0');
        
        return `${month}/${day}/${year} ${formattedHours}:${minutes} ${ampm}`;
    } catch (error) {
        console.error('Date formatting error:', error);
        return '<span class="text-muted">-</span>';
    }
}

function sellerverificationStatusFormatter(value) {
    let badgeClass, badgeText;
    if (value == "review") {
        badgeClass = 'primary';
        badgeText = window?.languageLabels?.["Under Review"] || "Under Review";
    } else if (value == "approved") {
        badgeClass = 'success';
        badgeText = window?.languageLabels?.["Approved"] || "Approved";
    } else if (value == "rejected") {
        badgeClass = 'danger';
        badgeText = window?.languageLabels?.["Rejected"] || "Rejected";
    } else if (value == "pending") {
        badgeClass = 'warning';
        badgeText = window?.languageLabels?.["Pending"] || "Pending";
    }
    return '<span class="badge rounded-pill bg-' + badgeClass + '">' + badgeText + '</span>';
}
function categoryNameFormatter(value, row) {
    let buttonHtml = '';
    let count = parseInt(row.subcategories_count);
    if (count > 0) {
        buttonHtml = `<button class="btn icon btn-xs btn-icon rounded-pill toggle-subcategories float-left btn-outline-primary text-center"
                            style="padding:.20rem; font-size:.875rem;cursor: pointer; margin-right: 5px;" data-id="${row.id}">
                        <i class="fa fa-plus"></i>
                      </button>`;
    } else {
        buttonHtml = `<span style="display:inline-block; width:30px;"></span>`;
    }
    return `${buttonHtml}${value}`;

}

function subCategoryNameFormatter(value, row, level) {
    let dataLevel = 0;
    let indent = level * 35;
    let buttonHtml = '';
   let count = parseInt(row.subcategories_count);
    if (count > 0) {
        buttonHtml = `<button class="btn icon btn-xs btn-icon rounded-pill toggle-subcategories float-left btn-outline-primary text-center"
                            style="padding:.20rem; cursor: pointer; margin-right: 5px;" data-id="${row.id}" data-level="${dataLevel}">
                        <i class="fa fa-plus"></i>
                      </button>`;
    } else {
        buttonHtml = `<span style="display:inline-block; width:30px;"></span>`;
    }
    dataLevel += 1;
    return `<div style="padding-left:${indent}px;" class="justify-content-center">${buttonHtml}<span>${value}</span></div>`;

}
function descriptionFormatter(value, row, index) {
    if (value.length >= 50) {
        const viewMore = (window.languageLabels && window.languageLabels["View More"]) ? window.languageLabels["View More"] : "View More";
        const viewLess = (window.languageLabels && window.languageLabels["View Less"]) ? window.languageLabels["View Less"] : "View Less";
        return '<div class="short-description">' + value.substring(0, 50) +
            '... <a href="#" class="view-more" data-index="' + index + '">' + viewMore + '</a></div>' +
            '<div class="full-description" style="display:none;">' + value +
            ' <a href="#" class="view-more" data-index="' + index + '">' + viewLess + '</a></div>';
    } else {
        return value;
    }
}

function rejectedReasonFormatter(value, row, index) {
    if (value !== null && value !== undefined && value !== '') {
        if (value.length > 20) {
            const viewMore = (window.languageLabels && window.languageLabels["View More"]) ? window.languageLabels["View More"] : "View More";
            const viewLess = (window.languageLabels && window.languageLabels["View Less"]) ? window.languageLabels["View Less"] : "View Less";
            return '<div class="short-description">' + value.substring(0, 100) +
                '... <a href="#" class="view-more" data-index="' + index + '">' + viewMore + '</a></div>' +
                '<div class="full-description" style="display:none;">' + value +
                ' <a href="#" class="view-more" data-index="' + index + '">' + viewLess + '</a></div>';
        } else {
            return value;
        }
    }
    return '<span class="no-description">-</span>';
}



function ratingFormatter(value, row, index) {
    const maxRating = 5;
    let stars = '';
    for (let i = 1; i <= maxRating; i++) {
        if (i <= Math.floor(value)) {
            stars += '<i class="fa fa-star text-warning"></i>';
        } else if (i === Math.ceil(value) && value % 1 !== 0) {
            stars += '<i class="fa fa-star-half text-warning" aria-hidden></i>';
        } else {
            stars += '<i class="fa fa-star text-secondary"></i>';
        }
    }
    return stars;
}

function reportStatusFormatter(value) {
    let badgeClass, badgeText;
    if (value == "reported") {
        badgeClass = 'primary';
        badgeText = window?.languageLabels?.["Reported"] || "Reported";
    } else if (value == "approved") {
        badgeClass = 'success';
        badgeText = window?.languageLabels?.["Approved"] || "Approved";
    } else if (value == "rejected") {
        badgeClass = 'danger';
        badgeText = window?.languageLabels?.["Rejected"] || "Rejected";
    }
    return '<span class="badge rounded-pill bg-' + badgeClass + '">' + badgeText + '</span>';
}


function typeFormatter(value, row) {
    if (value === 'App\\Models\\Category') {
        return 'Category';
    } else if (value === 'App\\Models\\Item') {
        return 'Advertisement';
    } else {
        return '-';
    }
}
