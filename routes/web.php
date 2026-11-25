<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('feed');
});

Route::view('/welcome', 'welcome')->name('welcome');
Route::get('/auth/google/redirect', [\App\Http\Controllers\Web\GoogleOAuthController::class, 'redirect'])->middleware('throttle:auth-google')->name('google.redirect');
Route::get('/auth/google/callback', [\App\Http\Controllers\Web\GoogleOAuthController::class, 'callback'])->middleware('throttle:auth-google')->name('google.callback');
Route::get('/account/google', [\App\Http\Controllers\Web\GoogleOAuthController::class, 'account'])->middleware(['auth:web', 'active'])->name('google.account');
Route::post('/auth/google/link', [\App\Http\Controllers\Web\GoogleOAuthController::class, 'link'])->middleware(['auth:web', 'active', 'throttle:auth-google-link'])->name('google.link');
Route::view('/search', 'search')->name('search');
Route::get('/settings', [\App\Http\Controllers\Web\AccountPagesController::class, 'settings'])->name('account.settings');
Route::get('/account/profile', [\App\Http\Controllers\Web\AccountPagesController::class, 'profile'])
    ->middleware(['auth:web','active'])->name('account.profile');
Route::middleware(['auth:web', 'active'])->controller(\App\Http\Controllers\Web\AccountEmailVerificationController::class)->group(function () {
    Route::post('/account/email/send', 'send')->middleware('throttle:3,10,email-verification-send')->name('account.email.send');
    Route::get('/account/email/confirm/{account}/{fingerprint}', 'show')
        ->whereNumber('account')->middleware('signed:relative')->name('account.email.confirm');
    Route::post('/account/email/confirm/{account}/{fingerprint}', 'confirm')
        ->whereNumber('account')->middleware(['signed:relative', 'throttle:10,1,email-verification-confirm'])->name('account.email.complete');
});
Route::patch('/settings/password', [\App\Http\Controllers\AccountPasswordController::class, 'update'])
    ->middleware(['auth:web', 'active', 'throttle:auth-login-password'])->name('account.password.update');
Route::post('/settings/password/otp', [\App\Http\Controllers\Web\EmailOtpController::class, 'startPassword'])
    ->middleware(['auth:web', 'active', 'throttle:auth-login-password'])->name('account.password.otp');
Route::where(['purpose' => 'login|password'])->prefix('account/email/otp/{purpose}')
    ->controller(\App\Http\Controllers\Web\EmailOtpController::class)->group(function () {
        Route::get('/', 'show')->name('email-otp.show');
        Route::post('/', 'verify')->middleware('throttle:15,1,email-otp-confirm')->name('email-otp.verify');
        Route::post('/resend', 'resend')->middleware('throttle:8,1,email-otp-resend')->name('email-otp.resend');
    });
Route::get('/help', [\App\Http\Controllers\Web\AccountPagesController::class, 'help'])->name('help.index');
Route::get('/agent/workspace', [\App\Http\Controllers\Web\AccountPagesController::class, 'agentWorkspace'])
    ->middleware(['auth:web','active','role:agent'])->name('agent.workspace');
Route::middleware([\App\Http\Middleware\AgentWebSession::class,'active','role:agent','verified.agent','permission:manage_own_listings'])
    ->group(function () {
        foreach (['dashboard' => ['', 'overview'], 'listings' => ['/listings', 'listings'],
            'create' => ['/listings/create', 'create'], 'projects' => ['/projects', 'projects'], 'media' => ['/media', 'media']] as $name => [$path, $section]) {
            Route::get('/agent'.$path, \App\Http\Controllers\Web\AgentWorkspaceController::class)
                ->defaults('section', $section)->name('agent.'.$name);
        }
    });
Route::view('/posts/{post}', 'feed')->whereNumber('post')->name('post.public');
Route::get('/agents/{agent}', \App\Http\Controllers\Web\AgentPortfolioController::class)->whereNumber('agent')->name('agent.public');
Route::view('/password/reset/complete', 'auth.password-reset-complete')->name('password.reset.complete');
Route::get('/password/reset/{token}', [\App\Http\Controllers\Web\CustomerPasswordResetController::class, 'show'])->name('password.reset');
Route::post('/password/reset', [\App\Http\Controllers\Web\CustomerPasswordResetController::class, 'reset'])->middleware('throttle:auth-reset')->name('password.update');

Route::prefix('web-auth')->controller(\App\Http\Controllers\Web\FeedSessionController::class)->group(function () {
    Route::get('/me', 'me');
    Route::post('/login', 'login')->middleware('throttle:auth-login-password');
    Route::post('/register', 'register')->middleware('throttle:auth-register-web');
    Route::post('/logout', 'logout');
});
Route::post('/web-auth/password/forgot', [\App\Http\Controllers\Web\CustomerPasswordResetController::class, 'requestLink'])
    ->middleware('throttle:auth-recovery')->name('password.email');

Route::view('/design-system', 'design-system')->name('design-system');

foreach(['branch'=>'branch_manager','admin'=>'admin','owner'=>'owner'] as $path=>$role){
    Route::get('/'.$path.'/{section?}',[\App\Http\Controllers\Web\OperationsWorkspaceController::class,'index'])
        ->middleware([\App\Http\Middleware\AgentWebSession::class,'active','role:'.$role])
        ->where('section','overview|agents|listings|users|customers|agent-data|branches|audit|reports|projects|content|catalog')->name($path.'.dashboard');
    Route::post('/'.$path.'/projects/{project}/assign',[\App\Http\Controllers\Web\OperationsWorkspaceController::class,'assignProject'])
        ->middleware([\App\Http\Middleware\AgentWebSession::class,'active','role:'.$role])->whereNumber('project');
    Route::post('/'.$path.'/assignments/{assignment}/revoke',[\App\Http\Controllers\Web\OperationsWorkspaceController::class,'revokeProject'])
        ->middleware([\App\Http\Middleware\AgentWebSession::class,'active','role:'.$role])->whereNumber('assignment');
}
Route::middleware([\App\Http\Middleware\AgentWebSession::class,'active','role:admin'])->group(function(){
    Route::middleware('permission:manage_permissions')->controller(\App\Http\Controllers\Web\AccessMatrixController::class)->group(function () {
        Route::get('/admin/access','index')->name('admin.access.index');
        Route::patch('/admin/access/{role}','update')->middleware('throttle:15,1,access-matrix')->name('admin.access.update');
        Route::post('/admin/access/history/{entry}/rollback','rollback')->whereNumber('entry')->middleware('throttle:15,1,access-matrix')->name('admin.access.rollback');
    });
    Route::post('/admin/users/{user}/password-reset', [\App\Http\Controllers\Web\CustomerPasswordResetController::class, 'requestManagedLink'])
        ->whereNumber('user')->middleware('throttle:5,1,admin-password-reset')->name('admin.users.password-reset');
    Route::post('/admin/directory/{kind}/{user?}',[\App\Http\Controllers\Web\OperationsWorkspaceController::class,'saveDirectoryAccount'])
        ->where(['kind'=>'customer|agent','user'=>'[0-9]+'])->middleware('throttle:30,1,admin-directory');
    Route::post('/admin/catalog/create',[\App\Http\Controllers\Web\OperationsWorkspaceController::class,'createProject']);
    Route::post('/admin/catalog/{project}/update',[\App\Http\Controllers\Web\OperationsWorkspaceController::class,'updateProject'])->whereNumber('project');
    Route::post('/admin/agents/create',[\App\Http\Controllers\Web\OperationsWorkspaceController::class,'createAgent']);
    Route::post('/admin/content/{post}/moderate',[\App\Http\Controllers\Web\OperationsWorkspaceController::class,'moderate'])->whereNumber('post');
    Route::post('/admin/users/{user}/status',[\App\Http\Controllers\Web\OperationsWorkspaceController::class,'userStatus'])->whereNumber('user');
    Route::post('/admin/branches/create',[\App\Http\Controllers\Web\OperationsWorkspaceController::class,'branchStore']);
    Route::post('/admin/agents/{user}/verify',[\App\Http\Controllers\Web\OperationsWorkspaceController::class,'verifyAgent'])->whereNumber('user');
});
