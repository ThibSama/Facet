<?php

declare(strict_types=1);

/**
 * Minimal dependency-free PHP syntax gate.
 * Lints every tracked PHP source file; exits non-zero on the first parse error.
 */

$roots = ['src', 'tests', 'public', 'config', 'tools', 'resources'];
$php = PHP_BINARY;
$failures = 0;
$checked = 0;

foreach ($roots as $root) {
    if (!is_dir($root)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $checked++;
        $output = [];
        $status = 0;
        exec(escapeshellarg($php) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $status);

        if ($status !== 0) {
            $failures++;
            fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
        }
    }
}

printf('PHP syntax lint: %d file(s) checked, %d failure(s)%s', $checked, $failures, PHP_EOL);

exit($failures === 0 ? 0 : 1);
