#!/usr/bin/env php
<?php

/**
 * 构建独立运行的 packme.phar(不依赖 composer)
 *
 * 用法:
 *   php -d phar.readonly=0 build-phar.php [输出路径]
 *
 * 默认输出到仓库根目录的 packme.phar; 传入路径时输出到指定位置。
 */

declare(strict_types=1);

$root = __DIR__;
$output = $argv[1] ?? ($root . '/packme.phar');
if ($output === '') {
    fwrite(STDERR, '输出路径不能为空' . PHP_EOL);
    exit(1);
}
if ($output[0] !== '/' && !preg_match('#^[A-Za-z]:#', $output)) {
    $output = getcwd() . DIRECTORY_SEPARATOR . $output;
}

if (filter_var(ini_get('phar.readonly'), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, 'phar.readonly=1, 请使用: php -d phar.readonly=0 build-phar.php' . PHP_EOL);
    exit(1);
}

if (!is_file($root . '/packme')) {
    fwrite(STDERR, 'packme 入口文件不存在: ' . $root . '/packme' . PHP_EOL);
    exit(1);
}

// 打进 phar 的附加文件; 缺少时跳过
$bundled = ['replaceme', 'replaceme5', 'README.md', 'CHANGELOG.md', 'LICENSE', 'composer.json'];

if (is_file($output)) {
    @unlink($output);
}

try {
    $phar = new Phar($output, 0, 'packme.phar');
    $phar->startBuffering();

    // 入口文件去掉 shebang, 否则被 require 时会原样输出
    $entry = (string)file_get_contents($root . '/packme');
    $entry = (string)preg_replace('/^#![^\n]*\n/', '', $entry, 1);
    $phar->addFromString('packme', $entry);

    foreach ($bundled as $file) {
        if (is_file($root . '/' . $file)) {
            $phar->addFile($root . '/' . $file, $file);
        }
    }

    // 打包执行器与 AI 模块
    foreach (glob($root . '/lib/*.php') as $libFile) {
        $phar->addFile($libFile, 'lib/' . basename($libFile));
    }

    $phar->setStub(
        "#!/usr/bin/env php\n" .
        "<?php\n" .
        "Phar::mapPhar('packme.phar');\n" .
        "require 'phar://packme.phar/packme';\n" .
        "__HALT_COMPILER();\n"
    );

    $phar->stopBuffering();
} catch (Throwable $e) {
    fwrite(STDERR, 'build phar failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

@chmod($output, 0755);
clearstatcache();
echo 'Built: ' . $output . ' (' . filesize($output) . ' bytes)' . PHP_EOL;
