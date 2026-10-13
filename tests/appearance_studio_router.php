<?php
/** Local isolated PHP built-in server router. Never install as a forum endpoint. */
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/images/default_avatar.png' || $path==='/uploads/mine.png') {
    header('Content-Type: image/png');echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');return;
}
if ($path!=='/apstudio.php') return false;
define('AA_STUDIO_FIXTURE',true);define('THIS_SCRIPT','apstudio.php');
require __DIR__.'/appearance_personal_integration.php';
