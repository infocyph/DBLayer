<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Tests\Unit;

use Infocyph\DBLayer\Repository\RepositoryOneOfManyOrder;

it('compares large numeric strings without float precision loss', function (): void {
    $orders = ['amount' => 'desc'];

    expect(RepositoryOneOfManyOrder::compare(
        ['amount' => '12345678901234567890.13'],
        ['amount' => '12345678901234567890.12'],
        $orders,
    ))->toBeLessThan(0);
});

it('compares exponent and fractional numeric strings exactly', function (): void {
    expect(RepositoryOneOfManyOrder::compare(
        ['amount' => '1e-3'],
        ['amount' => '0.002'],
        ['amount' => 'asc'],
    ))->toBeLessThan(0)
        ->and(RepositoryOneOfManyOrder::compare(
            ['amount' => '-10'],
            ['amount' => '-2'],
            ['amount' => 'asc'],
        ))->toBeLessThan(0);
});
