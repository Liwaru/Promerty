<?php

use App\Http\Controllers\Api\Feed\{ContactController, DownloadController, FeedController, InteractionController};
use App\Http\Controllers\Api\Auth\PhoneAuthController;
use App\Http\Middleware\OptionalFeedUser;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\LocalFirstPartySession as EnsureFrontendRequestsAreStateful;

Route::middleware([EnsureFrontendRequestsAreStateful::class, OptionalFeedUser::class])->group(function () {
    Route::get('/listing-media/{key}', [\App\Http\Controllers\Api\Agent\MediaController::class,'show'])
        ->where('key','[a-f0-9]{48}\.(jpg|png|webp|mp4)')->middleware('throttle:120,1,listing-media');
    Route::get('/search', \App\Http\Controllers\Api\Feed\SearchController::class)->middleware('throttle:60,1,customer-search')->name('search.results');
    Route::get('/feed', [FeedController::class,'index'])->name('feed.index');
    Route::get('/feed/config', [FeedController::class,'config']);
    Route::get('/feed/following', [FeedController::class,'index'])->middleware('auth:sanctum')->name('feed.following');
    Route::get('/posts/{post}', [FeedController::class,'show'])->whereNumber('post');
    Route::get('/agents/{agent}', [FeedController::class,'agent'])->whereNumber('agent');
    Route::get('/posts/{post}/comments', [InteractionController::class,'comments'])->whereNumber('post');
    Route::post('/posts/{post}/shares', [InteractionController::class,'share'])->middleware('throttle:20,1,feed-share')->whereNumber('post');
    Route::post('/posts/{post}/views', \App\Http\Controllers\Api\Feed\ViewController::class)->middleware('throttle:60,1,feed-view')->whereNumber('post');
    Route::post('/posts/{post}/downloads', [DownloadController::class,'store'])->middleware('throttle:10,1,feed-download')->whereNumber('post');

    Route::middleware(['auth:sanctum','active'])->group(function () {
        Route::prefix('customer')->middleware(['role:customer','throttle:60,1,customer-workspace'])->controller(\App\Http\Controllers\Api\CustomerController::class)->group(function () {
            Route::get('profile','profile');
            Route::post('profile','updateProfile')->middleware('throttle:10,1,customer-profile');
            Route::get('following','following');
            Route::get('surveys','surveys')->middleware('permission:request_surveys');
            Route::get('conversations','inbox')->middleware('permission:message_agents');
            Route::get('conversations/{conversation}/messages','messages')->whereNumber('conversation')->middleware('permission:message_agents');
            Route::post('conversations/{conversation}/messages','send')->whereNumber('conversation')->middleware(['permission:message_agents','throttle:30,1,customer-messages']);
            Route::get('notifications','notifications');
            Route::patch('notifications/{notification}/read','readNotification')->whereNumber('notification');
            Route::get('activity','activity')->middleware('permission:interact_posts');
            Route::match(['patch','delete'],'comments/{comment}','changeComment')->whereNumber('comment')->middleware('permission:interact_posts');
        });
        Route::prefix('personal')->middleware(['role:agent','throttle:60,1,personal-workspace'])->controller(\App\Http\Controllers\Api\CustomerController::class)->group(function () {
            Route::get('profile','profile');
            Route::post('profile','updateProfile')->middleware('throttle:10,1,customer-profile');
            Route::get('conversations','inbox')->middleware('permission:message_agents');
            Route::get('conversations/{conversation}/messages','messages')->whereNumber('conversation')->middleware('permission:message_agents');
            Route::post('conversations/{conversation}/messages','send')->whereNumber('conversation')->middleware(['permission:message_agents','throttle:30,1,customer-messages']);
            Route::get('surveys','surveys')->middleware('permission:request_surveys');
            Route::get('activity','activity')->middleware('permission:interact_posts');
            Route::match(['patch','delete'],'comments/{comment}','changeComment')->whereNumber('comment')->middleware('permission:interact_posts');
            Route::get('incoming-surveys','incomingSurveys')->middleware(['verified.agent','permission:handle_own_surveys']);
        });
        Route::middleware(['permission:interact_posts','throttle:60,1,feed-social'])->group(function () {
            Route::match(['post','delete'],'/posts/{post}/like', [InteractionController::class,'like'])->whereNumber('post');
            Route::match(['post','delete'],'/posts/{post}/save', [InteractionController::class,'save'])->whereNumber('post');
            Route::post('/posts/{post}/comments', [InteractionController::class,'comment'])->whereNumber('post');
            Route::match(['post','delete'],'/agents/{agent}/follow', [InteractionController::class,'follow'])->whereNumber('agent');
            Route::get('/social/activity', [\App\Http\Controllers\Api\CustomerController::class,'activity']);
            Route::match(['patch','delete'],'/social/comments/{comment}', [\App\Http\Controllers\Api\CustomerController::class,'changeComment'])->whereNumber('comment');
        });
        Route::post('/listings/{listing}/surveys', [ContactController::class,'survey'])->middleware(['permission:request_surveys','throttle:10,1,feed-survey'])->whereNumber('listing');
        Route::post('/listings/{listing}/messages', [ContactController::class,'message'])->middleware(['permission:message_agents','throttle:30,1,feed-message'])->whereNumber('listing');
        Route::get('/listings/{listing}/messages', [ContactController::class,'messages'])->middleware('permission:message_agents')->whereNumber('listing');
        // Same existing OTP controllers, with first-party session/CSRF support scoped to feed routes.
        Route::post('/feed/phone/otp', [PhoneAuthController::class,'requestVerifyOtp'])->middleware('throttle:auth-otp-request');
        Route::post('/feed/phone/verify', [PhoneAuthController::class,'verifyPhone'])->middleware('throttle:auth-otp-verify');
    });
});
Route::get('/downloads/{item}', [DownloadController::class,'file'])->middleware(['signed:relative','throttle:30,1,feed-download-file'])->name('feed.download.file');
