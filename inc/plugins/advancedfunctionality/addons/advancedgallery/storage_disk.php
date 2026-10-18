<?php
/** Read-only filesystem statistics. No volume scans, shell, FFI or elevated privileges. */
if (!defined('IN_MYBB')) die('No direct access');

function ag_storage_mount_unescape(string $value): string
{
    return preg_replace_callback('/\\\\([0-7]{3})/', static fn($m) => chr(octdec($m[1])), $value);
}

/** Match the deepest mount in this PHP process's own mount namespace. */
function ag_storage_mount_for_path(string $path, string $mountinfo): ?array
{
    $found = null;
    foreach (explode("\n", $mountinfo) as $line) {
        $parts = explode(' - ', $line, 2);
        if (count($parts) !== 2) continue;
        $left = explode(' ', $parts[0]);
        $right = explode(' ', $parts[1]);
        if (count($left) < 6 || count($right) < 3) continue;
        $mount = ag_storage_mount_unescape($left[4]);
        if ($path !== $mount && !str_starts_with($path, rtrim($mount, '/').'/')) continue;
        if ($found && strlen($found['mount']) > strlen($mount)) continue;
        $found = ['id'=>$left[0], 'device_id'=>$left[2], 'mount'=>$mount,
            'filesystem'=>$right[0], 'device'=>ag_storage_mount_unescape($right[1])];
    }
    return $found;
}

/** stat -f: fundamental block size, total blocks, ALL free blocks, available blocks. */
function ag_storage_block_usage(string $raw): ?array
{
    if (!preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*$/D', $raw, $m)) return null;
    [$size, $blocks, $free, $available] = array_map('floatval', array_slice($m, 1));
    if ($size <= 0 || $blocks <= 0 || $free > $blocks || $available > $free
        || !is_finite($size*$blocks) || $size*$blocks > PHP_INT_MAX) return null;
    $total = $size*$blocks;
    $used = $size*($blocks-$free);
    return ['total'=>$total, 'used'=>$used, 'free'=>$size*$free, 'available'=>$size*$available,
        'reserved'=>$size*($free-$available), 'percent'=>$used/$total*100];
}

/** GNU stat uses statfs(2). Fixed executable + argv; the Gallery path is never shell code. */
function ag_storage_read_blocks(string $path): ?array
{
    foreach (['proc_open','proc_get_status','proc_terminate','proc_close'] as $function) {
        if (!function_exists($function)) return null;
    }
    $binary = null;
    foreach (['/usr/bin/stat','/bin/stat'] as $candidate) {
        if (is_executable($candidate)) { $binary = $candidate; break; }
    }
    if (!$binary) return null;
    $process = @proc_open([$binary, '--file-system', '--format=%S %b %f %a', '--', $path],
        [0=>['file','/dev/null','r'], 1=>['pipe','w'], 2=>['file','/dev/null','w']], $pipes,
        null, null, ['bypass_shell'=>true]);
    if (!is_resource($process)) return null;
    stream_set_blocking($pipes[1], false);
    $raw = '';
    $deadline = microtime(true)+2;
    do {
        $raw .= stream_get_contents($pipes[1], 512);
        $status = proc_get_status($process);
        if (!$status['running']) break;
        usleep(10000);
    } while (microtime(true) < $deadline && strlen($raw) <= 256);
    if ($status['running']) {
        proc_terminate($process, 9);
        fclose($pipes[1]);
        proc_close($process);
        return null;
    }
    $raw .= stream_get_contents($pipes[1], 512);
    fclose($pipes[1]);
    $exit = proc_close($process);
    if ($status['exitcode'] !== 0 && $exit !== 0) return null;
    return ag_storage_block_usage($raw);
}

function ag_storage_disk(): array
{
    $root = realpath(af_ag_resolve_upload_path());
    $path = realpath(af_ag_resolve_upload_path().'/af_gallery');
    if (!$root || !$path || !is_dir($path)) {
        return ['warning'=>'Каталог хранилища Gallery недоступен. Проверьте подключение тома. Статистика основного диска не подставляется.'];
    }
    $configured = trim((string)af_ag_get_setting('storage_mount_path', ''));
    $expected = $configured === '' ? $root : realpath($configured);
    $mountinfo = @file_get_contents('/proc/self/mountinfo');
    $mount = $mountinfo !== false ? ag_storage_mount_for_path($path, $mountinfo) : null;
    if (!$expected || !$mount || $mount['mount'] !== $expected) {
        return ['path'=>$path, 'warning'=>'Ожидаемый том хранилища не смонтирован или его подключение невозможно подтвердить. Статистика основного диска не подставляется. Проверьте точку монтирования в настройках Gallery.'];
    }
    $device = trim((string)af_ag_get_setting('storage_mount_device', ''));
    if ($device !== '' && (realpath($device) ?: $device) !== (realpath($mount['device']) ?: $mount['device'])) {
        return ['path'=>$path, 'warning'=>'В точке хранения подключено другое устройство. Статистика тома скрыта; проверьте подключение хранилища.'];
    }
    $stats = ag_storage_read_blocks($path);
    if (!$stats) {
        // These two values are reliable individually. They cannot establish USED blocks.
        $total = function_exists('disk_total_space') ? @disk_total_space($path) : false;
        $available = function_exists('disk_free_space') ? @disk_free_space($path) : false;
        $stats = ['total'=>$total !== false && $total > 0 ? $total : null,
            'available'=>$available !== false && $available >= 0 ? $available : null,
            'used'=>null, 'free'=>null, 'reserved'=>null, 'percent'=>null,
            'warning'=>'Точные счётчики блоков недоступны. Занятость, резерв и процент заполнения не вычислялись.'];
    }
    // Detect an unmount/replacement during collection instead of displaying a parent volume.
    clearstatcache(true);
    $after = @file_get_contents('/proc/self/mountinfo');
    if ($after === false || ag_storage_mount_for_path($path, $after) !== $mount
        || realpath(af_ag_resolve_upload_path().'/af_gallery') !== $path) {
        return ['path'=>$path, 'warning'=>'Подключение тома изменилось во время получения статистики. Обновите страницу после проверки хранилища.'];
    }
    return ['path'=>$path]+$mount+$stats;
}

function ag_storage_disk_widget(array $disk): string
{
    $html = '';
    if (isset($disk['warning'])) $html .= '<p role="status">'.htmlspecialchars_uni($disk['warning']).'</p>';
    if (!isset($disk['mount'])) return $html;
    $format = static fn(float $bytes): string => number_format($bytes/1048576, 2, ',', ' ').' МиБ';
    $html .= '<h4>Файловая система тома</h4><p>Каталог Gallery: '.htmlspecialchars_uni($disk['path']).'</p>'
        .'<p>Точка монтирования: '.htmlspecialchars_uni($disk['mount']).' · Устройство: '.htmlspecialchars_uni($disk['device'])
        .' · Файловая система: '.htmlspecialchars_uni($disk['filesystem']).'</p><dl>';
    foreach (['total'=>'Полная ёмкость файловой системы', 'used'=>'Фактически занято (без свободного резерва)',
        'available'=>'Доступно процессу PHP (Avail)', 'reserved'=>'Свободно, но недоступно обычному процессу (резерв)'] as $key=>$label) {
        if (isset($disk[$key])) $html .= '<dt>'.$label.'</dt><dd>'.$format($disk[$key]).'</dd>';
    }
    $html .= '</dl>';
    if (isset($disk['percent'])) $html .= '<progress max="100" value="'.$disk['percent'].'" aria-label="Фактически занятые блоки тома"></progress>';
    return $html.'<p>1 МиБ = 1 048 576 байт. Резерв — свободные блоки, недоступные обычному процессу; на ext4 сюда входит свободный системный резерв. Занятость тома включает все данные на нём и показана отдельно от размера оригиналов Gallery.</p>';
}
