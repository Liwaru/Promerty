<?php

namespace App\Providers;

use App\Models\Comment;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Post;
use App\Models\SupportTicket;
use App\Models\SurveyRequest;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Contracts\OtpDelivery::class, \App\Services\UnavailableOtpDelivery::class);
    }

    public function boot(): void
    {
        \App\Support\AuthenticationRateLimits::register();
        ResetPassword::createUrlUsing(static fn (User $user, string $token): string =>
            rtrim(config('app.url'), '/').route('password.reset', [
                'token'=>$token, 'email'=>$user->getEmailForPasswordReset(),
            ], false));
        User::updated(function (User $subject): void {
            if (!\App\Support\SecurityRollout::enabled()) return;
            $audit=app(\App\Services\SecurityAudit::class);$actor=auth()->user();
            if ($subject->wasChanged(['role_id','branch_id','status'])) $audit->record('account.access_changed',$actor,['subject_user_id'=>$subject->id]);
            if ($subject->wasChanged('password')) $audit->record('account.password_changed',$actor,['subject_user_id'=>$subject->id]);
        });
        Listing::updated(function (Listing $listing): void {
            if (\App\Support\SecurityRollout::enabled() && $listing->wasChanged(['agent_id','branch_id'])) {
                app(\App\Services\SecurityAudit::class)->record('listing.ownership_changed',auth()->user(),['listing_id'=>$listing->id]);
            }
        });
        \App\Models\AgentProfile::updated(function (\App\Models\AgentProfile $profile): void {
            if($profile->wasChanged('verification_status'))app(\App\Services\SecurityAudit::class)
                ->record('agent.verification_changed',auth()->user(),['subject_user_id'=>$profile->user_id]);
        });
        \App\Models\Branch::updated(function (\App\Models\Branch $branch): void {
            if($branch->wasChanged(['status','agency_id']))app(\App\Services\SecurityAudit::class)
                ->record('branch.access_changed',auth()->user(),['branch_id'=>$branch->id]);
        });
        \Illuminate\Support\Facades\Gate::after(function ($user,$ability,$result,$arguments): void {
            if($result===false||($result instanceof \Illuminate\Auth\Access\Response&&$result->denied())){
                $context=[];$target=$arguments[0]??null;
                if($target instanceof Listing)$context['listing_id']=$target->id;
                elseif($target instanceof User)$context['subject_user_id']=$target->id;
                app(\App\Services\SecurityAudit::class)->denied($user,'policy',$context);
            }
        });
        Relation::enforceMorphMap([
            'user' => User::class,
            'post' => Post::class,
            'comment' => Comment::class,
            'listing' => Listing::class,
            'conversation' => Conversation::class,
            'support_ticket' => SupportTicket::class,
            'survey' => SurveyRequest::class,
        ]);
    }
}
