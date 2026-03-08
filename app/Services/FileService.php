<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FileService {
    private static function resolveWatermarkPath(): ?string
    {
        $preferred = public_path('lmx-watermark.png');
        if (file_exists($preferred)) {
            return $preferred;
        }

        $fallback = public_path('watermark.png');
        if (file_exists($fallback)) {
            return $fallback;
        }

        return null;
    }

    private static function randomBetween(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }
        return random_int($min, $max);
    }

    private static function pickRandomEdgePosition(
        int $imageWidth,
        int $imageHeight,
        int $watermarkWidth,
        int $watermarkHeight,
        int $padding
    ): array {
        $minX = $padding;
        $maxX = max($padding, $imageWidth - $watermarkWidth - $padding);
        $minY = $padding;
        $maxY = max($padding, $imageHeight - $watermarkHeight - $padding);
        $edge = ['top', 'right', 'bottom', 'left'][random_int(0, 3)];

        if ($edge === 'top') {
            return [self::randomBetween($minX, $maxX), $minY];
        }
        if ($edge === 'right') {
            return [$maxX, self::randomBetween($minY, $maxY)];
        }
        if ($edge === 'bottom') {
            return [self::randomBetween($minX, $maxX), $maxY];
        }

        return [$minX, self::randomBetween($minY, $maxY)];
    }

    private static function applyRandomEdgeWatermark($image, string $watermarkPath): void
    {
        if (!file_exists($watermarkPath)) {
            return;
        }

        $imageWidth = $image->width();
        $imageHeight = $image->height();
        $targetWatermarkWidth = max(1, (int) round($imageWidth * 0.22));

        $watermark = Image::make($watermarkPath)
            ->resize($targetWatermarkWidth, null, function ($constraint) {
                $constraint->aspectRatio();
            })
            ->opacity(35);

        $padding = max(8, (int) round(min($imageWidth, $imageHeight) * 0.02));
        [$x, $y] = self::pickRandomEdgePosition(
            $imageWidth,
            $imageHeight,
            $watermark->width(),
            $watermark->height(),
            $padding
        );

        $image->insert($watermark, 'top-left', $x, $y);
    }

    /**
     * @param $requestFile
     * @param $folder
     * @return string
     */
    public static function compressAndUpload($requestFile, $folder)
    {
        $file_name = uniqid('', true) . time() . '.' . $requestFile->getClientOriginalExtension();
        $path = $folder . '/' . $file_name;
        $disk = Storage::disk(config('filesystems.default'));
        $extension = strtolower((string) $requestFile->getClientOriginalExtension());

        // Prefer compressed upload for raster images; fall back to raw upload if compression fails.
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            try {
                $image = Image::make($requestFile)
                    ->orientate()
                    ->encode(null, 60);

                $stored = $disk->put($path, (string) $image);
                if ($stored !== true) {
                    throw new RuntimeException('Compressed image write failed');
                }
                return $path;
            } catch (Throwable $e) {
                Log::warning('Image compression failed, falling back to direct upload: ' . $e->getMessage());
            }
        }

        try {
            $storedPath = $disk->putFileAs($folder, $requestFile, $file_name);
            if ($storedPath === false || empty($storedPath)) {
                throw new RuntimeException('Direct image upload returned empty path');
            }
            return $storedPath;
        } catch (Throwable $e) {
            Log::error('Image upload failed: ' . $e->getMessage());
            throw new RuntimeException('Image upload failed');
        }
    }



    /**
     * @param $requestFile
     * @param $folder
     * @return string
     */
   public static function upload($requestFile, $folder) {
        $file_name = uniqid('', true) . time() . '.' . $requestFile->getClientOriginalExtension();
        Storage::disk(config('filesystems.default'))->putFileAs($folder, $requestFile, $file_name);
        return $folder . '/' . $file_name;
    }

    /**
     * @param $requestFile
     * @param $folder
     * @param $deleteRawOriginalImage
     * @return string
     */
    public static function replace($requestFile, $folder, $deleteRawOriginalImage) {
        self::delete($deleteRawOriginalImage);
        return self::upload($requestFile, $folder);
    }

    /**
     * @param $requestFile
     * @param $folder
     * @param $deleteRawOriginalImage
     * @return string
     */
    public static function compressAndReplace($requestFile, $folder, $deleteRawOriginalImage) {
        // Upload first; remove old file only after successful new upload.
        $newPath = self::compressAndUpload($requestFile, $folder);
        if (!empty($deleteRawOriginalImage) && !empty($newPath)) {
            self::delete($deleteRawOriginalImage);
        }
        return $newPath;
    }


    /**
     * @param $requestFile
     * @param $code
     * @return string
     */
    public static function uploadLanguageFile($requestFile, $code) {
        $filename = $code . '.' . $requestFile->getClientOriginalExtension();
        if (file_exists(base_path('resources/lang/') . $filename)) {
            File::delete(base_path('resources/lang/') . $filename);
        }
        $requestFile->move(base_path('resources/lang/'), $filename);
        return $filename;
    }

    /**
     * @param $file
     * @return bool
     */
    public static function deleteLanguageFile($file) {
        if (file_exists(base_path('resources/lang/') . $file)) {
            return File::delete(base_path('resources/lang/') . $file);
        }
        return true;
    }


    /**
     * @param $image = rawOriginalPath
     * @return bool
     */
    public static function delete($image) {
        if (!empty($image) && Storage::disk(config('filesystems.default'))->exists($image)) {
            return Storage::disk(config('filesystems.default'))->delete($image);
        }

        //Image does not exist in server so feel free to upload new image
        return true;
    }

    /**
     * @throws Exception
     */
    public static function compressAndUploadWithWatermark($requestFile, $folder, bool $applyWatermark = true) {
        $file_name = uniqid('', true) . time() . '.' . $requestFile->getClientOriginalExtension();

        try {
            if (in_array($requestFile->getClientOriginalExtension(), ['jpg', 'jpeg', 'png'])) {
                $fullWatermarkPath = self::resolveWatermarkPath();

                $imagePath = $requestFile->getPathname();
                if (!file_exists($imagePath) || !is_readable($imagePath)) {
                    throw new RuntimeException("Uploaded image file is not readable at path: " . $imagePath);
                }
                $image = Image::make($imagePath)->encode(null, 60);

                if ($applyWatermark && !empty($fullWatermarkPath) && file_exists($fullWatermarkPath)) {
                    self::applyRandomEdgeWatermark($image, $fullWatermarkPath);
                }

                Storage::disk(config('filesystems.default'))->put($folder . '/' . $file_name, (string)$image->encode());
            } else {
                // Else assign file as it is
                $file = $requestFile;
                $file->storeAs($folder, $file_name, 'public');
            }
            return $folder . '/' . $file_name;

        } catch (Exception $e) {
            throw new RuntimeException($e);
            //            $file = $requestFile;
            //            return  $file->storeAs($folder, $file_name, 'public');
        }
    }
    public static function compressAndReplaceWithWatermark($requestFile, $folder, $deleteRawOriginalImage = null, bool $applyWatermark = true)
{

    if (!empty($deleteRawOriginalImage)) {
        self::delete($deleteRawOriginalImage);
    }

    $file_name = uniqid('', true) . time() . '.' . $requestFile->getClientOriginalExtension();

    try {
        if (in_array($requestFile->getClientOriginalExtension(), ['jpg', 'jpeg', 'png'])) {
            $fullWatermarkPath = self::resolveWatermarkPath();
            $imagePath = $requestFile->getPathname();
            if (!file_exists($imagePath) || !is_readable($imagePath)) {
                throw new RuntimeException("Uploaded image file is not readable at path: " . $imagePath);
            }
            $image = Image::make($imagePath)->encode(null, 60);


            if ($applyWatermark && !empty($fullWatermarkPath) && file_exists($fullWatermarkPath)) {
                self::applyRandomEdgeWatermark($image, $fullWatermarkPath);
            }


            Storage::disk(config('filesystems.default'))->put($folder . '/' . $file_name, (string)$image->encode());
        } else {

            $file = $requestFile;
            $file->storeAs($folder, $file_name, 'public');
        }

        return $folder . '/' . $file_name;

    } catch (Exception $e) {
        throw new RuntimeException($e);
    }
}


}
