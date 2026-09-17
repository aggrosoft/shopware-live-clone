<?php
declare(strict_types=1);
try {
    $root = realpath($argv[1] ?? '');
    if ($root === false || !is_dir($root)) { throw new RuntimeException(); }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        if (!$file->isLink()) { continue; }
        $target = realpath($file->getPathname());
        if ($target === false || strpos($target, $root . '/') !== 0) { throw new RuntimeException(); }
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Copy contains an unsupported symlink.\n");
    exit(1);
}
