<?php

namespace Tests\Feature;

use GoogleLiveQaSupport;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once __DIR__.'/../../docs/qa/scripts/GoogleLiveQaSupport.php';

class GoogleLiveQaPrivateFileTest extends TestCase
{
    public function test_laravel_rejects_unsigned_and_invalidly_signed_guest_requests_before_reading_storage(): void
    {
        Storage::shouldReceive('disk')->never();
        $path = '/storage/app/private/google-live-qa-20261122-r2/fixture.sqlite';

        $this->assertGuest();
        $this->getJson($path)->assertForbidden();
        $this->getJson($path.'?signature=invalid')->assertForbidden();
        $this->call('HEAD', $path)->assertForbidden();
    }

    public function test_qa_router_path_rule_applies_before_laravel_for_storage_urls(): void
    {
        foreach (['/storage', '/storage/fixture.sqlite', '/%73torage/fixture.sqlite', '/storage%2Ffixture.sqlite'] as $path) {
            $this->assertTrue(GoogleLiveQaSupport::blocksStoragePath($path));
        }
        foreach (['/', '/storage-info', '/auth/google/redirect', '/auth/google/callback', '/web-auth/login'] as $path) {
            $this->assertFalse(GoogleLiveQaSupport::blocksStoragePath($path));
        }
    }

    #[DataProvider('privateResponses')]
    public function test_private_probe_accepts_only_the_expected_empty_denial(int $status, array $extra, string $body, bool $expected): void
    {
        $headers = $extra + array_map(static fn ($value) => [$value], GoogleLiveQaSupport::PRIVATE_DENIAL_HEADERS);
        $evidence = GoogleLiveQaSupport::privateDenialEvidence($status, $headers, $body);
        $this->assertSame($expected, $evidence['passed']);
        $this->assertSame(['status', 'emptyBody', 'safeHeaders', 'passed'], array_keys($evidence));
    }

    public static function privateResponses(): array
    {
        return [
            'router denial' => [404, ['date' => ['Mon, 23 Nov 2026 09:12:00 GMT'], 'connection' => ['close']], '', true],
            'unexpected 403' => [403, [], '', false],
            'success status' => [200, [], '', false],
            'redirect' => [302, ['location' => ['/storage/fixture.sqlite']], '', false],
            'denial with redirect header' => [404, ['location' => ['/storage/fixture.sqlite']], '', false],
            'sqlite content' => [404, [], "SQLite format 3\0", false],
            'sensitive path in body' => [404, [], 'C:/private/fixture.sqlite', false],
            'file metadata' => [404, ['content-disposition' => ['attachment; filename=fixture.sqlite']], '', false],
            'nonzero file length' => [404, ['content-length' => ['10']], '', false],
            'private metadata in normally safe header' => [404, ['cache-control' => ['no-store, C:/private/fixture.sqlite']], '', false],
            'duplicate header' => [404, ['content-type' => ['text/plain; charset=UTF-8', 'text/plain; charset=UTF-8']], '', false],
            'unexpected content type' => [404, ['content-type' => ['application/json']], '', false],
        ];
    }
}
