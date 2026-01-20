<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\Category;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\ResponseService;
use Carbon\Carbon;
use App\Jobs\SendFcmBatchJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Validator;

use function compact;
use function view;

class BlogController extends Controller {
    private string $uploadFolder;

    public function __construct() {
        $this->uploadFolder = "blog";
    }

    public function index() {
        ResponseService::noAnyPermissionThenRedirect(['blog-list', 'blog-create', 'blog-delete', 'blog-update']);
        $languages = CachingService::getLanguages()->values();
        return view('blog.index',compact('languages'));
    }

    public function create() {
        ResponseService::noPermissionThenRedirect('blog-create');
        $categories = Category::all();
        $languages = CachingService::getLanguages()->values();
        return view('blog.create', compact('categories','languages'));
    }

        public function store(Request $request)
        {
            // dd($request->toArray());
            ResponseService::noPermissionThenSendJson('blog-create');
            $request->validate([
                'title.1' => 'required',
                'slug' => 'required',
                'image' => 'required|mimes:jpg,jpeg,png|max:7168',
            ]);
            try {
                $data = [
                    'title'       => $request->input('title')[1],
                    'slug'        => HelperService::generateUniqueSlug(new Blog(), $request->input('slug')),
                    'description' => $request->input('blog_description')[1] ?? '',
                    'tags'        => implode(',', $request->input('tags')[1] ?? []),
                ];

                if ($request->hasFile('image')) {
                    $data['image'] = FileService::compressAndUpload($request->file('image'), $this->uploadFolder);
                }

                $blog = Blog::create($data);
               foreach ($request->input('languages', []) as $langId) {
                    if ($langId != 1) {
                        $translatedTitle = $request->input("title.$langId");
                        $translatedDesc  = $request->input("blog_description.$langId");
                       $translatedTags = implode(',', $request->input("tags.$langId", []));
                        if ($translatedTitle || $translatedDesc || !empty($translatedTags)) {
                            BlogTranslation::create([
                                'blog_id'     => $blog->id,
                                'language_id' => $langId,
                                'title'       => $translatedTitle,
                                'description' => $translatedDesc,
                                'tags'        => $translatedTags,
                            ]);
                        }
                    }
                }
                $customBodyFields = [
                    'image' => $blog->image,
                    'blog_id' => $blog->id,
                    'type' => 'blog'
                ];

                redirect(route('blog.index'))->with([
                    'success' => trans("Blog Added Successfully")
                ])->send();
                // ResponseService::successRedirectResponse("Blog Added Successfully", route('blog.index'))->send();
                if (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                    (new SendFcmBatchJob(
                        $blog->title,
                        "New blog uploaded by admin. Check it out!",
                        'blog',
                        $customBodyFields,
                        true, 
                        []
                    ))->handle();
                } else {
                    register_shutdown_function(function () use ($blog, $customBodyFields) {
                        try {
                            (new SendFcmBatchJob(
                                $blog->title,
                                "New blog uploaded by admin. Check it out!",
                                'blog',
                                $customBodyFields,
                                true,
                                []
                            ))->handle();
                        } catch (\Throwable $th) {
                            Log::error('Background notification job failed: ' . $th->getMessage());
                        }
                    });
                }
                exit();

            } catch (Throwable $th) {
                ResponseService::logErrorRedirect($th, "BlogController->store");
                ResponseService::errorRedirectResponse();
            }
        }


    public function show(Request $request) {
        ResponseService::noPermissionThenSendJson('blog-list');
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'ASC');


        $sql = Blog::with('category:id,name');

        if (!empty($request->search)) {
            $sql = $sql->search($request->search);
        }

        $total = $sql->count();
        $sql = $sql->sort($sort, $order)->skip($offset)->take($limit);
        $result = $sql->get();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;
        foreach ($result as $key => $row) {
            $operate = '';
            if (Auth::user()->can('blog-update')) {
                $operate .= BootstrapTableService::editButton(route('blog.edit', $row->id));
            }

            if (Auth::user()->can('blog-delete')) {
                $operate .= BootstrapTableService::deleteButton(route('blog.destroy', $row->id));
            }
            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['created_at'] = Carbon::createFromFormat('Y-m-d H:i:s', $row->created_at)->format('d-m-y H:i:s');
            $tempRow['updated_at'] = Carbon::createFromFormat('Y-m-d H:i:s', $row->updated_at)->format('d-m-y H:i:s');
            $tempRow['operate'] = $operate;
              $tempRow['description'] = Str::limit(strip_tags($row->description), 200);

            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function edit($id)
        {
            ResponseService::noPermissionThenRedirect('blog-update');

           $blog = Blog::with('translations')->findOrFail($id);
            $categories = Category::all();
            $languages = CachingService::getLanguages()->values();

            // Get translations as keyed array by language_id
            $translations = $blog->translations->keyBy('language_id');
            return view('blog.edit', compact('blog', 'categories', 'languages', 'translations'));
        }


        public function update(Request $request, $id)
            {
                ResponseService::noPermissionThenSendJson('blog-update');
                try {
                    $request->validate([
                        'title.1' => 'required',
                        'slug' => 'required',
                        'image' => 'nullable|mimes:jpg,jpeg,png|max:7168',
                    ]);

                    $blog = Blog::findOrFail($id);
                    $data = [
                        'title'       => $request->input('title')[1],
                        'slug'        => HelperService::generateUniqueSlug(new Blog(), $request->input('slug'), $blog->id),
                        'description' => $request->input('blog_description')[1] ?? '',
                        'tags'        => implode(',', $request->input('tags')[1] ?? []),
                    ];

                    if ($request->hasFile('image')) {
                        $data['image'] = FileService::compressAndReplace($request->file('image'), $this->uploadFolder, $blog->getRawOriginal('image'));
                    }

                    $blog->update($data);

                    foreach ($request->input('languages', []) as $langId) {
                        if ($langId != 1) {
                            $translatedTitle = $request->input("title.$langId");
                            $translatedDesc  = $request->input("blog_description.$langId");
                            $translatedTags  = $request->input("tags.$langId", []);

                            if ($translatedTitle || $translatedDesc || !empty($translatedTags)) {
                                BlogTranslation::updateOrCreate(
                                    ['blog_id' => $blog->id, 'language_id' => $langId],
                                    [
                                        'title'       => $translatedTitle,
                                        'description' => $translatedDesc,
                                        'tags'        => implode(',', $translatedTags),
                                    ]
                                );
                            }
                        }
                    }

                    ResponseService::successRedirectResponse("Blog Updated Successfully", route('blog.index'));
                } catch (Throwable $th) {
                    ResponseService::logErrorRedirect($th);
                    ResponseService::errorRedirectResponse('Something Went Wrong');
                }
            }


    public function destroy($id) {
        ResponseService::noPermissionThenSendJson('blog-delete');
        try {
            $blog = Blog::find($id);
            FileService::delete($blog->getRawOriginal('image'));
            $blog->delete();
            ResponseService::successResponse('Blog delete successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse('Something Went Wrong ');
        }
    }

}
