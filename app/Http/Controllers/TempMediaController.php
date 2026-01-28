<?php

namespace App\Http\Controllers;

use App\Models\TempMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Facades\Image;
// Koristimo punu putanju za FFMpeg klase da izbjegnemo greske ako nisu importovane
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;

class TempMediaController extends Controller
{
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|file|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        $file = $request->file('image');
        $userId = $request->user()->id;

        Storage::disk('public')->makeDirectory("tmp/images/{$userId}");

        $img = Image::make($file->getPathname());
        $img->orientate();

        // --- WATERMARK LOGIKA (30% width, 50% opacity, Centered) ---
        $watermarkPath = public_path('lmx-watermark.png');

        if (file_exists($watermarkPath)) {
            $watermark = Image::make($watermarkPath);
            $watermarkWidth = $img->width() * 0.30;
            $watermark->resize($watermarkWidth, null, function ($constraint) {
                $constraint->aspectRatio();
            });
            $watermark->opacity(50);
            $img->insert($watermark, 'center');
        }

        // Resize na Full HD ako je prevelika
        if ($img->width() > 1920) {
            $img->resize(1920, null, function ($constraint) {
                $constraint->aspectRatio();
            });
        }

        $encoded = (string) $img->encode('jpg', 90);
        $name = uniqid('img_', true) . '.jpg';
        $path = "tmp/images/{$userId}/{$name}";

        Storage::disk('public')->put($path, $encoded);

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
        // Povećani limiti da ne pukne na velikim fajlovima
        set_time_limit(0); 
        ini_set('memory_limit', '512M');

        $request->validate([
            'video' => 'required|file|mimetypes:video/mp4,video/quicktime,video/webm|max:51200',
        ]);

        $file = $request->file('video');
        $userId = $request->user()->id;
        $originalSize = $file->getSize();

        Storage::disk('public')->makeDirectory("tmp/videos/{$userId}");
        
        $filename = uniqid('vid_', true) . '.mp4';
        $relativePath = "tmp/videos/{$userId}/{$filename}";
        $absolutePath = storage_path("app/public/{$relativePath}");

        $compressionSuccess = false;

        try {
            if (class_exists('FFMpeg\FFMpeg')) {
                // Koristimo 4 threada za balans između brzine i CPU opterećenja
                $ffmpeg = \FFMpeg\FFMpeg::create([
                    'ffmpeg.binaries'  => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
                    'ffprobe.binaries' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
                    'timeout'          => 3600, 
                    'ffmpeg.threads'   => 4, 
                ]);

                $video = $ffmpeg->open($file->getPathname());

                // --- NOVA PAMETNIJA LOGIKA ---

                // 1. Resize na 720p (HD Ready) 
                // Ovo je ključno za smanjenje veličine (1080p je nepotreban za oglase na mobitelu)
                $video->filters()->resize(new \FFMpeg\Coordinate\Dimension(1280, 720), \FFMpeg\Filters\Video\ResizeFilter::RESIZEMODE_INSET, true);

                $format = new \FFMpeg\Format\Video\X264();
                
                // 2. Smanjen Audio Bitrate (sa 128 na 96k - dovoljno za govor)
                $format->setAudioKiloBitrate(96);

                // 3. PAMETNA KOMPRESIJA (CRF)
                // Uklanjamo fiksni setKiloBitrate ili ga stavljamo nisko, a oslanjamo se na CRF.
                // CRF 23 = Default
                // CRF 28 = Dobra kompresija (standard za web)
                // CRF 32 = Agresivna kompresija (manji fajl, vidljiv gubitak na velikom ekranu, ok na mobitelu)
                
                // Koristimo 'superfast' umjesto 'ultrafast'. 
                // 'superfast' pravi MANJE fajlove od 'ultrafast' uz malu razliku u brzini.
                
                $format->setAdditionalParameters([
                    '-movflags', '+faststart', 
                    '-preset', 'superfast', 
                    '-crf', '30',         // OVDJE JE TAJNA: Veći broj = manji fajl (probaj 28 ako je slika loša)
                    '-maxrate', '800k',   // Ne prelazi 800kbps ni u ludilu
                    '-bufsize', '1200k'
                ]);

                $video->save($format, $absolutePath);
                
                clearstatcache();
                // Provjera: Ako je kompresovani fajl VEĆI od originala (npr. original je bio lošeg kvaliteta),
                // odbaci kompresiju i koristi original.
                if (file_exists($absolutePath) && filesize($absolutePath) > 0) {
                    if (filesize($absolutePath) < $originalSize) {
                        $compressionSuccess = true;
                    } else {
                        // Kompresija je povećala fajl, vraćamo se na original
                        \Log::info("Compression result larger than original. Using original.");
                        $compressionSuccess = false; 
                    }
                }
            } 
        } catch (\Throwable $e) {
            \Log::error("Video compression error: " . $e->getMessage());
        }

        // FALLBACK LOGIKA
        if (!$compressionSuccess) {
            // Obriši neuspjeli/uvećani fajl
            if (file_exists($absolutePath)) @unlink($absolutePath);
            // Kopiraj original
            $file->storeAs("tmp/videos/{$userId}", $filename, 'public');
        }

        $finalSize = Storage::disk('public')->size($relativePath);

        $temp = TempMedia::create([
            'user_id' => $userId,
            'type' => 'video',
            'path' => $relativePath,
            'mime' => 'video/mp4',
            'size' => $finalSize,
        ]);

        return response()->json([
            'error' => false,
            'data' => [
                'id' => $temp->id,
                'url' => Storage::disk('public')->url($relativePath),
                'size' => $finalSize,
                'original_size' => $originalSize,
                // Vraćamo true samo ako smo stvarno smanjili fajl
                'compressed' => $compressionSuccess 
            ]
        ]);
    }

    public function delete(Request $request, $id)
    {
        $temp = TempMedia::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        Storage::disk('public')->delete($temp->path);
        $temp->delete();

        return response()->json(['error' => false]);
    }
}