<?php
/**
 * Extend the HestiaCP Pluginable object with our DEV_PW object for
 * providing a localhost development server functionality.
 * 
 * @version 1.0.0
 * @license GPL-3.0
 * @link https://github.com/virtuosoft-dev/hcpp-dev-pw
 * 
 */

if ( ! class_exists( 'DEV_PW') ) {
    class DEV_PW {
        
        /**
         * Constructor, listen for adding, or listing websites
         */
        public function __construct() {
            global $hcpp;
            $hcpp->dev_pw = $this;
            $hcpp->add_action( 'hcpp_update_core_cmd', [ $this, 'hcpp_update_core_cmd' ] );
            $hcpp->add_action( 'hcpp_invoke_plugin', [ $this, 'hcpp_invoke_plugin' ] );
            $hcpp->add_action( 'hcpp_new_domain_ready', [ $this, 'hcpp_new_domain_ready' ] );
            $hcpp->add_action( 'hcpp_csrf_verified', [ $this, 'hcpp_csrf_verified' ] );
            $hcpp->add_action( 'hcpp_nginx_reload', [ $this, 'hcpp_nginx_reload' ] );
            $hcpp->add_action( 'hcpp_render_body', [ $this, 'hcpp_render_body' ] );
            $hcpp->add_action( 'hcpp_rebooted', [ $this, 'hcpp_rebooted' ] );
            $hcpp->add_action( 'hcpp_head', [ $this, 'hcpp_head' ] );
            $hcpp->add_action( 'priv_update_sys_rrd', [ $this, 'priv_update_sys_rrd' ] );
            $hcpp->add_action( 'priv_log_user_logout', [ $this, 'priv_log_user_logout' ] );
            $hcpp->add_action( 'dev_pw_update_password', [ $this, 'dev_pw_update_password' ] );
            $hcpp->add_action( 'priv_add_user_notification', [ $this, 'priv_add_user_notification'] );
        }

        /**
         * Forward admin notifications to devstia user
         */
        public function priv_add_user_notification( $args ) {
            if ( $args[0] === 'admin' ) {
                $cmd = '/usr/local/hestia/bin/v-add-user-notification devstia "' . $args[1] . '" "' . $args[2] . '"';
                shell_exec( $cmd );
            }
            return $args;
        }

        /**
         * Update encrypted password for the devstia user.
         */
        public function dev_pw_update_password( $passwd ) {
            $passwd = $this->encrypt( $passwd );
            file_put_contents( '/home/admin/.pwPass', $passwd );
        }

        /**
         * Re-apply white label, pma sso, on core update.
         */
        public function hcpp_update_core_cmd( $cmd ) {
            $cmd = 'cd /usr/local/hestia/plugins/dev-pw && ./install && ' . $cmd;
            return $cmd;
        }

        /**
         * Remove phpMyAdmin SSO token on logout.
         */
         public function priv_log_user_logout( $args ) {
            $pma_token_file = '/tmp/pma_token.txt';
            $pma_pwpass = '/tmp/pma_pwpass.txt';
            unlink( $pma_token_file );
            unlink( $pma_pwpass );
            return $args;
         }

        /**
         * Publish certificates and keys to the devstia-app server.
         */
        public function publish_certs_keys() {
            global $hcpp;
            try {
                $data = array(
                    'pwPass' => file_get_contents( '/home/admin/.pwPass' ),
                    'ca/dev.pw.crt' => file_get_contents( '/usr/local/share/ca-certificates/dev.pw/dev.pw.crt' ),
                    'ca/dev.pw.key' => file_get_contents( '/usr/local/share/ca-certificates/dev.pw/dev.pw.key' ),
                    'ssh/debian_rsa' => file_get_contents( '/home/debian/.ssh/id_rsa' ),
                    'ssh/debian_rsa.pub' => file_get_contents( '/home/debian/.ssh/id_rsa.pub' ),
                    'ssh/devstia_rsa' => file_get_contents( '/home/devstia/.ssh/id_rsa' ),
                    'ssh/devstia_rsa.pub' => file_get_contents( '/home/devstia/.ssh/id_rsa.pub' ),
                    'ssh/ssh_host_ecdsa_key.pub' => file_get_contents( '/etc/ssh/ssh_host_ecdsa_key.pub' ),
                    'ssh/ssh_host_rsa_key.pub' => file_get_contents( '/etc/ssh/ssh_host_rsa_key.pub' )
                );
                $options = array(
                    'http' => array(
                        'method' => 'POST',
                        'header' => 'Content-Type: application/json',
                        'content' => json_encode($data)
                    )
                );
                $context = stream_context_create($options);
                $hcpp->log( file_get_contents( 'http://10.0.2.2:8088/', false, $context) );
            }catch( Exception $e ) {
                $hcpp->log( 'Error in DEV_PW->publish_certs_keys: ' . $e->getMessage() );
            }
        }

        /** 
         * Check for notifications on reboot and every 5 minutes.
         */
        public function check_for_pw_notifications() {
            global $hcpp;
            $jsonUrl = 'https://devstia.com/dev-pw-notifications/index.php';
            $contextOptions = [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ];
            sleep( mt_rand( 1, 8) ); // Stagger the requests
            $context = stream_context_create($contextOptions);
            $jsonData = file_get_contents($jsonUrl, false, $context);
            if ($jsonData === false) {
                $hcpp->log('Error: Failed to fetch notifications from ' . $jsonUrl);
                return;
            }
            $pwNoticeIndex = 0;
            $pwNoticeIndexFile = "/usr/local/hestia/data/hcpp/pw-notice-index.txt";
            if ( file_exists( $pwNoticeIndexFile ) ) {
                $pwNoticeIndex = (int)file_get_contents( $pwNoticeIndexFile );
            }
            $messages = json_decode($jsonData, true);
            foreach ($messages as $message) {
                if ( (int)$message['id'] <= $pwNoticeIndex ) continue;
                $pwNoticeIndex = (int)$message['id'];
                file_put_contents( $pwNoticeIndexFile, $pwNoticeIndex );
                $title = $this->sanitizeMessage( $message['title'] );
                $message = $this->sanitizeMessage( $message['message'] );
                $hcpp->run( 'add-user-notification devstia ' . $title . ' ' . $message);
            }
        }
        public function priv_update_sys_rrd( $args ) {
            $this->check_for_pw_notifications();
            return $args;
        }
        
        /**
         * Sanitize a message string for use in the shell command.
         */
        function sanitizeMessage( $message ) {
            $message = trim( $message );
            $message = str_replace( ["\n", "\r", "\t"], '', $message );
            $message = preg_replace('/\r/', '', $message );
            $message = escapeshellarg( html_entity_decode( $message ) );
            return $message;
        }

        /**
         * On reload of nginx, ensure we listen on 127.0.0.1 interface
         */
        public function hcpp_nginx_reload( $cmd ) {
            
            // Find all nginx.conf and nginx.ssl.conf files in devstia account
            function modify_nginx_conf_file( $filePath ) {
                global $hcpp;
                $lines = file( $filePath );
                $modifiedLines = [];
                foreach ($lines as $line) {
                    $modifiedLines[] = $line;
                    if (strpos( trim( $line ), 'listen') === 0 && strpos( $line, ':') !== false) {

                        // Duplicate the line and find existing IP
                        $ip = $hcpp->delLeftMost( $line, 'listen' );
                        $ip = $hcpp->getLeftMost( $ip, ':' );
                        $ip = trim( $ip );
                        $modifiedLine = str_replace( $ip, '127.0.0.1', $line );
                        $modifiedLines[] = $modifiedLine;
                    }
                }
                file_put_contents( $filePath, implode( '', $modifiedLines ) );
                $hcpp->log( "Modified $filePath for listen 127.0.0.1" );
            }
            function find_nginx_conf_files( $directory ) {
                $files = scandir( $directory );
                foreach ( $files as $file ) {
                    if ( $file === '.' || $file === '..' ) continue;
                    $filePath = $directory . '/' . $file;                    
                    if ( is_dir( $filePath ) ) {
                        find_nginx_conf_files( $filePath );
                    } elseif ( is_file( $filePath ) && ( basename( $file ) === 'nginx.conf' || basename( $file ) === 'nginx.ssl.conf' ) ) {
                        $lines = file( $filePath );
                        $found = false;
                        foreach ( $lines as $line ) {
                            if ( strpos( trim( $line ), 'listen' ) === 0 && strpos( $line, '127.0.0.1:') !== false ) {
                                $found = true;
                                break;
                            }
                        }
                        if ( false == $found ) {
                            modify_nginx_conf_file( $filePath );
                        }
                    }
                }
            }
            $directory = '/home/devstia/conf/web';
            find_nginx_conf_files( $directory );
            return $cmd;
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
                $args = [ 'dev_pw_generate_website_cert', $user ];
                $args = array_merge( $args, $domains );
                $hcpp->log( $hcpp->run( 'invoke-plugin ' . implode( ' ', $args ) ) );    
            }
        }

        // Generate certs on demand
        public function hcpp_invoke_plugin( $args ) {
            if ( $args[0] == 'dev_pw_pass' ) {
                echo shell_exec( 'cat /home/admin/.pwPass' );
            }
            if ( $args[0] == 'dev_pw_generate_master_cert' ) {
                $this->generate_master_cert();
            }
            if ( $args[0] == 'dev_pw_generate_website_cert') {
                $user = $args[1];
                $domains = array();
                for ($i = 2; $i < count($args); $i++) {
                    $domains[] = $args[$i];
                }
                $this->generate_website_cert( $user, $domains );
            }
            if ( $args[0] == 'dev_pw_regenerate_certificates' ) {
                $this->regenerate_certificates();
            }
            if ( $args[0] == 'dev_pw_regenerate_ssh_keys' ) {
                $this->regenerate_ssh_keys();
            }
            if ( $args[0] == 'dev_pw_pma_sso' ) {

                // Renew or expire the token file
                global $hcpp;
                $pma_token_file = '/tmp/pma_token.txt';
                $pma_token = '';
                if ( file_exists( $pma_token_file ) ) {
                    if ( time() - filemtime( $pma_token_file ) > 1800 ) { // 15 minutes
                        unlink( $pma_token_file );
                    }else{
                        touch( $pma_token_file );
                        $pma_token = file_get_contents( $pma_token_file );
                    }
                }

                // Generate a new token if needed
                if ( $pma_token == '' ) {
                    $pma_token = $hcpp->nodeapp->random_chars( 16 );
                    file_put_contents( $pma_token_file, $pma_token );
                    chmod( $pma_token_file, 0640 );
                    chown( $pma_token_file, 'www-data' );
                    chgrp( $pma_token_file, 'www-data' );
                }

                // Get the devstia preview password
                $passwd = trim( shell_exec( 'cat /home/admin/.pwPass' ) );
                $passwd = $this->decrypt( $passwd );

                // Re-encrypt it using the pma_token as the key
                $passwd = $this->encrypt( $passwd, $pma_token );
                $pma_pwpass = '/tmp/pma_pwpass.txt';
                file_put_contents( $pma_pwpass, $passwd );
                chmod( $pma_pwpass, 0640 );
                chown( $pma_pwpass, 'www-data' );
                chgrp( $pma_pwpass, 'www-data' );
                echo $pma_token;
            }
            return $args;
        }

        /**
         * Generate the master certificate for HestiaCP, Devstia Preview. This will overwrite
         * any existing certificate if one already exists; then add it
         * to the system trusted certificates.
         */
        public function generate_master_cert() {

            // Generate the master certificate
            global $hcpp;
            $devcc_folder = '/usr/local/share/ca-certificates/dev.pw';
            $cmd = "rm -rf $devcc_folder && mkdir -p $devcc_folder && cd $devcc_folder && ";
            $cmd .= 'openssl  genrsa -out ./dev.pw.key 2048 2>&1 && ';
            $cmd .= 'openssl req -x509 -new -nodes -key ./dev.pw.key -sha256 -days 825 -out ./dev.pw.crt -subj "/C=US/ST=California/L=San Diego/O=Virtuosoft/OU=Devstia Preview/CN=dev.pw" 2>&1 && ';
            $cmd .= 'update-ca-certificates 2>&1';
            $cmd = $hcpp->do_action( 'dev_pw_generate_master_cert', $cmd );
            $hcpp->log( shell_exec( $cmd ) );
            $this->publish_certs_keys();

            // Generate local.dev.pw for the control panel itself
            $hcpp->log( "Generating local.dev.pw certificate" );
            $this->generate_website_cert( 'admin', [ 'local.dev.pw', 'localhost' ] );

            // Update the Hestia nginx certificate
            $cmd = 'cp /home/admin/conf/web/local.dev.pw/ssl/local.dev.pw.crt /usr/local/hestia/ssl/certificate.crt && ';
            $cmd .= 'cp /home/admin/conf/web/local.dev.pw/ssl/local.dev.pw.key /usr/local/hestia/ssl/certificate.key && ';
            $cmd .= 'service hestia restart';
            $cmd = $hcpp->do_action( 'dev_pw_update_hestia_cert', $cmd );
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

            // Generate the certificate data in a fresh dev_pw_ssl
            $dev_pw_ssl = '/home/' . $user . '/conf/web/' . $domains[0] . '/dev_pw_ssl';
            $cmd = 'rm -rf ' . $dev_pw_ssl . ' && ';
            $cmd .= 'mkdir -p ' . $dev_pw_ssl;
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
            $template .= "organizationalUnitName = Devstia Preview\n";
            $template .= "commonName = $domains[0]\n";
            $template .= "\n";
            $template = $hcpp->do_action( 'dev_pw_generate_website_cert_template', $template );
            file_put_contents( $dev_pw_ssl . '/template.cnf', $template );

            $cmd = 'cd ' . $dev_pw_ssl . ' && ';
            $cmd .= 'openssl genrsa -out ./' . $domains[0] . '.key 2048 && ';
            $cmd .= 'openssl req -new -key ./' . $domains[0] . '.key -out ./' . $domains[0] . ".csr -subj '/CN=" . $domains[0] . "'" . ' -config ./template.cnf && ';
            $cmd .= 'openssl x509 -req -in ./' . $domains[0] . '.csr -CA /usr/local/share/ca-certificates/dev.pw/dev.pw.crt -CAkey /usr/local/share/ca-certificates/dev.pw/dev.pw.key -CAcreateserial -out ./' . $domains[0] . '.crt -days 825 -sha256 -extfile ./template.cnf && ';
            $cmd .= 'cat ./' . $domains[0] . '.key ./' . $domains[0] . '.crt > ./' . $domains[0] . '.pem && ';
            $cmd .= 'chmod -R 644 ./ && ';
            $cmd .= '/usr/local/hestia/bin/v-delete-web-domain-ssl ' . $user . ' ' . $domains[0] . ' "no" ; ';
            $cmd .= '/usr/local/hestia/bin/v-add-web-domain-ssl ' . $user . ' ' . $domains[0] . ' /home/' . $user . '/conf/web/' . $domains[0] . '/dev_pw_ssl && ';
            $cmd .= '/usr/local/hestia/bin/v-add-web-domain-ssl-force ' . $user . ' ' . $domains[0]; 
            $cmd = $hcpp->do_action( 'dev_pw_generate_website_cert', $cmd );
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
                '/home/admin/conf/web/local.dev.pw/ssl/local.dev.pw.crt',
                '/home/admin/conf/web/local.dev.pw/ssl/local.dev.pw.key',
                '/usr/local/share/ca-certificates/dev.pw/dev.pw.crt', 
                '/usr/local/share/ca-certificates/dev.pw/dev.pw.key'
            ];
            foreach ( $caFiles as $file ) {
                if ( ! file_exists( $file ) ) {
                    $this->regenerate_certificates(); // Generate the master and all website certificates.
                    break;
                }
            }
            
            // Generate ssh keypair for devstia, debian
            $sshFiles = [
                '/home/devstia/.ssh/id_rsa',
                '/home/devstia/.ssh/id_rsa.pub',
                '/home/debian/.ssh/id_rsa',
                '/home/debian/.ssh/id_rsa.pub'
            ];
            foreach ( $sshFiles as $file ) {
                if ( ! file_exists( $file ) ) {
                    $this->regenerate_ssh_keys(); // Generate the new ssh keys
                    break;
                }
            }

            // Always copy certs and keys back to the devstia-app server on reboot
            $this->publish_certs_keys();

            // Kickstart kludge to ensure apache2 and nginx startup on reboot
            $kicks = 5;
            do {
                $cmd = '';
                if ( strpos( shell_exec( 'service apache2 status' ), 'Active: active' ) === false ) {
                    $cmd .= 'service apache2 start';
                }
                if ( strpos( shell_exec( 'service nginx status' ), 'Active: active' ) === false ) {
                    $cmd .= ';service nginx start';
                }
                if ( $cmd != '' ) shell_exec( $cmd );
                sleep(1);
                $kicks--;
            } while( $cmd != '' && $kicks > 0 );

            // Check for notifications on reboot
            $this->check_for_pw_notifications();
        }

        /**
         * Generate a new ssh keypair for the devstia and debian users.
         */
        public function regenerate_ssh_keys() {
            global $hcpp;
            $hcpp->log( 'Regenerating ssh keys for debian and devstia...' );

            // debian
            $cmd = 'rm -rf /home/debian/.ssh && mkdir -p /home/debian/.ssh && ';
            $cmd .= 'chown -R debian:debian /home/debian/.ssh && chmod -R 700 /home/debian/.ssh && ';
            $cmd .= 'runuser -l debian -c \'ssh-keygen -t rsa -b 4096 -f /home/debian/.ssh/id_rsa -q -N ""\' && ';
            $cmd .= 'cp -f /home/debian/.ssh/id_rsa.pub /home/debian/.ssh/authorized_keys && ';
            $cmd .= 'chown debian:debian /home/debian/.ssh/authorized_keys && chmod 600 /home/debian/.ssh/authorized_keys && ';

            // devstia
            $cmd .= 'rm -rf /home/devstia/.ssh && mkdir -p /home/devstia/.ssh && ';
            $cmd .= 'chown -R devstia:devstia /home/devstia/.ssh && chmod -R 755 /home/devstia/.ssh && ';
            $cmd .= 'runuser -l devstia -c \'ssh-keygen -t rsa -b 4096 -f /home/devstia/.ssh/id_rsa -q -N ""\' && ';
            $cmd .= 'cp -f /home/devstia/.ssh/id_rsa.pub /home/devstia/.ssh/authorized_keys && ';
            $cmd .= 'chown devstia:devstia /home/devstia/.ssh/authorized_keys && chmod 644 /home/devstia/.ssh/authorized_keys';
            
            $cmd = $hcpp->do_action( 'dev_pw_regenerate_ssh_keys', $cmd );
            shell_exec( $cmd );
            $this->publish_certs_keys();
        }

        /**
         * Regenerate the master and all website certificates.
         */
        public function regenerate_certificates() {
            global $hcpp;
            $hcpp->log( 'Regenerating certificates...' );
            $path = '/home/devstia/conf/web';
            $this->generate_master_cert();
            if ( is_dir($path) ) {
                $directory = new DirectoryIterator( $path );
                foreach ( $directory as $file ) {
                    if ( $file->isDir() && !$file->isDot() ) {
                        $domain = $file->getFilename();
                        $hcpp->log( 'Regenerating certificate for ' . $domain );
                        $detail = $hcpp->run( "list-web-domain devstia $domain json" );
                        if ( $detail != NULL ) {
                            $domains = $detail[$domain]['ALIAS'];
                            $domains = explode(",", $domains);
                            array_unshift( $domains, $domain );
                        }else {
                            $domains = array( $domain );
                        }
                        $this->generate_website_cert( 'devstia', $domains );
                    }
                }
            }
            $cmd = '(service nginx reload) > /dev/null 2>&1 &';
            $cmd = $hcpp->do_action( 'dev_pw_nginx_reload', $cmd );
            shell_exec( $cmd );
        }

        /**
         * Customize our control panel pages. White label, restore convenience
         * features etc.
         */
        public function hcpp_render_body( $args ) {
            // Intercept web edit save, ensure ssl crt/key are not empty; suppressing
            //* the empty error message as we'll generate a certificate on the fly.
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

            // White label anything leftover that says Hestia Control Panel that
            // HestiaCP failed to white label.
            if ( $args['page'] == 'list_services' ) {
                $content = $args['content'];
                $content = str_replace( 'Hestia Control Panel', 'Devstia Preview', $content );
                $args['content'] = $content;
            }

            // Furnish enhanced phpMyAdmin SSO functionality in our localhost environment
            if ( $args['page'] == 'list_db' ) {
                global $hcpp;
                $content = $args['content'];
                $parse = '';

                $pma_token = $hcpp->run( 'invoke-plugin dev_pw_pma_sso' );                
                while( false !== strpos( $content, '//local.dev.pw/phpmyadmin/' ) ) {

                    // Find each phpMyAdmin URL
                    $parse .= $hcpp->getLeftMost( $content, '//local.dev.pw/phpmyadmin/' );
                    $content = $hcpp->delLeftMost( $content, '//local.dev.pw/phpmyadmin/' );

                    // Find the database name
                    $remaining = $hcpp->getLeftMost( $content, '"' );
                    $content = $hcpp->delLeftMost( $content, '"' );
                    $db = '';
                    if ( false !== strpos( $remaining, 'database=' ) ) {
                        $db = $hcpp->delLeftMost( $remaining, 'database=' );
                        $db = $hcpp->getLeftMost( $db, '&' );
                        $db = '&db=' . $db;
                    }

                    // Replace the phpMyAdmin URL with our version that includes our token and db
                    $parse .= '//local.dev.pw/phpmyadmin/?pma_token=' . $pma_token . $db .'"';
                }
                if ( $parse != '' ) {
                    $content = $parse . $content;
                }
                $args['content'] = $content;
            }
            return $args;
        }

        /**
         * Intercept login, check for valid auto-login token (alt), and automatically
         * submit login form if valid.
         */
        public function hcpp_head( $args ) {
            // Tweak our devstia logo and default theme
            $css = '<style>.top-bar-logo img { height:39px; width:64px }';
            if ( strpos( $args['content'], 'dark.min.css' ) !== false ) {
                $css = ".main-menu-item-link.active .main-menu-item-label {";
                $css .= "color:#FF2205}@media (min-width: 768px) {";
                $css .= ".main-menu-item-link.active {border-bottom-color:#FF2205}}";
            }
            $css .= '</style>';
            $args['content'] .= $css;

            // Forward to User menu to QuickStart for devstia user
            if ( isset( $_SESSION['user'] ) && $_SESSION['user'] == 'devstia' ) {
                if ( strpos( $_SERVER['REQUEST_URI'], '/list/user' ) === 0 ) {
                    header('Location: /list/web/?quickstart=main');
                    exit;
                }

                // Hide user menu from devstia user
                $content = $args['content'];
                $content .= '<style>li.main-menu-item:nth-child(1){display:none;}</style>';
                $args['content'] = $content;
            }

            // Check for valid auto-login token
            if ( !isset( $_GET['alt'] ) ) return $args;
            $content = $args['content'];
            if ( strpos( $content, 'LOGIN') === false ) return $args;
            global $hcpp;
            $altContent = trim( $hcpp->run( 'cat /tmp/alt.txt' ) );
            if ( $_GET['alt'] != $altContent ) return $args;

            // Get the dev.pw password
            $passwd = trim( $hcpp->run( 'invoke-plugin dev_pw_pass' ) );
            $passwd = $this->decrypt( $passwd );

            // Inject the auto-login script
            $content .= '<script>';
            $content .= 'document.addEventListener("DOMContentLoaded", function(event) {';
            $content .= '    if (document.getElementById("username") != null && document.getElementById("password") != null) {';
            $content .= '        document.getElementById("username").value="devstia";';
            $content .= '        document.getElementById("password").value="' . $passwd . '";';
            $content .= '        document.getElementsByTagName("button")[0].click();';
            $content .= '        var loginMsg = document.createElement("div");';
            $content .= '        var formLogin = document.getElementById("form_login");';
            $content .= '        formLogin.style.display = "none";';
            $content .= '        loginMsg.innerHTML = "<h1 class=\"login-title\">Welcome to Devstia Preview</h1>Please wait. Automatically logging in...<br><br><br>";';
            $content .= '        formLogin.parentNode.insertBefore(loginMsg, formLogin.nextSibling);';
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
         * @param key string optional value to be used as key
         * @returns string containing decrypted data
         */
        public function decrypt( $data, $key = 'devstia-preview' ) {
            $key = md5( $key );
            $data = explode( ':', $data );
            $encrypted_data = base64_decode( $data[0] );
            $iv = base64_decode( $data[1] );
            $decrypted = openssl_decrypt( $encrypted_data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
            return $decrypted;
        }

        /**
         * Encrypts data using aes-256-cbc algorithm
         * 
         * @param data string to be encrypted
         * @param key string optional value to be used as key
         * @returns string containing decrypted data
         */
        public function encrypt( $data, $key = 'devstia-preview' ) {
            $key = md5( $key );
            $iv = openssl_random_pseudo_bytes(16); // Generate a random IV
        
            $encrypted_data = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            $encoded_encrypted_data = base64_encode($encrypted_data);
            $encoded_iv = base64_encode($iv);
        
            return $encoded_encrypted_data . ':' . $encoded_iv;
        }
        
    }
    new DEV_PW(); 
} 
