<?php

namespace Tests\Feature;

use Tests\TestCase;

class DesignSystemTest extends TestCase
{
    public function test_gallery_renders_components_and_early_theme_script(): void
    {
        $response = $this->get('/design-system');
        $response->assertOk()->assertSee('Promerty')->assertSee('data-theme-select', false);
        foreach (['p-property-card', 'p-modal', 'p-sheet', 'p-sidebar', 'p-bottom-nav', 'p-toast', 'p-chat', 'p-notification', 'p-skeleton', 'p-stat'] as $class) {
            $response->assertSee($class, false);
        }
        $html = $response->getContent();
        $this->assertLessThan(strpos($html, 'rel="stylesheet"'), strpos($html, 'promerty.theme'));
    }

    public function test_legacy_welcome_retains_links_and_uses_shared_theme(): void
    {
        $response = $this->get('/welcome');
        $response->assertOk()->assertSee('https://laravel.com/docs', false)
            ->assertSee('https://cloud.laravel.com', false)
            ->assertSee('data-theme-select', false)
            ->assertDontSee('#FF750F', false)->assertDontSee('#F53003', false);
    }
}
