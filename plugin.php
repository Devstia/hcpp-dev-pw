<?php
/**
 * Plugin Name: CG-PWS
 * Plugin URI: https://github.com/virtuosoft-dev/hcpp-cg-pws
 * Description: Code Garden Personal Web Server plugin for HestiaCP enables Code Garden features on the desktop app.
 * Version: 1.0.0
 * Author: Stephen J. Carnam
 *
 */

// Register the install and uninstall scripts
global $hcpp;
require_once( dirname(__FILE__) . '/cg-pws.php' );

$hcpp->register_install_script( dirname(__FILE__) . '/install' );
$hcpp->register_uninstall_script( dirname(__FILE__) . '/uninstall' );
