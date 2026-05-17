<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\SqlStatementInspector;

it('detects leading keyword after comments and whitespace', function (): void {
    $sql = " \n/* warmup */\n-- tracer\nUPDATE users SET name = 'x'";

    expect(SqlStatementInspector::leadingStatementKeyword($sql))->toBe('UPDATE');
});

it('handles quoted identifiers and plain select statements', function (): void {
    $sql = 'select "with", `from` from "table_name"';

    expect(SqlStatementInspector::leadingStatementKeyword($sql))->toBe('SELECT');
});

it('resolves recursive and nested cte statements to final write keyword', function (): void {
    $recursiveUpdate = <<<SQL
WITH RECURSIVE chain(n) AS (
    SELECT 1
    UNION ALL
    SELECT n + 1 FROM chain WHERE n < 3
),
snapshot AS (
    SELECT n, (SELECT count(*) FROM chain c2 WHERE c2.n <= chain.n) as depth
    FROM chain
)
UPDATE users SET depth = snapshot.depth
FROM snapshot
WHERE users.id = snapshot.n
SQL;

    expect(SqlStatementInspector::leadingStatementKeyword($recursiveUpdate))->toBe('UPDATE');
});

it('resolves cte statements to delete when inner selects exist first', function (): void {
    $sql = <<<SQL
WITH recent AS (
    SELECT id FROM logs WHERE level = 'debug'
),
marked AS (
    SELECT id FROM recent WHERE id > 10
)
DELETE FROM logs WHERE id IN (SELECT id FROM marked)
SQL;

    expect(SqlStatementInspector::leadingStatementKeyword($sql))->toBe('DELETE');
});

it('handles leading line comments before cte delete statement', function (): void {
    $sql = <<<SQL
-- first
-- second
WITH c AS (SELECT 1)
DELETE FROM users WHERE id = 1
SQL;

    expect(SqlStatementInspector::leadingStatementKeyword($sql))->toBe('DELETE');
});

it('ignores quoted cte-body text and resolves final outer statement keyword', function (): void {
    $sql = <<<SQL
WITH flagged AS (
    SELECT
        id,
        'UPDATE users SET role = ''admin''' AS fake_update,
        "DELETE FROM users" AS fake_delete
    FROM audit_logs
    WHERE message like '%LOCK TABLE%'
)
UPDATE users
SET flagged = 1
WHERE id IN (SELECT id FROM flagged)
SQL;

    expect(SqlStatementInspector::leadingStatementKeyword($sql))->toBe('UPDATE');
});
