#
# variables
#
dir="/var/www/nmsprime"
env="/etc/nmsprime/env/global.env"
pw=$(openssl rand -base64 12) # SQL password for user nmsprime


#
# HTTP
#
# SSL demo certificate
mkdir /etc/httpd/ssl
openssl req -new -x509 -days 365 -nodes -batch -out /etc/httpd/ssl/httpd.pem -keyout /etc/httpd/ssl/httpd.key 

# reload apache config
systemctl start httpd
systemctl enable httpd


#
# firewalld
#
# enable admin interface
firewall-cmd --add-port=8080/tcp --zone=public --permanent
firewall-cmd --reload


#
# mariadb
#
systemctl start mariadb
systemctl enable mariadb

# create mysql db
mysql -u root -e "CREATE DATABASE nmsprime;"

mysql -u root -e "GRANT ALL ON nmsprime.* TO 'nmsprime'@'localhost' IDENTIFIED BY '$pw'";
sed -i "s/^DB_PASSWORD=$/DB_PASSWORD=$pw/" "$env"


#
# Laravel
#
cd $dir
chown -R apache $dir/storage/ $dir/bootstrap/cache/
ln -sr "$env" "$dir/.env" # TODO: force L5 to use global env file - remove this line

# L5 setup
composer install
php artisan key:generate
php artisan migrate
