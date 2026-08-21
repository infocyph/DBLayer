<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema\Dialect;

final class MariaDBSchemaDialect extends AbstractMySqlSchemaDialect
{
    #[\Override]
    public function name(): string
    {
        return 'mariadb';
    }
}
