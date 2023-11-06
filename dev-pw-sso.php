<?php
$pma_token_file = '/tmp/pma_token.txt';
$pma_pwpass = '/tmp/pma_pwpass.txt';

// Remove token on logout
if ( isset( $_REQUEST['route'] ) && $_REQUEST['route'] == '/logout' ) {
    setcookie( "pma_token", "", time() - 3600 );
    unlink( $pma_token_file );
    unlink( $pma_pwpass );
    return;
}

// Check pma_token token file
if ( false == file_exists( $pma_token_file ) ) return;
if ( time() - filemtime( $pma_token_file ) > 1800 ) return; // 30 minutes

// Check for valid pma_token from session or request
$pma_token = '';
if ( isset( $_REQUEST['pma_token'] ) ) {
    $pma_token = $_REQUEST['pma_token'];
}else{
    if ( isset( $_COOKIE['pma_token'] ) ) $pma_token = $_COOKIE['pma_token'];
}
if ( trim( file_get_contents( $pma_token_file ) ) != $pma_token || $pma_token == '') return;

// Renew token
setcookie( "pma_token", $pma_token, time() + 3600 );
touch( $pma_token_file );

// Decrypt current password
$pwPass = '';
if ( file_exists( $pma_pwpass ) ) {
    $pwPass = file_get_contents( $pma_pwpass );
    $key = md5( $pma_token );
    $pwPass = explode( ':', $pwPass );
    $encrypted_data = base64_decode( $pwPass[0] );
    $iv = base64_decode( $pwPass[1] );
    $pwPass = openssl_decrypt( $encrypted_data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
}else{
    return;
}

// Valid token, allow access and renew token
$cfg['Servers'][1]['auth_type'] = 'config';
$cfg['Servers'][1]['user'] = 'devstia';
$cfg['Servers'][1]['password'] = $pwPass; 

