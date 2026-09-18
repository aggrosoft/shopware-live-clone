<?php
declare(strict_types=1);

// Only counters and fixed phase labels go to logs, never filenames or SQL.
$mode = $argv[1] ?? '';
$label = $argv[2] ?? 'Working';
$started = microtime(true);
$last = $started;
$bytes = 0;
$percent = null;
$duration = static fn(float $seconds): string => sprintf('%dm %02ds', (int) ($seconds / 60), (int) $seconds % 60);
$report = static function (bool $finished = false) use (&$bytes, &$percent, $started, $label, $duration): void {
    $elapsed = microtime(true) - $started;
    $rate = $bytes / max(0.001, $elapsed);
    $fraction = $percent !== null ? $percent / 100 : null;
    $status = sprintf('%s: %.1f MiB | %.1f MiB/s | elapsed %s', $label, $bytes / 1048576, $rate / 1048576, $duration($elapsed));
    if ($fraction !== null) {
        $status .= sprintf(' | %.0f%%', $fraction * 100);
    }
    if (!$finished && $fraction !== null && $fraction > 0 && $fraction < 1 && $elapsed >= 5) {
        $status .= ' | estimated phase remaining ' . $duration($elapsed * (1 - $fraction) / $fraction);
    } elseif (!$finished) {
        $status .= ' | remaining unknown';
    }
    fwrite(STDERR, $status . ($finished ? ' | stream complete' : '') . PHP_EOL);
};

if ($mode === 'heartbeat') {
    while (true) {
        sleep(15);
        fwrite(STDERR, $label . ': still running | elapsed ' . $duration(microtime(true) - $started) . ' | remaining unknown' . PHP_EOL);
    }
}
if ($mode !== 'rsync') {
    exit(64);
}
stream_set_blocking(STDIN, false);
$buffer = '';
while (!feof(STDIN)) {
    $read = [STDIN];
    $write = $except = null;
    if (stream_select($read, $write, $except, 1) === false) {
        exit(1);
    }
    if ($read !== []) {
        $chunk = fread(STDIN, 65536);
        if ($chunk === false) {
            exit(1);
        }
        $buffer .= $chunk;
        $records = preg_split('/[\r\n]/', $buffer);
        $buffer = array_pop($records);
        foreach ($records as $record) {
            if (preg_match('/^\s*([0-9,]+)\s+(\d+)%/', $record, $matches)) {
                $bytes = (int) str_replace(',', '', $matches[1]);
                $percent = (int) $matches[2];
            }
        }
    }
    if (microtime(true) - $last >= 15) {
        $report();
        $last = microtime(true);
    }
}
$report(true);
