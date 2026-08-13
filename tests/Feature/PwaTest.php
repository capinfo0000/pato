<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class PwaTest extends TestCase
{
    public function test_manifest_is_served_with_the_expected_shape(): void
    {
        $path = public_path('manifest.json');
        $this->assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/home', $manifest['start_url']);
        $this->assertSame('ja', $manifest['lang']);
        $this->assertNotEmpty($manifest['icons']);
    }

    public function test_service_worker_exists_and_never_caches_non_get(): void
    {
        $path = public_path('sw.js');
        $this->assertFileExists($path);

        $sw = (string) file_get_contents($path);

        // 課金・状態遷移(POST)を再送しないためのガードが入っていること
        $this->assertStringContainsString("request.method !== 'GET'", $sw);
        // オフライン時のフォールバック先
        $this->assertStringContainsString("caches.match('/offline')", $sw);
    }

    public function test_offline_page_is_reachable_without_login(): void
    {
        $this->get('/offline')->assertOk()->assertSee('オフライン');
    }

    public function test_layout_registers_the_service_worker_and_manifest(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('rel="manifest"', $layout);
        $this->assertStringContainsString("serviceWorker.register('/sw.js')", $layout);
        $this->assertStringContainsString('name="theme-color"', $layout);
    }
}
