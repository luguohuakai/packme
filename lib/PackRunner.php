<?php

/**
 * 打包执行器: 把各打包模式封装成可调用方法, 供交互菜单与 AI 代理复用。
 *
 * 与原来的 switch 相比, 这里只返回结构化结果, 不调用 exit(),
 * 便于调用方(交互菜单 / AI 代理)在打包后继续做校验与汇报。
 */

class PackmeRunner
{
    /** @var Git */
    private $git;
    private string $objectName;
    private string $versionFile;
    private string $time;

    public function __construct($git, string $objectName, string $versionFile, ?string $time = null)
    {
        $this->git = $git;
        $this->objectName = $objectName;
        $this->versionFile = $versionFile;
        $this->time = $time ?? date('ymdHis');
    }

    public function time(): string
    {
        return $this->time;
    }

    public function objectName(): string
    {
        return $this->objectName;
    }

    /**
     * 执行打包
     * @param int $mode 1-9
     * @param array $opts new / old / branch / path / vendor / progress
     * @return array{ok:bool,mode:int,file:string,files:array,error:string}
     */
    public function run(int $mode, array $opts = []): array
    {
        if ($mode === 1) return $this->runFull($opts);
        if ($mode === 9) return $this->runPath($opts);
        if ($mode >= 2 && $mode <= 8) return $this->runGitMode($mode, $opts);
        return $this->fail($mode, 'Unsupported packaging mode: ' . $mode);
    }

    private function runFull(array $opts): array
    {
        $vendor = array_key_exists('vendor', $opts) ? (bool)$opts['vendor'] : null;
        $version = genVersion($this->versionFile, $this->time, $this->git);
        if (!fullPack($this->git, $this->objectName, $version, $this->versionFile, $vendor)) {
            return $this->fail(1, 'Full packaging failed');
        }
        $file = './dist/' . $this->objectName . '.' . $version['version'] . '.tar.gz';
        return $this->ok(1, $file, [], ['version' => $version['version'], 'vendor' => (bool)$vendor]);
    }

    private function runPath(array $opts): array
    {
        $file = "./dist/{$this->objectName}_PATH_{$this->time}.tar.gz";
        $path = array_key_exists('path', $opts) ? (string)$opts['path'] : null;
        if (!packPath($file, $path)) {
            return $this->fail(9, 'Path packaging failed');
        }
        return $this->ok(9, $file, [], ['path' => (string)$path]);
    }

    private function runGitMode(int $mode, array $opts): array
    {
        $spec = $this->spec($mode, $opts);
        if ($spec === null) return $this->fail($mode, 'Unsupported packaging mode: ' . $mode);
        if (isset($spec['error'])) return $this->fail($mode, $spec['error']);

        $progressEnabled = !array_key_exists('progress', $opts) || $opts['progress'] !== false;
        $progress = $progressEnabled ? new ProgressBar(100, $spec['label']) : null;

        genVersion($this->versionFile, $this->time, $this->git);
        $this->git->packed_files = [];
        $git = $this->git->output($spec['file'])->newCommit($spec['new'])->oldCommit($spec['old']);
        if ($progress) $git->setProgressBar($progress);

        $rs = $git->run();
        if ($rs === null) return $this->fail($mode, 'command exec failed: ' . $this->git->cmd);
        if ($rs) Func::logInfo($rs);

        $file = $this->git->getOutPutFile();
        Func::logPrimary('Generated File: ' . $file);
        return $this->ok($mode, $file, $this->git->packed_files);
    }

    /**
     * 各模式的参数与文件名规则
     * @return array|null
     */
    private function spec(int $mode, array $opts): ?array
    {
        $o = $this->objectName;
        $t = $this->time;
        $h = strtoupper($this->git->hashShort());

        switch ($mode) {
            case 2:
                return ['label' => 'Packing last commit and current changes...', 'file' => "{$o}_LAST2NOW_{$t}_$h.tar.gz", 'new' => 'NOW', 'old' => 'HEAD~1'];
            case 4:
                return ['label' => 'Packing uncommitted changes...', 'file' => "{$o}_NOT_COMMIT_{$t}_$h.tar.gz", 'new' => 'NOW', 'old' => ''];
            case 5:
                return ['label' => 'Packing last commit...', 'file' => "{$o}_LAST1COMMIT_{$t}_$h.tar.gz", 'new' => 'HEAD', 'old' => 'HEAD~1'];
            case 6:
                return ['label' => 'Packing last two commits...', 'file' => "{$o}_LAST2COMMIT_{$t}_$h.tar.gz", 'new' => 'HEAD', 'old' => 'HEAD~2'];
            case 3:
                $new = resolveCommitId((string)($opts['new'] ?? ''), 'HEAD');
                if ($new === '') return ['error' => 'Invalid new commit id'];
                $old = resolveCommitId((string)($opts['old'] ?? ''), 'HEAD~1');
                if ($old === '') return ['error' => 'Invalid old commit id'];
                return [
                    'label' => 'Packing changes between commits...',
                    'file' => "{$o}_2COMMIT_{$t}_" . strtoupper($new) . '_' . strtoupper($old) . '.tar.gz',
                    'new' => $new,
                    'old' => $old,
                ];
            case 7:
                $new = resolveCommitId((string)($opts['new'] ?? ''));
                if ($new === '') return ['error' => 'Invalid commit id'];
                return [
                    'label' => 'Packing specific commit...',
                    'file' => "{$o}_ONE_COMMIT_{$t}_" . strtoupper($new) . '.tar.gz',
                    'new' => $new,
                    'old' => $new . '~',
                ];
            case 8:
                $branch = (string)($opts['branch'] ?? '');
                $rs = $this->git->branchCommitId($branch);
                if (empty($rs)) return ['error' => 'Not found any commit'];
                $new = $rs[0];
                $old = $rs[1];
                return [
                    'label' => 'Packing branch changes...',
                    'file' => "{$o}_BRANCH_{$t}_" . strtoupper($new) . '_' . strtoupper($old) . '.tar.gz',
                    'new' => $new,
                    'old' => $old,
                ];
        }
        return null;
    }

    private function ok(int $mode, string $file, array $files, array $extra = []): array
    {
        return array_merge([
            'ok' => true,
            'mode' => $mode,
            'file' => $file,
            'files' => array_values($files),
            'error' => '',
        ], $extra);
    }

    private function fail(int $mode, string $error): array
    {
        return ['ok' => false, 'mode' => $mode, 'file' => '', 'files' => [], 'error' => $error];
    }

    /**
     * 确定性校验压缩包(AI 打包后用于核对, 不依赖模型判断)
     * @param string $archive 压缩包路径
     * @param array $expected 期望包含的变更文件(相对路径)
     * @param string $baseDir 变更文件所在目录(用于内容比对)
     * @param bool $compareContent 是否比对文件内容(仅对"工作区内容"打包有意义)
     * @return array{ok:bool,members:array,errors:array,warnings:array}
     */
    public static function verifyArchive(string $archive, array $expected = [], string $baseDir = '.', bool $compareContent = false): array
    {
        $errors = [];
        $warnings = [];
        $members = [];

        if (!is_file($archive)) {
            return ['ok' => false, 'members' => [], 'errors' => ['archive not found: ' . $archive], 'warnings' => []];
        }
        if (filesize($archive) <= 0) {
            $errors[] = 'archive is empty';
        }

        try {
            $phar = new PharData($archive);
            foreach (new RecursiveIteratorIterator($phar) as $item) {
                $path = str_replace('\\', '/', $item->getPathname());
                $rel = preg_replace('#^phar://.*?\.(?:tar\.gz|tar|zip)/#', '', $path);
                if ($rel === '' || $rel === $path) continue;
                $members[] = $rel;
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'members' => [], 'errors' => ['cannot read archive: ' . $e->getMessage()], 'warnings' => []];
        }

        if (empty($members)) {
            $errors[] = 'archive has no members';
        }

        // 必须存在的部署文件
        foreach (['replaceme', 'replaceme5', 'replaceme.ini'] as $required) {
            $found = false;
            foreach ($members as $member) {
                if (basename($member) === $required) {
                    $found = true;
                    break;
                }
            }
            if (!$found) $errors[] = 'missing required file: ' . $required;
        }

        // 期望的变更文件
        $memberMap = array_flip($members);
        $missing = [];
        foreach ($expected as $file) {
            $file = ltrim(str_replace('\\', '/', (string)$file), '/');
            if ($file === '') continue;
            if (!isset($memberMap[$file])) $missing[] = $file;
        }
        if ($missing) $errors[] = 'missing expected file(s): ' . implode(', ', array_slice($missing, 0, 10));

        // 可疑成员名(绝对路径 / 向上穿越)
        foreach ($members as $member) {
            if (strpos($member, '/') === 0) $errors[] = 'absolute member path: ' . $member;
            if (preg_match('#(^|/)\.\.(/|$)#', $member)) $errors[] = 'unsafe member path: ' . $member;
        }

        // 可选: 抽查期望文件内容是否与工作区一致
        if ($compareContent && $expected) {
            $real = realpath($archive);
            foreach (array_slice($expected, 0, 20) as $file) {
                $file = ltrim(str_replace('\\', '/', (string)$file), '/');
                $local = rtrim($baseDir, '/') . '/' . $file;
                if ($file === '' || !is_file($local) || !isset($memberMap[$file]) || $real === false) continue;
                $inArchive = @file_get_contents('phar://' . $real . '/' . $file);
                if ($inArchive === false) {
                    $warnings[] = 'cannot read archived content: ' . $file;
                    continue;
                }
                if ($inArchive !== file_get_contents($local)) {
                    $warnings[] = 'content differs from working tree: ' . $file;
                }
            }
        }

        return [
            'ok' => empty($errors),
            'members' => $members,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
