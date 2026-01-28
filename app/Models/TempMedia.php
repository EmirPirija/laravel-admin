<?php

namespace App\Http\Controllers;

use App\Models\TempMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class TempMediaController extends Controller
{
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|file|mimes:jpg,jpeg,png,webp|max:10240', // 10MB
        ]);

        $file = $request->file('image');
        $userId = $request->user()->id;

        $manager = new ImageManager(new Driver());
        $img = $manager->read($file->getPathname());

        // (opcionalno) auto-orijentacija ako koristi EXIF (Intervention v3 radi bolje s imagick)
        // $img = $img->orient();

        // ✅ Watermark
        $watermarkPath = public_path('lmx-watermark.png');
        if (file_exists($watermarkPath)) {
            // bottom-right, padding 24px
            $img->place($watermarkPath, 'bottom-right', 24, 24);
        }

        // ✅ Kompresija (bez resize-a, samo encode)
        // Ako želiš zadržati original format: možeš granati po mime
        $encoded = $img->toJpeg(90); // 88–92 sweet spot

        $name = uniqid('img_', true).'.jpg';
        $path = "tmp/images/{$userId}/{$name}";

        Storage::disk('public')->put($path, (string) $encoded);

        $temp = TempMedia::create([
            'user_id' => $userId,
            'type' => 'image',
            'path' => $path,
            'mime' => 'image/jpeg',
            'size' => Storage::disk('public')->size($path),
        ]);

        return response()->json([
            'error' => false,
            'data' => [
                'id' => $temp->id,
                'url' => Storage::disk('public')->url($path),
            ]
        ]);
    }

    public function uploadVideo(Request $request)
    {
        $request->validate([
            'video' => 'required|file|mimetypes:video/mp4,video/quicktime,video/webm|max:51200', // 50MB
        ]);

        $file = $request->file('video');
        $userId = $request->user()->id;

        // ✅ Za početak: samo snimi tmp bez recompress (brzo + stabilno)
        // Video recompress + watermark ćemo raditi kasnije kroz ffmpeg queue
        $path = $file->store("tmp/videos/{$userId}", 'public');

        $temp = TempMedia::create([
            'user_id' => $userId,
            'type' => 'video',
            'path' => $path,
            'mime' => $file->getMimeType(),
            'size' => Storage::disk('public')->size($path),
        ]);

        return response()->json([
            'error' => false,
            'data' => [
                'id' => $temp->id,
                'url' => Storage::disk('public')->url($path),
            ]
        ]);
    }

    public function delete(Request $request, $id)
    {
        $temp = TempMedia::where('id', $id)->where('user_id', $request->user()->id)->firstOrFail();
        Storage::disk('public')->delete($temp->path);
        $temp->delete();

        return response()->json(['error' => false]);
    }
}
