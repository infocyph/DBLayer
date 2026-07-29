<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Migration;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\MigrationException;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

/**
 * Persistent migration ledger for one connection.
 */
final readonly class MigrationRepository
{
    public function __construct(
        private Connection $connection,
        private string $table = 'migrations',
    ) {
        Blueprint::assertIdentifier($table);
    }

    /**
     * @return list<array{migration:string,batch:int,applied_at:string}>
     */
    public function all(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $rows = $this->connection
            ->table($this->table)
            ->select(['migration', 'batch', 'applied_at'])
            ->orderBy('batch')
            ->orderBy('migration')
            ->get();

        return array_map(
            fn(array $row): array => [
                'migration' => $this->stringValue($row['migration'] ?? null, 'migration'),
                'batch' => $this->intValue($row['batch'] ?? null, 'batch'),
                'applied_at' => $this->stringValue($row['applied_at'] ?? null, 'applied_at'),
            ],
            $rows,
        );
    }

    /** @return array<string,int> migration => batch */
    public function applied(): array
    {
        $applied = [];

        foreach ($this->all() as $row) {
            $applied[$row['migration']] = $row['batch'];
        }

        return $applied;
    }

    public function delete(string $migration): void
    {
        $this->connection->table($this->table)->where('migration', $migration)->delete();
    }

    public function ensureExists(): void
    {
        if ($this->exists()) {
            return;
        }

        new SchemaManager($this->connection)->create($this->table, static function (Blueprint $table): void {
            $table->string('migration', 255)->primary();
            $table->integer('batch');
            $table->timestamp('applied_at')->useCurrent();
            $table->index(['batch', 'migration']);
        });
    }

    public function exists(): bool
    {
        return new SchemaManager($this->connection)->hasTable($this->table);
    }

    public function log(string $migration, int $batch): void
    {
        $this->connection->table($this->table)->insert([
            'migration' => $migration,
            'batch' => $batch,
            'applied_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function nextBatch(): int
    {
        $maximum = $this->connection->table($this->table)->max('batch');

        return $maximum === null ? 1 : $this->intValue($maximum, 'batch') + 1;
    }

    public function table(): string
    {
        return $this->table;
    }

    private function intValue(mixed $value, string $column): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE)
                ?? throw new MigrationException(sprintf('Migration ledger column "%s" is outside the integer range.', $column));
        }

        throw new MigrationException(sprintf('Migration ledger column "%s" must contain an integer.', $column));
    }

    private function stringValue(mixed $value, string $column): string
    {
        if (!is_string($value)) {
            throw new MigrationException(sprintf('Migration ledger column "%s" must contain a string.', $column));
        }

        return $value;
    }
}
