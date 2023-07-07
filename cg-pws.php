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
            $hcpp->add_action( 'invoke_plugin', [ $this, 'invoke_plugin' ] );
        }

        /**
         * Generate the master certificate for PWS, this will overwrite
         * any existing certificate if one already exists.
         */
        public function generate_master_cert() {
            $path = '/media/appFolder';
            $cmd = 'cd ' . escapeshellarg( $path ) . ' && ';
            $cmd .= 'rm -f ./pws.key 2>/dev/null && ';
            $cmd .= 'openssl  genrsa -out ./pws.key 2048 2>&1 && ';
            $cmd .= 'rm -f ./pws.crt 2>/dev/null && ';
            $cmd .= 'openssl req -x509 -new -nodes -key ./pws.key -sha256 -days 825 -out ./pws.crt -subj "/C=US/ST=California/L=San Diego/O=CodeGarden PWS/OU=Customers/CN=dev.cc" 2>&1';
            global $hcpp;
            $cmd = $hcpp->do_action( 'cg_pws_generate_master_cert', $cmd );
            $hcpp->log( shell_exec( $cmd ) );
        }

        /**
         * Generate a master-dependent certificate for a website, this will
         * overwrite any existing certificate if one already exists.
         * 
         * @param string $user The user account to generate the certificate for.
         * @param array $domains The domains to generate the certificate for.
         */
        public function generate_website_cert( $user, $domains ) {
            global $hcpp;
            if ( ! is_dir( '/home/' . $user . '/conf/web/' . $domains[0] ) ) {
                $hcpp->log( 'Error - user ' . $user . ' or website ' . $domains[0] . ' does not exist, skipping certificate generation.');
                return;
            }

            // Write the /tmp/template.cnf file
            $template = "authorityKeyIdentifier=keyid,issuer\n";
            $template .= "basicConstraints=CA:FALSE\n";
            $template .= "keyUsage = digitalSignature, nonRepudiation, keyEncipherment, dataEncipherment\n";
            $template .= "subjectAltName = @alt_names\n";
            $template .= "\n";
            $template .= "[alt_names]\n";
            $n = 1;
            foreach ( $domains as $domain ) {
                $template .= "DNS." . $n . " = " . $domain . "\n";
                $n++;
            }
            $template = $hcpp->do_action( 'cg_pws_generate_website_cert_template', $template );
            file_put_contents( '/tmp/template.cnf', $template );

            // Generate the certificate
            if ( ! is_dir( '/home/' . $user . '/conf/web/' . $domains[0] . '/ssl' ) ) {
                mkdir( '/home/' . $user . '/conf/web/' . $domains[0] . '/ssl', 0755, true );
            }
            $cmd = 'cd /home/' . $user . '/conf/web/' . $domains[0] . '/ssl && ';
            $cmd .= 'openssl genrsa -out ./' . $domains[0] . '.key 2048 && ';
            $cmd .= 'openssl req -new -key ./' . $domains[0] . '.key -out ./' . $domains[0] . '.csr -subj "/CN=' . $domains[0] . '" -config /tmp/template.cnf && ';
            $cmd .= 'openssl x509 -req -in ./' . $domains[0] . '.csr -CA /media/appFolder/pws.crt -CAkey /media/appFolder/pws.key -CAcreateserial -out ./' . $domains[0] . '.crt -days 825 -sha256 -extfile /tmp/template.cnf && ';
            $cmd .= 'cat ./' . $domains[0] . '.key ./' . $domains[0] . '.crt > ./' . $domains[0] . '.pem';
            $cmd .= 'rm -f /tmp/template.cnf';
            $cmd = $hcpp->do_action( 'cg_pws_generate_website_cert', $cmd );
            $hcpp->log( shell_exec( $cmd ) );
        }

        // Generate certs on demand
        public function invoke_plugin( $args ) {
            if ( $args[0] == 'generate_master_cert' ) {
                $this->generate_master_cert();
            }
            if ( $args[0] == 'generate_website_cert') {
                $user = $args[1];
                $domains = array();
                for ($i = 2; $i < count($args); $i++) {
                    $domains[] = $args[$i];
                }
                $this->generate_website_cert( $user, $domains );
            }          
            return $args;
        }
    }
    new CG_PWS(); 
} 
