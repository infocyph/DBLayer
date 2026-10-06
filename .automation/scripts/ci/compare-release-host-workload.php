<?php

declare(strict_types=1);

final class ReleaseHostComparator
{
    public function __construct(
        private readonly string $baselineFile,
        private readonly string $candidateFile,
        private readonly string $outputFile,
        private readonly float $maxThroughputRegressionPercent,
    ) {}

    public function run(): void
    {
        $baseline = $this->readResult($this->baselineFile);
        $candidate = $this->readResult($this->candidateFile);
        $baselineGroups = $this->groupByConcurrency($baseline['runs'] ?? []);
        $candidateGroups = $this->groupByConcurrency($candidate['runs'] ?? []);
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
     * @param list<array<string,mixed>> $baselineRuns
     * @param list<array<string,mixed>> $candidateRuns
     * @return array<string,mixed>
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
     * @param list<array<string,mixed>> $runs
     * @return array<int,list<array<string,mixed>>>
     */
    private function groupByConcurrency(array $runs): array
    {
        $groups = [];

        foreach ($runs as $run) {
            if (!is_array($run)) {
                continue;
            }

            $concurrency = (int) ($run['concurrency'] ?? 0);
            if ($concurrency < 1) {
                continue;
            }

            $groups[$concurrency] ??= [];
            $groups[$concurrency][] = $run;
        }

        ksort($groups);

        return $groups;
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
     * @param list<array<string,mixed>> $baselineRuns
     * @param list<array<string,mixed>> $candidateRuns
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
     * @param list<array<string,mixed>> $runs
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

    /** @return array<string,mixed> */
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
     * @param list<array<string,mixed>> $runs
     */
    private function queryRatio(array $runs): float
    {
        $queries = $this->sumMetric($runs, 'queries');
        $requests = $this->sumMetric($runs, 'successful_requests');

        return $requests > 0.0 ? $queries / $requests : 0.0;
    }

    /**
     * @param list<array<string,mixed>> $runs
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

/** @return non-empty-string */
function releaseCompareRequiredOption(array $options, string $key): string
{
    $value = $options[$key] ?? null;

    if (!is_string($value) || trim($value) === '') {
        throw new InvalidArgumentException("Missing required --{$key} option.");
    }

    return trim($value);
}

$options = getopt('', ['baseline:', 'candidate:', 'output:', 'max-regression::']);

(new ReleaseHostComparator(
    releaseCompareRequiredOption($options, 'baseline'),
    releaseCompareRequiredOption($options, 'candidate'),
    releaseCompareRequiredOption($options, 'output'),
    max(0.0, (float) ($options['max-regression'] ?? 2.0)),
))->run();
