<?php

namespace Modules\Platform\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Http\PublicHttpException;
use RuntimeException;
use Tests\TestCase;

/**
 * P1-08 / docs/07 (Headers, Logging) / docs/08 (Errors): security headers with a CSP nonce, a request ID on every
 * response, problem+json errors without internals, and UTC everywhere.
 */
class SecureDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Vite::useHotFile(storage_path('framework/testing/no-vite-dev-server')); // deterministic: no dev server
        Route::get('/_test/nonce', fn () => Vite::cspNonce());
        Route::get('/api/v1/_test/boom', fn () => throw new RuntimeException('db password=hunter2 in SELECT * FROM users'));
        Route::get('/api/v1/_test/gone', fn () => throw new PublicHttpException(410, 'This resource was withdrawn.'));
        Route::get('/api/v1/_test/leaky/{status}', fn (int $status) => abort($status, 'db password=hunter2 /var/www/html/app.php SELECT * FROM users'));
        Route::get('/_test/json-page', fn () => abort(403, 'internal reason'));
        Route::get('/api/v1/_test/in-middleware', fn () => 'unreachable')
            ->middleware(ThrowingProbeMiddleware::class);
        Route::get('/api/v1/_test/throttled', fn () => throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 30]));
        Route::post('/api/v1/_test/validate', fn (Request $request) => $request->validate(['name' => 'required|string']));
    }

    public function test_every_response_carries_the_security_headers(): void
    {
        $response = $this->get('/_test/nonce');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->assertHeaderMissing('Strict-Transport-Security');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'"] as $directive) {
            $this->assertStringContainsString($directive, $csp);
        }
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_the_csp_nonce_is_fresh_per_request_and_matches_the_one_given_to_vite(): void
    {
        $first = $this->get('/_test/nonce');
        $second = $this->get('/_test/nonce');

        $nonce = $first->getContent();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{20,}$/', (string) $nonce);
        $this->assertStringContainsString("script-src 'self' 'nonce-$nonce'", (string) $first->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("style-src 'self' 'nonce-$nonce'", (string) $first->headers->get('Content-Security-Policy'));
        $this->assertNotSame($nonce, $second->getContent());
    }

    public function test_the_vite_dev_server_is_allowed_only_outside_production(): void
    {
        $hot = storage_path('framework/testing/vite-hot-'.Str::random(8));
        @mkdir(dirname($hot), 0775, true);
        file_put_contents($hot, 'http://localhost:5173');
        Vite::useHotFile($hot);

        try {
            $csp = (string) $this->get('/_test/nonce')->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
            $this->assertStringContainsString('http://localhost:5173', $csp);
            $this->assertStringContainsString("connect-src 'self' http://localhost:5173 ws://localhost:5173", $csp);

            $this->app['env'] = 'production';
            $csp = (string) $this->get('/_test/nonce')->headers->get('Content-Security-Policy');
            $this->assertStringNotContainsString('localhost:5173', $csp);
            $this->assertStringNotContainsString('unsafe-inline', $csp);
        } finally {
            $this->app['env'] = 'testing';
            @unlink($hot);
        }
    }

    public function test_hsts_is_sent_in_production_over_https_only(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->get('https://localhost/_test/nonce')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            $this->get('http://localhost/_test/nonce')->assertHeaderMissing('Strict-Transport-Security');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_every_response_gets_a_fresh_server_generated_request_id(): void
    {
        $first = $this->withHeader('X-Request-Id', 'client-chosen-id')->get('/_test/nonce');
        $second = $this->get('/api/v1/_test/gone');

        $id = (string) $first->headers->get('X-Request-Id');
        $this->assertTrue(Str::isUlid($id), "Not a ULID: $id");
        $this->assertNotSame('client-chosen-id', $id);
        $this->assertTrue(Str::isUlid((string) $second->headers->get('X-Request-Id')), 'Error responses carry it too.');
        $this->assertNotSame($id, $second->headers->get('X-Request-Id'));
    }

    public function test_audit_rows_carry_the_request_id(): void
    {
        $this->app->make(AuditActions::class)->define('probe.requested');
        Route::get('/_test/audited', fn () => DB::transaction(fn () => $this->app->make(AuditLogger::class)->record('probe.requested')));

        $response = $this->get('/_test/audited')->assertOk();

        $this->assertSame($response->headers->get('X-Request-Id'), DB::table('audit_events')->where('ulid', $response->getContent())->value('request_id'));
    }

    public function test_api_errors_are_problem_json_with_the_request_id(): void
    {
        $response = $this->get('/api/v1/_test/gone');

        $response->assertStatus(410)->assertHeader('Content-Type', 'application/problem+json');
        $this->assertSame([
            'type' => 'about:blank',
            'title' => 'Gone',
            'status' => 410,
            'detail' => 'This resource was withdrawn.',
            'instance' => '/api/v1/_test/gone',
            'request_id' => $response->headers->get('X-Request-Id'),
        ], $response->json());

        $this->get('/api/v1/_test/no-such-route')->assertStatus(404)->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('title', 'Not Found')
            ->assertJsonPath('detail', 'The requested resource was not found.');
    }

    public function test_client_errors_never_echo_exception_messages(): void
    {
        foreach ([400, 403, 404, 405, 409, 418] as $status) {
            $response = $this->get("/api/v1/_test/leaky/$status");

            $response->assertStatus($status)->assertHeader('Content-Type', 'application/problem+json');
            foreach (['hunter2', '/var/www', 'SELECT'] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $response->getContent(), "Status $status");
            }
            $this->assertNotEmpty($response->json('detail'));
        }
    }

    public function test_json_requests_to_web_routes_get_problem_json(): void
    {
        $this->getJson('/_test/json-page')
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'You are not allowed to do this.');
    }

    public function test_errors_thrown_inside_middleware_get_the_headers_and_the_request_id(): void
    {
        $response = $this->get('/api/v1/_test/in-middleware');

        $response->assertStatus(500)->assertHeader('Content-Type', 'application/problem+json')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertTrue(Str::isUlid((string) $response->headers->get('X-Request-Id')));
        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('request_id'));
        $this->assertStringNotContainsString('thrown inside', (string) $response->getContent());
    }

    public function test_validation_errors_list_the_fields(): void
    {
        $this->postJson('/api/v1/_test/validate', [])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'The given data was invalid.')
            ->assertJsonStructure(['errors' => ['name']]);
    }

    public function test_server_errors_reveal_nothing_even_in_debug_mode(): void
    {
        config()->set('app.debug', true);

        $response = $this->get('/api/v1/_test/boom');

        $response->assertStatus(500)->assertHeader('Content-Type', 'application/problem+json');
        $body = (string) $response->getContent();
        foreach (['hunter2', 'SELECT', 'RuntimeException', 'trace', 'file', 'line', 'exception'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $body);
        }
        $this->assertSame('An unexpected error occurred. Quote the request ID when reporting it.', $response->json('detail'));
    }

    public function test_rate_limit_errors_keep_retry_after(): void
    {
        $this->get('/api/v1/_test/throttled')->assertStatus(429)->assertHeader('Retry-After', '30')->assertJsonPath('title', 'Too Many Requests');
    }

    public function test_time_is_utc_in_php_and_in_every_database_session(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame('UTC', now()->getTimezone()->getName());
        foreach (['mysql', 'migrator', 'retention'] as $connection) {
            $this->assertSame('+00:00', DB::connection($connection)->scalar('select @@session.time_zone'), $connection);
        }
    }
}

/** A route middleware that fails before the route runs. */
final class ThrowingProbeMiddleware
{
    public function handle(Request $request, \Closure $next): never
    {
        throw new RuntimeException('thrown inside a middleware');
    }
}
