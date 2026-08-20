<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection\Concerns;

use Infocyph\DBLayer\Connection\SqlStatementInspector;
use Infocyph\DBLayer\Driver\SQLServer\SQLServerDriver;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Monitoring\DatabaseMonitor;
use Infocyph\DBLayer\Query\Core\QueryType;
use PDO;

/**
 * Explicit on-demand database-system diagnostics and monitoring entry points.
 */
trait ConnectionMonitoring
{
    /**
     * Inspect the execution plan for a SELECT statement.
     *
     * The returned rows retain the database-native plan representation.
     * PostgreSQL/MySQL return JSON plans by default; SQLite returns
     * EXPLAIN QUERY PLAN rows.
     *
     * @param array<int|string,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public function explain(
        string $sql,
        array $bindings = [],
        bool $analyze = false,
        bool $buffers = false,
        bool $verbose = false,
    ): array {
        if (SqlStatementInspector::leadingStatementKeyword($sql) !== 'SELECT') {
            throw QueryException::invalidParameter(
                'sql',
                'Execution plans accept SELECT statements only.',
            );
        }

        if ($this->driver instanceof SQLServerDriver) {
            $preparedSql = $this->prepareSqlForExecution($sql, $bindings);

            if ($this->pretending) {
                $this->recordPretend($preparedSql, $bindings);

                return [];
            }

            return $this->driver->executeExplain(
                $this->getPdo(),
                $preparedSql,
                $bindings,
                $analyze,
                $buffers,
                $verbose,
            );
        }

        $serverVersion = null;

        if ($analyze && $this->driver->getName() === 'mysql' && !$this->pretending) {
            $resolvedVersion = $this->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

            if (is_string($resolvedVersion) || is_int($resolvedVersion) || is_float($resolvedVersion)) {
                $serverVersion = (string) $resolvedVersion;
            }
        }

        $explainSql = $this->driver->compileExplain(
            $sql,
            $analyze,
            $buffers,
            $verbose,
            $serverVersion,
        );

        return array_values($this->fetchAllFromKnownTypeStatement(
            $explainSql,
            $bindings,
            QueryType::SELECT,
        ));
    }

    public function monitor(): DatabaseMonitor
    {
        return new DatabaseMonitor($this);
    }
}
