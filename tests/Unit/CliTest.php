<?php

declare(strict_types=1);

use SorgeIt\CdpBrowserInstaller\Console\Cli;

it('takes the text of an argument or an option', function (): void {
    expect(Cli::text('.chrome-version'))->toBe('.chrome-version');
});

it('stops at an argument or an option without a text value', function (mixed $value): void {
    expect(fn (): string => Cli::text($value))->toThrow(installerException('An argument or an option has no text value.'));
})->with([
    'no default' => [null],
    'a flag' => [true],
    'an array option' => [['a']],
]);
