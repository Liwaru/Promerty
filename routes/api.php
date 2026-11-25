<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\LocalFirstPartySession as EnsureFrontendRequestsAreStateful;

require __DIR__.'/feed.php';

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware([EnsureFrontendRequestsAreStateful::class, 'auth:sanctum', 'active']);

use App\Http\Controllers\Api\Admin\AgentController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\GoogleAuthController;
use App\Http\Controllers\Api\Auth\PhoneAuthController;
use App\Http\Controllers\Api\Auth\ProfileController;


Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-register-api');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login-password');

    Route::post('/phone/otp', [PhoneAuthController::class, 'requestPublicOtp'])->middleware('throttle:auth-otp-request');
    Route::post('/phone/register', [PhoneAuthController::class, 'register'])->middleware('throttle:auth-phone-register');
    Route::post('/phone/login', [PhoneAuthController::class, 'login'])->middleware('throttle:auth-phone-login');

    Route::post('/google', [GoogleAuthController::class, 'login'])->middleware('throttle:auth-google');
});

Route::middleware([EnsureFrontendRequestsAreStateful::class, 'auth:sanctum', 'active'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);

    Route::get('/me', [ProfileController::class, 'show']);
    Route::patch('/me', [ProfileController::class, 'update']);
    Route::patch('/me/password', [\App\Http\Controllers\AccountPasswordController::class, 'update'])
        ->middleware('throttle:auth-login-password');

    Route::post('/me/phone/otp', [PhoneAuthController::class, 'requestVerifyOtp'])->middleware('throttle:auth-otp-request');
    Route::post('/me/phone/verify', [PhoneAuthController::class, 'verifyPhone'])->middleware('throttle:auth-otp-verify');

    Route::prefix('admin')->middleware(['role:admin,super_admin','permission:verify_agents'])->group(function () {
        Route::get('/agents/pending', [AgentController::class, 'pending']);
        Route::post('/agents', [AgentController::class, 'store']);
        Route::post('/agents/{user}/verify', [AgentController::class, 'verify']);
        Route::post('/agents/{user}/reject', [AgentController::class, 'reject']);
    });

    Route::prefix('agent')->middleware(['role:agent', 'verified.agent'])->group(function () {
        Route::get('/listing-options', [\App\Http\Controllers\Api\Agent\ListingController::class, 'options'])
            ->middleware('permission:manage_own_listings');
        Route::get('/listings', [\App\Http\Controllers\Api\Agent\ListingController::class, 'index'])
            ->middleware('permission:manage_own_listings');
        Route::get('/videos', [\App\Http\Controllers\Api\Agent\ListingController::class, 'videos'])
            ->middleware(['permission:manage_own_listings', 'throttle:60,1,agent-portfolio']);
        Route::post('/listings', [\App\Http\Controllers\Api\Agent\ListingController::class, 'store'])
            ->middleware(['permission:create_listings', 'throttle:30,1']);
        Route::prefix('/listings/{listing}/video-upload')->where(['listing'=>'[0-9]+', 'id'=>'[a-f0-9]{48}'])
            ->middleware(['permission:manage_own_listings','permission:create_posts'])
            ->controller(\App\Http\Controllers\Api\Agent\VideoUploadController::class)->group(function () {
                Route::post('/', 'begin')->middleware('throttle:12,1,agent-video-start');
                Route::post('/{id}/chunk', 'append')->middleware('throttle:240,1,agent-video-chunk');
                Route::post('/{id}/finish', 'finish')->middleware('throttle:12,1,agent-video-finish');
                Route::delete('/{id}', 'cancel')->middleware('throttle:12,1,agent-video-cancel');
            });
        Route::middleware(['permission:manage_own_listings','throttle:60,1,agent-listing'])->group(function () {
            Route::get('/listings/{listing}', [\App\Http\Controllers\Api\Agent\ListingController::class,'show'])->whereNumber('listing');
            Route::patch('/listings/{listing}', [\App\Http\Controllers\Api\Agent\ListingController::class,'update'])->whereNumber('listing');
            Route::post('/listings/{listing}/publish', [\App\Http\Controllers\Api\Agent\ListingController::class,'publish'])->whereNumber('listing')->middleware('permission:create_posts');
            Route::post('/listings/{listing}/archive', [\App\Http\Controllers\Api\Agent\ListingController::class,'archive'])->whereNumber('listing');
            Route::post('/listings/{listing}/media', [\App\Http\Controllers\Api\Agent\MediaController::class,'store'])->whereNumber('listing')->middleware(['permission:create_posts','throttle:45,1,agent-upload']);
            Route::post('/listings/{listing}/media-link', [\App\Http\Controllers\Api\Agent\MediaController::class,'link'])->whereNumber('listing')->middleware(['permission:create_posts','throttle:20,1,agent-upload']);
            Route::delete('/listings/{listing}/media/{media}', [\App\Http\Controllers\Api\Agent\MediaController::class,'destroy'])->whereNumber(['listing','media']);
            Route::patch('/listings/{listing}/media-order', [\App\Http\Controllers\Api\Agent\MediaController::class,'reorder'])->whereNumber('listing')->middleware('permission:create_posts');
        });
    });

    // Examples for the next feature modules:
    // Route::middleware(['role:agent', 'verified.agent'])->group(...);
    // Route::middleware('phone.verified')->post('/surveys', ...);
    // Route::middleware('permission:moderate_content')->group(...);
});
