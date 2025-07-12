<?php
// 配置项
define('UPSTREAM_DNS', 'https://dns.google/dns-query'); // 上游DNS服务器（Google DoH）
define('TTL_THRESHOLD', 3600); // 1小时（秒）
$ALLOWED_METHODS = array('GET', 'POST'); // 支持的方法

// MySQL数据库配置
define('DB_HOST', 'localhost');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
define('DB_NAME', 'dns_cache');
define('DB_TABLE_IPV4', 'dns_records_ipv4');
define('DB_TABLE_IPV6', 'dns_records_ipv6');

// 构建DNS查询数据包
function buildDnsQuery($domain, $qtype) {
    $id = random_bytes(2); // 随机ID
    $header = $id . "\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00"; // 标准查询头部
    $qname = '';
    $labels = explode('.', $domain);
    foreach ($labels as $label) {
        $qname .= chr(strlen($label)) . $label;
    }
    $qname .= "\x00";
    $qtype = pack('n', $qtype); // 查询类型
    $qclass = "\x00\x01"; // 类 IN
    return $header . $qname . $qtype . $qclass;
}

// 查询上游DNS
function queryUpstreamDns($dns_query, $domain) {
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => UPSTREAM_DNS,
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => $dns_query,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/dns-message',
            'Accept: application/dns-message'
        ),
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => 1,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS
    ));

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $http_code !== 200) {
        error_log('Upstream DNS Error for domain: ' . $domain . ', HTTP code: ' . $http_code);
        return null;
    }

    // 解析DNS响应
    $ips = array('ipv4' => null, 'ipv6' => null);
    $offset = 12; // 跳过头部
    $offset += strlen($domain) + 2 + 4; // 跳过QNAME、QTYPE、QCLASS
    $answer_count = unpack('n', substr($response, 6, 2))[1];
    if ($answer_count > 0) {
        while ($offset < strlen($response)) {
            if (ord($response[$offset]) >= 192) {
                $offset += 2;
            } else {
                while (ord($response[$offset]) !== 0) {
                    $offset += ord($response[$offset]) + 1;
                }
                $offset++;
            }
            $type = unpack('n', substr($response, $offset, 2))[1];
            $offset += 4;
            $ttl = unpack('N', substr($response, $offset, 4))[1];
            $offset += 4;
            $data_len = unpack('n', substr($response, $offset, 2))[1];
            $offset += 2;
            if ($type == 1 && $data_len == 4 && $ips['ipv4'] === null) {
                $ips['ipv4'] = inet_ntop(substr($response, $offset, 4));
            } elseif ($type == 28 && $data_len == 16 && $ips['ipv6'] === null) {
                $ips['ipv6'] = inet_ntop(substr($response, $offset, 16));
            }
            $offset += $data_len;
            if ($ips['ipv4'] !== null && $ips['ipv6'] !== null) {
                break;
            }
        }
    }
    return $ips;
}

// 确保使用HTTPS
if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
    header('HTTP/1.1 403 Forbidden');
    exit('HTTPS is required for DoH');
}

// 获取当前脚本文件名
$script_name = basename($_SERVER['SCRIPT_NAME']);
$request_uri = $_SERVER['REQUEST_URI'];
$uri_path = parse_url($request_uri, PHP_URL_PATH);

// 检查请求路径是否匹配当前脚本文件名
if ($uri_path !== '/' . $script_name && substr($uri_path, -strlen($script_name) - 1) !== '/' . $script_name) {
    header('HTTP/1.1 404 Not Found');
    exit('404 Not Found');
}

// 检查请求方法
if (!in_array($_SERVER['REQUEST_METHOD'], $ALLOWED_METHODS)) {
    header('HTTP/1.1 405 Method Not Allowed');
    header('Allow: GET, POST');
    exit('Method Not Allowed');
}

// 连接到 MySQL 8.0 数据库
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ));
    $pdo->exec('SET SESSION sql_mode = "STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION"');
} catch (PDOException $e) {
    error_log('Database Connection Failed: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    exit('Database Connection Failed');
}

// 处理手动指定的域名更新
if (isset($_GET['domain']) && !empty($_GET['domain'])) {
    $domain = trim($_GET['domain']);
    $current_time = time();

    // 检查域名格式（简单验证）
    if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $domain)) {
        header('HTTP/1.1 400 Bad Request');
        exit('Invalid domain format');
    }

    // 查询数据库确认记录存在
    $ips = array('ipv4' => null, 'ipv6' => null);
    try {
        $stmt = $pdo->prepare('SELECT ipv4 FROM ' . DB_TABLE_IPV4 . ' WHERE domain = ?');
        $stmt->execute(array($domain));
        $cached = $stmt->fetch();
        if ($cached) {
            $ips['ipv4'] = $cached['ipv4'];
        }

        $stmt = $pdo->prepare('SELECT ipv6 FROM ' . DB_TABLE_IPV6 . ' WHERE domain = ?');
        $stmt->execute(array($domain));
        $cached = $stmt->fetch();
        if ($cached) {
            $ips['ipv6'] = $cached['ipv6'];
        }
    } catch (PDOException $e) {
        error_log('Failed to check domain in database: ' . $e->getMessage());
        header('HTTP/1.1 500 Internal Server Error');
        exit('Database Query Failed');
    }

    // 如果记录存在，查询上游DNS并更新
    if ($ips['ipv4'] !== null || $ips['ipv6'] !== null) {
        // 查询IPv4
        if ($ips['ipv4'] !== null) {
            $dns_query = buildDnsQuery($domain, 1); // 类型 1 = A
            $new_ip = queryUpstreamDns($dns_query, $domain);
            if ($new_ip && $new_ip['ipv4']) {
                try {
                    $stmt = $pdo->prepare('UPDATE ' . DB_TABLE_IPV4 . ' SET ipv4 = ?, timestamp = ? WHERE domain = ?');
                    $stmt->execute(array($new_ip['ipv4'], $current_time, $domain));
                } catch (PDOException $e) {
                    error_log('Failed to update IPv4 for domain: ' . $domain . ', Error: ' . $e->getMessage());
                }
            }
        }

        // 查询IPv6
        if ($ips['ipv6'] !== null) {
            $dns_query = buildDnsQuery($domain, 28); // 类型 28 = AAAA
            $new_ip = queryUpstreamDns($dns_query, $domain);
            if ($new_ip && $new_ip['ipv6']) {
                try {
                    $stmt = $pdo->prepare('UPDATE ' . DB_TABLE_IPV6 . ' SET ipv6 = ?, timestamp = ? WHERE domain = ?');
                    $stmt->execute(array($new_ip['ipv6'], $current_time, $domain));
                } catch (PDOException $e) {
                    error_log('Failed to update IPv6 for domain: ' . $domain . ', Error: ' . $e->getMessage());
                }
            }
        }
    }

    // 返回简单响应
    header('Content-Type: text/plain');
    echo 'Domain update triggered for: ' . htmlspecialchars($domain);
    exit;
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

// 从DNS查询数据中提取域名和查询类型
$domain = '';
$pos = 12; // 跳过DNS头部（12字节）
$domain_parts = array();
while ($pos < strlen($dns_query) && ord($dns_query[$pos]) !== 0) {
    $len = ord($dns_query[$pos]);
    $pos++;
    if ($len > 0) {
        $domain_parts[] = substr($dns_query, $pos, $len);
        $pos += $len;
    }
}
$domain = implode('.', $domain_parts);
$qtype = unpack('n', substr($dns_query, $pos + 2, 2))[1]; // 查询类型（1=A, 28=AAAA）

// 检查数据库中的DNS记录
$ips = array('ipv4' => null, 'ipv6' => null);
$timestamps = array('ipv4' => null, 'ipv6' => null);
$current_time = time();

// 查询IPv4
if ($qtype == 1 || $qtype == 255) { // A记录或ANY
    $stmt = $pdo->prepare('SELECT ipv4, timestamp FROM ' . DB_TABLE_IPV4 . ' WHERE domain = ?');
    $stmt->execute(array($domain));
    $cached = $stmt->fetch();
    if ($cached) {
        $ips['ipv4'] = $cached['ipv4'];
        $timestamps['ipv4'] = $cached['timestamp'];
    }
}

// 查询IPv6
if ($qtype == 28 || $qtype == 255) { // AAAA记录或ANY
    $stmt = $pdo->prepare('SELECT ipv6, timestamp FROM ' . DB_TABLE_IPV6 . ' WHERE domain = ?');
    $stmt->execute(array($domain));
    $cached = $stmt->fetch();
    if ($cached) {
        $ips['ipv6'] = $cached['ipv6'];
        $timestamps['ipv6'] = $cached['timestamp'];
    }
}

// 检查是否需要更新IP地址（时间戳过期或记录不存在）
$needs_update = false;
if ($ips['ipv4'] !== null && ($timestamps['ipv4'] === null || $current_time - $timestamps['ipv4'] > TTL_THRESHOLD)) {
    $needs_update = true;
}
if ($ips['ipv6'] !== null && ($timestamps['ipv6'] === null || $current_time - $timestamps['ipv6'] > TTL_THRESHOLD)) {
    $needs_update = true;
}

// 如果缓存有效，直接构建DNS响应
if (!$needs_update && ($ips['ipv4'] || $ips['ipv6'])) {
    $response = $dns_query; // 复制查询头部
    $response[2] = "\x81\x80"; // 设置响应标志（QR=1）
    $answer_count = ($ips['ipv4'] ? 1 : 0) + ($ips['ipv6'] ? 1 : 0);
    $response[6] = pack('n', $answer_count); // Answer RRs
    $response[8] = "\x00\x00"; // Authority RRs = 0
    $response[10] = "\x00\x00"; // Additional RRs = 0
    $answer = '';
    if ($ips['ipv4']) {
        $answer .= "\xc0\x0c"; // 指向查询中的域名
        $answer .= "\x00\x01"; // 类型 A
        $answer .= "\x00\x01"; // 类 IN
        $answer .= "\x00\x00\x0e\x10"; // TTL 3600秒
        $answer .= "\x00\x04"; // 数据长度 4字节
        $answer .= inet_pton($ips['ipv4']); // IPv4地址
    }
    if ($ips['ipv6']) {
        $answer .= "\xc0\x0c"; // 指向查询中的域名
        $answer .= "\x00\x1c"; // 类型 AAAA
        $answer .= "\x00\x01"; // 类 IN
        $answer .= "\x00\x00\x0e\x10"; // TTL 3600秒
        $answer .= "\x00\x10"; // 数据长度 16字节
        $answer .= inet_pton($ips['ipv6']); // IPv6地址
    }
    $response .= $answer;

    header('Content-Type: application/dns-message');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Content-Length: ' . strlen($response));
    echo $response;
    exit;
}

// 查询上游DNS
$ch = curl_init();
curl_setopt_array($ch, array(
    CURLOPT_URL => UPSTREAM_DNS,
    CURLOPT_POST => 1,
    CURLOPT_POSTFIELDS => $dns_query,
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/dns-message',
        'Accept: application/dns-message'
    ),
    CURLOPT_TIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => 1,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS
));

// 执行上游DNS请求
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 处理上游DNS响应
if ($response === false || $http_code !== 200) {
    error_log('Upstream DNS Error for domain: ' . $domain . ', HTTP code: ' . $http_code);
    header('HTTP/1.1 502 Bad Gateway');
    exit('Upstream DNS Error');
}

// 解析DNS响应以提取IP地址（只取第一个A和AAAA记录）
$ipv4 = null;
$ipv6 = null;
$offset = 12; // 跳过头部
$offset += strlen($domain) + 2 + 4; // 跳过QNAME、QTYPE、QCLASS
$answer_count = unpack('n', substr($response, 6, 2))[1]; // 获取答案数量
if ($answer_count > 0) {
    while ($offset < strlen($response)) {
        if (ord($response[$offset]) >= 192) { // 压缩指针
            $offset += 2;
        } else {
            while (ord($response[$offset]) !== 0) {
                $offset += ord($response[$offset]) + 1;
            }
            $offset++;
        }
        $type = unpack('n', substr($response, $offset, 2))[1];
        $offset += 4; // 跳过TYPE和CLASS
        $ttl = unpack('N', substr($response, $offset, 4))[1];
        $offset += 4;
        $data_len = unpack('n', substr($response, $offset, 2))[1];
        $offset += 2;
        if ($type == 1 && $data_len == 4 && $ipv4 === null) { // A记录，IPv4
            $ipv4 = inet_ntop(substr($response, $offset, 4));
        } elseif ($type == 28 && $data_len == 16 && $ipv6 === null) { // AAAA记录，IPv6
            $ipv6 = inet_ntop(substr($response, $offset, 16));
        }
        $offset += $data_len;
        if ($ipv4 !== null && $ipv6 !== null) {
            break;
        }
    }
}

// 更新数据库中的IP地址和时间戳（仅更新，不新增）
try {
    if ($ipv4 && $ips['ipv4'] !== null) {
        $stmt = $pdo->prepare('UPDATE ' . DB_TABLE_IPV4 . ' SET ipv4 = ?, timestamp = ? WHERE domain = ?');
        $stmt->execute(array($ipv4, $current_time, $domain));
    }
    if ($ipv6 && $ips['ipv6'] !== null) {
        $stmt = $pdo->prepare('UPDATE ' . DB_TABLE_IPV6 . ' SET ipv6 = ?, timestamp = ? WHERE domain = ?');
        $stmt->execute(array($ipv6, $current_time, $domain));
    }
} catch (PDOException $e) {
    error_log('Failed to update DNS response in database: ' . $e->getMessage());
}

// 设置响应头 (RFC8484)
header('Content-Type: application/dns-message');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Content-Length: ' . strlen($response));

// 输出完整DNS响应
echo $response;
?>
