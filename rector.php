<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([__DIR__ . '/src']);
    $rectorConfig->sets([
        SetList::DEAD_CODE,
        constant(SetList::class . '::PHP_' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION),
    ]);
};
