<?php
declare(strict_types=1);

/**
 * IP Whitelist Middleware
 * Validates that POS requests come from whitelisted IPs
 */

/**
 * Check if the current IP is whitelisted for the given tenant
 * @param array<string> $whitelistedIps  Line-separated IPs from tenant config
 */
function enforceIPWhitelist(array $whitelistedIps): void
{
    $clientIP = getClientIP();
    $allowed = false;

    foreach ($whitelistedIps as $ipRange) {
        $ipRange = trim($ipRange);
        if (empty($ipRange)) continue;

        // Support CIDR notation (e.g., 192.168.1.0/24)
        if (str_contains($ipRange, '/')) {
            $allowed = ipInRange($clientIP, $ipRange);
        } else {
            $allowed = ($clientIP === $ipRange);
        }

        if ($allowed) break;
    }

    if (!$allowed) {
        \Response::forbidden('POS access denied - IP not whitelisted');
    }
}

/**
 * Check if an IP is within a CIDR range
 */
function ipInRange(string $ip, string $range): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;

    [$subnet, $bits] = explode('/', $range, 2);
    $bits = (int) $bits;

    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    $mask = -1 << (32 - $bits);

    return ($ipLong & $mask) === ($subnetLong & $mask);
}
