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
            $hcpp->cg_pws = $this;
            $hcpp->add_action( 'hccp_update_core_cmd', [ $this, 'hccp_update_core_cmd' ] );
            $hcpp->add_action( 'hcpp_invoke_plugin', [ $this, 'hcpp_invoke_plugin' ] );
            $hcpp->add_action( 'hcpp_new_domain_ready', [ $this, 'hcpp_new_domain_ready' ] );
            $hcpp->add_action( 'hcpp_csrf_verified', [ $this, 'hcpp_csrf_verified' ] );
            $hcpp->add_action( 'hcpp_render_body', [ $this, 'hcpp_render_body' ] );
            $hcpp->add_action( 'hcpp_rebooted', [ $this, 'hcpp_rebooted' ] );
            $hcpp->add_action( 'hcpp_head', [ $this, 'hcpp_head' ] );
        }

        /**
         * Capture edit web form submission, and generate a new certificate
         * if ssl option is checked and crt/key are empty.
         */
        public function hcpp_csrf_verified() {
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
                $args = [ 'cg_pws_generate_website_cert', $user ];
                $args = array_merge( $args, $domains );
                $hcpp->log( $hcpp->run( 'invoke-plugin ' . implode( ' ', $args ) ) );    
            }
        }

        // Generate certs on demand
        public function hcpp_invoke_plugin( $args ) {
            if ( $args[0] == 'cg_pws_generate_master_cert' ) {
                $this->generate_master_cert();
            }
            if ( $args[0] == 'cg_pws_generate_website_cert') {
                $user = $args[1];
                $domains = array();
                for ($i = 2; $i < count($args); $i++) {
                    $domains[] = $args[$i];
                }
                $this->generate_website_cert( $user, $domains );
            }
            if ( $args[0] == 'cg_pws_regenerate_certificates' ) {
                $this->regenerate_certificates();
            }
            if ( $args[0] == 'cg_pws_regenerate_ssh_keys' ) {
                $this->regenerate_ssh_keys();
            }
            return $args;
        }

        // Re-apply installation after core udpate
        public function hcpp_update_core_cmd( $cmd ) {
            $cmd = 'cd /usr/local/hestia/plugins/cg-pws && ./install && ' . $cmd;
            return $cmd;
        }

        /**
         * Generate the master certificate for HestiaCP, PWS. This will overwrite
         * any existing certificate if one already exists; then add it
         * to the system trusted certificates.
         */
        public function generate_master_cert() {

            // Generate the master certificate
            $devcc_folder = '/usr/local/share/ca-certificates/dev.cc';
            $app_folder = '/media/appFolder/security/ca';
            $cmd = "rm -rf $devcc_folder && mkdir -p $devcc_folder && cd $devcc_folder && ";
            $cmd .= 'openssl  genrsa -out ./dev.cc.key 2048 2>&1 && ';
            $cmd .= 'openssl req -x509 -new -nodes -key ./dev.cc.key -sha256 -days 825 -out ./dev.cc.crt -subj "/C=US/ST=California/L=San Diego/O=Virtuosoft/OU=CodeGarden PWS/CN=dev.cc" 2>&1 && ';
            $cmd .= 'update-ca-certificates 2>&1 && ';

            // Copy the master certificate to the appFolder
            $cmd .= 'cp ./dev.cc.crt ' . $app_folder . '/dev.cc.crt ; cp ./dev.cc.key ' . $app_folder . '/dev.cc.key';
            global $hcpp;
            $cmd = $hcpp->do_action( 'cg_pws_generate_master_cert', $cmd );
            $hcpp->log( shell_exec( $cmd ) );

            // Generate local.dev.cc for the control panel itself
            $hcpp->log( "Generating local.dev.cc certificate" );
            $this->generate_website_cert( 'admin', [ 'local.dev.cc', 'localhost' ] );

            // Update the Hestia nginx certificate
            $cmd = 'cp /home/admin/conf/web/local.dev.cc/ssl/local.dev.cc.crt /usr/local/hestia/ssl/certificate.crt && ';
            $cmd .= 'cp /home/admin/conf/web/local.dev.cc/ssl/local.dev.cc.key /usr/local/hestia/ssl/certificate.key && ';
            $cmd .= 'service hestia restart';
            $cmd = $hcpp->do_action( 'cg_pws_update_hestia_cert', $cmd );
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
            $hcpp->log( "generate_website_cert for $user" );
            $hcpp->log( $domains );
            if ( ! is_dir( '/home/' . $user . '/conf/web/' . $domains[0] ) ) {
                $hcpp->log( 'Error - user ' . $user . ' or website ' . $domains[0] . ' does not exist, skipping certificate generation.');
                return;
            }

            // Generate the certificate data in a fresh cg_pws_ssl
            $cg_pws_ssl = '/home/' . $user . '/conf/web/' . $domains[0] . '/cg_pws_ssl';
            $cmd = 'rm -rf ' . $cg_pws_ssl . ' && ';
            $cmd .= 'mkdir -p ' . $cg_pws_ssl;
            $hcpp->log( $cmd );
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
            $template .= "organizationName = Virtuosoft\n";
            $template .= "organizationalUnitName = CodeGarden PWS\n";
            $template .= "commonName = $domains[0]\n";
            $template .= "\n";
            $template = $hcpp->do_action( 'cg_pws_generate_website_cert_template', $template );
            file_put_contents( $cg_pws_ssl . '/template.cnf', $template );

            $cmd = 'cd ' . $cg_pws_ssl . ' && ';
            $cmd .= 'openssl genrsa -out ./' . $domains[0] . '.key 2048 && ';
            $cmd .= 'openssl req -new -key ./' . $domains[0] . '.key -out ./' . $domains[0] . ".csr -subj '/CN=" . $domains[0] . "'" . ' -config ./template.cnf && ';
            $cmd .= 'openssl x509 -req -in ./' . $domains[0] . '.csr -CA /usr/local/share/ca-certificates/dev.cc/dev.cc.crt -CAkey /usr/local/share/ca-certificates/dev.cc/dev.cc.key -CAcreateserial -out ./' . $domains[0] . '.crt -days 825 -sha256 -extfile ./template.cnf && ';
            $cmd .= 'cat ./' . $domains[0] . '.key ./' . $domains[0] . '.crt > ./' . $domains[0] . '.pem && ';
            $cmd .= 'chmod -R 644 ./ && ';
            $cmd .= '/usr/local/hestia/bin/v-delete-web-domain-ssl ' . $user . ' ' . $domains[0] . ' "no" ; ';
            $cmd .= '/usr/local/hestia/bin/v-add-web-domain-ssl ' . $user . ' ' . $domains[0] . ' /home/' . $user . '/conf/web/' . $domains[0] . '/cg_pws_ssl && ';
            $cmd .= '/usr/local/hestia/bin/v-add-web-domain-ssl-force ' . $user . ' ' . $domains[0]; 
            $cmd = $hcpp->do_action( 'cg_pws_generate_website_cert', $cmd );
            $hcpp->log( shell_exec( $cmd ) );
        }

        /**
         * Generate a certificate for the website domain.
         */
        public function hcpp_new_domain_ready( $args ) {
            $user = $args[0];
            $domain = $args[1];
            $this->generate_website_cert( $user, array( $domain ) );
            global $hcpp;
            $hcpp->run( 'add-web-domain-ssl-force ' . $user . ' ' . $domain . ' no ');
            return $args;
        }

        /**
         * Ensure that the master certificate and ssh keypair is generated upon boot.
         */
        public function hcpp_rebooted() {
            // Generate the master certificate if it doesn't exist
            $caFiles = [
                '/home/admin/conf/web/local.dev.cc/ssl/local.dev.cc.crt',
                '/home/admin/conf/web/local.dev.cc/ssl/local.dev.cc.key',
                '/usr/local/share/ca-certificates/dev.cc/dev.cc.crt', 
                '/usr/local/share/ca-certificates/dev.cc/dev.cc.key'
            ];
            foreach ( $caFiles as $file ) {
                if ( ! file_exists( $file ) ) {
                    $this->regenerate_certificates(); // Generate the master and all website certificates.
                    break;
                }
            }

            // Always copy the ca-certificates back to the appFolder on reboot
            global $hcpp;
            $cmd = 'rm -rf /media/appFolder/security/ca ; mkdir -p /media/appFolder/security/ca ; ';
            $cmd .= 'cp /usr/local/share/ca-certificates/dev.cc/dev.cc.crt /media/appFolder/security/ca/dev.cc.crt ; ';
            $cmd .= 'cp /usr/local/share/ca-certificates/dev.cc/dev.cc.key /media/appFolder/security/ca/dev.cc.key';
            $cmd = $hcpp->do_action( 'cg_pws_copy_ca_certificates', $cmd );
            $hcpp->log( shell_exec( $cmd ) );

            // Generate ssh keypair for pws, debian
            $sshFiles = [
                '/home/pws/.ssh/id_rsa',
                '/home/pws/.ssh/id_rsa.pub',
                '/home/debian/.ssh/id_rsa',
                '/home/debian/.ssh/id_rsa.pub',
                '/media/appFolder/security/ssh/debian_rsa',
                '/media/appFolder/security/ssh/debian_rsa.pub',
                '/media/appFolder/security/ssh/pws_rsa',
                '/media/appFolder/security/ssh/pws_rsa.pub'
            ];
            foreach ( $sshFiles as $file ) {
                if ( ! file_exists( $file ) ) {
                    $this->regenerate_ssh_keys(); // Generate the new ssh keys
                    break;
                }
            }

            // Always copy the ssh keys back to the appFolder/security/ssh on reboot
            $this->copy_ssh_keys();
        }

        /**
         * Copy back the ssh keys to the appFolder/security/ssh.
         */
        public function copy_ssh_keys() {
            global $hcpp;
            $cmd = 'rm -rf /media/appFolder/security/ssh && mkdir -p /media/appFolder/security/ssh && ';
            $cmd .= 'cp /home/debian/.ssh/id_rsa /media/appFolder/security/ssh/debian_rsa && ';
            $cmd .= 'cp /home/debian/.ssh/id_rsa.pub /media/appFolder/security/ssh/debian_rsa.pub &&';
            $cmd .= 'cp /home/pws/.ssh/id_rsa /media/appFolder/security/ssh/pws_rsa && ';
            $cmd .= 'cp /home/pws/.ssh/id_rsa.pub /media/appFolder/security/ssh/pws_rsa.pub && ';
            $cmd .= 'cp /etc/ssh/ssh_host_ecdsa_key.pub /media/appFolder/security/ssh/ssh_host_ecdsa_key.pub && ';
            $cmd .= 'cp /etc/ssh/ssh_host_rsa_key.pub /media/appFolder/security/ssh/ssh_host_rsa_key.pub';
            $cmd = $hcpp->do_action( 'cg_pws_copy_ssh_keys', $cmd );
            $hcpp->log( shell_exec( $cmd ) );
        }

        /**
         * Generate a new ssh keypair for the pws and debian users.
         */
        public function regenerate_ssh_keys() {
            global $hcpp;
            $hcpp->log( 'Regenerating ssh keys for debian and pws...' );

            // debian
            $cmd = 'rm -rf /home/debian/.ssh && mkdir -p /home/debian/.ssh && ';
            $cmd .= 'chown -R debian:debian /home/debian/.ssh && chmod -R 700 /home/debian/.ssh && ';
            $cmd .= 'runuser -l debian -c \'ssh-keygen -t rsa -b 4096 -f /home/debian/.ssh/id_rsa -q -N ""\' && ';
            $cmd .= 'cp -f /home/debian/.ssh/id_rsa.pub /home/debian/.ssh/authorized_keys && ';
            $cmd .= 'chown debian:debian /home/debian/.ssh/authorized_keys && chmod 600 /home/debian/.ssh/authorized_keys && ';

            // pws
            $cmd .= 'rm -rf /home/pws/.ssh && mkdir -p /home/pws/.ssh && ';
            $cmd .= 'chown -R pws:pws /home/pws/.ssh && chmod -R 700 /home/pws/.ssh && ';
            $cmd .= 'runuser -l pws -c \'ssh-keygen -t rsa -b 4096 -f /home/pws/.ssh/id_rsa -q -N ""\' && ';
            $cmd .= 'cp -f /home/pws/.ssh/id_rsa.pub /home/pws/.ssh/authorized_keys && ';
            $cmd .= 'chown pws:pws /home/pws/.ssh/authorized_keys && chmod 600 /home/pws/.ssh/authorized_keys';
            
            $cmd = $hcpp->do_action( 'cg_pws_regenerate_ssh_keys', $cmd );
            shell_exec( $cmd );
            $this->copy_ssh_keys();
        }

        /**
         * Regenerate the master and all website certificates.
         */
        public function regenerate_certificates() {
            global $hcpp;
            $hcpp->log( 'Regenerating certificates...' );
            $path = '/home/pws/conf/web';
            $this->generate_master_cert();
            if ( is_dir($path) ) {
                $directory = new DirectoryIterator( $path );
                foreach ( $directory as $file ) {
                    if ( $file->isDir() && !$file->isDot() ) {
                        $domain = $file->getFilename();
                        $cg_crt = "$path/$domain/cg_pws_ssl/$domain.crt";
                        $ssl_crt = "$path/$domain/ssl/$domain.crt";
                        if ( file_exists( $cg_crt ) && file_exists( $ssl_crt ) ) {
                            $hcpp->log( 'Regenerating certificate for ' . $domain );
                            $detail = $hcpp->run( "list-web-domain pws $domain json" );
                            if ( $detail != NULL ) {
                                $domains = $detail[$domain]['ALIAS'];
                                $domains = explode(",", $domains);
                                array_unshift( $domains, $domain );
                            }else {
                                $domains = array( $domain );
                            }
                            $this->generate_website_cert( 'pws', $domains );
                        }
                    }
                }
            }
            $cmd = '(service nginx reload) > /dev/null 2>&1 &';
            $cmd = $hcpp->do_action( 'cg_pws_nginx_reload', $cmd );
            shell_exec( $cmd );
        }

        /**
         * Intercept web edit save, ensure ssl crt/key are not empty; suppresing
         * the empty error message as we'll generate a certificate on the fly.
         * 
         * White label list_services page
         */
        public function hcpp_render_body( $args ) {
            if ( $args['page'] == 'edit_web' ) {
                $code = '<script>
                // Get references to the necessary elements
                const sslCheckbox = document.getElementById("v_ssl");
                const sslCrtTextarea = document.getElementById("ssl_crt");
                const sslKeyTextarea = document.getElementById("v_ssl_key");
                const form = document.getElementById("main-form");
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
            if ( $args['page'] == 'list_services' ) {
                $content = $args['content'];
                $content = str_replace( 'Hestia Control Panel', 'CodeGarden PWS', $content );
                $args['content'] = $content;
            }
            return $args;
        }

        /**
         * Intercept login, check for valid auto-login token (alt), and automatically
         * submit login form if valid.
         */
        public function hcpp_head( $args ) {

            // Check for valid auto-login token
            if ( !isset( $_GET['alt'] ) ) return $args;
            $content = $args['content'];
            if ( strpos( $content, 'LOGIN') === false ) return $args;
            $altContent = trim( shell_exec( 'cat /media/appFolder/alt.txt' ) ); 
            if ( $_GET['alt'] != $altContent ) return $args;

            // Get the password
            $settings = trim( shell_exec( 'cat /media/appFolder/settings.json' ) );
            $settings = json_decode( $settings, true );
            $passwd = $this->decrypt( $settings['pwsPass'] );

            // Inject the auto-login script
            $content .= '<script>';
            $content .= 'document.addEventListener("DOMContentLoaded", function(event) {';
            $content .= '    if (document.getElementById("username") != null && document.getElementById("password") != null) {';
            $content .= '        document.getElementsByClassName("login")[0].style.display = "none";';
            $content .= '        document.getElementById("form_login").style.display = "none";';
            $content .= '        document.getElementById("username").value="pws";';
            $content .= '        document.getElementById("password").value="' . $passwd . '";';
            $content .= '        setTimeout(function(){document.getElementsByTagName("button")[0].click();},1000);';
            $content .= '    }';
            $content .= '});';
            $content .= '</script>';
            $args['content'] = $content;
            return $args;
        }

        /**
         * Decrypts data using aes-256-cbc algorithm
         * 
         * @param data string to be decrypted
         * @returns string containing decrypted data
         */
        public function decrypt( $data ) {
            $key = md5('personal-web-server');
            $data = explode( ':', $data );
            $encrypted_data = base64_decode( $data[0] );
            $iv = base64_decode( $data[1] );
            $decrypted = openssl_decrypt( $encrypted_data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
            return $decrypted;
        }
    }
    new CG_PWS(); 
} 
