<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller\Tests\PHPStan;

use Override;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\FunctionParameterClosureThisExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use SorgeIt\CdpBrowserInstaller\Tests\TestCase;

/**
 * For PHPStan only. Pest types `$this` in the closure of `it()` and `beforeEach()` as TestCall.
 * Pest.php extends each test from TestCase, so `$this` is that class, with its properties.
 */
final class PestClosureThis implements FunctionParameterClosureThisExtension
{
    #[Override]
    public function isFunctionSupported(FunctionReflection $functionReflection, ParameterReflection $parameter): bool
    {
        return in_array($functionReflection->getName(), ['it', 'beforeEach'], true) && $parameter->getName() === 'closure';
    }

    #[Override]
    public function getClosureThisTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, ParameterReflection $parameter, Scope $scope): Type
    {
        return new ObjectType(TestCase::class);
    }
}
