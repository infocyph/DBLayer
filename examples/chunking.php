<?php

// examples/chunking.php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

require __DIR__ . '/bootstrap.php';

$writeLine = static function (string $message): void {
    fwrite(STDOUT, $message . PHP_EOL);
};

// 4.1 Generic chunk() – paged by LIMIT/OFFSET
DB::table('audit_logs')
  ->orderBy('id') // always sort before chunking for deterministic paging
  ->chunk(1000, function (array $rows, int $page) use ($writeLine): bool {
      $writeLine("Processing chunk page {$page}, rows: " . count($rows));

      foreach ($rows as $row) {
          unset($row); // process $row ...
      }

      // Return false to break early, true/null to continue
      return true;
  });

// 4.2 chunkById() – safer for large/volatile tables
DB::table('audit_logs')
  ->where('created_at', '>=', date('Y-m-d 00:00:00'))
  ->chunkById(1000, function (array $rows) use ($writeLine): bool {
      $writeLine('Processing chunkById rows: ' . count($rows));

      foreach ($rows as $row) {
          unset($row); // process $row ...
      }

      return true;
  }, 'id');
