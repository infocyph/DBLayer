<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Concerns;

use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Exceptions\SecurityException;
use Infocyph\DBLayer\Query\Core\QueryType;
use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Security\Security;
use Infocyph\DBLayer\Support\Numeric;

trait QueryBuilderInternals
{
    /**
     * Set the columns to select.
     *
     * @param array<int,string|Expression>|string|Expression ...$columns
     */
    public function select(array|string|Expression ...$columns): self
    {
        if ($columns === []) {
            return $this;
        }

        $resolvedColumns = [];

        foreach ($columns as $columnGroup) {
            if (is_array($columnGroup)) {
                foreach ($columnGroup as $column) {
                    $resolvedColumns[] = $column;
                }

                continue;
            }

            $resolvedColumns[] = $columnGroup;
        }

        foreach ($resolvedColumns as $column) {
            if (!\is_string($column)) {
                continue;
            }

            $this->validateColumnIdentifier($column, true);
        }

        if ($resolvedColumns === []) {
            return $this;
        }

        $this->type = 'select';
        $this->columns = $resolvedColumns;

        return $this;
    }

    /**
     * Register a CTE and preserve placeholder binding order.
     *
     * @param QueryBuilder|callable(QueryBuilder):void|string $query
     * @param list<mixed> $bindings
     */
    private function addCte(
        string $name,
        QueryBuilder|callable|string $query,
        bool $recursive,
        array $bindings,
    ): self {
        if (\is_callable($query)) {
            $builder = $this->newQuery();
            $query($builder);
            $query = $builder;
        }

        if (\is_string($query)) {
            $this->validateRawFragment($query, $bindings);
        }

        $this->ctes[] = [
            'name' => $name,
            'query' => $query,
            'recursive' => $recursive,
        ];

        if ($query instanceof self) {
            $this->cteBindings = \array_merge($this->cteBindings, $query->getBindings());
        }

        if ($bindings !== []) {
            $this->cteBindings = \array_merge($this->cteBindings, $bindings);
        }

        return $this;
    }

    /**
     * Validate and normalize a comparison operator.
     */
    private function assertValidOperator(string $operator): string
    {
        $normalized = $this->normalizeOperator($operator);

        if (!\in_array($normalized, self::ALLOWED_OPERATORS, true)) {
            throw QueryException::invalidOperator($operator);
        }

        return $normalized;
    }

    /**
     * Enforce connection-level policy for raw SQL fragments.
     */
    private function enforceRawSqlPolicy(string $sql): void
    {
        $security = $this->connection->getConfig()->securityConfig();
        $policy = strtolower(trim($this->stringifyScalar($security['raw_sql_policy'] ?? 'allow', 'allow')));

        if ($policy === 'allow') {
            return;
        }

        if ($policy === 'deny') {
            throw QueryException::buildingFailed(
                'Raw SQL fragments are disabled by security.raw_sql_policy=deny.',
            );
        }

        if ($policy !== 'allowlist') {
            throw QueryException::buildingFailed(
                sprintf('Unsupported raw SQL policy [%s].', $policy),
            );
        }

        $allowlist = $security['raw_sql_allowlist'] ?? [];

        if (!is_array($allowlist) || $allowlist === []) {
            throw QueryException::buildingFailed(
                'Raw SQL allowlist policy requires security.raw_sql_allowlist patterns.',
            );
        }

        foreach ($allowlist as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }

            $rule = trim($pattern);
            if ($rule === '') {
                continue;
            }

            if ($this->matchesRawPolicyRule($sql, $rule)) {
                return;
            }
        }

        throw QueryException::buildingFailed(
            'Raw SQL fragment is not allowlisted by security.raw_sql_allowlist.',
        );
    }

    /**
     * Map legacy string type to QueryType enum.
     */
    private function mapTypeToEnum(?string $type): QueryType
    {
        $type = $type !== null ? \strtolower($type) : 'select';

        return match ($type) {
            'insert' => QueryType::INSERT,
            'update' => QueryType::UPDATE,
            'delete' => QueryType::DELETE,
            'truncate' => QueryType::TRUNCATE,
            'select', '' => QueryType::SELECT,
            default => QueryType::SELECT,
        };
    }

    /**
     * Match one allowlist rule against a raw SQL fragment.
     */
    private function matchesRawPolicyRule(string $sql, string $rule): bool
    {
        // Treat /.../modifiers rules as regex patterns.
        if (strlen($rule) >= 3 && $rule[0] === '/' && strrpos($rule, '/') !== 0) {
            $matched = $this->safePregMatch($rule, $sql);

            return $matched === 1;
        }

        return str_contains(strtolower($sql), strtolower($rule));
    }

    /**
     * Normalize operator token before validation/storage.
     */
    private function normalizeOperator(string $operator): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($operator));

        return strtolower($normalized ?? $operator);
    }

    /**
     * @return non-empty-string
     */
    private function requireNonEmptyString(string $value, string $name): string
    {
        if ($value === '') {
            throw QueryException::invalidParameter($name, ucfirst($name) . ' must not be empty.');
        }

        return $value;
    }

    /**
     * Internal helper to run aggregate queries.
     */
    private function runAggregate(string $function, string $column = '*', bool $ignoreLimitOffset = false): mixed
    {
        $clone = clone $this;

        if ($ignoreLimitOffset) {
            $this->resetAggregateWindow($clone);
        }

        $clone->aggregate = [
            'function' => \strtoupper($function),
            'column' => $column,
        ];

        $results = $this->executor->select($clone);

        if ($results === []) {
            return null;
        }

        $row = $results[0];

        return $row['aggregate'] ?? (\array_values($row)[0] ?? null);
    }

    /**
     * Execute preg_match while converting invalid-pattern warnings to "no match".
     */
    private function safePregMatch(string $pattern, string $subject): int|false
    {
        set_error_handler(static fn(): bool => true);

        try {
            return preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Whether strict identifier policy is enabled on this connection.
     */
    private function shouldValidateIdentifiers(): bool
    {
        if (!$this->connection->getConfig()->isSecurityEnabled()) {
            return false;
        }

        $security = $this->connection->getConfig()->securityConfig();

        if (!array_key_exists('strict_identifiers', $security)) {
            return true;
        }

        return (bool) $security['strict_identifiers'];
    }

    private function stringifyScalar(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    private function toInt(mixed $value, int $default): int
    {
        return Numeric::toInt($value, $default);
    }

    /**
     * Validate a column/alias identifier when strict identifier policy is enabled.
     */
    private function validateColumnIdentifier(string $column, bool $allowWildcard): void
    {
        if (!$this->shouldValidateIdentifiers()) {
            return;
        }

        $trimmed = trim($column);

        if ($trimmed === '') {
            throw QueryException::invalidParameter('column', 'Column identifier must not be empty.');
        }

        if ($allowWildcard && $trimmed === '*') {
            return;
        }

        if ($allowWildcard && preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*\.\*$/', $trimmed) === 1) {
            return;
        }

        try {
            Security::validateColumnName($trimmed);
        } catch (SecurityException $e) {
            throw QueryException::invalidParameter('column', $e->getMessage());
        }
    }

    /**
     * Validate raw SQL fragment with lightweight security checks.
     *
     * @param list<mixed> $bindings
     */
    private function validateRawFragment(string $sql, array $bindings = []): void
    {
        $this->enforceRawSqlPolicy($sql);

        try {
            Security::validateQuery($sql, $bindings, [
                'enabled' => true,
                'max_sql_length' => 8_192,
                'max_params' => 256,
                'max_param_bytes' => 2_048,
            ]);
        } catch (SecurityException $e) {
            throw QueryException::buildingFailed('Unsafe raw SQL fragment: ' . $e->getMessage());
        }
    }

    /**
     * Validate a table identifier when strict identifier policy is enabled.
     */
    private function validateTableIdentifier(string $table): void
    {
        if (!$this->shouldValidateIdentifiers()) {
            return;
        }

        try {
            Security::validateTableName($table);
        } catch (SecurityException $e) {
            throw QueryException::invalidParameter('table', $e->getMessage());
        }
    }
}
