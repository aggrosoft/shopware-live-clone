<?php
declare(strict_types=1);
// Exit 23 is only tolerable when every reported error is a dangling source link.
// Permission errors, full disks, network errors and vanished files still fail.
if (($argv[1] ?? '') !== '23') { exit(1); }
$lines = @file($argv[2] ?? '', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) { exit(1); }
$missing = [];
foreach ($lines as $line) {
    if (preg_match('/^(?:\[(?:sender|receiver)\] )?symlink has no referent: (.+)$/', $line, $match)) {
        $missing[] = $match[1];
    } elseif (!preg_match('/^rsync error: some files\/attrs were not transferred .*\(code 23\) /', $line)) {
        exit(1);
    }
}
if ($missing === []) { exit(1); }
foreach ($missing as $path) {
    fwrite(STDERR, 'Warning: skipped broken source link ' . json_encode($path, JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
}
