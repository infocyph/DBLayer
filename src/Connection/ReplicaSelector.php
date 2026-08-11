<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Closure;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use PDO;
use PDOException;

/**
 * Owns read-replica strategy, health suppression, and latency winner state.
 */
final class ReplicaSelector
{
    /** @var array<int,float> */
    private array $latenciesMs = [];

    private ?int $leastLatencyIndex = null;

    private ?int $leastLatencyResolvedAt = null;

    private int $roundRobinCursor = 0;

    private ?int $selectedIndex = null;

    /** @var array<int,int> */
    private array $unavailableUntil = [];

    public function __construct(private readonly ConnectionConfig $config) {}

    /**
     * @return array{strategy:string,selected_index:int|null,latencies_ms:array<int,float>}
     */
    public function info(): array
    {
        return [
            'strategy' => $this->config->getReadStrategy(),
            'selected_index' => $this->selectedIndex,
            'latencies_ms' => $this->latenciesMs,
        ];
    }

    public function reset(): void
    {
        $this->leastLatencyIndex = null;
        $this->leastLatencyResolvedAt = null;
        $this->latenciesMs = [];
        $this->roundRobinCursor = 0;
        $this->selectedIndex = null;
        $this->unavailableUntil = [];
    }

    /**
     * @param list<array<string,mixed>> $replicas
     * @param Closure(array<string,mixed>):PDO $factory
     * @return array{0:int,1:PDO}
     */
    public function resolve(array $replicas, Closure $factory): array
    {
        if ($this->config->getReadStrategy() === 'least_latency') {
            [$index, $pdo] = $this->resolveLeastLatency($replicas, $factory);
            $this->selectedIndex = $index;

            return [$index, $pdo];
        }

        $this->latenciesMs = [];

        foreach ($this->probeOrder($replicas, $this->config->getReadStrategy()) as $index) {
            try {
                $pdo = $factory($replicas[$index]);
                unset($this->unavailableUntil[$index]);
                $this->selectedIndex = $index;

                return [$index, $pdo];
            } catch (PDOException|ConnectionException) {
                $this->markFailure($index);
            }
        }

        throw ConnectionException::connectionFailed(
            $this->config->getDriver(),
            'No healthy read replica available.',
        );
    }

    /** @return list<int> */
    private function availableIndexes(int $count): array
    {
        $now = time();
        $available = [];

        for ($index = 0; $index < $count; $index++) {
            $retryAt = $this->unavailableUntil[$index] ?? null;

            if ($retryAt === null || $retryAt <= $now) {
                unset($this->unavailableUntil[$index]);
                $available[] = $index;
            }
        }

        return $available;
    }

    private function markFailure(int $index): void
    {
        if ($this->leastLatencyIndex === $index) {
            $this->leastLatencyIndex = null;
            $this->leastLatencyResolvedAt = null;
        }

        $cooldown = $this->config->getReadHealthCooldown();
        if ($cooldown > 0) {
            $this->unavailableUntil[$index] = time() + $cooldown;
        }
    }

    /**
     * @param list<int> $indexes
     * @param list<array<string,mixed>> $replicas
     * @param Closure(array<string,mixed>):PDO $factory
     * @param array<int,float> $latencies
     * @return array{0:int|null,1:PDO|null}
     */
    private function probeIndexes(
        array $indexes,
        array $replicas,
        Closure $factory,
        array &$latencies,
    ): array {
        $bestIndex = null;
        $bestPdo = null;
        $bestLatency = INF;

        foreach ($indexes as $index) {
            try {
                $startedAt = microtime(true);
                $pdo = $factory($replicas[$index]);
                $pdo->query('SELECT 1');
                $latency = (microtime(true) - $startedAt) * 1_000.0;
                unset($this->unavailableUntil[$index]);
                $latencies[$index] = round($latency, 4);

                if ($latency < $bestLatency) {
                    $bestLatency = $latency;
                    $bestIndex = $index;
                    $bestPdo = $pdo;
                }
            } catch (PDOException|ConnectionException) {
                $this->markFailure($index);
            }
        }

        return [$bestIndex, $bestPdo];
    }

    /**
     * @param list<array<string,mixed>> $replicas
     * @return list<int>
     */
    private function probeOrder(array $replicas, string $strategy): array
    {
        $count = count($replicas);
        if ($count <= 1) {
            return [0];
        }

        $available = $this->availableIndexes($count);
        $fallback = array_values(array_diff(range(0, $count - 1), $available));
        $pool = $available !== [] ? $available : range(0, $count - 1);

        $primary = match ($strategy) {
            'round_robin' => $this->roundRobinIndex($pool),
            'weighted' => $this->weightedIndex($pool, $replicas),
            default => $pool[random_int(0, count($pool) - 1)],
        };
        $rest = array_values(array_diff($pool, [$primary]));

        if (count($rest) > 1) {
            shuffle($rest);
        }

        if (count($fallback) > 1) {
            shuffle($fallback);
        }

        return array_values(array_unique([$primary, ...$rest, ...$fallback]));
    }

    /**
     * @param list<array<string,mixed>> $replicas
     * @param list<int> $available
     * @param Closure(array<string,mixed>):PDO $factory
     * @return array{0:int,1:PDO}|null
     */
    private function resolveCachedWinner(array $replicas, array $available, Closure $factory): ?array
    {
        $index = $this->leastLatencyIndex;
        $resolvedAt = $this->leastLatencyResolvedAt;
        $ttl = $this->config->getLeastLatencyCacheTtl();

        if (
            $index === null
            || $resolvedAt === null
            || $ttl <= 0
            || (time() - $resolvedAt) > $ttl
            || !in_array($index, $available, true)
            || !isset($replicas[$index])
        ) {
            return null;
        }

        try {
            $pdo = $factory($replicas[$index]);
            unset($this->unavailableUntil[$index]);
            $this->latenciesMs = [];

            return [$index, $pdo];
        } catch (PDOException|ConnectionException) {
            $this->markFailure($index);

            return null;
        }
    }

    /**
     * @param list<array<string,mixed>> $replicas
     * @param Closure(array<string,mixed>):PDO $factory
     * @return array{0:int,1:PDO}
     */
    private function resolveLeastLatency(array $replicas, Closure $factory): array
    {
        $indexes = $this->availableIndexes(count($replicas));
        $indexes = $indexes !== [] ? $indexes : range(0, count($replicas) - 1);
        $cached = $this->resolveCachedWinner($replicas, $indexes, $factory);

        if ($cached !== null) {
            return $cached;
        }

        $sample = $this->sampleIndexes($indexes);
        $fallback = array_values(array_diff($indexes, $sample));
        $latencies = [];
        [$bestIndex, $bestPdo] = $this->probeIndexes($sample, $replicas, $factory, $latencies);

        if ($bestIndex === null || !$bestPdo instanceof PDO) {
            [$bestIndex, $bestPdo] = $this->probeIndexes($fallback, $replicas, $factory, $latencies);
        }

        $this->latenciesMs = $latencies;

        if ($bestIndex === null || !$bestPdo instanceof PDO) {
            throw ConnectionException::connectionFailed(
                $this->config->getDriver(),
                'No healthy read replica available for least_latency strategy.',
            );
        }

        $this->leastLatencyIndex = $bestIndex;
        $this->leastLatencyResolvedAt = time();

        return [$bestIndex, $bestPdo];
    }

    /** @param list<int> $indexes */
    private function roundRobinIndex(array $indexes): int
    {
        if ($indexes === []) {
            return 0;
        }

        $slot = $this->roundRobinCursor % count($indexes);
        $this->roundRobinCursor = ($slot + 1) % count($indexes);

        return $indexes[$slot];
    }

    /**
     * @param list<int> $indexes
     * @return list<int>
     */
    private function sampleIndexes(array $indexes): array
    {
        $size = $this->config->getReadProbeSampleSize();

        if ($size <= 0 || $size >= count($indexes)) {
            return $indexes;
        }

        shuffle($indexes);

        return array_slice($indexes, 0, $size);
    }

    /**
     * @param list<int> $indexes
     * @param list<array<string,mixed>> $replicas
     */
    private function weightedIndex(array $indexes, array $replicas): int
    {
        $weights = [];
        $total = 0;

        foreach ($indexes as $index) {
            $raw = $replicas[$index]['weight'] ?? 1;
            $weight = is_numeric($raw) ? max(1, (int) $raw) : 1;
            $weights[$index] = $weight;
            $total += $weight;
        }

        $ticket = random_int(1, $total);

        foreach ($weights as $index => $weight) {
            $ticket -= $weight;
            if ($ticket <= 0) {
                return $index;
            }
        }

        return $indexes[count($indexes) - 1];
    }
}
