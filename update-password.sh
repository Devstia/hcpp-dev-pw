#!/bin/bash
passwd="$1"

# Update HestiaCP admin password
/usr/local/hestia/bin/v-change-user-password admin "$passwd"

# Update HestiaCP devstia password
/usr/local/hestia/bin/v-change-user-password devstia "$passwd"

# Update the system user account password for user "debian"
echo "debian:$passwd" | chpasswd

## Update Samba password for user devstia
(echo "$passwd"; echo "$passwd") | smbpasswd -s -a "devstia"

## Allow us to hook into the password change event from our plugins (WebDAV, etc)
php -r 'require "/usr/local/hestia/web/pluginable.php"; $hcpp->do_action("dev_pw_update_password", "'"$passwd"'");'

## Support advanced phpMyAdmin SSO, Create/update devstia permissions for MySQL/phpMyAdmin
echo "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'devstia'@'localhost';" | mysql
echo "DROP USER 'devstia'@'localhost';" | mysql
echo "CREATE USER 'devstia'@'localhost' IDENTIFIED BY '$passwd';" | mysql
echo "GRANT ALL ON \`devstia\_%\`.* TO devstia@localhost;" | mysql
