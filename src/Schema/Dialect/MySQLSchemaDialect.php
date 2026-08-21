<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema\Dialect;

final class MySQLSchemaDialect extends AbstractMySqlSchemaDialect
{
    #[\Override]
    public function name(): string
    {
        return 'mysql';
    }
}
