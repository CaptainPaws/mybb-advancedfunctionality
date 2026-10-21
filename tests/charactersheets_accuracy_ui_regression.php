<?php
// Three real render-route/DTO/template scenarios; only MyBB/DB boundaries are stubbed.
$root = dirname(__DIR__);
$fixture = __DIR__ . '/fixtures/charactersheets_runtime.php';
foreach (['accuracy_value', 'accuracy_zero', 'accuracy_missing'] as $mode) {
    $process = proc_open([PHP_BINARY, '-c', php_ini_loaded_file() ?: '', '-d', 'display_errors=stderr', $fixture, $root, $mode], [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    $result = json_decode($output, true);
    if ($status !== 0 || !is_array($result) || ($result['mode'] ?? '') !== $mode) {
        throw new RuntimeException("{$mode}: {$errors}\n{$output}");
    }
    echo "CharacterSheets {$mode}: DTO → actual ARPG template passed\n";
}
