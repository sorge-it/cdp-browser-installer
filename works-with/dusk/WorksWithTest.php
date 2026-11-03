<?php

declare(strict_types=1);

// tests/Browser/WorksWithTest.php of the Laravel app that the CI job `works-with` creates for
// laravel/dusk. `php artisan serve` serves public/works-with.html.

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

require dirname(__DIR__).'/check.php';

final class WorksWithTest extends DuskTestCase
{
    public function test_dusk_reads_the_page_through_the_pinned_pair(): void
    {
        $this->browse(static function (Browser $browser): void {
            $browser->visit('/works-with.html')->assertSeeIn('#ok', 'laravel/dusk');
            $chrome = $browser->driver->getCapabilities()->getCapability('chrome');

            check('laravel/dusk', $browser->text('#ok'), 'laravel/dusk', (string) $browser->script('return navigator.userAgent')[0], (string) ($chrome['chromedriverVersion'] ?? ''));
        });
    }
}
