<?php
declare(strict_types=1);

/**
 * Requires config.php returning an array:
 * [
 *   'TARGET_BASE' => 'https://example.com',
 *   'TIMEOUT' => 10,
 *   'VERIFY_SSL' => true,
 *   'MAX_BODY_SIZE' => 5 * 1024 * 1024,
 *   'REWRITE_BODY' => true,
 *   'CUSTOM_HEADERS' => [],
 *   'DEBUG' => false,
 * ]
 */

$config = require __DIR__ . '/config.php';
function cfg(string $k, $d = null) { global $config; return $config[$k] ?? $d; }

$proxyBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' . $_SERVER['HTTP_HOST'] : 'http://' . $_SERVER['HTTP_HOST'];
$targetBase = rtrim(cfg('TARGET_BASE'), '/');

// Build target
$path = $_GET['path'] ?? '';
parse_str($_SERVER['QUERY_STRING'] ?? '', $qsarr);
unset($qsarr['path']);
$qs_clean = http_build_query($qsarr);
$target = $targetBase . '/' . ltrim($path, '/');
if ($qs_clean !== '') $target .= '?' . $qs_clean;

// Helper debug
function dbg(string $msg) {
    if (cfg('DEBUG', false)) error_log('[proxy] ' . $msg);
}

/* ---------- Build request headers forwarded to upstream ---------- */
function build_upstream_headers(string $proxyBase, string $targetBase): array {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $out = [];

    // Các header không forward
    $strip = [
        'connection','keep-alive','transfer-encoding','te','trailer',
        'upgrade','proxy-authorization','proxy-authenticate'
    ];

    foreach ($headers as $k => $v) {
        $lk = strtolower($k);
        if (in_array($lk, $strip, true)) continue;

        // Rewrite referer/origin để upstream thấy proxyBase thay vì server gốc
        if (in_array($lk, ['referer','origin'], true)) {
            $v = str_replace(rtrim($targetBase, '/'), rtrim($proxyBase, '/'), $v);
        }

        // Forward cookie nguyên vẹn from client
        if ($lk === 'cookie') {
            // prefer $_SERVER['HTTP_COOKIE'] if present
            $v = $_SERVER['HTTP_COOKIE'] ?? $v;
        }

        // If client lacks Host header or we want upstream to see original target host,
        // it's often better to set Host header to upstream host. But keep default unless necessary.
        $out[] = "$k: $v";
    }

    // ensure Cookie forwarded even if getallheaders didn't provide it
    if (!array_filter($out, fn($h)=>stripos($h, 'Cookie:')===0) && !empty($_SERVER['HTTP_COOKIE'])) {
        $out[] = 'Cookie: ' . $_SERVER['HTTP_COOKIE'];
    }

    // custom headers
    foreach (cfg('CUSTOM_HEADERS', []) as $k => $v) {
        $out[] = "$k: $v";
    }

    return $out;
}

/* ---------- cURL to upstream ---------- */
$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => cfg('TIMEOUT', 10),
    CURLOPT_TIMEOUT => cfg('TIMEOUT', 10),
    CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    CURLOPT_HTTPHEADER => build_upstream_headers($proxyBase, $targetBase),
    CURLOPT_SSL_VERIFYPEER => (bool) cfg('VERIFY_SSL', true),
    CURLOPT_SSL_VERIFYHOST => cfg('VERIFY_SSL', true) ? 2 : 0,
    CURLOPT_ENCODING => '', // accept compressed
]);

// forward body
$bodyIn = file_get_contents('php://input');
if ($bodyIn !== '' && $bodyIn !== false) {
    if (strlen($bodyIn) > cfg('MAX_BODY_SIZE', 5 * 1024 * 1024)) {
        http_response_code(413);
        exit("Request body too large");
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyIn);
}

// Execute
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

http_response_code($statusCode);
dbg("Upstream returned status $statusCode");

/* ---------- Parse headers and emit to client (with rewrites) ---------- */
$lines = preg_split("/\r\n|\n|\r/", trim($rawHeaders));
foreach ($lines as $line) {
    if ($line === '' || preg_match('#^HTTP/\d#i', $line)) continue;
    [$name, $value] = array_map('trim', explode(':', $line, 2));
    $lname = strtolower($name);

    // skip hop-by-hop
    if (in_array($lname, ['connection','keep-alive','transfer-encoding','te','trailer','upgrade','proxy-authenticate','proxy-authorization'])) continue;

    // skip content-encoding to avoid double decoding
    if ($lname === 'content-encoding') continue;
    if ($lname === 'content-length') continue;
    
    // Rewrite Location to proxyBase
    if ($lname === 'location') {
        $value = str_replace($targetBase, $proxyBase, $value);
        header("$name: $value", true);
        continue;
    }

    // Handle Set-Cookie specially: rewrite Domain/SameSite/Secure
    if ($lname === 'set-cookie') {
        // Might be multiple Set-Cookie lines concatenated; preserve each header separately
        // $value contains single Set-Cookie line here
        $modified = rewrite_set_cookie_for_proxy($value, $proxyBase);
        header("Set-Cookie: $modified", false); // allow multiple Set-Cookie
        dbg("Rewrote Set-Cookie: $value -> $modified");
        continue;
    }

    // Default passthrough, but hide upstream base in header values
    $value = str_replace($targetBase, $proxyBase, $value);
    header("$name: $value", false);
}

/* ---------- Optionally rewrite body to hide upstream URL ---------- */
if (cfg('REWRITE_BODY', true)) {
    $bodyOut = str_replace($targetBase, $proxyBase, $bodyOut);
}

echo $bodyOut;

/* ---------- Helper: rewrite Set-Cookie ---------- */
function rewrite_set_cookie_for_proxy(string $headerValue, string $proxyBase): string {
    // Parse cookie name=value and attributes
    // Example: PHPSESSID=abc; Path=/; HttpOnly; SameSite=Strict; Domain=up.example
    $parts = array_map('trim', explode(';', $headerValue));
    if (count($parts) === 0) return $headerValue;

    $nameval = array_shift($parts); // "PHPSESSID=..."
    $attrs = [];
    foreach ($parts as $p) {
        if ($p === '') continue;
        $kv = explode('=', $p, 2);
        $k = trim($kv[0]);
        $v = $kv[1] ?? null;
        $attrs[strtolower($k)] = $v === null ? true : $v;
    }

    // Determine proxy host (host-only cookie is safest)
    $proxyHost = parse_url($proxyBase, PHP_URL_HOST) ?: $_SERVER['HTTP_HOST'];

    // Force host-only cookie (remove Domain) OR explicitly set Domain to proxy host.
    // We'll remove Domain attribute to make cookie host-only (better).
    if (isset($attrs['domain'])) {
        unset($attrs['domain']);
    }

    // Ensure Path exists
    if (!isset($attrs['path'])) $attrs['path'] = '/';

    // If upstream set SameSite=Strict (problematic for cross-site), change to None
    $attrs['samesite'] = 'None';

    // Ensure Secure if proxyBase is https
    $isHttps = (parse_url($proxyBase, PHP_URL_SCHEME) === 'https');
    if ($isHttps) {
        $attrs['secure'] = true;
    } else {
        // If not HTTPS, removing Secure flag (can't add Secure) — warning: SameSite=None requires Secure in modern browsers,
        // so browser may ignore SameSite=None if not secure.
        if (isset($attrs['secure'])) {
            $attrs['secure'] = true; // keep it, but browser will only send on https
        }
    }

    // Rebuild cookie header
    $outParts = [$nameval];
    // Keep HttpOnly if set upstream
    if (isset($attrs['httponly']) && $attrs['httponly'] === true) {
        $outParts[] = 'HttpOnly';
        unset($attrs['httponly']);
    }

    // Add remaining attrs (Path, Expires, Max-Age...)
    foreach ($attrs as $ak => $av) {
        if ($av === true) {
            $outParts[] = ucfirst($ak);
        } else {
            $outParts[] = ucfirst($ak) . '=' . $av;
        }
    }

    // Important: do NOT include Domain attribute so cookie is host-only for proxy.
    return implode('; ', $outParts);
}