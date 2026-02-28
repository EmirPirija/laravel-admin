<?php

namespace App\Services;

use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResponseService
{
    private static function resolveErrorHttpStatus(string|int|null $code = null): int
    {
        $map = [
            config('constants.RESPONSE_CODE.VALIDATION_ERROR') => 422,
            config('constants.RESPONSE_CODE.PHONE_NOT_REGISTERED') => 404,
            config('constants.RESPONSE_CODE.UNAUTHORIZED') => 401,
            config('constants.RESPONSE_CODE.FORBIDDEN') => 403,
            config('constants.RESPONSE_CODE.INVALID_LOGIN') => 401,
            config('constants.RESPONSE_CODE.DEACTIVATED_ACCOUNT') => 403,
            config('constants.RESPONSE_CODE.NOT_FOUND') => 404,
            config('constants.RESPONSE_CODE.CONFLICT') => 409,
        ];

        if ($code !== null && array_key_exists($code, $map)) {
            return $map[$code];
        }

        return 500;
    }

    /**
     * @param $permission
     * @return Application|RedirectResponse|Redirector|true
     */
    public static function noPermissionThenRedirect($permission)
    {
        if (!Auth::user()->can($permission)) {
            return redirect(route('home'))->withErrors([
                'message' => trans("You Don't have enough permissions")
            ])->send();
        }
        return true;
    }

    /**
     * @param $permission
     * @return true
     */
    public static function noPermissionThenSendJson($permission)
    {
        if (!Auth::user()->can($permission)) {
            self::errorResponse(
                "You Don't have enough permissions",
                null,
                config('constants.RESPONSE_CODE.FORBIDDEN'),
                null,
                403
            );
        }
        return true;
    }

    /**
     * @param $role
     * @return Application|\Illuminate\Foundation\Application|RedirectResponse|Redirector|true
     */
    // Check user role
    public static function noRoleThenRedirect($role)
    {
        if (!Auth::user()->hasRole($role)) {
            return redirect(route('home'))->withErrors([
                'message' => trans("You Don't have enough permissions")
            ])->send();
        }
        return true;
    }

    /**
     * @param array $role
     * @return bool|Application|\Illuminate\Foundation\Application|RedirectResponse|Redirector
     */
    public static function noAnyRoleThenRedirect(array $role)
    {
        if (!Auth::user()->hasAnyRole($role)) {
            return redirect(route('home'))->withErrors([
                'message' => trans("You Don't have enough permissions")
            ])->send();
        }
        return true;
    }

    //    /**
    //     * @param $role
    //     * @return true
    //     */
    //    public static function noRoleThenSendJson($role)
    //    {
    //        if (!Auth::user()->hasRole($role)) {
    //            self::errorResponse("You Don't have enough permissions");
    //        }
    //        return true;
    //    }

    /**
     * @param $feature
     * @return RedirectResponse|true
     */
    // Check Feature
    //    public static function noFeatureThenRedirect($feature) {
    //        if (Auth::user()->school_id && !app(FeaturesService::class)->hasFeature($feature)) {
    //            return redirect()->back()->withErrors([
    //                'message' => trans('Purchase') . " " . $feature . " " . trans("to Continue using this functionality")
    //            ])->send();
    //        }
    //        return true;
    //    }
    //
    //    public static function noFeatureThenSendJson($feature) {
    //        if (Auth::user()->school_id && !app(FeaturesService::class)->hasFeature($feature)) {
    //            self::errorResponse(trans('Purchase') . " " . $feature . " " . trans("to Continue using this functionality"));
    //        }
    //        return true;
    //    }

    /**
     * If User don't have any of the permission that is specified in Array then Redirect will happen
     * @param array $permissions
     * @return RedirectResponse|true
     */
    public static function noAnyPermissionThenRedirect(array $permissions)
    {
        if (!Auth::user()->canany($permissions)) {
            return redirect()->back()->withErrors([
                'message' => trans("You Don't have enough permissions")
            ])->send();
        }
        return true;
    }

    /**
     * If User don't have any of the permission that is specified in Array then Json Response will be sent
     * @param array $permissions
     * @return true
     */
    public static function noAnyPermissionThenSendJson(array $permissions)
    {
        if (!Auth::user()->canany($permissions)) {
            self::errorResponse(
                "You Don't have enough permissions",
                null,
                config('constants.RESPONSE_CODE.FORBIDDEN'),
                null,
                403
            );
        }
        return true;
    }

    /**
     * @param string|null $message
     * @param null $data
     * @param array $customData
     * @param null $code
     * @return void
     */
    public static function successResponse(
        string|null $message = "Success",
        $data = null,
        array $customData = array(),
        $code = null,
        int $httpStatus = 200
    ): void
    {
        response()->json(array_merge([
            'error'   => false,
            'message' => trans($message),
            'data'    => $data,
            'code'    => $code ?? config('constants.RESPONSE_CODE.SUCCESS')
        ], $customData), $httpStatus)->send();
        exit();
    }

    /**
     * @param string $message
     * @param $url
     * @return Application|\Illuminate\Foundation\Application|RedirectResponse|Redirector
     */
    public static function successRedirectResponse(string $message = "success", $url = null)
    {
        return isset($url) ? redirect($url)->with([
            'success' => trans($message)
        ])->send() : redirect()->back()->with([
            'success' => trans($message)
        ])->send();
    }

    /**
     *
     * @param string $message - Pass the Translatable Field
     * @param null $data
     * @param string $code
     * @param null $e
     * @return void
     */
    public static function errorResponse(
        string $message = 'Error Occurred',
        $data = null,
        string|int $code = null,
        $e = null,
        ?int $httpStatus = null
    )
    {
        $resolvedHttpStatus = $httpStatus ?? self::resolveErrorHttpStatus($code);
        response()->json([
            'error'   => true,
            'message' => trans($message),
            'data'    => $data,
            'code'    => $code ?? config('constants.RESPONSE_CODE.EXCEPTION_ERROR'),
            'details' => (!empty($e) && is_object($e)) ? $e->getMessage() . ' --> ' . $e->getFile() . ' At Line : ' . $e->getLine() : ''
        ], $resolvedHttpStatus)->send();
        exit();
    }

    /**
     * return keyword should, must be used wherever this function is called.
     * @param string|string[] $message
     * @param $url
     * @param null $input
     * @return RedirectResponse
     */
    public static function errorRedirectResponse(string|array $message = 'Error Occurred', $url = 'back', $input = null)
    {
        return $url == "back" ? redirect()->back()->with([
            'errors' => trans($message)
        ])->withInput($input) : redirect($url)->with([
            'errors' => trans($message)
        ])->withInput($input);
    }

    /**
     * @param string $message
     * @param null $data
     * @param null $code
     * @return void
     */
    public static function warningResponse(string $message = 'Error Occurred', $data = null, $code = null, int $httpStatus = 200)
    {
        response()->json([
            'error'   => false,
            'warning' => true,
            'code'    => $code,
            'message' => trans($message),
            'data'    => $data,
        ], $httpStatus)->send();
        exit();
    }


    /**
     * @param string $message
     * @param null $data
     * @return void
     */
    public static function validationError(string $message = 'Error Occurred', $data = null)
    {
        self::errorResponse($message, $data, config('constants.RESPONSE_CODE.VALIDATION_ERROR'), null, 422);
    }

    public static function conflictResponse(string $message = 'Conflict Occurred', $data = null, $code = null): void
    {
        self::errorResponse(
            $message,
            $data,
            $code ?? config('constants.RESPONSE_CODE.CONFLICT'),
            null,
            409
        );
    }

    public static function notFoundResponse(string $message = 'Data not found', $data = null): void
    {
        self::errorResponse(
            $message,
            $data,
            config('constants.RESPONSE_CODE.NOT_FOUND'),
            null,
            404
        );
    }

    public static function unauthorizedResponse(string $message = 'Unauthorized', $data = null): void
    {
        self::errorResponse(
            $message,
            $data,
            config('constants.RESPONSE_CODE.UNAUTHORIZED'),
            null,
            401
        );
    }

    public static function forbiddenResponse(string $message = "You Don't have enough permissions", $data = null): void
    {
        self::errorResponse(
            $message,
            $data,
            config('constants.RESPONSE_CODE.FORBIDDEN'),
            null,
            403
        );
    }

    /**
     * @param string $message
     * @return void
     */
    public static function validationErrorRedirect(string $message = 'Error Occurred')
    {
        self::errorRedirectResponse(route('custom-fields.create'), $message);
        exit();
    }

    /**
     * @param Throwable|Exception $e
     * @param string $logMessage
     * @param string $responseMessage
     * @param bool $jsonResponse
     * @return void
     */
    public static function logErrorResponse(Throwable|Exception $e, string $logMessage = ' ', string $responseMessage = 'Error Occurred', bool $jsonResponse = true)
    {
        $token= request()->bearerToken();

        Log::error($logMessage . ' ' . $e->getMessage() . '---> ' . $e->getFile() . ' At Line : ' . $e->getLine() . "\n\n" . request()->method() . " : " . request()->fullUrl() ."\nToken : ".$token. "\nParams : ", request()->all());
        if ($jsonResponse && config('app.debug')) {
            self::errorResponse($responseMessage, null, null, $e);
        }
    }
    /**
     * @param $e
     * @param string $logMessage
     * @param string $responseMessage
     * @param bool $jsonResponse
     */
    public static function logErrorRedirect($e, string $logMessage = ' ', string $responseMessage = 'Error Occurred', bool $jsonResponse = true)
    {
        Log::error($logMessage . ' ' . $e->getMessage() . '---> ' . $e->getFile() . ' At Line : ' . $e->getLine());
        if ($jsonResponse && config('app.debug')) {
            throw $e;
        }
    }

    public static function errorRedirectWithToast(string $message, $input = null)
    {
        return redirect()->back()->with('error', $message)->withInput($input);
    }
}
