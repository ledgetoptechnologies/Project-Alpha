<?php
// src/utils/client_ip.php
// Determine the real client IP when the app runs behind a reverse proxy.
// Only trusts forwarding headers when the immediate connection (REMOTE_ADDR)
// comes from a specifically configured reverse proxy.

function is_trusted_proxy(string $ip): bool {
    if ($ip === '') { return false; }

    // Explicitly configured trusted proxies (comma/space separated CIDRs or IPs)
    $configured = getenv('TRUSTED_PROXIES');
    if ($configured !== false && $configured !== '') {
        foreach (preg_split('/[,\s]+/', $configured, -1, PREG_SPLIT_NO_EMPTY) as $trusted) {
            if (ip_in_cidr($ip, $trusted)) {
                return true;
            }
        }
    }

    return false;
}

function ip_in_cidr(string $ip, string $cidr): bool {
    if (str_contains($ip, ':')) {
        return ipv6_in_cidr($ip, $cidr);
    }
    if (!str_contains($cidr, '/')) {
        return $ip === $cidr;
    }
    [$subnet, $mask] = explode('/', $cidr, 2);
    $mask = (int)$mask;
    if ($mask < 0 || $mask > 32) { return false; }
    $ipBin = ip2long($ip);
    $subnetBin = ip2long($subnet);
    if ($ipBin === false || $subnetBin === false) { return false; }
    $maskBin = $mask === 0 ? 0 : (~0 << (32 - $mask)) & 0xFFFFFFFF;
    return ($ipBin & $maskBin) === ($subnetBin & $maskBin);
}

function ipv6_in_cidr(string $ip, string $cidr): bool {
    if (!str_contains($cidr, '/')) {
        // Allow compressed/normalized comparison
        return inet_pton($ip) === inet_pton($cidr);
    }
    [$subnet, $mask] = explode('/', $cidr, 2);
    $mask = (int)$mask;
    if ($mask < 0 || $mask > 128) { return false; }
    $ipBin = inet_pton($ip);
    $subnetBin = inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false) { return false; }
    // Compare only the first $mask bits
    $bytes = (int)floor($mask / 8);
    $remainder = $mask % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
        return false;
    }
    if ($remainder > 0 && isset($ipBin[$bytes]) && isset($subnetBin[$bytes])) {
        $shift = 8 - $remainder;
        return (ord($ipBin[$bytes]) >> $shift) === (ord($subnetBin[$bytes]) >> $shift);
    }
    return true;
}

/**
 * Return the best-effort client IP.
 * - If REMOTE_ADDR is not a trusted proxy, return REMOTE_ADDR.
 * - If it is trusted, inspect only X-Forwarded-For. The edge proxy must append
 *   the actual peer address and strip other client-supplied identity headers.
 * - Parse from the proxy side (right to left) so a client-prepended address
 *   cannot override the actual peer that the proxy appended.
 */
function get_client_ip(): string {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_trusted_proxy($remoteAddr)) {
        return $remoteAddr !== '' ? $remoteAddr : 'unknown';
    }

    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        $ips = array_map('trim', explode(',', $forwarded));
        foreach (array_reverse($ips) as $candidate) {
            if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $remoteAddr;
            }
            if (!is_trusted_proxy($candidate)) {
                return $candidate;
            }
        }
        // A chain of configured proxies contains no verified client address.
        return $remoteAddr;
    }

    return $remoteAddr !== '' ? $remoteAddr : 'unknown';
}
