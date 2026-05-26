#!/usr/bin/env php
<?php

declare(strict_types=1);

error_reporting(E_ALL);

final class TestFailure extends RuntimeException
{
}

final class CommandResult
{
    public string $command;
    public int $exitCode;
    public string $stdout;
    public string $stderr;

    public function __construct(string $command, int $exitCode, string $stdout, string $stderr)
    {
        $this->command = $command;
        $this->exitCode = $exitCode;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    public function combinedOutput(): string
    {
        return $this->stdout . $this->stderr;
    }
}

final class IntegrationSuite
{
    private string $repoRoot;
    private array $tmpDirs = [];
    private int $passed = 0;

    public function __construct(string $repoRoot)
    {
        $this->repoRoot = $repoRoot;
    }

    public function run(): int
    {
        $this->requireCommand('composer');
        $this->requireCommand('git');
        $this->requireCommand('tar');
        $this->requireCommand('gzip');
        $this->requireCommand('php');

        $tests = [
            'pack specified path keeps nested structure and single-layer tar.gz' => function (): void {
                $this->testPackSpecifiedPath();
            },
            'full pack with vendor keeps single-layer tar.gz' => function (): void {
                $this->testFullPackWithVendor();
            },
            'replaceme install and rollback keep backup suffix and restore files' => function (): void {
                $this->testReplacemeInstallAndRollback();
            },
            'last commit pack keeps a single runtime replaceme.ini' => function (): void {
                $this->testLastCommitPackKeepsSingleRuntimeConfig();
            },
            'replaceme stops when backup suffix cannot be persisted' => function (): void {
                $this->testReplacemeStopsWhenConfigIsNotWritable();
            },
        ];

        foreach ($tests as $name => $test) {
            $start = microtime(true);
            try {
                $test();
                $this->passed++;
                $duration = number_format(microtime(true) - $start, 2);
                fwrite(STDOUT, "[PASS] {$name} ({$duration}s)" . PHP_EOL);
            } catch (Throwable $e) {
                fwrite(STDERR, "[FAIL] {$name}" . PHP_EOL);
                fwrite(STDERR, $e->getMessage() . PHP_EOL);
                $this->cleanup(false);
                return 1;
            }
        }

        fwrite(STDOUT, PHP_EOL . "Passed {$this->passed} integration tests." . PHP_EOL);
        $this->cleanup(true);
        return 0;
    }

    private function testPackSpecifiedPath(): void
    {
        $projectDir = $this->createComposerProject('packme-test-path');
        $this->writeFile($projectDir . '/common/config/a.php', "<?php return ['ok' => true];\n");
        $this->writeFile($projectDir . '/common/config/sub/b.php', "<?php return ['nested' => true];\n");
        $this->writeFile($projectDir . '/dist/changes.txt', "manual note\n");
        $this->initGitRepository($projectDir, ['composer.json', 'composer.lock', 'common', 'dist']);

        $run = $this->runCommand('php vendor/bin/packme', $projectDir, "9\ncommon/config\n");
        $this->assertSame(0, $run->exitCode, 'pack specified path should succeed: ' . $run->combinedOutput());

        $archives = glob($projectDir . '/dist/*_PATH_*.tar.gz');
        $this->assertTrue(!empty($archives), 'path packaging should create a tar.gz archive');
        $archive = $archives[0];

        $list = $this->runCommand('tar -tzf ' . escapeshellarg($archive), $projectDir);
        $this->assertSame(0, $list->exitCode, 'archive should be readable by tar -tzf');
        $this->assertContains('common/config/', $list->stdout, 'archive should keep the full nested directory path');
        $this->assertContains('common/config/sub/b.php', $list->stdout, 'archive should contain nested files');
        $this->assertNotContains(".tar\n", $list->stdout, 'archive should not contain an inner tar file');

        $replacemeIni = $this->extractTarEntry($archive, 'common/config/replaceme.ini', $projectDir);
        $this->assertContains('object_root=/srun3/www/', $replacemeIni, 'replaceme.ini should be packed beside target files');

        $changes = $this->extractTarEntry($archive, 'common/config/changes.txt', $projectDir);
        $this->assertContains('manual note', $changes, 'changes.txt should keep original notes');
        $this->assertContains('sub/b.php', $changes, 'changes.txt should append nested relative paths');
        $this->assertContains('a.php', $changes, 'changes.txt should append file paths');
    }

    private function testFullPackWithVendor(): void
    {
        $projectDir = $this->createComposerProject('packme-test-full');
        $this->writeFile($projectDir . '/app/index.php', "<?php echo 'app';\n");
        $this->writeFile($projectDir . '/src/lib.php', "<?php echo 'src';\n");
        $this->initGitRepository($projectDir, ['composer.json', 'composer.lock', 'app', 'src']);

        $run = $this->runCommand('php vendor/bin/packme', $projectDir, "1\ny\n");
        $this->assertSame(0, $run->exitCode, 'full pack with vendor should succeed: ' . $run->combinedOutput());

        $archives = glob($projectDir . '/dist/*.tar.gz');
        $this->assertTrue(!empty($archives), 'full packaging should create a tar.gz archive');
        $archive = $archives[0];

        $list = $this->runCommand('tar -tzf ' . escapeshellarg($archive), $projectDir);
        $this->assertSame(0, $list->exitCode, 'full archive should be readable by tar -tzf');
        $this->assertContains("vendor/\n", $list->stdout, 'vendor directory should be included when choosing vendor=y');
        $this->assertContains('vendor/autoload.php', $list->stdout, 'vendor files should be present');
        $this->assertContains("replaceme.ini\n", $list->stdout, 'replaceme.ini should be packed at archive root');
        $this->assertContains("version.ini\n", $list->stdout, 'version.ini should be packed at archive root');
        $this->assertNotContains(".tar\n", $list->stdout, 'full archive should not contain an inner tar file');
    }

    private function testReplacemeInstallAndRollback(): void
    {
        $packageDir = $this->createTempDir('replaceme-package-');
        $targetDir = $this->createTempDir('replaceme-target-');

        $this->writeFile($packageDir . '/replaceme', file_get_contents($this->repoRoot . '/replaceme'));
        $this->writeFile($packageDir . '/replaceme5', file_get_contents($this->repoRoot . '/replaceme5'));
        chmod($packageDir . '/replaceme', 0755);
        chmod($packageDir . '/replaceme5', 0755);
        $this->writeFile($packageDir . '/replaceme.ini', 'object_root=' . $targetDir . '/' . PHP_EOL);
        $this->writeFile($packageDir . '/module/demo.php', "<?php echo 'new';\n");
        $this->writeFile($packageDir . '/newdir/created.php', "<?php echo 'created';\n");

        $this->writeFile($targetDir . '/module/demo.php', "<?php echo 'old';\n");

        $install = $this->runCommand('php ./replaceme', $packageDir, $targetDir . "\n");
        $this->assertSame(0, $install->exitCode, 'replaceme install should succeed: ' . $install->combinedOutput());

        $installedConfig = parse_ini_file($packageDir . '/replaceme.ini');
        $this->assertTrue(is_array($installedConfig), 'replaceme.ini should remain parseable after install');
        $this->assertTrue(!empty($installedConfig['backup_suffix'] ?? ''), 'backup_suffix should be written during install');
        $this->assertTrue(!empty($installedConfig['install_time'] ?? ''), 'install_time should be written during install');

        $backupSuffix = (string)$installedConfig['backup_suffix'];
        $this->assertSame("<?php echo 'new';\n", file_get_contents($targetDir . '/module/demo.php'), 'target file should be replaced');
        $this->assertSame("<?php echo 'old';\n", file_get_contents($targetDir . '/module/demo.php' . $backupSuffix), 'original file should be backed up');
        $this->assertSame("<?php echo 'created';\n", file_get_contents($targetDir . '/newdir/created.php'), 'new files should be created');

        $rollback = $this->runCommand('php ./replaceme --rollback', $packageDir, $targetDir . "\ny\n");
        $this->assertSame(0, $rollback->exitCode, 'replaceme rollback should succeed: ' . $rollback->combinedOutput());

        $rolledConfig = parse_ini_file($packageDir . '/replaceme.ini');
        $this->assertTrue(is_array($rolledConfig), 'replaceme.ini should remain parseable after rollback');
        $this->assertTrue(!empty($rolledConfig['rollback_time'] ?? ''), 'rollback_time should be written during rollback');
        $this->assertSame("<?php echo 'old';\n", file_get_contents($targetDir . '/module/demo.php'), 'rollback should restore original file');
        $this->assertTrue(!is_file($targetDir . '/module/demo.php' . $backupSuffix), 'rollback should remove backup file after restore');
        $this->assertTrue(!is_file($targetDir . '/newdir/created.php'), 'rollback should remove newly created files');
    }

    private function testLastCommitPackKeepsSingleRuntimeConfig(): void
    {
        $projectDir = $this->createComposerProject('packme-test-last-commit');
        $this->writeFile($projectDir . '/app/index.php', "<?php echo 'old';\n");
        $this->initGitRepository($projectDir, ['composer.json', 'composer.lock', 'app']);

        $this->writeFile($projectDir . '/app/index.php', "<?php echo 'new';\n");
        $this->writeFile($projectDir . '/replaceme.ini', 'object_root=/tmp/packme-target/' . PHP_EOL);
        $this->assertSame(0, $this->runCommand('git add app/index.php replaceme.ini', $projectDir)->exitCode, 'git add second commit files should succeed');
        $commit = $this->runCommand("git commit -qm 'change app and config'", $projectDir);
        $this->assertSame(0, $commit->exitCode, 'git second commit should succeed: ' . $commit->combinedOutput());

        $this->writeFile(
            $projectDir . '/replaceme.ini',
            'object_root=/tmp/packme-target/' . PHP_EOL . 'backup_suffix=WORKTREE' . PHP_EOL
        );

        $run = $this->runCommand('php vendor/bin/packme', $projectDir, "5\n");
        $this->assertSame(0, $run->exitCode, 'last commit pack should succeed: ' . $run->combinedOutput());

        $archives = glob($projectDir . '/dist/*_LAST1COMMIT_*.tar.gz');
        $this->assertTrue(!empty($archives), 'last commit packaging should create a tar.gz archive');
        $archive = $archives[0];

        $list = $this->runCommand('tar -tzf ' . escapeshellarg($archive), $projectDir);
        $this->assertSame(0, $list->exitCode, 'archive should be readable by tar -tzf');
        $this->assertSame(1, substr_count($list->stdout, "replaceme.ini\n"), 'archive should contain one top-level replaceme.ini');

        $replacemeIni = $this->extractTarEntry($archive, 'replaceme.ini', $projectDir);
        $this->assertContains('backup_suffix=WORKTREE', $replacemeIni, 'runtime replaceme.ini should come from working tree add-file');
    }

    private function testReplacemeStopsWhenConfigIsNotWritable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            fwrite(STDOUT, '[SKIP] replaceme config permission check is skipped for root user' . PHP_EOL);
            return;
        }

        $packageDir = $this->createTempDir('replaceme-readonly-package-');
        $targetDir = $this->createTempDir('replaceme-readonly-target-');

        $this->writeFile($packageDir . '/replaceme', file_get_contents($this->repoRoot . '/replaceme'));
        chmod($packageDir . '/replaceme', 0755);
        $this->writeFile($packageDir . '/replaceme.ini', 'object_root=' . $targetDir . '/' . PHP_EOL);
        $this->writeFile($packageDir . '/module/demo.php', "<?php echo 'new';\n");
        $this->writeFile($targetDir . '/module/demo.php', "<?php echo 'old';\n");
        chmod($packageDir . '/replaceme.ini', 0444);

        $install = $this->runCommand('php ./replaceme', $packageDir, $targetDir . "\n");
        chmod($packageDir . '/replaceme.ini', 0644);

        $this->assertTrue($install->exitCode !== 0, 'replaceme should fail when backup_suffix cannot be written');
        $this->assertContains('写入replaceme.ini失败', $install->combinedOutput(), 'replaceme should report config write failure');
        $this->assertSame("<?php echo 'old';\n", file_get_contents($targetDir . '/module/demo.php'), 'target file should not be replaced when config write fails');
    }

    private function createComposerProject(string $prefix): string
    {
        $projectDir = $this->createTempDir($prefix . '-');
        $composer = [
            'name' => 'tests/' . $prefix,
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => $this->repoRoot,
                    'options' => ['symlink' => true],
                ],
            ],
            'require-dev' => [
                'luguohuakai/packme' => '*@dev',
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ];

        $this->writeFile(
            $projectDir . '/composer.json',
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );

        $install = $this->runCommand('composer install --no-interaction --ignore-platform-req=ext-redis', $projectDir);
        $this->assertSame(0, $install->exitCode, 'composer install should succeed: ' . $install->combinedOutput());

        return $projectDir;
    }

    private function initGitRepository(string $projectDir, array $pathsToAdd): void
    {
        $this->assertSame(0, $this->runCommand('git init -q', $projectDir)->exitCode, 'git init should succeed');
        $this->assertSame(0, $this->runCommand('git config user.email tests@example.com', $projectDir)->exitCode, 'git email config should succeed');
        $this->assertSame(0, $this->runCommand('git config user.name packme-tests', $projectDir)->exitCode, 'git name config should succeed');

        $quoted = array_map('escapeshellarg', $pathsToAdd);
        $this->assertSame(0, $this->runCommand('git add ' . implode(' ', $quoted), $projectDir)->exitCode, 'git add should succeed');
        $commit = $this->runCommand("git commit -qm 'init test fixture'", $projectDir);
        $this->assertSame(0, $commit->exitCode, 'git commit should succeed: ' . $commit->combinedOutput());
    }

    private function extractTarEntry(string $archive, string $entry, string $cwd): string
    {
        $result = $this->runCommand(
            'tar -xOf ' . escapeshellarg($archive) . ' ' . escapeshellarg($entry),
            $cwd
        );
        $this->assertSame(0, $result->exitCode, "failed to extract {$entry} from archive: " . $result->combinedOutput());
        return $result->stdout;
    }

    private function runCommand(string $command, string $cwd, string $input = ''): CommandResult
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(['/bin/sh', '-lc', $command], $descriptorSpec, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new TestFailure("failed to start command: {$command}");
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return new CommandResult($command, $exitCode, $stdout === false ? '' : $stdout, $stderr === false ? '' : $stderr);
    }

    private function createTempDir(string $prefix): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(4));
        if (!mkdir($base, 0777, true) && !is_dir($base)) {
            throw new TestFailure("failed to create temp directory: {$base}");
        }
        $this->tmpDirs[] = $base;
        return $base;
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new TestFailure("failed to create directory: {$dir}");
        }
        if (file_put_contents($path, $contents) === false) {
            throw new TestFailure("failed to write file: {$path}");
        }
    }

    private function requireCommand(string $command): void
    {
        $result = $this->runCommand('command -v ' . escapeshellarg($command), $this->repoRoot);
        $this->assertSame(0, $result->exitCode, "required command not found: {$command}");
    }

    private function cleanup(bool $success): void
    {
        if (!$success || getenv('PACKME_KEEP_TEST_TMP') === '1') {
            if (!empty($this->tmpDirs)) {
                fwrite(STDERR, 'Temporary test directories:' . PHP_EOL);
                foreach ($this->tmpDirs as $dir) {
                    fwrite(STDERR, "  {$dir}" . PHP_EOL);
                }
            }
            return;
        }

        foreach (array_reverse($this->tmpDirs) as $dir) {
            $this->runCommand('rm -rf ' . escapeshellarg($dir), $this->repoRoot);
        }
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new TestFailure($message);
        }
    }

    private function assertSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new TestFailure($message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true));
        }
    }

    private function assertContains(string $needle, string $haystack, string $message): void
    {
        if (strpos($haystack, $needle) === false) {
            throw new TestFailure($message . PHP_EOL . "Missing fragment: {$needle}" . PHP_EOL . "Actual output:\n{$haystack}");
        }
    }

    private function assertNotContains(string $needle, string $haystack, string $message): void
    {
        if (strpos($haystack, $needle) !== false) {
            throw new TestFailure($message . PHP_EOL . "Unexpected fragment: {$needle}" . PHP_EOL . "Actual output:\n{$haystack}");
        }
    }
}

$suite = new IntegrationSuite(dirname(__DIR__));
exit($suite->run());
