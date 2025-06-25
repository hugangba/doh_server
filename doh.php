<?php
#PHP 5.3+ and PHP 7.0
// 启用错误显示以便调试（生产环境中应移除）
#ini_set('display_errors', 1);
#ini_set('display_startup_errors', 1);
#error_reporting(E_ALL);

// 配置项
define('UPSTREAM_DNS', 'https://1.1.1.1/dns-query'); // 上游DNS服务器
define('DOH_PATH', '/doh.php'); // 预期访问路径
$ALLOWED_METHODS = array('GET', 'POST'); // 支持的方法（兼容旧PHP版本）

// 调试头：确认脚本是否执行
header('X-DOH-Debug: Script-Executed');

// 检查是否以/doh.php结尾
$request_uri = $_SERVER['REQUEST_URI'];
$uri_path = parse_url($request_uri, PHP_URL_PATH);
header('X-DOH-Request-URI: ' . $request_uri); // 调试：返回实际请求URI
if ($uri_path !== DOH_PATH && substr($uri_path, -strlen(DOH_PATH)) !== DOH_PATH) {
    header('HTTP/1.1 404 Not Found');
    exit('404 Not Found');
}

// 检查请求方法
if (!in_array($_SERVER['REQUEST_METHOD'], $ALLOWED_METHODS)) {
    header('HTTP/1.1 405 Method Not Allowed');
    header('Allow: GET, POST');
    exit('Method Not Allowed');
}

// 处理DNS查询
$dns_query = null;
$content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : 'application/dns-message';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // POST请求：检查Content-Type并获取DNS查询数据
    if ($content_type !== 'application/dns-message') {
        header('HTTP/1.1 415 Unsupported Media Type');
        exit('Unsupported Media Type');
    }
    $dns_query = file_get_contents('php://input');
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // GET请求：从dns参数获取base64url编码的DNS消息
    if (!isset($_GET['dns'])) {
        header('HTTP/1.1 400 Bad Request');
        exit('Missing dns parameter');
    }
    // base64url解码
    $dns_query = base64_decode(str_replace(array('-', '_'), array('+', '/'), $_GET['dns']));
}

if (empty($dns_query) || $dns_query === false) {
    header('HTTP/1.1 400 Bad Request');
    exit('Empty or Invalid DNS Query');
}

// 初始化cURL
$ch = curl_init();
curl_setopt_array($ch, array(
    CURLOPT_URL => UPSTREAM_DNS,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $dns_query,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/dns-message',
        'Accept: application/dns-message'
    ),
    CURLOPT_TIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2
));

// 执行请求
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 处理响应
if ($response === false || $http_code !== 200) {
    header('HTTP/1.1 502 Bad Gateway');
    exit('Upstream DNS Error');
}

// 设置响应头 (RFC8484)
header('Content-Type: application/dns-message');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Content-Length: ' . strlen($response));

// 输出响应
echo $response;

?>
