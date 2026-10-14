<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/images/default_avatar.png' || $path==='/uploads/mine.png') {header('Content-Type: image/png');echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');return;}
if ($path!=='/apstudio.php' && $path!=='/member.php') return false;
define('THIS_SCRIPT',basename($path));require __DIR__.'/appearance_surfaces_fixture.php';
