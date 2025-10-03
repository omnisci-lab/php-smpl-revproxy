<?php
declare(strict_types=1);
$config = require __DIR__ . '/config.php';

function cfg(string $key, $default = null) {
    global $config;
    return $config[$key] ?? $default;
}

function get_request_headers(): array {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $out = [];
    $strip = ['host','connection','keep-alive','transfer-encoding','te','trailer',
              'upgrade','proxy-authorization','proxy-authenticate',
              'referer','origin','cookie','authorization','forwarded','via'];
    foreach ($headers as $k => $v) {
        if (in_array(strtolower($k), $strip, true)) continue;
        $out[] = "$k: $v";
    }
    return $out;
}

// Build target URL
$path = $_GET['path'] ?? '';
parse_str($_SERVER['QUERY_STRING'] ?? '', $qsarr);
unset($qsarr['path']);
$qs_clean = http_build_query($qsarr);
$target = rtrim(cfg('TARGET_BASE'), '/') . '/' . ltrim($path, '/');
if ($qs_clean !== '') $target .= '?' . $qs_clean;

// Prepare cURL
$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => cfg('TIMEOUT'),
    CURLOPT_TIMEOUT => cfg('TIMEOUT'),
    CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    CURLOPT_HTTPHEADER => get_request_headers(),
    CURLOPT_SSL_VERIFYPEER => (bool) cfg('VERIFY_SSL'),
    CURLOPT_SSL_VERIFYHOST => cfg('VERIFY_SSL') ? 2 : 0,
]);

// Forward body
$bodyIn = file_get_contents('php://input');
if ($bodyIn !== '' && $bodyIn !== false) {
    if (strlen($bodyIn) > cfg('MAX_BODY_SIZE')) {
        http_response_code(413);
        exit("Request body too large");
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyIn);
}

$response = curl_exec($ch);
if ($response === false) {
    http_response_code(502);
    exit("Upstream failed: " . curl_error($ch));
}

$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$rawHeaders = substr($response, 0, $headerSize);
$bodyOut = substr($response, $headerSize);
curl_close($ch);

// Base URL của proxy (a.com)
$proxyBase = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

// Gửi status
http_response_code($statusCode);

// Xử lý header
$lines = preg_split("/\r\n|\n|\r/", trim($rawHeaders));
foreach ($lines as $line) {
    if ($line === '' || preg_match('#^HTTP/\d#i', $line)) continue;
    [$name, $value] = array_map('trim', explode(':', $line, 2));
    $lname = strtolower($name);
    if (in_array($lname, ['connection','keep-alive','transfer-encoding','te','trailer','upgrade','proxy-authenticate','proxy-authorization'])) continue;

    if ($lname === 'location') {
        $value = str_replace(rtrim(cfg('TARGET_BASE'), '/'), rtrim($proxyBase, '/'), $value);
    }
    if ($lname === 'set-cookie') {
        $value = preg_replace('/;\s*Domain=[^;]+/i', '', $value);
    }
    header("$name: $value", false);
}

// Rewrite body nếu cần
if (cfg('REWRITE_BODY')) {
    $bodyOut = str_replace(rtrim(cfg('TARGET_BASE'), '/'), rtrim($proxyBase, '/'), $bodyOut);
}

echo $bodyOut;