<?php

namespace Modules\Platform\Tests\Feature;

use Illuminate\Support\Facades\Vite;
use Inertia\Testing\AssertableInertia;
use Modules\Platform\Http\Middleware\HandleInertiaRequests;
use Tests\TestCase;

/**
 * The Vue 3 + Inertia shell (docs/01): pages render through the root view, Inertia visits get JSON, and the
 * HTML stays within the CSP from P1-08 (no executable inline script without the nonce).
 */
class InertiaShellTest extends TestCase
{
    public function test_the_start_page_renders_through_inertia(): void
    {
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Platform/Home', shouldExist: false)->has('requestId'));
    }

    public function test_inertia_visits_get_json_with_the_request_id(): void
    {
        $this->withoutVite();
        $version = (string) $this->app->make(HandleInertiaRequests::class)->version(request());

        $response = $this->get('/', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version]);

        $response->assertOk()->assertHeader('X-Inertia', 'true')->assertJsonPath('component', 'Platform/Home');
        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('props.requestId'));
    }

    public function test_every_script_in_the_page_carries_the_nonce_or_is_not_executable(): void
    {
        Vite::useHotFile(storage_path('framework/testing/no-vite-dev-server'));
        Vite::useBuildDirectory('build-test-'.getmypid());
        $manifest = public_path('build-test-'.getmypid().'/manifest.json');
        @mkdir(dirname($manifest), 0775, true);
        file_put_contents($manifest, json_encode([
            'resources/js/app.ts' => ['file' => 'assets/app.js', 'src' => 'resources/js/app.ts', 'isEntry' => true, 'css' => ['assets/app.css']],
            'resources/css/app.css' => ['file' => 'assets/app.css', 'src' => 'resources/css/app.css', 'isEntry' => true],
        ]));

        try {
            $response = $this->get('/')->assertOk();
        } finally {
            @unlink($manifest);
            @rmdir(dirname($manifest));
        }

        preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy'), $m);
        $nonce = $m[1] ?? '';
        $this->assertNotSame('', $nonce);

        preg_match_all('/<script\b[^>]*>/i', (string) $response->getContent(), $scripts);
        $this->assertNotEmpty($scripts[0]);
        foreach ($scripts[0] as $tag) {
            $this->assertTrue(str_contains($tag, "nonce=\"$nonce\"") || str_contains($tag, 'type="application/json"'), "Script without nonce: $tag");
        }
        preg_match_all('/<(?:style|link)\b[^>]*>/i', (string) $response->getContent(), $styles);
        foreach ($styles[0] as $tag) {
            if (str_contains($tag, '<style') || str_contains($tag, 'stylesheet')) {
                $this->assertStringContainsString("nonce=\"$nonce\"", $tag, "Style without nonce: $tag");
            }
        }
    }
}
