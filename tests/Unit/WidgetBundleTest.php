<?php

namespace Tests\Unit;

use App\Support\WidgetBundle;
use Tests\TestCase;

class WidgetBundleTest extends TestCase
{
    public function test_it_finds_the_public_widget_bundle_when_present(): void
    {
        if (! is_file(WidgetBundle::publicPath())) {
            $this->markTestSkipped('public/build/widget.js is not built in this environment.');
        }

        $this->assertTrue(WidgetBundle::isBuilt());
        $this->assertSame(WidgetBundle::publicPath(), WidgetBundle::resolvePath());
    }
}
