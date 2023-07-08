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
            $hcpp->add_action( 'new_web_domain_ready', [ $this, 'new_web_domain_ready' ] );
            $hcpp->add_action( 'csrf_verified', [ $this, 'csrf_verified' ] );
        }

        /**
         * Capture edit web form submission, and generate a new certificate
         */
        public function csrf_verified() {
            global $hcpp;
            if ( $_SERVER['PHP_SELF'] != '/web/index.php' ) return;
            if ( ! isset( $_REQUEST['v_ftp_pre_path'] ) ) return;
            $user = $hcpp->delLeftMost( $_REQUEST['v_ftp_pre_path'], '/' );
            $user = $hcpp->getLeftMost( $user, '/' );
            $lines = explode( "\r\n", $_REQUEST['v_aliases'] );
            $domains = array_map( 'trim', $lines );
            array_unshift($domains, $_REQUEST['v_domain'] );
            $this->generate_website_cert( $user, $domains );
        }

        /**
         * Generate the master certificate for PWS, this will overwrite
         * any existing certificate if one already exists; then add it
         * to the system trusted certificates.
         */
        public function generate_master_cert() {
            $path = '/media/appFolder';
            $cmd = 'cd ' . escapeshellarg( $path ) . ' && ';
            $cmd .= 'rm -f ./pws.key 2>/dev/null && ';
            $cmd .= 'openssl  genrsa -out ./pws.key 2048 2>&1 && ';
            $cmd .= 'rm -f ./pws.crt 2>/dev/null && ';
            $cmd .= 'openssl req -x509 -new -nodes -key ./pws.key -sha256 -days 825 -out ./pws.crt -subj "/C=US/ST=California/L=San Diego/O=CodeGarden PWS/OU=Customers/CN=dev.cc" 2>&1 && ';
            $cmd .= 'rm -f /usr/local/share/ca-certificates/pws && ';
            $cmd .= 'mkdir -p /usr/local/share/ca-certificates/pws && ';
            $cmd .= 'cp ./pws.crt /usr/local/share/ca-certificates/pws/pws.crt && ';
            $cmd .= 'cp ./pws.key /usr/local/share/ca-certificates/pws/pws.key && ';
            $cmd .= 'update-ca-certificates 2>&1';
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
            $template .= "[req]\n";
            $template .= "distinguished_name=req_distinguished_name\n";
            $template .= "\n";
            $template .= "[alt_names]\n";
            $n = 1;
            foreach ( $domains as $domain ) {
                $template .= "DNS." . $n . " = " . $domain . "\n";
                $n++;
            }
            $template .= "\n";
            $template .= "[req_distinguished_name]\n";
            $template .= "countryName = US\n";
            $template .= "stateOrProvinceName = California\n";
            $template .= "localityName = San Diego\n";
            $template .= "organizationName = Code Garden\n";
            $template .= "organizationalUnitName = IT Department\n";
            $template .= "commonName = $domains[0]\n";
            $template .= "\n";
            $template = $hcpp->do_action( 'cg_pws_generate_website_cert_template', $template );
            file_put_contents( '/tmp/template.cnf', $template );

            // Generate the certificate
            if ( ! is_dir( '/home/' . $user . '/conf/web/' . $domains[0] . '/cg_pws_ssl' ) ) {
                mkdir( '/home/' . $user . '/conf/web/' . $domains[0] . '/cg_pws_ssl', 0755, true );
            }
            $cmd = 'cd /home/' . $user . '/conf/web/' . $domains[0] . '/cg_pws_ssl && ';
            $cmd .= 'openssl genrsa -out ./' . $domains[0] . '.key 2048 && ';
            $cmd .= 'openssl req -new -key ./' . $domains[0] . '.key -out ./' . $domains[0] . '.csr -subj "/CN=' . $domains[0] . '" -config /tmp/template.cnf && ';
            $cmd .= 'openssl x509 -req -in ./' . $domains[0] . '.csr -CA /media/appFolder/pws.crt -CAkey /media/appFolder/pws.key -CAcreateserial -out ./' . $domains[0] . '.crt -days 825 -sha256 -extfile /tmp/template.cnf && ';
            $cmd .= 'cat ./' . $domains[0] . '.key ./' . $domains[0] . '.crt > ./' . $domains[0] . '.pem && ';
            $cmd .= 'chmod -R 640 ./ && ';
            $cmd .= 'rm -f /tmp/template.cnf && ';
            $cmd .= 'v-delete-web-domain-ssl ' . $user . ' ' . $domains[0] . ' ; ';
            $cmd .= 'v-add-web-domain-ssl ' . $user . ' ' . $domains[0] . ' /home/' . $user . '/conf/web/' . $domains[0] . '/cg_pws_ssl';
            $cmd = $hcpp->do_action( 'cg_pws_generate_website_cert', $cmd );
            $hcpp->log( shell_exec( $cmd ) );
        }

        /**
         * Generate a certificate for the website domain.
         */
        public function new_web_domain_ready( $args ) {
            $user = $args[0];
            $domain = $args[1];
            $this->generate_website_cert( $user, array( $domain ) );
            return $args;
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
