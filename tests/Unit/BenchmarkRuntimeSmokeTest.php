<?php

declare(strict_types=1);

use Infocyph\DBLayer\Benchmarks\DBLayerBench;
use Infocyph\DBLayer\Benchmarks\DBLayerCompilerBench;
use Infocyph\DBLayer\Benchmarks\RuntimeLifecycleBench;

require_once dirname(__DIR__, 2) . '/benchmarks/RequiresSqlite.php';
require_once dirname(__DIR__, 2) . '/benchmarks/DBLayerBench.php';
require_once dirname(__DIR__, 2) . '/benchmarks/DBLayerCompilerBench.php';
require_once dirname(__DIR__, 2) . '/benchmarks/RuntimeLifecycleBench.php';

it('keeps every PHPBench subject executable', function (string $benchmarkClass): void {
    $benchmark = new $benchmarkClass();
    $reflection = new ReflectionClass($benchmark);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'bench')) {
            continue;
        }

        if ($reflection->hasMethod('setUpBeforeSubject')) {
            $benchmark->setUpBeforeSubject();
        }

        $method->invoke($benchmark);
    }
})->with([
    DBLayerBench::class,
    DBLayerCompilerBench::class,
    RuntimeLifecycleBench::class,
]);
