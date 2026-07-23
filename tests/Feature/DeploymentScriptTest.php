<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentScriptTest extends TestCase
{
    private ?string $temporaryDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('posix_getegid') || ! function_exists('posix_geteuid')) {
            $this->markTestSkipped('The POSIX extension is required to exercise the deployment script.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'fishcounts-deploy-');

        $this->assertNotFalse($temporaryPath);
        unlink($temporaryPath);
        mkdir($temporaryPath, 0700);

        $this->temporaryDirectory = $temporaryPath;
    }

    protected function tearDown(): void
    {
        if ($this->temporaryDirectory !== null) {
            File::deleteDirectory($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function test_config_preflight_failure_does_not_modify_the_active_release(): void
    {
        $deployment = $this->prepareDeployment();

        $process = $this->runDeployment($deployment, ['FAIL_PREFLIGHT' => '1']);

        $this->assertFalse($process->isSuccessful(), $process->getOutput());
        $this->assertSame($deployment['old_release'], realpath($deployment['deploy_path'].'/current'));
        $this->assertSame('old-bootstrap-cache', File::get($deployment['old_bootstrap_sentinel']));
        $this->assertSame('old-compiled-view', File::get($deployment['old_view_sentinel']));
        $this->assertSame([$deployment['old_release']], $this->releaseDirectories($deployment['deploy_path']));

        $log = File::get($deployment['log']);
        $this->assertStringContainsString('php:artisan fish:production-check --skip-database --no-interaction', $log);
        $this->assertStringNotContainsString('php:artisan migrate', $log);
    }

    public function test_full_production_check_failure_keeps_candidate_caches_isolated(): void
    {
        $deployment = $this->prepareDeployment();

        $process = $this->runDeployment($deployment, ['FAIL_FULL_CHECK' => '1']);

        $this->assertFalse($process->isSuccessful(), $process->getOutput());
        $this->assertSame($deployment['old_release'], realpath($deployment['deploy_path'].'/current'));
        $this->assertSame('old-bootstrap-cache', File::get($deployment['old_bootstrap_sentinel']));
        $this->assertSame('old-compiled-view', File::get($deployment['old_view_sentinel']));
        $this->assertSame([$deployment['old_release']], $this->releaseDirectories($deployment['deploy_path']));

        $log = File::get($deployment['log']);
        $this->assertCommandOrder($log, [
            'php:artisan fish:production-check --skip-database --no-interaction',
            'php:artisan migrate --force --no-interaction',
            'php:artisan optimize --no-interaction',
            'php:artisan fish:production-check --no-interaction',
        ]);
        $this->assertStringNotContainsString('php:artisan queue:restart', $log);
    }

    public function test_successful_deployment_switches_to_release_local_caches_before_restarting_workers(): void
    {
        $deployment = $this->prepareDeployment();

        $process = $this->runDeployment($deployment);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $currentRelease = realpath($deployment['deploy_path'].'/current');

        $this->assertNotFalse($currentRelease);
        $this->assertNotSame($deployment['old_release'], $currentRelease);
        $this->assertSame('candidate-bootstrap-cache', File::get($currentRelease.'/bootstrap/cache/config.php'));
        $this->assertSame('candidate-compiled-view', File::get($currentRelease.'/storage/framework/views/view.php'));
        $this->assertSame('old-bootstrap-cache', File::get($deployment['old_bootstrap_sentinel']));
        $this->assertSame('old-compiled-view', File::get($deployment['old_view_sentinel']));

        $log = File::get($deployment['log']);
        $this->assertCommandOrder($log, [
            'php:artisan fish:production-check --skip-database --no-interaction',
            'php:artisan migrate --force --no-interaction',
            'php:artisan fish:production-check --no-interaction',
            'php:artisan queue:restart --no-interaction',
        ]);
    }

    /**
     * @return array{
     *     deploy_path: string,
     *     archive: string,
     *     php: string,
     *     composer: string,
     *     log: string,
     *     old_release: string,
     *     old_bootstrap_sentinel: string,
     *     old_view_sentinel: string,
     *     group: string,
     *     user: string
     * }
     */
    private function prepareDeployment(): array
    {
        $this->assertNotNull($this->temporaryDirectory);

        $deployPath = $this->temporaryDirectory.'/deployment';
        $oldRelease = $deployPath.'/releases/old-release';
        $oldBootstrapSentinel = $oldRelease.'/bootstrap/cache/config.php';
        $oldViewSentinel = $oldRelease.'/storage/framework/views/view.php';
        $fixturePath = $this->temporaryDirectory.'/fixture';
        $binaryPath = $this->temporaryDirectory.'/bin';
        $archivePath = $this->temporaryDirectory.'/release.tar.gz';
        $logPath = $this->temporaryDirectory.'/commands.log';

        File::ensureDirectoryExists(dirname($oldBootstrapSentinel));
        File::ensureDirectoryExists(dirname($oldViewSentinel));
        File::ensureDirectoryExists($deployPath.'/shared');
        File::ensureDirectoryExists($fixturePath.'/public/build');
        File::ensureDirectoryExists($binaryPath);

        File::put($oldBootstrapSentinel, 'old-bootstrap-cache');
        File::put($oldViewSentinel, 'old-compiled-view');
        File::put($deployPath.'/shared/.env', "APP_ENV=production\n");
        File::put($fixturePath.'/public/build/manifest.json', '{}');
        File::put($fixturePath.'/.release-sha', 'test-release-sha');
        File::put($fixturePath.'/.release-manifest', "fixture\n");
        File::put($fixturePath.'/artisan', '');
        symlink($oldRelease, $deployPath.'/current');

        $composerPath = $binaryPath.'/composer';
        File::put($composerPath, <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
printf 'composer:%s\n' "$*" >> "${DEPLOY_TEST_LOG}"
mkdir -p bootstrap/cache
printf 'composer-bootstrap-cache' > bootstrap/cache/packages.php
BASH);

        $phpPath = $binaryPath.'/php';
        File::put($phpPath, <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
printf 'php:%s\n' "$*" >> "${DEPLOY_TEST_LOG}"

if [[ "${2:-}" == "fish:production-check" && "$*" == *"--skip-database"* ]]; then
    [[ "${FAIL_PREFLIGHT:-0}" != "1" ]]
    exit
fi

if [[ "${2:-}" == "optimize" ]]; then
    mkdir -p bootstrap/cache storage/framework/views
    printf 'candidate-bootstrap-cache' > bootstrap/cache/config.php
    printf 'candidate-compiled-view' > storage/framework/views/view.php
fi

if [[ "${2:-}" == "fish:production-check" ]]; then
    [[ "${FAIL_FULL_CHECK:-0}" != "1" ]]
fi
BASH);

        $sha256sumPath = $binaryPath.'/sha256sum';
        File::put($sha256sumPath, <<<'BASH'
#!/usr/bin/env bash
exit 0
BASH);

        chmod($composerPath, 0755);
        chmod($phpPath, 0755);
        chmod($sha256sumPath, 0755);

        $archiveProcess = new Process(['tar', '-czf', $archivePath, '-C', $fixturePath, '.']);
        $archiveProcess->mustRun();

        $group = posix_getgrgid(posix_getegid());
        $user = posix_getpwuid(posix_geteuid());

        $this->assertIsArray($group);
        $this->assertIsArray($user);

        return [
            'deploy_path' => $deployPath,
            'archive' => $archivePath,
            'php' => $phpPath,
            'composer' => $composerPath,
            'log' => $logPath,
            'old_release' => $oldRelease,
            'old_bootstrap_sentinel' => $oldBootstrapSentinel,
            'old_view_sentinel' => $oldViewSentinel,
            'group' => $group['name'],
            'user' => $user['name'],
        ];
    }

    /**
     * @param  array<string, string>  $deployment
     * @param  array<string, string>  $environment
     */
    private function runDeployment(array $deployment, array $environment = []): Process
    {
        $process = new Process(
            ['bash', base_path('.github/scripts/deploy.sh')],
            base_path(),
            [
                ...$environment,
                'APP_GROUP' => $deployment['group'],
                'COMPOSER_BIN' => $deployment['composer'],
                'DEPLOY_PATH' => $deployment['deploy_path'],
                'DEPLOY_TEST_LOG' => $deployment['log'],
                'KEEP_RELEASES' => '5',
                'PATH' => dirname($deployment['php']).':'.getenv('PATH'),
                'PHP_BIN' => $deployment['php'],
                'RELEASE_ARCHIVE' => $deployment['archive'],
                'RELEASE_SHA' => 'test-release-sha',
                'WEB_USER' => $deployment['user'],
            ],
        );
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    /**
     * @return list<string>
     */
    private function releaseDirectories(string $deployPath): array
    {
        $releases = glob($deployPath.'/releases/*', GLOB_ONLYDIR);

        $this->assertIsArray($releases);
        sort($releases);

        return $releases;
    }

    /**
     * @param  list<string>  $commands
     */
    private function assertCommandOrder(string $log, array $commands): void
    {
        $previousPosition = -1;

        foreach ($commands as $command) {
            $position = strpos($log, $command);

            $this->assertNotFalse($position, "Command [{$command}] was not recorded.");
            $this->assertGreaterThan($previousPosition, $position, "Command [{$command}] ran out of order.");

            $previousPosition = $position;
        }
    }
}
