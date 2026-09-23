#!/usr/bin/env php
<?php

declare(strict_types=1);

error_reporting(E_ALL);

/**
 * 快速单元测试: 不需要 composer install / git 仓库, 只验证 CLI 与配置文件读写。
 * 完整打包流程见 tests/integration.php。
 */
final class UnitFailure extends RuntimeException
{
}

final class UnitCommandResult
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

final class UnitSuite
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
        $tests = [
            'packme --version / --help work without vendor' => function (): void {
                $this->testPackmeCli();
            },
            'replaceme / replaceme5 --help' => function (): void {
                $this->testReplacemeHelp();
            },
            'replaceme.ini keeps special characters and persists backup_suffix' => function (): void {
                $this->testIniRoundTrip();
            },
            'replaceme --dry-run changes nothing' => function (): void {
                $this->testDryRun();
            },
            'replaceme --rollback --dry-run is rejected' => function (): void {
                $this->testRollbackDryRunRejected();
            },
            'ai client parses non-stream tool calls' => function (): void {
                $this->testAiClientNonStream();
            },
            'ai client parses streamed content and tool calls' => function (): void {
                $this->testAiClientStream();
            },
            'ai client surfaces API errors' => function (): void {
                $this->testAiClientError();
            },
            'verifyArchive detects missing files' => function (): void {
                $this->testVerifyArchive();
            },
        ];

        foreach ($tests as $name => $test) {
            try {
                $test();
                $this->passed++;
                fwrite(STDOUT, "[PASS] {$name}" . PHP_EOL);
            } catch (Throwable $e) {
                fwrite(STDERR, "[FAIL] {$name}" . PHP_EOL);
                fwrite(STDERR, $e->getMessage() . PHP_EOL);
                $this->cleanup(false);
                return 1;
            }
        }

        fwrite(STDOUT, PHP_EOL . "Passed {$this->passed} unit tests." . PHP_EOL);
        $this->cleanup(true);
        return 0;
    }

    private function testPackmeCli(): void
    {
        $version = $this->runCommand('php ' . escapeshellarg($this->repoRoot . '/packme') . ' --version', $this->repoRoot);
        $this->assertSame(0, $version->exitCode, 'packme --version should succeed: ' . $version->combinedOutput());
        $this->assertContains('packme ', $version->stdout, 'version output should start with the tool name');

        $help = $this->runCommand('php ' . escapeshellarg($this->repoRoot . '/packme') . ' --help', $this->repoRoot);
        $this->assertSame(0, $help->exitCode, 'packme --help should succeed: ' . $help->combinedOutput());
        $this->assertContains('打包模式', $help->stdout, 'help should describe packaging modes');
        $this->assertContains('--version', $help->stdout, 'help should mention --version');
    }

    private function testReplacemeHelp(): void
    {
        $help = $this->runCommand('php ' . escapeshellarg($this->repoRoot . '/replaceme') . ' --help', $this->repoRoot);
        $this->assertSame(0, $help->exitCode, 'replaceme --help should succeed: ' . $help->combinedOutput());
        $this->assertContains('--dry-run', $help->stdout, 'replaceme help should mention --dry-run');
        $this->assertContains('--rollback', $help->stdout, 'replaceme help should mention --rollback');

        $help5 = $this->runCommand('php ' . escapeshellarg($this->repoRoot . '/replaceme5') . ' --help', $this->repoRoot);
        $this->assertSame(0, $help5->exitCode, 'replaceme5 --help should succeed: ' . $help5->combinedOutput());
        $this->assertContains('replaceme5', $help5->stdout, 'replaceme5 help should identify itself');
    }

    private function testIniRoundTrip(): void
    {
        $base = $this->createTempDir('unit-ini-');
        // 目标目录故意包含 ini 特殊字符 ';'，验证写入时会被正确引用
        $target = $base . '/tar;get';
        if (!mkdir($target, 0777, true) && !is_dir($target)) {
            throw new UnitFailure("failed to create directory: {$target}");
        }

        $packageDir = $base . '/package';
        $this->writeFile($packageDir . '/replaceme', file_get_contents($this->repoRoot . '/replaceme'));
        chmod($packageDir . '/replaceme', 0755);
        $this->writeFile($packageDir . '/replaceme.ini', 'object_root="' . $target . '/"' . PHP_EOL);
        $this->writeFile($packageDir . '/module/demo.php', "<?php echo 'new';\n");

        $run = $this->runCommand('php ./replaceme', $packageDir, "\n");
        $this->assertSame(0, $run->exitCode, 'replaceme should succeed: ' . $run->combinedOutput());

        $raw = file_get_contents($packageDir . '/replaceme.ini');
        $ini = parse_ini_file($packageDir . '/replaceme.ini');
        $this->assertTrue(is_array($ini), 'replaceme.ini should stay parseable');
        $this->assertSame($target . '/', $ini['object_root'] ?? '', 'object_root with special chars should survive round-trip');
        $this->assertTrue(!empty($ini['backup_suffix'] ?? ''), 'backup_suffix should be persisted');
        $this->assertTrue(!empty($ini['install_time'] ?? ''), 'install_time should be persisted');
        $this->assertTrue(strpos($raw, 'object_root="') === 0, 'value containing special chars should be quoted');
        $this->assertTrue(is_file($target . '/module/demo.php'), 'file should be deployed into the special-char target');
    }

    private function testDryRun(): void
    {
        $base = $this->createTempDir('unit-dryrun-');
        $target = $base . '/target';
        if (!mkdir($target, 0777, true) && !is_dir($target)) {
            throw new UnitFailure("failed to create directory: {$target}");
        }

        $packageDir = $base . '/package';
        $this->writeFile($packageDir . '/replaceme', file_get_contents($this->repoRoot . '/replaceme'));
        chmod($packageDir . '/replaceme', 0755);
        $this->writeFile($packageDir . '/replaceme.ini', 'object_root=' . $target . '/' . PHP_EOL);
        $this->writeFile($packageDir . '/module/demo.php', "<?php echo 'new';\n");
        $before = file_get_contents($packageDir . '/replaceme.ini');

        $run = $this->runCommand('php ./replaceme --dry-run', $packageDir, $target . "\n");
        $this->assertSame(0, $run->exitCode, 'replaceme --dry-run should succeed: ' . $run->combinedOutput());
        $this->assertContains('[DRY-RUN]', $run->combinedOutput(), 'dry-run should be announced');
        $this->assertContains('module/demo.php', $run->combinedOutput(), 'dry-run should list the file to create');
        $this->assertTrue(!is_file($target . '/module/demo.php'), 'dry-run must not create files');
        $this->assertSame($before, file_get_contents($packageDir . '/replaceme.ini'), 'dry-run must not modify replaceme.ini');
    }

    private function testRollbackDryRunRejected(): void
    {
        $base = $this->createTempDir('unit-rollback-dryrun-');
        $target = $base . '/target';
        if (!mkdir($target, 0777, true) && !is_dir($target)) {
            throw new UnitFailure("failed to create directory: {$target}");
        }

        $packageDir = $base . '/package';
        $this->writeFile($packageDir . '/replaceme', file_get_contents($this->repoRoot . '/replaceme'));
        chmod($packageDir . '/replaceme', 0755);
        $this->writeFile($packageDir . '/replaceme.ini', 'object_root=' . $target . '/' . PHP_EOL);

        $run = $this->runCommand('php ./replaceme --rollback --dry-run', $packageDir, $target . "\n");
        $this->assertTrue($run->exitCode !== 0, 'rollback dry-run should fail fast');
        $this->assertContains('回滚操作暂不支持 --dry-run', $run->combinedOutput(), 'rollback dry-run should be reported as unsupported');
    }

    private function loadLib(): void
    {
        require_once $this->repoRoot . '/lib/HttpClient.php';
        require_once $this->repoRoot . '/lib/AiClient.php';
        require_once $this->repoRoot . '/lib/PackRunner.php';
    }

    private function testAiClientNonStream(): void
    {
        $this->loadLib();
        $transport = function ($url, $payload, $headers, $onChunk) {
            return ['status' => 200, 'error' => '', 'body' => (string)json_encode([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'get_repo_context', 'arguments' => '{}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4, 'total_tokens' => 7],
            ])];
        };

        $client = new PackmeAiClient(['api_key' => 'k', 'stream' => false], $transport);
        $response = $client->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('get_repo_context', $response['tool_calls'][0]['function']['name'], 'tool name should be parsed');
        $this->assertSame('call_1', $response['tool_calls'][0]['id'], 'tool id should be parsed');
        $this->assertSame(7, $client->usage()['total_tokens'], 'usage should be accumulated');
    }

    private function testAiClientStream(): void
    {
        $this->loadLib();
        $chunks = [
            ['choices' => [['delta' => ['content' => 'Hel']]]],
            ['choices' => [['delta' => ['content' => 'lo']]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'c9', 'function' => ['name' => 'git_diff', 'arguments' => '{"files":']]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '["a.php"]}']]]]]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3]],
        ];
        $sse = '';
        foreach ($chunks as $chunk) $sse .= 'data: ' . json_encode($chunk) . "\n\n";
        $sse .= "data: [DONE]\n\n";

        $transport = function ($url, $payload, $headers, $onChunk) use ($sse) {
            // 故意拆块, 验证跨块缓冲
            foreach (str_split($sse, 17) as $piece) $onChunk($piece);
            return ['status' => 200, 'error' => '', 'body' => ''];
        };

        $client = new PackmeAiClient(['api_key' => 'k', 'stream' => true], $transport);
        $deltas = '';
        $response = $client->chat([['role' => 'user', 'content' => 'hi']], [], function ($delta) use (&$deltas) {
            $deltas .= $delta;
        });

        $this->assertSame('Hello', $response['content'], 'streamed content should be concatenated');
        $this->assertSame('Hello', $deltas, 'onDelta should receive each content delta');
        $this->assertSame('git_diff', $response['tool_calls'][0]['function']['name'], 'streamed tool name should accumulate');
        $this->assertSame('{"files":["a.php"]}', $response['tool_calls'][0]['function']['arguments'], 'streamed tool arguments should accumulate across chunks');
        $this->assertSame(3, $client->usage()['total_tokens'], 'streamed usage should be captured');
    }

    private function testAiClientError(): void
    {
        $this->loadLib();
        $transport = function ($url, $payload, $headers, $onChunk) {
            return ['status' => 401, 'error' => '', 'body' => (string)json_encode(['error' => ['message' => 'bad key']])];
        };

        $client = new PackmeAiClient(['api_key' => 'k', 'stream' => false], $transport);
        try {
            $client->chat([['role' => 'user', 'content' => 'hi']]);
            $this->assertTrue(false, 'an API error should throw');
        } catch (PackmeAiException $e) {
            $this->assertContains('401', $e->getMessage(), 'error should include the HTTP status');
            $this->assertContains('bad key', $e->getMessage(), 'error should include the API message');
        }
    }

    private function testVerifyArchive(): void
    {
        $this->loadLib();
        $dir = $this->createTempDir('unit-verify-');

        $good = $dir . '/good.tar.gz';
        $this->buildTar($good, [
            'replaceme' => "#!/usr/bin/env php\n",
            'replaceme5' => "#!/usr/bin/env php\n",
            'replaceme.ini' => "object_root=/tmp/\n",
            'app/a.php' => "<?php echo 'x';\n",
        ]);
        $bad = $dir . '/bad.tar.gz';
        $this->buildTar($bad, ['app/a.php' => "<?php echo 'x';\n"]);

        $okResult = PackmeRunner::verifyArchive($good, ['app/a.php'], $dir, false);
        $this->assertTrue($okResult['ok'], 'complete archive should verify: ' . implode('; ', $okResult['errors']));
        $this->assertTrue(in_array('app/a.php', $okResult['members'], true), 'members should include the packaged file');

        $badResult = PackmeRunner::verifyArchive($bad, ['app/a.php'], $dir, false);
        $this->assertTrue(!$badResult['ok'], 'archive without replaceme files should fail verification');

        $missingResult = PackmeRunner::verifyArchive($good, ['not-there.php'], $dir, false);
        $this->assertTrue(!$missingResult['ok'], 'missing expected file should fail verification');
    }

    private function buildTar(string $path, array $files): void
    {
        $tar = (string)preg_replace('/\.gz$/', '', $path);
        @unlink($tar);
        @unlink($path);

        $archive = new PharData($tar);
        foreach ($files as $name => $content) {
            $archive->addFromString($name, $content);
        }
        unset($archive);

        $in = fopen($tar, 'rb');
        $out = gzopen($path, 'wb');
        while (!feof($in)) {
            gzwrite($out, (string)fread($in, 8192));
        }
        fclose($in);
        gzclose($out);
        @unlink($tar);
    }

    private function runCommand(string $command, string $cwd, string $input = ''): UnitCommandResult
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(['/bin/sh', '-lc', $command], $descriptorSpec, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new UnitFailure("failed to start command: {$command}");
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return new UnitCommandResult($command, $exitCode, $stdout === false ? '' : $stdout, $stderr === false ? '' : $stderr);
    }

    private function createTempDir(string $prefix): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(4));
        if (!mkdir($base, 0777, true) && !is_dir($base)) {
            throw new UnitFailure("failed to create temp directory: {$base}");
        }
        $this->tmpDirs[] = $base;
        return $base;
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new UnitFailure("failed to create directory: {$dir}");
        }
        if (file_put_contents($path, $contents) === false) {
            throw new UnitFailure("failed to write file: {$path}");
        }
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
            throw new UnitFailure($message);
        }
    }

    private function assertSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new UnitFailure($message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true));
        }
    }

    private function assertContains(string $needle, string $haystack, string $message): void
    {
        if (strpos($haystack, $needle) === false) {
            throw new UnitFailure($message . PHP_EOL . "Missing fragment: {$needle}" . PHP_EOL . "Actual output:\n{$haystack}");
        }
    }
}

$suite = new UnitSuite(dirname(__DIR__));
exit($suite->run());
