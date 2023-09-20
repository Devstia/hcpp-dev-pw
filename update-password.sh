#!/bin/bash
passwd="$1"

# Update HestiaCP admin password
/usr/local/hestia/bin/v-change-user-password admin "$passwd"

# Update HestiaCP pws password
/usr/local/hestia/bin/v-change-user-password pws "$passwd"

# Update the system user account password for user "debian"
echo "debian:$passwd" | chpasswd

## Update Samba password for user pws
(echo "$passwd"; echo "$passwd") | smbpasswd -s -a "pws"

## Allow us to hook into the password change event from our plugins (WebDAV, etc)
php -r 'require "/usr/local/hestia/web/pluginable.php"; $hcpp->do_action("cg_pws_update_password", "'"$passwd"'");'

## Create/update pws permissions for MySQL/phpMyAdmin
echo "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'pws'@'localhost';" | mysql
echo "DROP USER 'pws'@'localhost';" | mysql
echo "CREATE USER 'pws'@'localhost' IDENTIFIED BY '$passwd';" | mysql
echo "GRANT ALL ON \`pws\_%\`.* TO pws@localhost;" | mysql
