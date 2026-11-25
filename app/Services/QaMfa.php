<?php

namespace App\Services;

use App\Models\{SecurityIdentity, User};
use App\Support\SecurityRollout;
use Illuminate\Support\Facades\{DB, Hash, RateLimiter};
use Illuminate\Validation\ValidationException;

/** QA rollout only. No public enrollment route or operational account enforcement. */
class QaMfa
{
    public function __construct(private readonly Totp $totp,private readonly SessionSecurity $sessions) {}
    private function guard(User $user): void
    {
        abort_unless(config('security.mfa_enabled', false)&&SecurityRollout::enabled()&&$user->isActive()&&in_array($user->role?->name,['owner','admin','branch_manager','agent','super_admin'],true),403);
    }
    private function limit(User $user): void
    {
        $key='security:mfa:'.$user->id;
        if(RateLimiter::tooManyAttempts($key,5))throw ValidationException::withMessages(['mfa_code'=>'Terlalu banyak percobaan. Coba lagi nanti.']);
        RateLimiter::hit($key,60);
    }
    private function password(User $user,string $password): void
    {
        $this->limit($user);
        if(!$user->password||!Hash::check($password,$user->password))$this->fail();
    }
    private function fail(): never {throw ValidationException::withMessages(['mfa_code'=>'Verifikasi keamanan tidak valid.']);}
    public function begin(User $user,string $password): string
    {
        $this->guard($user);$this->password($user,$password);
        $state=$this->sessions->state($user);abort_if($state->mfa_confirmed_at!==null,409);
        $secret=$this->totp->secret();
        $state->fill(['mfa_secret'=>$secret,'mfa_pending_until'=>now()->addMinutes(10),'mfa_last_step'=>null,'recovery_hashes'=>null]);
        $saved=SecurityIdentity::whereKey($user->id)->whereNull('mfa_confirmed_at')->update($state->getDirty());
        abort_unless($saved===1,409);
        app(SecurityAudit::class)->record('mfa.enrollment_started',$user);
        return $secret; // Caller must display privately once; never log/serialize the model secret.
    }
    public function confirm(User $user,string $code): array
    {
        $this->guard($user);$this->limit($user);$state=$this->sessions->state($user);
        if($state->mfa_confirmed_at||!$state->mfa_secret||!$state->mfa_pending_until?->isFuture())$this->fail();
        $step=$this->totp->match($state->mfa_secret,$code,now()->timestamp,null);if($step===null)$this->fail();
        $codes=[];for($i=0;$i<8;$i++)$codes[]=bin2hex(random_bytes(10));
        $saved=DB::transaction(function () use ($user,$state,$step,$codes): int {
            $saved=SecurityIdentity::whereKey($user->id)->whereNull('mfa_confirmed_at')->where('mfa_secret',$state->getRawOriginal('mfa_secret'))
                ->update(['mfa_confirmed_at'=>now(),'mfa_pending_until'=>null,'mfa_last_step'=>$step,'recovery_hashes'=>json_encode(array_map(fn($c)=>Hash::make($c),$codes),JSON_THROW_ON_ERROR)]);
            if ($saved===1) $this->sessions->revokeAll($user);
            return $saved;
        });
        if($saved!==1)$this->fail();
        app(SecurityAudit::class)->record('mfa.enrolled',$user);return $codes;
    }
    public function verify(User $user,string $code): void
    {
        $this->guard($user);$this->limit($user);$state=$this->sessions->state($user);
        if(!$state->mfa_confirmed_at||!$state->mfa_secret)$this->fail();
        if(preg_match('/^[0-9]{6}$/D',$code)){
            $step=$this->totp->match($state->mfa_secret,$code,now()->timestamp,$state->mfa_last_step);
            if($step===null)$this->fail();
            $updated=SecurityIdentity::whereKey($user->id)->whereNotNull('mfa_confirmed_at')
                ->where('mfa_secret',$state->getRawOriginal('mfa_secret'))
                ->where('mfa_last_step',$state->mfa_last_step)->update(['mfa_last_step'=>$step]);
            if($updated!==1)$this->fail();
        }elseif(preg_match('/^[a-f0-9]{20}$/D',$code)){
            $hashes=$state->recovery_hashes??[];$found=null;foreach($hashes as$i=>$hash)if(Hash::check($code,$hash))$found=$i;
            if($found===null)$this->fail();unset($hashes[$found]);
            $updated=SecurityIdentity::whereKey($user->id)->whereNotNull('mfa_confirmed_at')
                ->where('mfa_secret',$state->getRawOriginal('mfa_secret'))->where('revision',$state->revision)->update([
                'recovery_hashes'=>json_encode(array_values($hashes),JSON_THROW_ON_ERROR),'revision'=>$state->revision+1]);
            if($updated!==1)$this->fail();
            app(SecurityAudit::class)->record('mfa.recovery_used',$user);
        }else{$this->fail();}
    }
    public function assertSatisfied(User $user,string $code): void
    {
        if(!config('security.mfa_enabled', false)||!SecurityRollout::enabled()||!in_array($user->role?->name,['owner','admin','branch_manager','agent','super_admin'],true))return;
        if(SecurityIdentity::whereKey($user->id)->whereNotNull('mfa_confirmed_at')->exists())$this->verify($user,$code);
    }
    public function disable(User $user,string $password,string $code): void
    {
        $this->guard($user);$this->password($user,$password);
        DB::transaction(function () use ($user,$code): void {
            $this->verify($user,$code);
            SecurityIdentity::whereKey($user->id)->update(['mfa_secret'=>null,'mfa_confirmed_at'=>null,'mfa_pending_until'=>null,'mfa_last_step'=>null,'recovery_hashes'=>null]);
            $this->sessions->revokeAll($user);
        });
        app(SecurityAudit::class)->record('mfa.disabled',$user);
    }
}
