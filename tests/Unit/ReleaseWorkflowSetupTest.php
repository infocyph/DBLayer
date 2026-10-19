<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * @param array<string,mixed> $manifest
 * @return array<string,mixed>
 */
function dblayerRunWorkflowManifestSetup(string $workflowName, string $job, string $stepName, array $manifest): array
{
    /** @var array{jobs:array<string,array{steps:list<array{name?:string,run?:string}>}>} $workflow */
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/' . $workflowName);
    $step = array_find($workflow['jobs'][$job]['steps'], static fn(array $step): bool => ($step['name'] ?? null) === $stepName);
    if ($step === null || preg_match("/php <<'PHP'\n(.+?)\nPHP(?:\n|$)/s", $step['run'] ?? '', $matches) !== 1) {
        throw new RuntimeException('Workflow manifest setup script was not found.');
    }
    $directory = sys_get_temp_dir() . '/dblayer-workflow-setup-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        file_put_contents($directory . '/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $process = new Process([PHP_BINARY], $directory);
        $process->setInput($matches[1]);
        $process->mustRun();
        /** @var array<string,mixed> $prepared */
        $prepared = json_decode((string) file_get_contents($directory . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        return $prepared;
    } finally {
        dblayerRemoveTestPath($directory);
    }
}

it('prepares a historical baseline without changing its production dependency contract', function (): void {
    $manifest = [
        'name' => 'infocyph/dblayer',
        'require' => ['php' => '^8.4', 'infocyph/arraykit' => '^5.3', 'infocyph/cachelayer' => '^4.0'],
        'require-dev' => ['infocyph/phpforge' => 'dev-main@dev', 'tomasvotruba/cognitive-complexity' => '1.2.0'],
        'autoload' => ['psr-4' => ['Infocyph\\DBLayer\\' => 'src/']],
        'autoload-dev' => ['classmap' => ['tests/']],
        'config' => ['sort-packages' => true],
    ];
    $prepared = dblayerRunWorkflowManifestSetup('release-performance.yml', 'release-performance', 'Prepare baseline production manifest', $manifest);

    expect($prepared)->not->toHaveKey('require-dev')->not->toHaveKey('autoload-dev')
        ->and($prepared['require'])->toBe($manifest['require'])
        ->and($prepared['autoload'])->toBe($manifest['autoload'])
        ->and($prepared['config'])->toBe($manifest['config']);
});

it('injects the ReqShield candidate while respecting released dependency constraints', function (): void {
    $manifest = [
        'require' => ['infocyph/cachelayer' => '^4.0'],
        'require-dev' => ['infocyph/dblayer' => '^6.0'],
        'conflict' => ['infocyph/dblayer' => '<6.0'],
        'repositories' => [['type' => 'composer', 'url' => 'https://packages.example.test']],
        'minimum-stability' => 'stable',
    ];
    $prepared = dblayerRunWorkflowManifestSetup('downstream-smoke.yml', 'reqshield', 'Inject DBLayer candidate', $manifest);
    /** @var array{require:array<string,string>,'require-dev':array<string,string>,conflict:array<string,string>,repositories:list<array{type:string,url:string,options?:array{versions:array<string,string>}}>,'minimum-stability':string} $prepared */
    $repository = $prepared['repositories'][0];
    $version = $repository['options']['versions']['infocyph/dblayer'];

    expect($prepared['require-dev']['infocyph/dblayer'])->toBe('^6.0')
        ->and($prepared['require'])->not->toHaveKey('infocyph/dblayer')
        ->and($prepared['conflict'])->toBe($manifest['conflict'])
        ->and($prepared['minimum-stability'])->toBe('stable')
        ->and($repository['type'])->toBe('path')->and($repository['url'])->toBe('../dblayer')
        ->and(preg_match('/^\d+\.\d+\.\d+$/D', $version))->toBe(1)
        ->and(version_compare($version, '6.0.0', '>='))->toBeTrue()
        ->and(version_compare($version, '7.0.0', '<'))->toBeTrue()
        ->and(end($prepared['repositories']))->toBe($manifest['repositories'][0]);
});
