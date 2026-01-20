@extends('layouts.main')

@section('title')
    {{__("Bulk Upload Custom Fields")}}
@endsection

 @section('page-title')
    <div class="page-title">
        <div class="row d-flex align-items-center">
            <div class="col-12 col-md-6">
                <h4 class="mb-0">@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 text-end">
                <a href="{{ route('custom-fields.index') }}" class="btn btn-secondary mb-0">
                    <i class="fas fa-arrow-left"></i> {{__("Back")}}
                </a>
            </div>
        </div>
    </div>
@endsection
@section('content')
    <section class="section">
        <div class="row">
            <div class="col-md-12 mb-2">
                <div class="card shadow-sm">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0 fw-bold">
                            <i class="fas fa-info-circle me-2"></i> {{__("Instructions & Reference Guide")}}
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-4">
                            {{-- Section 1: Important Instructions --}}
                            <div class="col-md-4">
                                <div class="h-100 p-3 border rounded">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                            <i class="fas fa-exclamation-circle"></i>
                                        </div>
                                        <h6 class="mb-0 ms-3 fw-bold">{{__("Important Instructions")}}</h6>
                                    </div>
                                    <ul class="list-unstyled mb-0">
                                        <li class="mb-3 d-flex align-items-start">
                                            <i class="fas fa-check-circle text-success me-2 mt-1"></i>
                                            <span>{{__("Download the detailed instructions PDF for complete guide")}}</span>
                                        </li>
                                        <li class="mb-3 d-flex align-items-start">
                                            <i class="fas fa-check-circle text-success me-2 mt-1"></i>
                                            <span>{{__("Download the example CSV file to see the correct format")}}</span>
                                        </li>
                                        <li class="mb-3 d-flex align-items-start">
                                            <i class="fas fa-check-circle text-success me-2 mt-1"></i>
                                            <span>{{__("Fill in all required fields according to the field type")}}</span>
                                        </li>
                                        <li class="mb-3 d-flex align-items-start">
                                            <i class="fas fa-check-circle text-success me-2 mt-1"></i>
                                            <span>{{__("For image paths, use the gallery section to upload images")}}</span>
                                        </li>
                                        <li class="mb-3 d-flex align-items-start">
                                            <i class="fas fa-check-circle text-success me-2 mt-1"></i>
                                            <span>{{__("Category IDs should be comma-separated if multiple categories")}}</span>
                                        </li>
                                        <li class="mb-0 d-flex align-items-start">
                                            <i class="fas fa-check-circle text-success me-2 mt-1"></i>
                                            <span>{{__("Values for radio, dropdown, and checkbox types should be pipe-separated (|)")}}</span>
                                        </li>
                                        <li class="mb-0 d-flex align-items-start mt-3">
                                            <i class="fas fa-info-circle text-info me-2 mt-1"></i>
                                            <span><strong>{{__("Note:")}}</strong> {{__("You can open the CSV file in Excel, edit it, and save it. Excel will maintain the CSV format.")}}</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <div class="col-md-4">
                            <div class="h-100 p-3 border rounded">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                        <i class="fas fa-book"></i>
                                    </div>
                                    <h6 class="mb-0 ms-3 fw-bold">{{__("Quick Reference")}}</h6>
                                </div>

                                <div class="mb-4">
                                    <strong class="d-block mb-2">{{__("Field Types:")}}</strong>
                                    <div class="d-flex flex-wrap gap-2">
                                        <span class="badge bg-primary">Number</span>
                                        <span class="badge bg-secondary">Textbox</span>
                                        <span class="badge bg-info text-dark">Fileinput</span>
                                        <span class="badge bg-warning text-dark">Radio</span>
                                        <span class="badge bg-success">Dropdown</span>
                                        <span class="badge bg-danger">Checkbox</span>
                                    </div>
                                </div>

                                <div>
                                    <strong class="d-block mb-2">{{__("Data Format:")}}</strong>
                                    <ul class="list-unstyled mb-0 small">
                                        <li class="mb-2">
                                            <i class="fas fa-dot-circle text-primary me-2"></i>
                                            <strong>{{__("Required:")}}</strong> 0 = {{__("Optional")}}, 1 = {{__("Required")}}
                                        </li>
                                        <li class="mb-2">
                                            <i class="fas fa-dot-circle text-primary me-2"></i>
                                            <strong>{{__("Status:")}}</strong> 0 = {{__("Inactive")}}, 1 = {{__("Active")}}
                                        </li>
                                        <li class="mb-2">
                                            <i class="fas fa-dot-circle text-primary me-2"></i>
                                            <strong>{{__("Values:")}}</strong> {{__("Use pipe (|) to separate options")}}
                                        </li>
                                        <li class="mb-0">
                                            <i class="fas fa-dot-circle text-primary me-2"></i>
                                            <strong>{{__("Categories:")}}</strong> {{__("Use comma (,) to separate IDs")}}
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>


                            {{-- Section 3: Gallery Instructions --}}
                            <div class="col-md-4">
                                <div class="h-100 p-3 border rounded">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="bg-info text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                            <i class="fas fa-images"></i>
                                        </div>
                                        <h6 class="mb-0 ms-3 fw-bold">{{__("Image Gallery Guide")}}</h6>
                                    </div>
                                    <ul class="list-unstyled mb-0">
                                        <li class="mb-3 d-flex align-items-start">
                                            <i class="fas fa-arrow-right text-info me-2 mt-1"></i>
                                            <span>{{__("Click 'Open Image Gallery' button to upload images")}}</span>
                                        </li>
                                        <li class="mb-3 d-flex align-items-start">
                                            <i class="fas fa-arrow-right text-info me-2 mt-1"></i>
                                            <span>{{__("Upload multiple images at once (JPG, PNG, SVG)")}}</span>
                                        </li>
                                        <li class="mb-3 d-flex align-items-start">
                                            <i class="fas fa-arrow-right text-info me-2 mt-1"></i>
                                            <span>{{__("Click on image or 'Copy Path' button to copy image path")}}</span>
                                        </li>
                                        <li class="mb-3 d-flex align-items-start">
                                            <i class="fas fa-arrow-right text-info me-2 mt-1"></i>
                                            <span>{{__("Paste the copied path in CSV file's Image column")}}</span>
                                        </li>
                                        <li class="mb-0 d-flex align-items-start">
                                            <i class="fas fa-arrow-right text-info me-2 mt-1"></i>
                                            <span><strong>{{__("Format:")}}</strong> custom-fields/filename.jpg</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0 fw-bold text-white">
                            <i class="fas fa-download me-2"></i> {{__("Download Files & Image Gallery")}}
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="p-3 border rounded h-100">
                                    <div class="d-flex align-items-center mb-3">
                                        <i class="fas fa-file-download text-success me-2 fs-5"></i>
                                        <h6 class="mb-0 fw-bold">{{__("Download Required Files")}}</h6>
                                    </div>
                                    <p class="text-muted small mb-3">{{__("Download these files before starting the bulk upload process")}}</p>
                                    <div class="d-grid gap-2">
                                        <a href="{{ route('custom-fields.bulk-upload.instructions-pdf') }}" class="btn btn-primary">
                                            <i class="fas fa-file-pdf me-2"></i> {{__("Download Instructions PDF")}}
                                        </a>
                                        <a href="{{ route('custom-fields.bulk-upload.example') }}" class="btn btn-success">
                                            <i class="fas fa-file-csv me-2"></i> {{__("Download Example CSV File")}}
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 border rounded h-100">
                                    <div class="d-flex align-items-center mb-3">
                                        <i class="fas fa-images text-info me-2 fs-5"></i>
                                        <h6 class="mb-0 fw-bold">{{__("Image Gallery")}}</h6>
                                    </div>
                                    <p class="text-muted small mb-3">{{__("Upload images and copy their paths to use in your CSV file. The gallery allows you to manage all custom field images in one place.")}}</p>
                                    <button type="button" class="btn btn-info w-100" data-bs-toggle="modal" data-bs-target="#editModal">
                                        <i class="fas fa-images me-2"></i> {{__("Open Image Gallery")}}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0 fw-bold text-white">
                            <i class="fas fa-file-upload me-2"></i> {{__("Upload & Process CSV File")}}
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <form action="{{ route('custom-fields.bulk-upload.process') }}" method="POST" class="create-form" enctype="multipart/form-data" id="bulkUploadForm">
                            @csrf
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group mb-3">
                                        <label for="excel_file" class="form-label fw-bold">
                                            {{__("SelectFile")}} <span class="text-danger">*</span>
                                        </label>
                                       <input type="file" name="excel_file" id="excel_file" class="form-control form-control-lg" accept=".xlsx,.xls,.csv" required>
                                        <div class="form-text">
                                            <i class="fas fa-info-circle me-1"></i>
                                            {{__("Supported formats: CSV, XLSX, XLS")}}
                                        </div>
                                    </div>
                                    <div class="alert alert-light border d-flex align-items-start mb-3">
                                        <i class="fas fa-lightbulb text-warning me-2 mt-1"></i>
                                        <div>
                                            <strong class="d-block mb-1">{{__("Important:")}}</strong>
                                            <p class="mb-0 small">{{__("Make sure your CSV file follows the exact format from the example file. You can open CSV in Excel, edit it, and save. Invalid data will be skipped during processing.")}}</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-12 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-upload"></i> {{__("Upload and Process")}}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
         {{-- <div id="editModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel1" aria-hidden="true"> --}}
        <div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-labelledby="galleryModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content shadow-lg">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title fw-bold" id="galleryModalLabel">
                            <i class="fas fa-images me-2"></i> {{__("Image Gallery")}}
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="card mb-4">
                            <div class="card-body">
                                <h6 class="fw-bold mb-3">
                                    <i class="fas fa-upload text-primary me-2"></i> {{__("Upload New Images")}}
                                </h6>
                                <form id="galleryUploadForm" enctype="multipart/form-data">
                                    @csrf
                                    <div class="input-group input-group-lg">
                                        <input type="file" name="image" id="galleryImageInput" class="form-control" accept=".jpg,.jpeg,.png,.svg" multiple>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-upload me-2"></i> {{__("Upload Images")}}
                                        </button>
                                    </div>
                                    <div class="form-text mt-2">
                                        <i class="fas fa-info-circle me-1"></i>
                                        {{__("You can select multiple images at once. Supported formats: JPG, JPEG, PNG, SVG (Maximum size: 5MB per image)")}}
                                    </div>
                                </form>
                            </div>
                        </div>
                        <div class="mb-3">
                            <h6 class="fw-bold mb-3">
                                <i class="fas fa-images text-success me-2"></i> {{__("Uploaded Images")}}
                            </h6>
                        </div>
                        <div id="galleryImagesList" class="row g-3" style="max-height: 500px; overflow-y: auto; padding: 10px;">
                            {{-- Gallery images will be loaded here via AJAX --}}
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i> {{__("Close")}}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('js')
<script>
        $(document).ready(function() {
            // Handle gallery image upload
            $('#galleryUploadForm').on('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const files = $('#galleryImageInput')[0].files;

                if (files.length === 0) {
                    showErrorToast('{{__("Please select at least one image")}}');
                    return;
                }

                // Add all files to FormData
                for (let i = 0; i < files.length; i++) {
                    formData.append('images[]', files[i]);
                }

                $.ajax({
                    url: '{{ route("custom-fields.bulk-upload.gallery.upload") }}',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.status === 'success') {
                            showSuccessToast(response.message || '{{__("Images uploaded successfully")}}');
                            $('#galleryImageInput').val('');
                            loadGalleryImages();
                        } else {
                            showErrorToast(response.message || '{{__("Error uploading images")}}');
                        }
                    },
                    error: function(xhr) {
                        const errorMsg = xhr.responseJSON?.message || '{{__("Error uploading images")}}';
                        showErrorToast(errorMsg);
                    }
                });
            });
        });

        function loadGalleryImages() {
            $.ajax({
                url: '{{ route("custom-fields.bulk-upload.gallery.list") }}',
                type: 'GET',
                success: function(response) {
                    if (response.status === 'success' && response.images) {
                        let html = '';
                        if (response.images.length === 0) {
                            html = `
                                <div class="col-12">
                                    <div class="text-center py-5">
                                        <i class="fas fa-images text-muted" style="font-size: 48px;"></i>
                                        <p class="text-muted mt-3 mb-0">{{__("No images uploaded yet. Upload images using the form above.")}}</p>
                                    </div>
                                </div>
                            `;
                        } else {
                            response.images.forEach(function(image) {
                                html += `
                                    <div class="col-md-3">
                                        <div class="card h-100 shadow-sm border">
                                            <div class="position-relative">
                                                <img src="${image.url}" class="card-img-top" alt="Gallery Image" style="height: 200px; object-fit: cover; cursor: pointer;" onclick="copyImagePath('${image.path}')">
                                                <div class="position-absolute top-0 end-0 m-2">
                                                    <span class="badge bg-dark opacity-75">
                                                        <i class="fas fa-image"></i>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="card-body p-3">
                                                <label class="form-label small fw-bold mb-2">{{__("Image Path:")}}</label>
                                                <input type="text" class="form-control form-control-sm mb-2 image-path-input" value="${image.path}" readonly onclick="this.select();" style="font-size: 11px;">
                                                <button type="button" class="btn btn-sm btn-primary w-100 copy-path-btn" data-path="${image.path}">
                                                    <i class="fas fa-copy me-1"></i> {{__("Copy Path")}}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            });
                        }
                        $('#galleryImagesList').html(html);
                    } else {
                        $('#galleryImagesList').html(`
                            <div class="col-12">
                                <div class="text-center py-5">
                                    <i class="fas fa-images text-muted" style="font-size: 48px;"></i>
                                    <p class="text-muted mt-3 mb-0">{{__("No images uploaded yet. Upload images using the form above.")}}</p>
                                </div>
                            </div>
                        `);
                    }
                },
                error: function() {
                    $('#galleryImagesList').html(`
                        <div class="col-12">
                            <div class="alert alert-danger text-center">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                {{__("Error loading images. Please try again.")}}
                            </div>
                        </div>
                    `);
                }
            });
        }

        // Helper function to copy image path
        async function copyImagePath(path) {
            try {
                // Try modern Clipboard API first
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(path);
                } else {
                    // Fallback for older browsers
                    const input = document.createElement('input');
                    input.value = path;
                    input.style.position = 'fixed';
                    input.style.opacity = '0';
                    document.body.appendChild(input);
                    input.select();
                    input.setSelectionRange(0, 99999); // For mobile devices
                    document.execCommand('copy');
                    document.body.removeChild(input);
                }
                
                // Show feedback
                const btn = $(`.copy-path-btn[data-path="${path}"]`);
                const originalHtml = btn.html();
                btn.html('<i class="fas fa-check me-1"></i> {{__("Copied!")}}').removeClass('btn-primary').addClass('btn-success');
                setTimeout(() => {
                    btn.html(originalHtml).removeClass('btn-success').addClass('btn-primary');
                }, 2000);
            } catch (err) {
                console.error('Failed to copy:', err);
                showErrorToast('{{__("Failed to copy path to clipboard")}}');
            }
        }

        // Copy path to clipboard
        $(document).on('click', '.copy-path-btn', function() {
            const path = $(this).data('path');
            copyImagePath(path);
        });
        
        // Also handle click on image to copy path
        $(document).on('click', '.card-img-top', function() {
            const pathInput = $(this).closest('.card').find('.image-path-input');
            if (pathInput.length) {
                const path = pathInput.val();
                copyImagePath(path);
            }
        });

        // Load gallery when modal is opened
        $('#editModal').on('show.bs.modal', function() {
            loadGalleryImages();
        });
</script>
@endsection

