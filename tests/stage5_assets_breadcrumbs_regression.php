<?php
if (isset($argv[1])) {
    define('THIS_SCRIPT', $argv[1]);
    require __DIR__ . '/fixtures/stage5_form.php';
    $GLOBALS['af_atf_has_frontend_component'] = true;
    $page='<html><head></head><body></body></html>';
    af_advancedthreadfields_pre_output($page);
    echo $page;
    exit;
}
foreach (['showthread.php','newthread.php','editpost.php'] as $script) {
    $process=proc_open([PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=mbstring',__FILE__,$script],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $html=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    if ($code || $error) throw new RuntimeException($script . ': ' . $error . $html);
    if (!str_contains($html,'advancedthreadfields.css')) throw new RuntimeException('ATF CSS missing on '.$script);
    $hasForm=str_contains($html,'advancedthreadfields-form.js');
    if ($hasForm !== ($script!=='showthread.php') || str_contains($html,'advancedthreadfields.js') || str_contains($html,'advancedthreadfields-view.js')) throw new RuntimeException('Incorrect ATF asset contract: '.$script);
}
require __DIR__ . '/fixtures/stage5_form.php';
require AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php';
$header='<header>Header</header><navigation>';
af_adaptivethemeframework_compose_forumdisplay();
if (str_contains($header,'<navigation>')) throw new RuntimeException('Header still owns forum breadcrumbs');
$seed=file_get_contents(AF_ADDONS.'adaptivethemeframework/templates/forumdisplay.html');
$main=strpos($seed,'<main ');$nav=strpos($seed,'<navigation>');$end=strpos($seed,'</main>');
if ($main===false || $nav===false || $nav<$main || $nav>$end || substr_count($seed,'<navigation>')!==1) throw new RuntimeException('Breadcrumbs are outside canonical main');
$showthread=file_get_contents(AF_ADDONS.'adaptivethemeframework/templates/showthread.html');
if (!str_contains($showthread,'{$atf_thread_breadcrumbs}')) throw new RuntimeException('Showthread breadcrumb ownership changed');
echo "Stage 5 actual asset responses and breadcrumb ownership passed.\n";
