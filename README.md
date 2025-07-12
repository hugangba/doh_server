DoH DNS Server
A PHP-based DNS over HTTPS (DoH) server that queries DNS records via Google DoH and caches results in a MySQL database. It supports manual updates of IPv4 and IPv6 records for specified domains without adding or deleting records.
Features

DoH Query Handling: Processes DNS queries via GET or POST requests, compliant with RFC8484.
Database Caching: Stores IPv4 and IPv6 records in MySQL, with caching based on a 1-hour TTL (3600 seconds).
Manual Domain Updates: Updates existing records for a specified domain using GET /dns_update.php?domain=example.com.
Production-Ready: Optimized for production with minimal logging and no debug output.
Compatibility: Supports PHP 5.6 and MySQL 8.0, using charset=utf8 to avoid compatibility issues.

Prerequisites

PHP: Version 5.6 (Note: PHP 5.6 is end-of-life; consider upgrading to PHP 7.4 or 8.x for security and compatibility).
MySQL: Version 8.0 or compatible, with a user configured for mysql_native_password authentication.
Web Server: Nginx or Apache with HTTPS enabled (TLS certificate required).
PHP Extensions: pdo_mysql, curl, and openssl must be enabled.
File Permissions: Web server must have read access to the PHP script and write access to the error log path.

Installation

Clone or Copy the Code:

Copy dns_update.php to your web server directory (e.g., /www/wwwroot/dns_update.php).
Set file permissions to 644:chmod 644 /www/wwwroot/dns_update.php




Configure MySQL Database:

Create a database (e.g., m6760_dnsphp) and two tables: dns_records_ipv4 and dns_records_ipv6.
Table schema:CREATE TABLE dns_records_ipv4 (
    domain VARCHAR(255) NOT NULL PRIMARY KEY,
    ipv4 VARCHAR(45) NOT NULL,
    timestamp BIGINT NOT NULL
);
CREATE TABLE dns_records_ipv6 (
    domain VARCHAR(255) NOT NULL PRIMARY KEY,
    ipv6 VARCHAR(45) NOT NULL,
    timestamp BIGINT NOT NULL
);


Insert initial records for domains you want to cache (the script does not add new records).
Configure the MySQL user with mysql_native_password to ensure PHP 5.6 compatibility:ALTER USER 'm6760_dnsphp'@'%' IDENTIFIED WITH mysql_native_password BY 'passwd';




Configure Web Server:

Ensure HTTPS is enabled (the script enforces HTTPS).
Example Nginx configuration:server {
    listen 443 ssl;
    server_name yourdomain.com;
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;
    root /www/wwwroot;
    location /dns_update.php {
        fastcgi_pass unix:/var/run/php-cgi-56.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}


Restart the web server after configuration.


Configure PHP Error Logging:

Edit php.ini to set the error log path:error_log = /var/log/php_errors.log


Ensure the web server has write access to the log file:touch /var/log/php_errors.log
chmod 664 /var/log/php_errors.log
chown www-data:www-data /var/log/php_errors.log





Usage
Manual Domain Update

Update a specific domain's IPv4 and/or IPv6 records:GET https://yourdomain.com/dns_update.php?domain=google.com


Response: A plain text message (e.g., Domain update triggered for: google.com).
The script checks if the domain exists in dns_records_ipv4 or dns_records_ipv6, queries Google DoH for A and AAAA records, and updates the corresponding ipv4 or ipv6 and timestamp fields.

DNS Query

Perform a DoH query via GET or POST:
GET:GET https://yourdomain.com/dns_update.php?dns=AAABAAABAAAAAAAAA3d3dwdleGFtcGxlA2NvbQAAAQAB


POST:POST https://yourdomain.com/dns_update.php
Content-Type: application/dns-message
Body: <binary DNS query>




The script checks the database for cached records. If the cache is valid (timestamp within 1 hour), it returns the cached response. Otherwise, it queries Google DoH and updates existing records.

Database Schema

dns_records_ipv4:
domain (VARCHAR(255), PRIMARY KEY): Domain name (e.g., google.com).
ipv4 (VARCHAR(45)): IPv4 address (e.g., 142.250.190.14).
timestamp (BIGINT): Unix timestamp of the last update.


dns_records_ipv6:
domain (VARCHAR(255), PRIMARY KEY): Domain name (e.g., google.com).
ipv6 (VARCHAR(45)): IPv6 address (e.g., 2607:f8b0:4004:80a::200e).
timestamp (BIGINT): Unix timestamp of the last update.



Note: The script only updates existing records. Pre-populate the tables with the domains you want to manage.
Notes

PHP 5.6 Compatibility: The script is designed for PHP 5.6 due to existing environment constraints. PHP 5.6 is end-of-life and may have security risks. Upgrade to PHP 7.4 or 8.x if possible.
MySQL 8.0 Compatibility: Uses charset=utf8 to avoid charset (255) unknown errors. If MySQL uses caching_sha2_password, configure the user with mysql_native_password:ALTER USER 'm6760_dnsphp'@'%' IDENTIFIED WITH mysql_native_password BY 'passwd';


SSL Requirement: The script enforces HTTPS. Ensure your web server has a valid TLS certificate.
Security:
Avoid hardcoding DB_PASS. Use environment variables or a secure configuration file.
Restrict access to dns_update.php using web server rules (e.g., Nginx allow/deny).


Error Logging: Monitor /var/log/php_errors.log for database connection issues, upstream DNS errors, or update failures.

Troubleshooting

Database Connection Errors:
Check DB_HOST, DB_USER, DB_PASS, and DB_NAME in dns_update.php.
Verify MySQL user authentication (mysql_native_password).
Ensure the MySQL server allows non-SSL connections if needed.


Upstream DNS Errors:
Confirm https://dns.google/dns-query is accessible and not blocked.
Check cURL and OpenSSL extensions in PHP.


No Updates Performed:
Ensure the domain exists in dns_records_ipv4 or dns_records_ipv6.
Verify the domain format is valid (e.g., google.com, not http://google.com).



License
MIT License. See LICENSE for details.
Contact
For issues or feature requests, contact the administrator or open an issue in the repository.
