<?php

namespace App\Services;

use App\Models\{SecurityIdentity, User};
use App\Support\SecurityRollout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Support\Str;

class SessionSecurity
{
    public function state(User $user): SecurityIdentity
    {
        abort_unless(SecurityRollout::enabled(), 404);
        return SecurityIdentity::firstOrCreate(['user_id'=>$user->id], ['session_version'=>Str::random(64)]);
    }
    private function passwordMarker(User $user): string
    {
        return hash_hmac('sha256', (string)$user->getAuthPassword(), (string)config('app.key'));
    }
    public function stamp(Request $request, User $user): void
    {
        $request->session()->put('security.password_marker', $this->passwordMarker($user));
        if (!SecurityRollout::enabled()) return;
        $request->session()->put('security.session_version', $this->state($user)->session_version);
    }
    public function valid(Request $request, User $user): bool
    {
        $password = $request->session()->get('security.password_marker');
        if (!SecurityRollout::enabled()) {
            return is_string($password) ? hash_equals($this->passwordMarker($user), $password)
                : !$request->session()->has(Auth::guard('web')->getName());
        }
        $state = SecurityIdentity::find($user->id);
        $version = $request->session()->get('security.session_version');
        return $state && is_string($version) && is_string($password)
            && hash_equals($state->session_version, $version) && hash_equals($this->passwordMarker($user), $password);
    }
    public function revokeAll(User $user): void
    {
        abort_unless(SecurityRollout::enabled(), 404);
        DB::transaction(function () use ($user): void {
            $this->state($user)->update(['session_version'=>Str::random(64)]);
            $user->forceFill(['remember_token'=>Str::random(60)])->save();
            $user->tokens()->delete();
        });
    }
    public function invalidateCurrent(Request $request): void
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) { $request->session()->invalidate(); $request->session()->regenerateToken(); }
    }
}
