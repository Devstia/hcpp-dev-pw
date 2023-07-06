<?php
/**
 * Extend the HestiaCP Pluginable object with our CG_PWS object for
 * providing a localhost development server functionality.
 * 
 * @version 1.0.0
 * @license GPL-3.0
 * @link https://github.com/virtuosoft-dev/hcpp-cg-pws
 * 
 */

if ( ! class_exists( 'CG_PWS') ) {
    class CG_PWS {
        
        /**
         * Constructor, listen for adding, or listing websites
         */
        public function __construct() {
            global $hcpp;
            $hcpp->collabora = $this;
        }
    }
    new CG_PWS(); 
} 
// openssl  genrsa -out ./pws.key 2048 2>&1
// openssl req -x509 -new -nodes -key ./pws.key -sha256 -days 825 -out ./pws.crt -subj "/C=US/ST=California/L=San Diego/O=CodeGarden PWS/OU=Customers/CN=pws.localhost" 2>&1
