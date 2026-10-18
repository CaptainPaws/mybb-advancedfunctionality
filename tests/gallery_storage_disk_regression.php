<?php
/** No database, mount changes, root or production storage required. */
define('IN_MYBB', 1);
require dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/advancedgallery/storage_disk.php';
function disk_check($ok, $message) {if (!$ok) throw new RuntimeException($message);}
function htmlspecialchars_uni($text) {return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');}
function af_ag_resolve_upload_path() {return $GLOBALS['disk_test_root'];}
function af_ag_get_setting($key,$default=null) {return $GLOBALS['disk_test_settings'][$key] ?? $default;}

$mountinfo="20 1 8:2 / / rw - ext4 /dev/sda2 rw\n21 20 8:1 / /srv/forum/uploads rw - ext4 /dev/sda1 rw\n22 20 0:50 / /srv/gallery\\040data rw - nfs4 server:/gallery rw\n";
$mount=ag_storage_mount_for_path('/srv/forum/uploads/af_gallery', $mountinfo);
disk_check($mount['device']==='/dev/sda1' && $mount['filesystem']==='ext4','Storage mount was replaced by root filesystem');
disk_check(ag_storage_mount_for_path('/srv/forum/uploads-other', $mountinfo)['mount']==='/','Mount prefix boundary ignored');
disk_check(ag_storage_mount_for_path('/srv/gallery data/af_gallery', $mountinfo)['filesystem']==='nfs4','Escaped network mount path');
disk_check(ag_storage_mount_for_path('/srv/forum/uploads/af_gallery', explode("\n",$mountinfo)[0])['mount']==='/','Unmount fixture should resolve only root, not missing upload mount');

// 100 GiB filesystem: actually used 29 MiB, free-but-reserved 5 GiB.
$totalBlocks=26214400;$usedBlocks=7424;$reservedBlocks=1310720;
$freeBlocks=$totalBlocks-$usedBlocks;$availBlocks=$freeBlocks-$reservedBlocks;
$stats=ag_storage_block_usage("4096 {$totalBlocks} {$freeBlocks} {$availBlocks}\n");
disk_check($stats['used']===29.0*1048576,'ext4 reserve included in used blocks');
disk_check($stats['reserved']===5.0*1073741824,'Free ext4 reserve incorrect');
disk_check($stats['available']===$availBlocks*4096.0,'Avail does not use f_bavail');
disk_check(abs($stats['percent']-(29/102400*100))<1e-10,'Progress includes reserve');
disk_check($stats['total']-$stats['available']!==$stats['used'],'Regression fixture does not distinguish used from unavailable');
$html=ag_storage_disk_widget(['path'=>'/srv/forum/uploads/af_gallery']+$mount+$stats);
disk_check(str_contains($html,'29,00 МиБ') && str_contains($html,'5 120,00 МиБ'),'Readable ext4 usage/reserve output');
disk_check(str_contains($html,'Фактически занято') && str_contains($html,'Avail'),'Separate used and available labels missing');
foreach(['bad','0 1 0 0','4096 10 11 0','4096 10 9 10','4096 0 0 0','4096 9999999999999999999999 1 1'] as $raw) disk_check(ag_storage_block_usage($raw)===null,'Invalid counters accepted: '.$raw);
$partial=ag_storage_disk_widget(['path'=>'/srv/forum/uploads/af_gallery']+$mount+['total'=>100.0,'available'=>20.0,'used'=>null,'reserved'=>null,'percent'=>null]);
disk_check(!str_contains($partial,'<progress') && !str_contains($partial,'Фактически занято'),'Partial metrics invent usage or progress');

$disk_test_root=sys_get_temp_dir().'/ag-disk-'.getmypid();$disk_test_settings=[];
mkdir($disk_test_root.'/af_gallery',0755,true);
try {
    $result=ag_storage_disk();
    disk_check(isset($result['warning']) && !isset($result['total']),'Unmounted upload directory fell back to root disk');
    $actualMount=ag_storage_mount_for_path(realpath($disk_test_root.'/af_gallery'), file_get_contents('/proc/self/mountinfo'));
    $disk_test_settings['storage_mount_path']=$actualMount['mount'];
    $result=ag_storage_disk();
    disk_check($result['path']===realpath($disk_test_root.'/af_gallery') && $result['mount']===$actualMount['mount'],'Explicit actual mount configuration ignored');
    if (($argv[1]??'')==='--no-stat') {
        disk_check(!function_exists('proc_open'),'Run --no-stat with -d disable_functions=proc_open');
        disk_check($result['used']===null && $result['reserved']===null && $result['percent']===null,'Unavailable stat fabricated occupancy');
        disk_check($result['available']===disk_free_space($disk_test_root),'Fallback changed available space');
        disk_check(!str_contains(ag_storage_disk_widget($result),'<progress'),'Fallback displayed invented percentage');
    } else {
        disk_check(isset($result['used']),'statfs counters unavailable in test environment');
        $p=proc_open(['/usr/bin/df','-B1','--output=size,used,avail','--',$disk_test_root], [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
        $raw=stream_get_contents($pipes[1]);fclose($pipes[1]);disk_check(proc_close($p)===0,'df reference failed');
        preg_match('/\n\s*(\d+)\s+(\d+)\s+(\d+)/',$raw,$reference);
        disk_check((float)$reference[1]===$result['total'],'Filesystem capacity differs from df');
        // Other processes can write between samples: allow 16 MiB on the live test volume.
        disk_check(abs((float)$reference[2]-$result['used'])<16*1048576 && abs((float)$reference[3]-$result['available'])<16*1048576,'statfs counters differ from df Used/Avail');
    }
    $disk_test_settings['storage_mount_device']='/dev/not-the-gallery-volume';
    disk_check(!isset(ag_storage_disk()['total']),'Unexpected source device accepted');
    rmdir($disk_test_root.'/af_gallery');
    disk_check(!isset(ag_storage_disk()['total']),'Absent Gallery path guessed parent storage');
} finally {
    @rmdir($disk_test_root.'/af_gallery');@rmdir($disk_test_root);
}
echo "Disk stats passed: ext4 29 MiB used / 5 GiB reserve, actual mount namespace, df Used/Avail, missing mount/source, no-stat fallback, no invented progress.\n";
