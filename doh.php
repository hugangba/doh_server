<?php

define('UPSTREAM_DNS', 'https://dns.google/dns-query');

// ==================== 配置 ====================
define('DB_HOST', 'localhost');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
define('DB_NAME', 'dns_cache');

// ==================== 基础检查 ====================
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    header('HTTP/1.1 403 Forbidden'); exit('HTTPS required');
}
$script = basename($_SERVER['SCRIPT_NAME']);
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
if ($path !== '/' . $script && !str_ends_with($path, '/' . $script)) {
    header('HTTP/1.1 404 Not Found'); exit;
}

// ==================== 获取查询 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_SERVER['CONTENT_TYPE'] ?? '') !== 'application/dns-message') {
        header('HTTP/1.1 415 Unsupported Media Type'); exit;
    }
    $dns_query = file_get_contents('php://input');
} else {
    if (!isset($_GET['dns'])) {
        header('HTTP/1.1 400 Bad Request'); exit('Missing dns parameter');
    }
    $dns_query = base64_decode(strtr($_GET['dns'], '-_', '+/'), true);
}
if (!$dns_query) {
    header('HTTP/1.1 400 Bad Request'); exit('Invalid query');
}

// ==================== 解析域名 ====================
$domain = '';
$pos = 12;
while ($pos < strlen($dns_query) && ($len = ord($dns_query[$pos])) !== 0) {
    $pos++;
    if ($len) $domain .= substr($dns_query, $pos, $len) . '.';
    $pos += $len;
}
$domain = rtrim($domain, '.');
$qtype  = unpack('n', substr($dns_query, $pos + 2, 2))[1];

// ==================== 数据库连接 ====================
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3
    ]);
} catch (Throwable $e) {
    header('HTTP/1.1 503 Database Error'); exit;
}

// ==================== 1. 优先查缓存（有就直接返回，绝不继续执行）===================
$ipv4 = $ipv6 = null;

if (in_array($qtype, [1, 255])) {
    $stmt = $pdo->prepare("SELECT ipv4 FROM dns_records_ipv4 WHERE domain = ?");
    $stmt->execute([$domain]);
    if ($row = $stmt->fetch()) $ipv4 = $row['ipv4'];
}
if (in_array($qtype, [28, 255])) {
    $stmt = $pdo->prepare("SELECT ipv6 FROM dns_records_ipv6 WHERE domain = ?");
    $stmt->execute([$domain]);
    if ($row = $stmt->fetch()) $ipv6 = $row['ipv6'];
}

// 缓存命中 → 直接返回，彻底结束脚本！！
if ($ipv4 !== null || $ipv6 !== null) {
    $resp = $dns_query;
    $resp[2] = "\x81\x80"; // QR=1, AA=1
    $ancount = ($ipv4 ? 1 : 0) + ($ipv6 ? 1 : 0);
    substr_replace($resp, pack('n', $ancount), 6, 2);
    $answer = '';
    if ($ipv4) $answer .= "\xc0\x0c\x00\x01\x00\x01\x00\x00\x0e\x10\x00\x04" . inet_pton($ipv4);
    if ($ipv6) $answer .= "\xc0\x0c\x00\x1c\x00\x01\x00\x00\x0e\x10\x00\x10" . inet_pton($ipv6);
    $resp .= $answer;

    header('Content-Type: application/dns-message');
    header('Cache-Control: no-store');
    echo $resp;
    exit; // 必须 exit！！
}

// ==================== 2. 缓存未命中 → 才开始后续逻辑 ====================

// 高并发防重锁
$lock_file = sys_get_temp_dir() . '/doh_lock_' . hash('sha256', $domain);
$lock_fp   = fopen($lock_file, 'w+');
if (!flock($lock_fp, LOCK_EX)) {
    // 拿不到锁 → 等待 3 秒后返回 SERVFAIL
    sleep(3);
    $fail = $dns_query;
    $fail[2] = "\x81\x82"; // RCODE=2 SERVFAIL
    header('Content-Type: application/dns-message');
    echo $fail;
    exit;
}

// 再次检查（防止等待期间已有记录）
$ipv4 = $ipv6 = null;
if (in_array($qtype, [1, 255])) {
    $stmt = $pdo->prepare("SELECT ipv4 FROM dns_records_ipv4 WHERE domain = ?");
    $stmt->execute([$domain]);
    if ($row = $stmt->fetch()) $ipv4 = $row['ipv4'];
}
if (in_array($qtype, [28, 255])) {
    $stmt = $pdo->prepare("SELECT ipv6 FROM dns_records_ipv6 WHERE domain = ?");
    $stmt->execute([$domain]);
    if ($row = $stmt->fetch()) $ipv6 = $row['ipv6'];
}
if ($ipv4 !== null || $ipv6 !== null) {
    flock($lock_fp, LOCK_UN); fclose($lock_fp); @unlink($lock_file);
    // 直接返回缓存
    $resp = $dns_query;
    $resp[2] = "\x81\x80";
    $ancount = ($ipv4 ? 1 : 0) + ($ipv6 ? 1 : 0);
    substr_replace($resp, pack('n', $ancount), 6, 2);
    $answer = '';
    if ($ipv4) $answer .= "\xc0\x0c\x00\x01\x00\x01\x00\x00\x0e\x10\x00\x04" . inet_pton($ipv4);
    if ($ipv6) $answer .= "\xc0\x0c\x00\x1c\x00\x01\x00\x00\x0e\x10\x00\x10" . inet_pton($ipv6);
    $resp .= $answer;
    header('Content-Type: application/dns-message');
    echo $resp;
    exit;
}

// 尝试向上游查询
$ch = curl_init(UPSTREAM_DNS);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $dns_query,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/dns-message'],
    CURLOPT_TIMEOUT        => 6,
    CURLOPT_CONNECTTIMEOUT => 4,
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 上游失败 → 返回 SERVFAIL
if ($response === false || $http_code !== 200 || strlen($response) < 12) {
    flock($lock_fp, LOCK_UN); fclose($lock_fp); @unlink($lock_file);
    $fail = $dns_query;
    $fail[2] = "\x81\x82"; // SERVFAIL
    header('Content-Type: application/dns-message');
    echo $fail;
    exit;
}

// 成功 → 立即返回给用户
header('Content-Type: application/dns-message');
echo $response;
ob_flush(); flush();

// 解析 IP 并异步写入（只写入一次）
$ipv4_new = $ipv6_new = null;
$offset = 12 + strlen($domain) + 6;
$ancount = unpack('n', substr($response, 6, 2))[1];
for ($i = 0; $i < $ancount && $offset < strlen($response); $i++) {
    if (ord($response[$offset]) >= 192) $offset += 2;
    else { while (ord($response[$offset])) $offset += ord($response[$offset]) + 1; $offset++; }
    $type = unpack('n', substr($response, $offset, 2))[1];
    $offset += 8;
    $rdlen = unpack('n', substr($response, $offset, 2))[1];
    $offset += 2;
    if ($type == 1  && $rdlen == 4  && !$ipv4_new) $ipv4_new = inet_ntop(substr($response, $offset, 4));
    if ($type == 28 && $rdlen == 16 && !$ipv6_new) $ipv6_new = inet_ntop(substr($response, $offset, 16));
    $offset += $rdlen;
}

if ($ipv4_new || $ipv6_new) {
    register_shutdown_function(function () use ($domain, $ipv4_new, $ipv6_new, $lock_file, $lock_fp) {
        try {
            $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $db->beginTransaction();

            if ($ipv4_new) {
                $stmt = $db->prepare("SELECT 1 FROM dns_records_ipv4 WHERE domain = ? LIMIT 1");
                $stmt->execute([$domain]);
                if ($stmt->fetch()) goto skip4;

                $stmt = $db->query("SELECT id FROM dns_id_counter_v4 FOR UPDATE");
                $cur = $stmt->fetchColumn();
                $db->exec("UPDATE dns_id_counter_v4 SET id = id + 1");
                $new_id = $cur + 1;
                $db->prepare("INSERT INTO dns_records_ipv4 (id, domain, ipv4, timestamp) VALUES (?, ?, ?, ?)")
                    ->execute([$new_id, $domain, $ipv4_new, time()]);
                skip4:
            }

            if ($ipv6_new) {
                $stmt = $db->prepare("SELECT 1 FROM dns_records_ipv6 WHERE domain = ? LIMIT 1");
                $stmt->execute([$domain]);
                if ($stmt->fetch()) goto skip6;

                $stmt = $db->query("SELECT id FROM dns_id_counter_v6 FOR UPDATE");
                $cur = $stmt->fetchColumn();
                $db->exec("UPDATE dns_id_counter_v6 SET id = id + 1");
                $new_id = $cur + 1;
                $db->prepare("INSERT INTO dns_records_ipv6 (id, domain, ipv6, timestamp) VALUES (?, ?, ?, ?)")
                    ->execute([$new_id, $domain, $ipv6_new, time()]);
                skip6:
            }

            $db->commit();
        } catch (Throwable $e) {
            error_log("Write failed: " . $e->getMessage());
        } finally {
            if ($lock_fp) {
                @flock($lock_fp, LOCK_UN);
                @fclose($lock_fp);
                @unlink($lock_file);
            }
        }
    });
}
?>
