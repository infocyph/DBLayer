<?php

declare(strict_types=1);

use Infocyph\DBLayer\Exceptions\SchemaException;
use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\ColumnDefinition;
use Infocyph\DBLayer\Schema\SchemaColumnCompiler;
use Infocyph\DBLayer\Schema\SchemaGrammar;

it('compiles every supported column type for each dialect', function (
    string $driver,
    array $expected,
): void {
    $table = new Blueprint('type_matrix', true);
    $table->bigInteger('big_value')->unsigned();
    $table->binary('binary_value');
    $table->boolean('bool_value')->default(true);
    $table->date('date_value');
    $table->dateTime('datetime_value');
    $table->decimal('decimal_value', 12, 4);
    $table->float('float_value');
    $table->integer('integer_value');
    $table->json('json_value');
    $table->smallInteger('small_value');
    $table->string('string_value', 120);
    $table->text('text_value')->nullable();
    $table->timestamp('timestamp_value')->useCurrent();
    $table->uuid('uuid_value');

    $sql = implode(' ', (new SchemaGrammar($driver))->compile($table));

    foreach ($expected as $fragment) {
        expect($sql)->toContain($fragment);
    }
})->with([
    'mysql' => ['mysql', [
        '`big_value` BIGINT UNSIGNED',
        '`binary_value` BLOB',
        '`bool_value` TINYINT(1)',
        '`datetime_value` DATETIME',
        '`decimal_value` DECIMAL(12, 4)',
        '`float_value` FLOAT',
        '`json_value` JSON',
        '`string_value` VARCHAR(120)',
        '`uuid_value` CHAR(36)',
    ]],
    'pgsql' => ['pgsql', [
        '"big_value" BIGINT',
        '"binary_value" BYTEA',
        '"bool_value" BOOLEAN',
        '"decimal_value" DECIMAL(12, 4)',
        '"float_value" FLOAT',
        '"json_value" JSON',
        '"string_value" VARCHAR(120)',
        '"uuid_value" UUID',
    ]],
    'sqlite' => ['sqlite', [
        '"big_value" INTEGER',
        '"binary_value" BLOB',
        '"bool_value" BOOLEAN',
        '"datetime_value" TEXT',
        '"decimal_value" DECIMAL(12, 4)',
        '"float_value" REAL',
        '"json_value" TEXT',
        '"string_value" TEXT',
        '"uuid_value" TEXT',
    ]],
]);

it('compiles column modifiers composite indexes and foreign-key actions', function (
    string $driver,
): void {
    $table = new Blueprint('children', true);
    $table->bigIncrements();
    $table->bigInteger('tenant_id')->unsigned();
    $table->bigInteger('parent_id')->unsigned();
    $table->string('code')->default("child's code");
    $table->unique(['tenant_id', 'code'], 'children_tenant_code_unique');
    $table->index(['tenant_id', 'parent_id'], 'children_parent_lookup');
    $table->foreign(['tenant_id', 'parent_id'], 'children_parent_foreign')
        ->references(['tenant_id', 'id'])
        ->on('parents')
        ->onDelete('cascade')
        ->onUpdate('restrict');

    $sql = implode(' ', (new SchemaGrammar($driver))->compile($table));

    expect($sql)->toContain('children_tenant_code_unique')
        ->toContain('children_parent_lookup')
        ->toContain('children_parent_foreign')
        ->toContain('ON DELETE CASCADE')
        ->toContain('ON UPDATE RESTRICT')
        ->toContain("child''s code");
})->with(['mysql', 'pgsql', 'sqlite']);

it('compiles driver-specific alter and drop operations', function (
    string $driver,
    array $expected,
): void {
    $table = new Blueprint('accounts');
    $table->dropColumn('obsolete');
    $table->renameColumn('old_name', 'new_name');
    $table->dropIndex('accounts_email_index');

    if ($driver !== 'sqlite') {
        $table->dropForeign('accounts_tenant_foreign');
    }

    $sql = implode(' ', (new SchemaGrammar($driver))->compile($table));

    foreach ($expected as $fragment) {
        expect($sql)->toContain($fragment);
    }
})->with([
    'mysql' => ['mysql', [
        'DROP COLUMN `obsolete`',
        'RENAME COLUMN `old_name` TO `new_name`',
        'DROP INDEX `accounts_email_index` ON `accounts`',
        'DROP FOREIGN KEY `accounts_tenant_foreign`',
    ]],
    'pgsql' => ['pgsql', [
        'DROP COLUMN "obsolete"',
        'RENAME COLUMN "old_name" TO "new_name"',
        'DROP INDEX "accounts_email_index"',
        'DROP CONSTRAINT "accounts_tenant_foreign"',
    ]],
    'sqlite' => ['sqlite', [
        'DROP COLUMN "obsolete"',
        'RENAME COLUMN "old_name" TO "new_name"',
        'DROP INDEX "accounts_email_index"',
    ]],
]);

it('reports transactional ddl support explicitly', function (): void {
    expect((new SchemaGrammar('mysql'))->supportsTransactionalDdl())->toBeFalse()
        ->and((new SchemaGrammar('pgsql'))->supportsTransactionalDdl())->toBeTrue()
        ->and((new SchemaGrammar('sqlite'))->supportsTransactionalDdl())->toBeTrue();
});

it('compiles every supported scalar default for each dialect', function (
    string $driver,
    string $boolean,
): void {
    $table = new Blueprint('default_matrix', true);
    $table->string('nullable_value')->nullable()->default(null);
    $table->boolean('bool_value')->default(false);
    $table->integer('integer_value')->default(-42);
    $table->float('float_value')->default(12.5);
    $table->string('string_value')->default("it's safe");

    $sql = (new SchemaGrammar($driver))->compile($table)[0];

    expect($sql)->toContain('DEFAULT NULL')
        ->toContain('DEFAULT ' . $boolean)
        ->toContain('DEFAULT -42')
        ->toContain('DEFAULT 12.5')
        ->toContain("DEFAULT 'it''s safe'");
})->with([
    'mysql' => ['mysql', '0'],
    'pgsql' => ['pgsql', 'FALSE'],
    'sqlite' => ['sqlite', '0'],
]);

it('honors the last explicit default mode selected', function (): void {
    $current = new ColumnDefinition('created_at', 'timestamp');
    $current->default('ignored')->useCurrent();
    $explicit = new ColumnDefinition('updated_at', 'timestamp');
    $explicit->useCurrent()->default(null);
    $compiler = new SchemaColumnCompiler('pgsql');

    expect($compiler->compile($current))->toContain('DEFAULT CURRENT_TIMESTAMP')
        ->not->toContain("'ignored'")
        ->and($compiler->compile($explicit))->toContain('DEFAULT NULL')
        ->not->toContain('CURRENT_TIMESTAMP');
});

it('rejects invalid schema definitions before execution', function (Closure $operation): void {
    expect($operation)->toThrow(SchemaException::class);
})->with([
    'invalid identifier' => static fn() => new Blueprint('bad-name'),
    'zero string length' => static fn() => (new Blueprint('items'))->string('name', 0),
    'invalid decimal precision' => static fn() => (new Blueprint('items'))->decimal('amount', 0, 0),
    'invalid decimal scale' => static fn() => (new Blueprint('items'))->decimal('amount', 4, 5),
    'duplicate column' => static function (): void {
        $table = new Blueprint('items');
        $table->integer('id');
        $table->integer('id');
    },
    'empty index' => static fn() => (new Blueprint('items'))->index([]),
    'empty foreign key' => static fn() => (new Blueprint('items'))->foreign([]),
    'empty referenced columns' => static function (): void {
        $table = new Blueprint('items', true);
        $table->integer('parent_id');
        $table->foreign('parent_id')->references([])->on('parents');
        (new SchemaGrammar('pgsql'))->compile($table);
    },
    'foreign key without table' => static function (): void {
        $table = new Blueprint('items', true);
        $table->integer('parent_id');
        $table->foreign('parent_id');
        (new SchemaGrammar('pgsql'))->compile($table);
    },
    'unsupported foreign action' => static function (): void {
        $table = new Blueprint('items', true);
        $table->integer('parent_id');
        $table->foreign('parent_id')->on('parents')->onDelete('explode');
        (new SchemaGrammar('pgsql'))->compile($table);
    },
    'multiple primary keys' => static function (): void {
        $table = new Blueprint('items', true);
        $table->integer('first')->primary();
        $table->integer('second')->primary();
        (new SchemaGrammar('pgsql'))->compile($table);
    },
    'non-scalar default' => static function (): void {
        $column = new ColumnDefinition('payload', 'json');
        $column->default([]);
        (new SchemaColumnCompiler('pgsql'))->compile($column);
    },
    'non-finite default' => static function (): void {
        $column = new ColumnDefinition('amount', 'float');
        $column->default(INF);
        (new SchemaColumnCompiler('pgsql'))->compile($column);
    },
    'auto increment text' => static function (): void {
        $column = new ColumnDefinition('code', 'text');
        $column->autoIncrement();
        (new SchemaColumnCompiler('mysql'))->compile($column);
    },
    'sqlite auto increment without primary key' => static function (): void {
        $column = new ColumnDefinition('id', 'integer');
        $column->autoIncrement();
        (new SchemaColumnCompiler('sqlite'))->compile($column);
    },
    'unsupported column type' => static fn() => (new SchemaColumnCompiler('pgsql'))
        ->compile(new ColumnDefinition('value', 'unknown')),
    'unsupported driver' => static fn() => new SchemaGrammar('oracle'),
    'empty alter definition' => static fn() => (new SchemaGrammar('pgsql'))
        ->compile(new Blueprint('items')),
    'empty create definition' => static fn() => (new SchemaGrammar('pgsql'))
        ->compile(new Blueprint('items', true)),
    'sqlite add primary key' => static function (): void {
        $table = new Blueprint('items');
        $table->primary('id');
        (new SchemaGrammar('sqlite'))->compile($table);
    },
    'sqlite drop foreign key' => static function (): void {
        $table = new Blueprint('items');
        $table->dropForeign('items_parent_id_foreign');
        (new SchemaGrammar('sqlite'))->compile($table);
    },
]);

it('bounds generated mysql index names to the vendor limit', function (): void {
    $table = new Blueprint('extremely_long_table_name_used_for_index_name_generation', true);
    $table->integer('extremely_long_column_name_used_for_index_generation')->index();

    $sql = (new SchemaGrammar('mysql'))->compile($table)[1];
    preg_match('/INDEX `([^`]+)`/', $sql, $matches);

    expect($matches[1] ?? null)->toBeString()
        ->and(strlen($matches[1]))->toBeLessThanOrEqual(64);
});

it('compiles the extended portable column catalog for every dialect', function (
    string $driver,
    array $expected,
): void {
    $table = new Blueprint('extended_types', true);
    $table->char('country_code', 2);
    $table->tinyInteger('tiny_value');
    $table->mediumInteger('medium_value');
    $table->tinyText('tiny_text');
    $table->mediumText('medium_text');
    $table->longText('long_text');
    $table->double('double_value');
    $table->time('time_value', 3);
    $table->timeTz('time_tz_value', 3);
    $table->dateTimeTz('datetime_tz_value', 3);
    $table->timestampTz('timestamp_tz_value', 3);
    $table->year('year_value');
    $table->jsonb('jsonb_value');
    $table->enum('state', ['new', "owner's"]);
    $table->ipAddress('ip_value');
    $table->macAddress('mac_value');
    $table->ulid('ulid_value');
    $table->foreignId('foreign_id');
    $table->foreignUuid('foreign_uuid');
    $table->foreignUlid('foreign_ulid');

    $sql = (new SchemaGrammar($driver))->compile($table)[0];

    foreach ($expected as $fragment) {
        expect($sql)->toContain($fragment);
    }
})->with([
    'mysql' => ['mysql', [
        '`country_code` CHAR(2)',
        '`tiny_value` TINYINT',
        '`medium_value` MEDIUMINT',
        '`tiny_text` TINYTEXT',
        '`medium_text` MEDIUMTEXT',
        '`long_text` LONGTEXT',
        '`double_value` DOUBLE',
        '`time_value` TIME(3)',
        '`time_tz_value` TIME(3)',
        '`datetime_tz_value` DATETIME(3)',
        '`timestamp_tz_value` TIMESTAMP(3)',
        '`year_value` YEAR',
        '`jsonb_value` JSON',
        "`state` ENUM('new', 'owner''s')",
        '`ip_value` VARCHAR(45)',
        '`mac_value` VARCHAR(17)',
        '`ulid_value` CHAR(26)',
        '`foreign_id` BIGINT UNSIGNED',
        '`foreign_uuid` CHAR(36)',
        '`foreign_ulid` CHAR(26)',
    ]],
    'pgsql' => ['pgsql', [
        '"country_code" CHAR(2)',
        '"tiny_value" SMALLINT',
        '"medium_value" INTEGER',
        '"tiny_text" TEXT',
        '"medium_text" TEXT',
        '"long_text" TEXT',
        '"double_value" DOUBLE PRECISION',
        '"time_value" TIME(3) WITHOUT TIME ZONE',
        '"time_tz_value" TIME(3) WITH TIME ZONE',
        '"datetime_tz_value" TIMESTAMP(3) WITH TIME ZONE',
        '"timestamp_tz_value" TIMESTAMP(3) WITH TIME ZONE',
        '"year_value" SMALLINT',
        '"jsonb_value" JSONB',
        "\"state\" VARCHAR(255) CHECK (\"state\" IN ('new', 'owner''s'))",
        '"ip_value" INET',
        '"mac_value" MACADDR',
        '"ulid_value" CHAR(26)',
        '"foreign_id" BIGINT',
        '"foreign_uuid" UUID',
        '"foreign_ulid" CHAR(26)',
    ]],
    'sqlite' => ['sqlite', [
        '"country_code" TEXT',
        '"tiny_value" INTEGER',
        '"medium_value" INTEGER',
        '"tiny_text" TEXT',
        '"medium_text" TEXT',
        '"long_text" TEXT',
        '"double_value" REAL',
        '"time_value" TEXT',
        '"time_tz_value" TEXT',
        '"datetime_tz_value" TEXT',
        '"timestamp_tz_value" TEXT',
        '"year_value" INTEGER',
        '"jsonb_value" TEXT',
        "\"state\" TEXT CHECK (\"state\" IN ('new', 'owner''s'))",
        '"ip_value" TEXT',
        '"mac_value" TEXT',
        '"ulid_value" TEXT',
        '"foreign_id" INTEGER',
        '"foreign_uuid" TEXT',
        '"foreign_ulid" TEXT',
    ]],
]);

it('compiles increment aliases and explicit unsigned helpers', function (
    string $driver,
    string $tinyIncrement,
    string $mediumIncrement,
    string $smallIncrement,
): void {
    $compiler = new SchemaColumnCompiler($driver);

    expect($compiler->compile((new Blueprint('items'))->tinyIncrements()))->toContain($tinyIncrement)
        ->and($compiler->compile((new Blueprint('items'))->mediumIncrements()))->toContain($mediumIncrement)
        ->and($compiler->compile((new Blueprint('items'))->smallIncrements()))->toContain($smallIncrement);

    $table = new Blueprint('unsigned_values', true);
    $table->unsignedTinyInteger('tiny_value');
    $table->unsignedSmallInteger('small_value');
    $table->unsignedMediumInteger('medium_value');
    $table->unsignedInteger('integer_value');
    $table->unsignedBigInteger('big_value');
    $sql = (new SchemaGrammar($driver))->compile($table)[0];

    expect(substr_count($sql, ' UNSIGNED'))->toBe($driver === 'mysql' ? 5 : 0);
})->with([
    'mysql' => ['mysql', 'TINYINT UNSIGNED', 'MEDIUMINT UNSIGNED', 'SMALLINT UNSIGNED'],
    'pgsql' => ['pgsql', 'SMALLSERIAL', 'SERIAL', 'SMALLSERIAL'],
    'sqlite' => ['sqlite', 'INTEGER PRIMARY KEY AUTOINCREMENT', 'INTEGER PRIMARY KEY AUTOINCREMENT', 'INTEGER PRIMARY KEY AUTOINCREMENT'],
]);

it('compiles driver-specific set spatial and vector types explicitly', function (): void {
    $mysql = new Blueprint('mysql_special', true);
    $mysql->set('flags', ['one', 'two']);
    $mysql->geometry('location', 'point', 4326);
    $mysqlSql = (new SchemaGrammar('mysql'))->compile($mysql)[0];

    $pgsql = new Blueprint('pgsql_special', true);
    $pgsql->geometry('location', 'point', 3857);
    $pgsql->geography('world_location', 'point', 4326);
    $pgsql->vector('embedding', 768);
    $pgsqlSql = (new SchemaGrammar('pgsql'))->compile($pgsql)[0];

    expect($mysqlSql)->toContain("SET('one', 'two')")
        ->toContain('POINT SRID 4326')
        ->and($pgsqlSql)->toContain('GEOMETRY(POINT, 3857)')
        ->toContain('GEOGRAPHY(POINT, 4326)')
        ->toContain('VECTOR(768)');
});

it('rejects unavailable driver-specific column types', function (
    string $driver,
    Closure $definition,
    string $operation,
): void {
    $table = new Blueprint('unsupported_types', true);
    $definition($table);

    expect(fn(): array => (new SchemaGrammar($driver))->compile($table))
        ->toThrow(SchemaException::class, $operation);
})->with([
    'mysql geography' => ['mysql', static fn(Blueprint $table) => $table->geography('location'), 'geography'],
    'mysql vector' => ['mysql', static fn(Blueprint $table) => $table->vector('embedding', 3), 'vector'],
    'pgsql set' => ['pgsql', static fn(Blueprint $table) => $table->set('flags', ['one']), 'set'],
    'sqlite geometry' => ['sqlite', static fn(Blueprint $table) => $table->geometry('location'), 'geometry'],
    'sqlite geography' => ['sqlite', static fn(Blueprint $table) => $table->geography('location'), 'geography'],
    'sqlite vector' => ['sqlite', static fn(Blueprint $table) => $table->vector('embedding', 3), 'vector'],
]);

it('compiles expression defaults generated columns and timestamp update behavior', function (): void {
    $mysql = new Blueprint('generated_values', true);
    $mysql->json('payload')->default(Expression::make('JSON_ARRAY()'));
    $mysql->integer('total')->virtualAs('price * quantity');
    $mysql->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
    $mysqlSql = (new SchemaGrammar('mysql'))->compile($mysql)[0];

    $pgsql = new Blueprint('generated_values', true);
    $pgsql->integer('total')->storedAs(Expression::make('price * quantity'));
    $pgsqlSql = (new SchemaGrammar('pgsql'))->compile($pgsql)[0];

    expect($mysqlSql)->toContain('DEFAULT JSON_ARRAY()')
        ->toContain('GENERATED ALWAYS AS (price * quantity) VIRTUAL')
        ->toContain('DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP')
        ->and($pgsqlSql)->toContain('GENERATED ALWAYS AS (price * quantity) STORED');
});

it('compiles change column operations per supported dialect', function (): void {
    $mysql = new Blueprint('users');
    $mysql->string('name', 500)->nullable()->change();
    $pgsql = new Blueprint('users');
    $pgsql->string('name', 500)->default('unknown')->change();

    expect((new SchemaGrammar('mysql'))->compile($mysql))->toBe([
        'ALTER TABLE `users` MODIFY COLUMN `name` VARCHAR(500) NULL',
    ])->and((new SchemaGrammar('pgsql'))->compile($pgsql))->toBe([
        'ALTER TABLE "users" ALTER COLUMN "name" TYPE VARCHAR(500)',
        'ALTER TABLE "users" ALTER COLUMN "name" SET NOT NULL',
        'ALTER TABLE "users" ALTER COLUMN "name" SET DEFAULT \'unknown\'',
    ]);

    $sqlite = new Blueprint('users');
    $sqlite->string('name')->change();
    expect(fn(): array => (new SchemaGrammar('sqlite'))->compile($sqlite))
        ->toThrow(SchemaException::class, 'change column');
});

it('compiles schema convenience columns and their drop operations', function (): void {
    $create = new Blueprint('users', true);
    $create->timestamps(3);
    $create->softDeletes();
    $create->softDeletesTz('archived_at');
    $create->rememberToken();

    expect((new SchemaGrammar('pgsql'))->compile($create)[0])
        ->toContain('"created_at" TIMESTAMP(3) WITHOUT TIME ZONE NULL')
        ->toContain('"updated_at" TIMESTAMP(3) WITHOUT TIME ZONE NULL')
        ->toContain('"deleted_at" TIMESTAMP WITHOUT TIME ZONE NULL')
        ->toContain('"archived_at" TIMESTAMP WITH TIME ZONE NULL')
        ->toContain('"remember_token" VARCHAR(100) NULL');

    $timezoneCreate = new Blueprint('audits', true);
    $timezoneCreate->timestampsTz(3);
    expect((new SchemaGrammar('pgsql'))->compile($timezoneCreate)[0])
        ->toContain('"created_at" TIMESTAMP(3) WITH TIME ZONE NULL')
        ->toContain('"updated_at" TIMESTAMP(3) WITH TIME ZONE NULL');

    $alter = new Blueprint('users');
    $alter->dropTimestamps();
    $alter->dropSoftDeletes();
    $alter->dropRememberToken();

    expect((new SchemaGrammar('pgsql'))->compile($alter))->toHaveCount(4);
});

it('compiles primary unique and index lifecycle commands per dialect', function (): void {
    $mysql = new Blueprint('users');
    $mysql->dropPrimary();
    $mysql->dropUnique('users_email_unique');
    $mysql->renameIndex('users_name_index', 'users_display_name_index');

    expect((new SchemaGrammar('mysql'))->compile($mysql))->toBe([
        'ALTER TABLE `users` DROP PRIMARY KEY',
        'DROP INDEX `users_email_unique` ON `users`',
        'ALTER TABLE `users` RENAME INDEX `users_name_index` TO `users_display_name_index`',
    ]);

    $pgsql = new Blueprint('users');
    $pgsql->dropPrimary();
    $pgsql->dropUnique('users_email_unique');
    $pgsql->renameIndex('users_name_index', 'users_display_name_index');

    expect((new SchemaGrammar('pgsql'))->compile($pgsql))->toBe([
        'ALTER TABLE "users" DROP CONSTRAINT "users_pkey"',
        'DROP INDEX "users_email_unique"',
        'ALTER INDEX "users_name_index" RENAME TO "users_display_name_index"',
    ]);
});

it('rejects unsupported sqlite key and index lifecycle commands', function (Closure $definition): void {
    $table = new Blueprint('users');
    $definition($table);

    expect(fn(): array => (new SchemaGrammar('sqlite'))->compile($table))
        ->toThrow(SchemaException::class);
})->with([
    'drop primary' => static fn(Blueprint $table) => $table->dropPrimary(),
    'rename index' => static fn(Blueprint $table) => $table->renameIndex('old_index', 'new_index'),
]);

it('validates extended type parameters and generated modifiers', function (Closure $operation): void {
    expect($operation)->toThrow(SchemaException::class);
})->with([
    'empty enum' => static fn() => (new Blueprint('items'))->enum('state', []),
    'duplicate enum' => static fn() => (new Blueprint('items'))->enum('state', ['one', 'one']),
    'empty set value' => static fn() => (new Blueprint('items'))->set('state', ['']),
    'invalid char length' => static fn() => (new Blueprint('items'))->char('code', 0),
    'invalid temporal precision' => static fn() => (new Blueprint('items'))->timestamp('created_at', 7),
    'invalid vector dimension' => static fn() => (new Blueprint('items'))->vector('embedding', 0),
    'invalid spatial subtype' => static fn() => (new Blueprint('items'))->geometry('point', 'bad type'),
    'negative srid' => static fn() => (new Blueprint('items'))->geometry('point', 'point', -1),
    'empty generated expression' => static fn() => (new Blueprint('items'))->integer('total')->storedAs(''),
    'current on non-temporal' => static function (): void {
        $table = new Blueprint('items', true);
        $table->integer('count')->useCurrent();
        (new SchemaGrammar('mysql'))->compile($table);
    },
    'pgsql virtual generated' => static function (): void {
        $table = new Blueprint('items', true);
        $table->integer('count')->virtualAs('first + second');
        (new SchemaGrammar('pgsql'))->compile($table);
    },
    'pgsql current on update' => static function (): void {
        $table = new Blueprint('items', true);
        $table->timestamp('updated_at')->useCurrentOnUpdate();
        (new SchemaGrammar('pgsql'))->compile($table);
    },
]);
