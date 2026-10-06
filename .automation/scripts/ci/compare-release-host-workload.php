<?php

declare(strict_types=1);

final class ReleaseHostComparator
{
    public function __construct(
        private readonly string $baselineFile,
        private readonly string $candidateFile,
        private readonly string $outputFile,
        private readonly float $maxThroughputRegressionPercent,
        private readonly string $baselineRevision,
        private readonly string $candidateRevision,
    ) {}

    public function run(): void
    {
        $baseline = $this->readResult($this->baselineFile);
        $candidate = $this->readResult($this->candidateFile);
        $baselineGroups = $this->validateResult($baseline, $this->baselineRevision);
        $candidateGroups = $this->validateResult($candidate, $this->candidateRevision);
        foreach (['workload', 'environment', 'duration_seconds', 'warmup_seconds'] as $field) {
            if ($baseline[$field] !== $candidate[$field]) {
                throw new RuntimeException("Mismatched workload metadata: {$field}.");
            }
        }
        $comparisons = [];
        $failures = [];

        foreach ($baselineGroups as $concurrency => $baselineRuns) {
            $candidateRuns = $candidateGroups[$concurrency] ?? [];
            if ($candidateRuns === []) {
                $failures[] = "Missing candidate results for concurrency {$concurrency}.";

                continue;
            }

            $comparison = $this->compareRuns((int) $concurrency, $baselineRuns, $candidateRuns);
            $comparisons[] = $comparison;

            foreach ($comparison['failures'] as $failure) {
                $failures[] = $failure;
            }
        }

        $payload = [
            'max_throughput_regression_percent' => $this->maxThroughputRegressionPercent,
            'comparisons' => $comparisons,
            'failures' => $failures,
            'passed' => $failures === [],
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->outputFile, $encoded . PHP_EOL) === false) {
            throw new RuntimeException("Unable to write comparison result: {$this->outputFile}");
        }

        fwrite(STDOUT, $encoded . PHP_EOL);

        if ($failures !== []) {
            throw new RuntimeException('Representative release workload exceeded its acceptance budget.');
        }
    }

    /**
     * @param list<array<array-key,mixed>> $baselineRuns
     * @param list<array<array-key,mixed>> $candidateRuns
     * @return array{failures:list<string>,...}
     */
    private function compareRuns(int $concurrency, array $baselineRuns, array $candidateRuns): array
    {
        $baselineRps = $this->medianMetric($baselineRuns, 'successful_rps');
        $candidateRps = $this->medianMetric($candidateRuns, 'successful_rps');
        $baselineP95 = $this->medianMetric($baselineRuns, 'p95_ms');
        $candidateP95 = $this->medianMetric($candidateRuns, 'p95_ms');
        $baselineRss = $this->medianMetric($baselineRuns, 'peak_rss_bytes');
        $candidateRss = $this->medianMetric($candidateRuns, 'peak_rss_bytes');
        $baselineQueryRatio = $this->queryRatio($baselineRuns);
        $candidateQueryRatio = $this->queryRatio($candidateRuns);
        $pairedThroughputRegressions = $this->pairedThroughputRegressions(
            $baselineRuns,
            $candidateRuns,
        );
        $throughputRegression = $this->medianValues($pairedThroughputRegressions);
        $failures = [];

        if (count($baselineRuns) !== count($candidateRuns)) {
            $failures[] = sprintf(
                'Concurrency %d has %d baseline trials but %d candidate trials.',
                $concurrency,
                count($baselineRuns),
                count($candidateRuns),
            );
        }

        $errorCount = $this->sumMetric($baselineRuns, 'errors')
            + $this->sumMetric($candidateRuns, 'errors')
            + $this->sumMetric($baselineRuns, 'worker_process_failures')
            + $this->sumMetric($candidateRuns, 'worker_process_failures');

        if ($errorCount > 0.0) {
            $failures[] = "Concurrency {$concurrency} recorded workload errors.";
        }

        if ($throughputRegression > $this->maxThroughputRegressionPercent) {
            $failures[] = sprintf(
                'Concurrency %d median matched-pair successful-RPS regression %.2f%% exceeds %.2f%%.',
                $concurrency,
                $throughputRegression,
                $this->maxThroughputRegressionPercent,
            );
        }

        $p95Limit = max($baselineP95 * 1.15, $baselineP95 + 1.0);
        if ($candidateP95 > $p95Limit) {
            $failures[] = sprintf(
                'Concurrency %d median p95 %.3fms exceeds %.3fms budget.',
                $concurrency,
                $candidateP95,
                $p95Limit,
            );
        }

        $rssLimit = max($baselineRss * 1.20, $baselineRss + (8 * 1024 * 1024));
        if ($candidateRss > $rssLimit) {
            $failures[] = sprintf(
                'Concurrency %d median RSS %.0f exceeds %.0f byte budget.',
                $concurrency,
                $candidateRss,
                $rssLimit,
            );
        }

        return [
            'concurrency' => $concurrency,
            'baseline_median_rps' => $baselineRps,
            'candidate_median_rps' => $candidateRps,
            'paired_throughput_regressions_percent' => $pairedThroughputRegressions,
            'throughput_regression_percent' => $throughputRegression,
            'baseline_median_p95_ms' => $baselineP95,
            'candidate_median_p95_ms' => $candidateP95,
            'baseline_median_peak_rss_bytes' => $baselineRss,
            'candidate_median_peak_rss_bytes' => $candidateRss,
            'baseline_queries_per_request' => $baselineQueryRatio,
            'candidate_queries_per_request' => $candidateQueryRatio,
            'failures' => $failures,
        ];
    }

    /**
     * @param array<array-key,mixed> $result
     * @return array<int,list<array<array-key,mixed>>>
     */
    private function validateResult(array $result, string $revision): array
    {
        $expected = [
            'schema_version' => 2,
            'workload' => 'dblayer-release-host-v1',
            'revision' => $revision,
            'trials' => 7,
            'concurrencies' => [1, 2, 4],
        ];
        foreach ($expected as $field => $value) {
            if (($result[$field] ?? null) !== $value) {
                throw new RuntimeException("Invalid workload metadata: {$field}.");
            }
        }
        if (preg_match('/^[a-f0-9]{40}$/D', $revision) !== 1) {
            throw new RuntimeException('An exact expected revision is required.');
        }
        if (!is_array($result['environment'] ?? null) || $result['environment'] === []) {
            throw new RuntimeException('Missing workload environment.');
        }
        foreach (['php', 'extensions', 'os', 'architecture', 'cpu', 'database', 'dataset'] as $field) {
            if (!is_string($result['environment'][$field] ?? null) || $result['environment'][$field] === '') {
                throw new RuntimeException("Missing workload environment field: {$field}.");
            }
        }
        $this->requireMetric($result, 'duration_seconds', 1.0);
        $this->requireMetric($result, 'warmup_seconds', 0.0);
        return $this->validateTrials($result['runs'] ?? null);
    }

    /** @return array<int,list<array<array-key,mixed>>> */
    private function validateTrials(mixed $runs): array
    {
        if (!is_array($runs) || !array_is_list($runs) || count($runs) !== 21) {
            throw new RuntimeException('Expected seven measured trials at each of concurrency 1, 2 and 4.');
        }
        $seen = [];
        $groups = [];
        foreach ($runs as $run) {
            if (!is_array($run)) {
                throw new RuntimeException('Malformed workload trial.');
            }
            [$concurrency, $trial] = $this->validateTrial($run);
            $key = $concurrency . ':' . $trial;
            if (isset($seen[$key])) {
                throw new RuntimeException("Duplicate matched trial: {$key}.");
            }
            $seen[$key] = true;
            $groups[$concurrency][$trial] = $run;
        }
        ksort($groups);
        $ordered = [];
        foreach ($groups as $concurrency => $group) {
            ksort($group);
            $ordered[$concurrency] = array_values($group);
        }

        return $ordered;
    }

    /** @param array<array-key,mixed> $run
     * @return array{0:int,1:int} */
    private function validateTrial(array $run): array
    {
        $concurrency = $run['concurrency'] ?? null;
        $trial = $run['trial'] ?? null;
        if (!is_int($concurrency) || !in_array($concurrency, [1, 2, 4], true)
            || !is_int($trial) || !in_array($trial, range(1, 7), true)) {
            throw new RuntimeException('Invalid concurrency or matched trial identity.');
        }
        foreach (['successful_requests', 'queries', 'peak_rss_bytes'] as $field) {
            $this->requireMetric($run, $field, 1.0);
        }
        $this->requireMetric($run, 'successful_rps', PHP_FLOAT_MIN);
        foreach (['errors', 'worker_process_failures', 'reads', 'writes', 'p50_ms', 'p95_ms', 'p99_ms'] as $field) {
            $this->requireMetric($run, $field, 0.0);
        }

        return [$concurrency, $trial];
    }

    /** @param array<array-key,mixed> $record */
    private function requireMetric(array $record, string $field, float $minimum): void
    {
        $value = $record[$field] ?? null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $minimum) {
            throw new RuntimeException("Invalid or missing workload metric: {$field}.");
        }
    }

    /**
     * @param list<float> $values
     */
    private function medianValues(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2.0;
    }

    /**
     * @param list<array<array-key,mixed>> $baselineRuns
     * @param list<array<array-key,mixed>> $candidateRuns
     * @return list<float>
     */
    private function pairedThroughputRegressions(array $baselineRuns, array $candidateRuns): array
    {
        $count = min(count($baselineRuns), count($candidateRuns));
        $regressions = [];

        for ($index = 0; $index < $count; $index++) {
            $baselineRps = $baselineRuns[$index]['successful_rps'] ?? null;
            $candidateRps = $candidateRuns[$index]['successful_rps'] ?? null;

            if (
                (!is_int($baselineRps) && !is_float($baselineRps))
                || (!is_int($candidateRps) && !is_float($candidateRps))
                || (float) $baselineRps <= 0.0
            ) {
                $regressions[] = 100.0;

                continue;
            }

            $regressions[] = (
                ((float) $baselineRps - (float) $candidateRps)
                / (float) $baselineRps
            ) * 100.0;
        }

        return $regressions;
    }

    /**
     * @param list<array<array-key,mixed>> $runs
     */
    private function medianMetric(array $runs, string $metric): float
    {
        $values = [];

        foreach ($runs as $run) {
            $value = $run[$metric] ?? null;
            if (is_int($value) || is_float($value)) {
                $values[] = (float) $value;
            }
        }

        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2.0;
    }

    /** @return array<array-key,mixed> */
    private function readResult(string $file): array
    {
        $contents = file_get_contents($file);
        if (!is_string($contents) || $contents === '') {
            throw new RuntimeException("Unable to read workload result: {$file}");
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid workload result: {$file}");
        }

        return $decoded;
    }

    /**
     * @param list<array<array-key,mixed>> $runs
     */
    private function queryRatio(array $runs): float
    {
        $queries = $this->sumMetric($runs, 'queries');
        $requests = $this->sumMetric($runs, 'successful_requests');

        return $requests > 0.0 ? $queries / $requests : 0.0;
    }

    /**
     * @param list<array<array-key,mixed>> $runs
     */
    private function sumMetric(array $runs, string $metric): float
    {
        $sum = 0.0;

        foreach ($runs as $run) {
            $value = $run[$metric] ?? null;
            if (is_int($value) || is_float($value)) {
                $sum += (float) $value;
            }
        }

        return $sum;
    }
}

/** @param array<array-key,mixed> $options
 * @return non-empty-string */
function releaseCompareRequiredOption(array $options, string $key): string
{
    $value = $options[$key] ?? null;

    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
        throw new InvalidArgumentException("Missing required --{$key} option.");
    }

    return $value;
}

$options = getopt('', ['baseline:', 'candidate:', 'output:', 'max-regression::', 'baseline-revision:', 'candidate-revision:']);

(new ReleaseHostComparator(
    releaseCompareRequiredOption($options, 'baseline'),
    releaseCompareRequiredOption($options, 'candidate'),
    releaseCompareRequiredOption($options, 'output'),
    max(0.0, (float) ($options['max-regression'] ?? 2.0)),
    releaseCompareRequiredOption($options, 'baseline-revision'),
    releaseCompareRequiredOption($options, 'candidate-revision'),
))->run();
