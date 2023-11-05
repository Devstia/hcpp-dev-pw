<?php
/**
 * Plugin Name: DEV-PW
 * Plugin URI: https://github.com/virtuosoft-dev/hcpp-dev-pw
 * Description: Devstia Preview plugin for HestiaCP enables Devstia Preview features on the desktop app.
 * Version: 1.0.0
 * Author: Stephen J. Carnam
 *
 */

// Register the install and uninstall scripts
global $hcpp;
require_once( dirname(__FILE__) . '/dev-pw.php' );

$hcpp->register_install_script( dirname(__FILE__) . '/install' );
$hcpp->register_uninstall_script( dirname(__FILE__) . '/uninstall' );
