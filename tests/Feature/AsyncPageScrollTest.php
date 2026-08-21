<?php

namespace Tests\Feature;

use Tests\TestCase;

class AsyncPageScrollTest extends TestCase
{
    public function test_async_page_swap_freezes_scroll_anchoring_and_restores_position(): void
    {
        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertIsString($source);
        $this->assertStringContainsString("htmlElement.style.overflowAnchor = 'none'", $source);
        $this->assertStringContainsString("body.style.overflowAnchor = 'none'", $source);
        $this->assertStringContainsString("htmlElement.style.scrollBehavior = 'auto'", $source);
        $this->assertGreaterThanOrEqual(3, substr_count($source, 'restoreScroll();'));
        $this->assertStringContainsString('htmlElement.style.overflowAnchor = previousHtmlOverflowAnchor', $source);
        $this->assertStringContainsString('body.style.overflowAnchor = previousBodyOverflowAnchor', $source);
    }
}
