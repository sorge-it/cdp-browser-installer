<?php

declare(strict_types=1);

// The PHPUnit test of the CI job `works-with` for symfony/panther: a test that extends the
// PantherTestCase of the README. Panther's web server serves works-with.html from this directory.

namespace App\Tests;

require_once __DIR__.'/PantherTestCase.php';
require_once dirname(__DIR__).'/check.php';

final class WorksWithTest extends PantherTestCase
{
    public function test_panther_reads_the_page_through_the_pair(): void
    {
        $client = self::createPantherClient(['webServerDir' => __DIR__]);
        $text = $client->request('GET', '/works-with.html')->filter('#ok')->text();
        $capabilities = $client->getWebDriver()->getCapabilities();

        self::assertSame('symfony/panther', $text);
        check('symfony/panther', $text, 'symfony/panther', (string) $capabilities->getCapability('browserVersion'), (string) ($capabilities->getCapability('chrome')['chromedriverVersion'] ?? ''));
    }
}
