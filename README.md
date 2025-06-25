# doh_server
DNS-over-HTTPS (DoH) Service
This project implements a DNS-over-HTTPS (DoH) service using PHP, compliant with RFC8484. It forwards DNS queries to an upstream DoH server (default: Cloudflare's 1.1.1.1) and supports both GET and POST methods. The service is accessible via a specific endpoint (/doh.php) and is designed for secure, privacy-focused DNS resolution.
Features

RFC8484 Compliance: Supports GET and POST requests with application/dns-message Content-Type.
Custom Endpoint: Accessible only via /doh.php.
Upstream DNS: Configurable upstream DoH server (default: https://1.1.1.1/dns-query).
Security: Restricts access to the specified path and validates request methods and Content-Type.
Compatibility: Works with older PHP versions (5.3+).

Requirements

Web server (e.g., Apache, Nginx) with PHP support.
PHP 5.3 or higher with the cURL extension enabled.
HTTPS-enabled server (required for RFC8484 compliance).
Write permissions for the web server user in the deployment directory.

Installation

Clone or Download the Repository:
git clone <repository-url> /path/to/your/project

Or download and extract the project files.




Configure PHP:

Verify the cURL extension is enabled:php -m | grep curl


If open_basedir is enabled in php.ini, ensure the document root is included or disable it for testing:open_basedir = none


Restart the web server after changes:systemctl restart httpd  # or apache2




Verify HTTPS:

Ensure your server has a valid SSL/TLS certificate (e.g., via Let's Encrypt).
Test HTTPS access: https://your-domain.com/doh.php.



Configuration
Edit doh.php to customize settings:

Upstream DNS: Change UPSTREAM_DNS to another DoH server (e.g., https://dns.google/dns-query).define('UPSTREAM_DNS', 'https://dns.google/dns-query');


Path: The endpoint is fixed at /doh.php. Modify DOH_PATH if you rename the file.define('DOH_PATH', '/doh.php');



Usage
Testing with cURL

GET Request:Send a base64url-encoded DNS query (e.g., for www.example.com A record):
curl -v -H "Accept: application/dns-message" "https://your-domain.com/doh.php?dns=AAABAAABAAAAAAAAA3d3dwdleGFtcGxlA2NvbQAAAQAB"

Expected response:
HTTP/1.1 200 OK
Content-Type: application/dns-message
Cache-Control: no-cache, no-store, must-revalidate
Content-Length: <length>
<binary DNS response>


POST Request:Send a binary DNS query:
curl -v -X POST -H "Content-Type: application/dns-message" -H "Accept: application/dns-message" --data-binary @dns_query.bin "https://your-domain.com/doh.php"

(Replace dns_query.bin with a file containing a binary DNS query.)


Using with DoH Clients
Configure a DoH-compatible client (e.g., Firefox, Cloudflare's 1.1.1.1 app) to use https://your-domain.com/doh.php as the DoH endpoint.
Debugging

Check Script Execution:
Look for the X-DOH-Debug: Script-Executed header in cURL responses.
Check X-DOH-Request-URI to verify the requested path.


PHP Errors:
Temporarily enable error display in doh.php:ini_set('display_errors', 1);
error_reporting(E_ALL);


Check logs: /var/log/httpd/error_log or /var/log/php_errors.log.


Test Upstream DNS:curl -v -H "Accept: application/dns-message" "https://1.1.1.1/dns-query?dns=AAABAAABAAAAAAAAA3d3dwdleGFtcGxlA2NvbQAAAQAB"


Verify PHP:Create test.php with <?php phpinfo(); ?> and access https://your-domain.com/test.php.

Troubleshooting

404 Not Found:
Verify doh.php exists at /www/wwwroot/your-domain/doh.php.
Check Apache’s document root and .htaccess rules.


cURL Errors:
If open_basedir restricts cURL, adjust php.ini or ensure the document root is allowed.


Content-Type: text/html:
Indicates PHP isn’t executing. Confirm .php files are handled by PHP in Apache config:<FilesMatch \.php$>
    SetHandler application/x-httpd-php
</FilesMatch>





Limitations

Designed for basic DoH forwarding; lacks advanced features like caching or rate limiting.
Tested with PHP 5.3+ and Apache; other environments may require adjustments.
Older cURL versions (e.g., 7.29.0) may produce warnings but should work.

Contributing
Contributions are welcome! Submit issues or pull requests to improve functionality or compatibility.
License
This project is licensed under the MIT License.
