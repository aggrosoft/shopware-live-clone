<?php
declare(strict_types=1);
try {
    $root = realpath($argv[1] ?? '');
    if ($root === false || !is_dir($root)) { throw new RuntimeException(); }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        if (!$file->isLink()) { continue; }
        $target = realpath($file->getPathname());
        if ($target === false) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (!unlink($file->getPathname())) { throw new RuntimeException(); }
            fwrite(STDERR, 'Warning: removed leftover broken link ' . json_encode($relative, JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
        } elseif ($target !== $root && strpos($target, $root . '/') !== 0) {
            throw new RuntimeException('Unresolved external link: ' . json_encode(substr($file->getPathname(), strlen($root) + 1), JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Copy validation failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
