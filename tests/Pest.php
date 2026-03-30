<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

beforeEach(function (): void {
    DB::purge();
});

afterEach(function (): void {
    DB::purge();
});
