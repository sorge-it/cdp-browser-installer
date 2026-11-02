<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/bin',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withImportNames(removeUnusedImports: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        codingStyle: true,
    )
    ->withPhpSets();
