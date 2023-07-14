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
            $hcpp->add_action( 'render_page', [ $this, 'render_page' ] );
            $hcpp->add_action( 'nodeapp_resurrect_apps', [ $this, 'nodeapp_resurrect_apps' ] );
        }

        /**
         * Capture edit web form submission, and generate a new certificate
         * if ssl option is checked and crt/key are empty.
         */
        public function csrf_verified() {
            global $hcpp;
            if ( $_SERVER['PHP_SELF'] != '/edit/web/index.php' ) return;
            if ( ! isset( $_REQUEST['v_ftp_pre_path'] ) ) return;
            $generate = false;
            
            // Generate a new certificate on ssl option with no existing crt/key
            if ( $_REQUEST['v_ssl'] == 'on' && trim( $_REQUEST['v_ssl_crt'] ) == '' && trim( $_REQUEST['v_ssl_key'] ) == '' ) {
                $generate = true;
            }
            $user = $hcpp->delLeftMost( $_REQUEST['v_ftp_pre_path'], '/home/' );
            $user = $hcpp->getLeftMost( $user, '/' );
            $domain = $_REQUEST['v_domain'];

            // Generate a new certificate on ssl option and alias added
            if ( $_REQUEST['v_ssl'] == 'on' ) {
                $aliases = explode( "\r\n", $_REQUEST['v_aliases'] );
                $existing = $hcpp->run( 'list-web-domain ' . $user . ' ' . $domain . ' json');
                $existing = $existing[$domain]['ALIAS'];
                foreach ( $aliases as $alias ) {
                    if ( strpos( $existing . ',', $alias . ',' ) === false ) {
                        $generate = true;
                        break;    
                    }
                }
            }

            // Generate the new certificate
            if ( $generate ) {
                unset($_REQUEST['v_ssl']);
                $lines = explode( "\r\n", $_REQUEST['v_aliases'] );
                $domains = array_map( 'trim', $lines );
                array_unshift($domains, $_REQUEST['v_domain'] );
                $args = [ 'generate_website_cert', $user ];
                $args = array_merge( $args, $domains );
                $hcpp->log( $hcpp->run( 'invoke-plugin ' . implode( ' ', $args ) ) );    
            }
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
            if ( $args[0] == 'regenerate_certificates' ) {
                $this->regenerate_certificates();
            }
            return $args;
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

            // Generate the certificate data in a fresh cg_pws_ssl
            $cg_pws_ssl = '/home/' . $user . '/conf/web/' . $domains[0] . '/cg_pws_ssl';
            $cmd = 'rm -rf ' . $cg_pws_ssl . ' && ';
            $cmd .= 'mkdir -p ' . $cg_pws_ssl;
            shell_exec( $cmd );

            // Write the template.cnf file
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
            file_put_contents( $cg_pws_ssl . '/template.cnf', $template );

            $cmd = 'cd ' . $cg_pws_ssl . ' && ';
            $cmd .= 'openssl genrsa -out ./' . $domains[0] . '.key 2048 && ';
            $cmd .= 'openssl req -new -key ./' . $domains[0] . '.key -out ./' . $domains[0] . ".csr -subj '/CN=" . $domains[0] . "'" . ' -config ./template.cnf && ';
            $cmd .= 'openssl x509 -req -in ./' . $domains[0] . '.csr -CA /media/appFolder/pws.crt -CAkey /media/appFolder/pws.key -CAcreateserial -out ./' . $domains[0] . '.crt -days 825 -sha256 -extfile ./template.cnf && ';
            $cmd .= 'cat ./' . $domains[0] . '.key ./' . $domains[0] . '.crt > ./' . $domains[0] . '.pem && ';
            $cmd .= 'chmod -R 644 ./ && ';
            $cmd .= '/usr/local/hestia/bin/v-delete-web-domain-ssl ' . $user . ' ' . $domains[0] . ' ; ';
            $cmd .= '/usr/local/hestia/bin/v-add-web-domain-ssl ' . $user . ' ' . $domains[0] . ' /home/' . $user . '/conf/web/' . $domains[0] . '/cg_pws_ssl';
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

        /**
         * Ensure that the master certificate and ssh keypair is generated upon boot.
         */
        public function nodeapp_resurrect_apps( $cmd ) {
            // TODO: generate ssh keypair for pws, debian, and make it avail to /media/appFolder
            if ( ! file_exists( '/media/appFolder/pws.crt') || ! file_exists( '/media/appFolder/pws.key' ) ) {
                $this->generate_master_cert();
            }
            return $cmd;
        }

        /**
         * Regenerate the master and all website certificates.
         */
        public function regenerate_certificates() {
            global $hcpp;
            $hcpp->log( 'Regenerating certificates...' );
            $path = '/home/pws/conf/web';
            if ( is_dir($path) ) {
                $directory = new DirectoryIterator( $path );
                foreach ( $directory as $file ) {
                    if ( $file->isDir() && !$file->isDot() ) {
                        $domain = $file->getFilename();
                        $cg_crt = "$path/$domain/cg_pws_ssl/$domain.crt";
                        $ssl_crt = "$path/$domain/ssl/$domain.crt";
                        if ( file_exists( $cg_crt ) && file_exists( $ssl_crt ) ) {
                            if ( md5_file( $cg_crt ) == md5_file( $ssl_crt ) ) {
                                $detail = $hcpp->run( "list-web-domain pws $domain json" );
                                $domains = $detail[$domain]['ALIAS'];
                                $domains = explode(",", $domains);
                                array_unshift( $domains, $domain );
                                $this->generate_website_cert( 'pws', $domains );
                            }
                        }
                    }
                }
            }
        }

        /**
         * Intercept web edit save, ensure ssl crt/key are not empty; suppresing
         * the empty error message as we'll generate a certificate on the fly.
         */
        public function render_page( $args ) {
            if ( $args['page'] == 'edit_web' ) {
                $code = '<script>
                // Get references to the necessary elements
                const sslCheckbox = document.getElementById("v_ssl");
                const sslCrtTextarea = document.getElementById("ssl_crt");
                const sslKeyTextarea = document.getElementById("v_ssl_key");
                const form = document.getElementById("vstobjects");
                const saveButton = form.querySelector("button[type=\"submit\"]");
                form.addEventListener("submit", function(event) {
                    if (sslCheckbox.checked) {
                        if (sslCrtTextarea.value.trim() == "") {
                            sslCrtTextarea.value = "     ";
                        }
                        if (sslKeyTextarea.value.trim() == "") {
                            sslKeyTextarea.value = "     ";
                        }
                    }
                    return true;
                });</script>
                <style>
                    /* Hide native generate csr link */
                    #generate-csr {
                        display: none;
                    }
                </style>';
                $content = $args['content'];
                $content = str_replace( '</form>', '</form>' . $code, $content );
                $args['content'] = $content;
            }
            return $args;
        }
    }
    new CG_PWS(); 
} 
