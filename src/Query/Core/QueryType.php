<?php

// src/Query/Core/QueryType.php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Core;

enum QueryType: string
{
    case DELETE   = 'delete';
    case INSERT   = 'insert';
    case SELECT   = 'select';
    case TRUNCATE = 'truncate';
    case UPDATE   = 'update';
}
