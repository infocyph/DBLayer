<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Migration;

use Infocyph\DBLayer\Connection\Connection;
use InvalidArgumentException;

final readonly class SeedRunner
{
    public function __construct(private Connection $connection) {}

    /**
     * Execute explicit seed definitions in the supplied order.
     *
     * @param iterable<mixed> $seeders
     * @return int number of executed seeders
     */
    public function run(iterable $seeders, bool $transactional = true): int
    {
        $resolved = $this->resolve($seeders);
        $executed = 0;
        $execute = static fn(iterable $children): int => throw new \LogicException(
            sprintf('Seed execution context is not initialized for %s.', get_debug_type($children)),
        );
        $context = new SeedContext(
            $this->connection,
            static function (iterable $children) use (&$execute): int {
                return $execute($children);
            },
        );

        $execute = function (iterable $manifest) use (&$executed, $context): int {
            $children = $this->resolve($manifest);
            $before = $executed;

            foreach ($children as $seeder) {
                if ($seeder instanceof Seeder) {
                    $seeder->run($this->connection, $context);
                } else {
                    $seeder($this->connection, $context);
                }

                ++$executed;
            }

            return $executed - $before;
        };

        if (!$transactional) {
            return $execute($resolved);
        }

        $result = $this->connection->transaction(static fn() => $execute($resolved));
        if (!is_int($result)) {
            throw new \LogicException('Transactional seed execution returned an invalid count.');
        }

        return $result;
    }

    /**
     * @param iterable<mixed> $seeders
     * @return list<Seeder|callable>
     */
    private function resolve(iterable $seeders): array
    {
        $resolved = is_array($seeders) ? array_values($seeders) : iterator_to_array($seeders, false);

        foreach ($resolved as $seeder) {
            if (!$seeder instanceof Seeder && !is_callable($seeder)) {
                throw new InvalidArgumentException('Seed definitions must implement Seeder or be callable.');
            }
        }

        return $resolved;
    }
}
