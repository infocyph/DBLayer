<?php

declare(strict_types=1);

use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaGrammar;

it('keeps MariaDB schema compilation independent while retaining MySQL-family syntax', function (): void {
    $table = new Blueprint('lineup_items', true);
    $table->id();
    $table->boolean('active')->default(true);
    $table->json('payload');
    $table->string('code', 120)->unique();

    $sql = implode(' ', (new SchemaGrammar('mariadb'))->compile($table));

    expect($sql)
        ->toContain('CREATE TABLE `lineup_items`')
        ->toContain('`id` BIGINT UNSIGNED')
        ->toContain('AUTO_INCREMENT')
        ->toContain('`active` TINYINT(1)')
        ->toContain('`payload` JSON')
        ->toContain('`code` VARCHAR(120)');
});

it('compiles SQL Server identity types constraints and alter syntax', function (): void {
    $table = new Blueprint('lineup_items', true);
    $table->id();
    $table->boolean('active')->default(true);
    $table->json('payload');
    $table->uuid('public_id');

    $createSql = implode(' ', (new SchemaGrammar('mssql'))->compile($table));

    expect($createSql)
        ->toContain('CREATE TABLE [lineup_items]')
        ->toContain('[id] BIGINT IDENTITY(1,1)')
        ->toContain('[active] BIT')
        ->toContain('[payload] NVARCHAR(MAX)')
        ->toContain('[public_id] UNIQUEIDENTIFIER');

    $alter = new Blueprint('lineup_items');
    $alter->string('display_name')->nullable();
    $alterSql = implode(' ', (new SchemaGrammar('mssql'))->compile($alter));

    expect($alterSql)->toContain('ALTER TABLE [lineup_items] ADD [display_name] NVARCHAR(255) NULL');
});

it('normalizes SQL Server foreign-key restrict actions and reports transactional ddl', function (): void {
    $table = new Blueprint('children', true);
    $table->bigInteger('parent_id');
    $table->foreign('parent_id')
        ->references('id')
        ->on('parents')
        ->onDelete('restrict')
        ->onUpdate('cascade');

    $sql = implode(' ', (new SchemaGrammar('mssql'))->compile($table));

    expect($sql)
        ->toContain('ON DELETE NO ACTION')
        ->toContain('ON UPDATE CASCADE')
        ->and((new SchemaGrammar('mariadb'))->supportsTransactionalDdl())->toBeFalse()
        ->and((new SchemaGrammar('mssql'))->supportsTransactionalDdl())->toBeTrue();
});
