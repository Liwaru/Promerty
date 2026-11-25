<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Cache\{ArrayStore, DatabaseStore, FileStore, RedisStore, Repository};
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\{Cache, Log};
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PasswordLoginAttempts
{
    private const LIMIT = 5;
    private const WINDOW = 3600;
    public const DENIED = 'Email atau password salah, atau akun tidak aktif.';
    public const THROTTLED = 'Terlalu banyak percobaan masuk. Silakan coba lagi nanti.';

    public function check(string $email, callable $verify): mixed
    {
        $secret=(string) config('app.key');
        abort_if($secret==='',503,'Layanan masuk sementara tidak tersedia.');
        // Use the same lookup semantics as authentication, including DB collation.
        $identity=User::withTrashed()->where('email',$email)->value('email') ?? $email;
        $key='password-login:v1:'.hash_hmac('sha256',mb_strtolower(trim($identity)),$secret);
        $ticket=bin2hex(random_bytes(16));
        $this->locked($key,function (Repository $cache) use ($key,$ticket): void {
            $at=now()->timestamp;
            $attempts=$this->recent($cache,$key,$at);
            if (count($attempts)>=self::LIMIT) {
                throw new HttpException(429,self::THROTTLED,null,['Retry-After'=>(string)(min($attempts)+self::WINDOW-$at)]);
            }
            // Reserve before checking a password so parallel requests share the same budget.
            $attempts[$ticket]=$at;
            $this->persist($cache,$key,$attempts,$at);
        });
        try {
            $verified=$verify();
        } catch (\Throwable $error) {
            $this->release($key,$ticket);
            throw $error;
        }
        if ($verified) $this->release($key,$ticket);
        return $verified;
    }

    private function release(string $key,string $ticket): void
    {
        $this->locked($key,function (Repository $cache) use ($key,$ticket): void {
            $at=now()->timestamp;
            $attempts=$this->recent($cache,$key,$at);
            unset($attempts[$ticket]);
            $this->persist($cache,$key,$attempts,$at);
        });
    }

    private function recent(Repository $cache,string $key,int $at): array
    {
        return array_filter($cache->get($key,[]),fn (int $time) => $time > $at-self::WINDOW);
    }

    private function persist(Repository $cache,string $key,array $attempts,int $at): void
    {
        $ok=$attempts ? $cache->put($key,$attempts,max($attempts)+self::WINDOW-$at) : $cache->forget($key);
        if ($attempts && !$ok) throw new \RuntimeException('Could not persist login budget.');
    }

    private function locked(string $key,callable $operation): void
    {
        try {
            $cache=Cache::store(config('cache.password_login_store'));
            $store=$cache->getStore();
            $supported=$store instanceof DatabaseStore || $store instanceof RedisStore
                || (!app()->isProduction() && $store instanceof FileStore)
                || (app()->environment('testing') && $store instanceof ArrayStore);
            if (!$supported || !$store instanceof LockProvider) throw new \RuntimeException('Invalid login budget store.');
            $store->lock($key.':lock',15)->block(2,fn () => $operation($cache));
        } catch (HttpException $error) {
            throw $error;
        } catch (\Throwable) {
            Log::error('security.password_login_budget_unavailable');
            throw new HttpException(503,'Layanan masuk sementara tidak tersedia.');
        }
    }
}
