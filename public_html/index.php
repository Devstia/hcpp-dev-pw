<?php
$content = shell_exec('cat /usr/local/hestia/nginx/conf/nginx.conf');
$port = "8083";
if (preg_match('/\blisten\s+(\d+)\s+ssl\b/', $content, $matches)) {
    $port = $matches[1];
}
$alt = "";
if ( isset( $_GET['alt'] ) ) {
   $alt = '&alt=' . $_GET['alt'];
}
$redirectURL = "https://local.dev.cc:" . $port . "/pluginable.php?load=cg-pws" . $alt;
header("Location: " . $redirectURL);
exit;
