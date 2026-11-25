<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../docs/qa/scripts/DeploymentSecurityPolicy.php';

final class DeploymentSecurityPolicyTest extends TestCase
{
    private function profile():array
    {
        return ['environment'=>'production','debug'=>false,'url'=>'https://promerty.example.test',
            'cookie_secure'=>true,'cookie_http_only'=>true,'cookie_same_site'=>'lax',
            'session_driver'=>'redis','cache_driver'=>'redis','rate_limit_cache_driver'=>'redis',
            'password_login_cache_driver'=>'database','queue_driver'=>'redis',
            'db_runtime_user'=>'pr_runtime','db_migration_user'=>'pr_migrate','db_host'=>'127.0.0.1',
            'db_tls_peer'=>true,'db_tls_identity'=>true,'trusted_proxies'=>[],'mfa'=>false,
            'media_driver'=>'local','log_drivers'=>['daily'],'mail_transport'=>'smtp',
            'smtp_host'=>'127.0.0.1','smtp_url_configured'=>false];
    }
    public function test_valid_static_profile_does_not_claim_runtime_or_production_readiness():void
    {
        $r=\DeploymentSecurityPolicy::inspect($this->profile());
        $this->assertSame('STATIC_PASS_ONLY',$r['status']);
        foreach(['runtimeVerified','effectiveGrantsVerified','certificateVerified','providerDeliveryVerified','productionGo']as$key)
            $this->assertFalse($r[$key]);
    }
    public function test_unsafe_deployment_settings_are_rejected_without_echoing_values():void
    {
        $mutations=['environment'=>'local','debug'=>true,'url'=>'https://private-user:PRIVATE_SECRET@example.test',
            'cookie_secure'=>false,'cookie_http_only'=>false,'cookie_same_site'=>'none','session_driver'=>'cookie',
            'cache_driver'=>'array','queue_driver'=>'sync','db_runtime_user'=>'root','db_migration_user'=>'pr_runtime',
            'db_host'=>'mysql://PRIVATE_SECRET@db','db_tls_peer'=>false,'db_tls_identity'=>false,'trusted_proxies'=>['*'],'mfa'=>true];
        foreach($mutations as$key=>$value){
            $p=$this->profile();$p[$key]=$value;$r=\DeploymentSecurityPolicy::inspect($p);
            $this->assertSame('BLOCKED_STATIC_PROFILE',$r['status'],$key);
            $this->assertStringNotContainsString('PRIVATE_SECRET',json_encode($r));
        }
    }
    public function test_partial_unknown_and_wrongly_typed_profiles_fail_closed():void
    {
        foreach([[],array_merge($this->profile(),['password'=>'PRIVATE_SECRET']),
            array_merge($this->profile(),['trusted_proxies'=>'127.0.0.1']),
            array_merge($this->profile(),['url'=>['https://example.test']]),
            array_merge($this->profile(),['cookie_secure'=>'true'])] as$p){
            $r=\DeploymentSecurityPolicy::inspect($p);$this->assertSame('BLOCKED_STATIC_PROFILE',$r['status']);
            $this->assertStringNotContainsString('PRIVATE_SECRET',json_encode($r));
        }
    }

    public function test_effective_limiter_stores_are_validated_independently_of_default_cache():void
    {
        foreach (['rate_limit_cache_driver'=>'SHARED_ROUTE_LIMIT_CACHE',
            'password_login_cache_driver'=>'SHARED_PASSWORD_LOGIN_CACHE'] as $key=>$issue) {
            foreach (['array','file','failover',null] as $unsupported) {
                $profile=$this->profile();
                $profile[$key]=$unsupported;
                $result=\DeploymentSecurityPolicy::inspect($profile);
                $this->assertSame('BLOCKED_STATIC_PROFILE',$result['status']);
                $this->assertSame([$issue],$result['issues']);
                $this->assertFalse($result['productionGo']);
            }
        }
    }

    public function test_zero_additional_cost_profile_rejects_metered_or_unverified_transport(): void
    {
        foreach ([
            ['media_driver','s3','LOCAL_MEDIA'],
            ['log_drivers',['daily','slack'],'LOCAL_LOGS'],
            ['log_drivers',[],'LOCAL_LOGS'],
            ['mail_transport','ses','LOCAL_SMTP'],
            ['mail_transport','log','LOCAL_SMTP'],
            ['smtp_host','unreviewed-smtp.example.test','LOCAL_SMTP'],
            ['smtp_url_configured',true,'LOCAL_SMTP'],
            ['smtp_url_configured','false','LOCAL_SMTP'],
        ] as [$key,$value,$issue]) {
            $profile=$this->profile();
            $profile[$key]=$value;
            $result=\DeploymentSecurityPolicy::inspect($profile);
            $this->assertSame('BLOCKED_STATIC_PROFILE',$result['status']);
            $this->assertSame([$issue],$result['issues']);
            $this->assertFalse($result['providerDeliveryVerified']);
        }
    }
}
