<?php
// examples/chunking.php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

require __DIR__ . '/bootstrap.php';

// 4.1 Generic chunk() – paged by LIMIT/OFFSET
DB::table('audit_logs')
  ->orderBy('id') // always sort before chunking for deterministic paging
  ->chunk(1000, static function (array $rows, int $page): bool {
      echo "Processing chunk page {$page}, rows: " . count($rows) . PHP_EOL;

      foreach ($rows as $row) {
          // process $row ...
      }

      // Return false to break early, true/null to continue
      return true;
  });

// 4.2 chunkById() – safer for large/volatile tables
DB::table('audit_logs')
  ->where('created_at', '>=', date('Y-m-d 00:00:00'))
  ->chunkById(1000, static function (array $rows): bool {
      echo "Processing chunkById rows: " . count($rows) . PHP_EOL;

      foreach ($rows as $row) {
          // process $row ...
      }

      return true;
  }, 'id');
