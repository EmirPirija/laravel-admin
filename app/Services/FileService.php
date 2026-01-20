<?php

namespace App\Services;

use App\Models\Setting;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FileService {
    /**
     * @param $requestFile
     * @param $folder
     * @return string
     */
    public static function compressAndUpload($requestFile, $folder)
        {
            $file_name = uniqid('', true) . time() . '.' . $requestFile->getClientOriginalExtension();

            if (in_array(strtolower($requestFile->getClientOriginalExtension()), ['jpg', 'jpeg', 'png'])) {

                try {
                    $image = Image::make($requestFile)
                        ->orientate()
                        ->encode(null, 60);

                    Storage::disk(config('filesystems.default'))
                        ->put($folder . '/' . $file_name, $image);

                } catch (\Exception $e) {
                    Log::error('Image upload failed: ' . $e->getMessage());
                    return false; // return if fails
                }

            } else {
                try {
                    Storage::disk(config('filesystems.default'))
                        ->putFileAs($folder, $requestFile, $file_name);
                } catch (\Exception $e) {
                    Log::error('S3 upload failed: ' . $e->getMessage());
                    return false; // return if fails
                }
            }

            return $folder . '/' . $file_name;
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
        if (!empty($deleteRawOriginalImage)) {
            self::delete($deleteRawOriginalImage);
        }
        return self::compressAndUpload($requestFile, $folder);
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
    public static function compressAndUploadWithWatermark($requestFile, $folder) {
        $file_name = uniqid('', true) . time() . '.' . $requestFile->getClientOriginalExtension();

        try {
            if (in_array($requestFile->getClientOriginalExtension(), ['jpg', 'jpeg', 'png'])) {
                $watermarkPath = Setting::where('name', 'watermark_image')->value('value');

                $fullWatermarkPath = storage_path('app/public/' . $watermarkPath);
                $watermark = null;

                $imagePath = $requestFile->getPathname();
                if (!file_exists($imagePath) || !is_readable($imagePath)) {
                    throw new RuntimeException("Uploaded image file is not readable at path: " . $imagePath);
                }
                $image = Image::make($imagePath)->encode(null, 60);
                $imageWidth = $image->width();
                $imageHeight = $image->height();

                if (!empty($watermarkPath) && file_exists($fullWatermarkPath)) {
                    $watermark = Image::make($fullWatermarkPath)
                        ->resize($imageWidth, $imageHeight, function ($constraint) {
                            $constraint->aspectRatio(); // Preserve aspect ratio
                        })
                        ->opacity(10);
                }

                if ($watermark) {
                    $image->insert($watermark, 'center');
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
    public static function compressAndReplaceWithWatermark($requestFile, $folder, $deleteRawOriginalImage = null)
{

    if (!empty($deleteRawOriginalImage)) {
        self::delete($deleteRawOriginalImage);
    }

    $file_name = uniqid('', true) . time() . '.' . $requestFile->getClientOriginalExtension();

    try {
        if (in_array($requestFile->getClientOriginalExtension(), ['jpg', 'jpeg', 'png'])) {
            $watermarkPath = Setting::where('name', 'watermark_image')->value('value');
            $fullWatermarkPath = storage_path('app/public/' . $watermarkPath);
            $watermark = null;
            $imagePath = $requestFile->getPathname();
            if (!file_exists($imagePath) || !is_readable($imagePath)) {
                throw new RuntimeException("Uploaded image file is not readable at path: " . $imagePath);
            }
            $image = Image::make($imagePath)->encode(null, 60);
            $imageWidth = $image->width();
            $imageHeight = $image->height();


            if (!empty($watermarkPath) && file_exists($fullWatermarkPath)) {
                $watermark = Image::make($fullWatermarkPath)
                    ->resize($imageWidth, $imageHeight, function ($constraint) {
                        $constraint->aspectRatio(); // Preserve aspect ratio
                    })
                    ->opacity(10);
            }

            if ($watermark) {
                $image->insert($watermark, 'center');
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
