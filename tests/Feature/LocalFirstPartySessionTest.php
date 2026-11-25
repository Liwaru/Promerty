<?php

namespace Tests\Feature;

use App\Http\Middleware\LocalFirstPartySession;
use Illuminate\Http\Request;
use Tests\TestCase;

class LocalFirstPartySessionTest extends TestCase
{
    private function check(string $source,string $host='localhost:8000',string $remote='127.0.0.1'):bool
    {
        $r=Request::create('http://'.$host.'/api/customer/profile','GET',server:['REMOTE_ADDR'=>$remote,'HTTP_ORIGIN'=>$source]);return LocalFirstPartySession::fromFrontend($r);
    }
    public function test_local_exact_origin_uses_first_party_session_without_changing_the_allowlist():void
    {
        config(['sanctum.stateful'=>[]]);$this->app->instance('env','local');
        $this->assertTrue($this->check('http://localhost:8000'));$this->assertTrue($this->check('http://127.0.0.1:8000','127.0.0.1:8000'));
        $this->assertSame([],config('sanctum.stateful'));
    }
    public function test_other_origins_ports_remote_clients_and_production_do_not_get_local_fallback():void
    {
        config(['sanctum.stateful'=>[]]);$this->app->instance('env','local');
        foreach(['http://evil.test','http://localhost:8001','https://localhost:8000','http://localhost:8000.evil.test','http://localhost:8000@evil.test'] as $url)$this->assertFalse($this->check($url));
        $this->assertFalse($this->check('http://localhost:8000','localhost:8000','192.168.1.10'));$this->assertFalse($this->check('http://localhost:8001','localhost:8001'));
        $this->app->instance('env','production');$this->assertFalse($this->check('http://localhost:8000'));
    }
    public function test_existing_sanctum_domain_configuration_is_preserved():void
    {
        $this->app->instance('env','production');config(['sanctum.stateful'=>['example.test']]);$this->assertTrue($this->check('https://example.test'));
    }
}
