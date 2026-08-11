<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Benchmarks;

use Attribute;
use PDO;
use PhpBench\Attributes\Skip;

if (\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    #[Attribute(Attribute::TARGET_CLASS)]
    final class RequiresSqlite {}
} else {
    class_alias(Skip::class, RequiresSqlite::class);
}
