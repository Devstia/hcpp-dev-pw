<?php
/**
 * Plugin Name: DEV-PW
 * Plugin URI: https://github.com/Devstia/hcpp-dev-pw
 * Description: Devstia Personal Web plugin for HestiaCP enables Devstia Personal Web features on the desktop app.
 * Version: 1.0.0
 * Author: Stephen J. Carnam
 *
 */

// Register the install and uninstall scripts
global $hcpp;
require_once( dirname(__FILE__) . '/dev-pw.php' );

$hcpp->register_install_script( dirname(__FILE__) . '/install' );
$hcpp->register_uninstall_script( dirname(__FILE__) . '/uninstall' );
