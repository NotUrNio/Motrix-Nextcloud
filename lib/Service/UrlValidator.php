<?php

declare(strict_types=1);

namespace OCA\Motrix\Service;

use InvalidArgumentException;
use OCP\IConfig;

class UrlValidator {
    public const CONFIG_ALLOW_PRIVATE = 'allow_private_network';

    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    /**
     * Validates a URL or Magnet link against SSRF vulnerabilities.
     *
     * @throws InvalidArgumentException
     */
    public function validate(string $url): void {
        $trimmed = trim($url);
        if ($trimmed === '') {
            throw new InvalidArgumentException('URL cannot be empty');
        }

        if (str_starts_with(strtolower($trimmed), 'magnet:')) {
            $this->validateMagnet($trimmed);
            return;
        }

        $this->validateNetworkUrl($trimmed);
    }

    private function validateMagnet(string $uri): void {
        if (!preg_match('#^magnet:\?#i', $uri)) {
            throw new InvalidArgumentException('Invalid Magnet URI format');
        }
        if (!stripos($uri, 'xt=')) {
            throw new InvalidArgumentException('Magnet URI must contain an xt parameter');
        }
    }

    private function validateNetworkUrl(string $url): void {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Malformed URL');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https', 'ftp'], true)) {
            throw new InvalidArgumentException("Unsupported URL scheme: $scheme. Allowed: http, https, ftp, magnet");
        }

        $host = strtolower($parts['host']);
        if ($host === '') {
            throw new InvalidArgumentException('URL host cannot be empty');
        }

        // Strip IPv6 square brackets if present
        $cleanHost = trim($host, '[]');

        // Check for disallowed hostnames
        if (
            $cleanHost === 'localhost' ||
            str_ends_with($cleanHost, '.localhost') ||
            $cleanHost === 'metadata.google.internal' ||
            $cleanHost === 'instance-data'
        ) {
            throw new InvalidArgumentException("Access to host '$cleanHost' is restricted");
        }

        $allowPrivate = $this->config->getAppValue('motrix', self::CONFIG_ALLOW_PRIVATE, 'no') === 'yes';

        // Collect resolved IP addresses
        $ips = [];
        if (filter_var($cleanHost, FILTER_VALIDATE_IP)) {
            $ips[] = $cleanHost;
        } else {
            $ipv4List = @gethostbynamel($cleanHost);
            if (is_array($ipv4List) && !empty($ipv4List)) {
                $ips = array_merge($ips, $ipv4List);
            }

            // Check for IPv6 DNS records
            if (function_exists('dns_get_record')) {
                $records = @dns_get_record($cleanHost, DNS_AAAA);
                if (is_array($records)) {
                    foreach ($records as $r) {
                        if (!empty($r['ipv6'])) {
                            $ips[] = $r['ipv6'];
                        }
                    }
                }
            }

            if (empty($ips)) {
                throw new InvalidArgumentException("Unable to resolve hostname: $cleanHost");
            }
        }

        foreach ($ips as $ip) {
            $this->validateIpAddress($ip, $allowPrivate);
        }
    }

    private function validateIpAddress(string $ip, bool $allowPrivate): void {
        // Handle IPv4-mapped IPv6 (::ffff:192.168.1.1)
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7);
        }

        $isIpv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isIpv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (!$isIpv4 && !$isIpv6) {
            throw new InvalidArgumentException("Invalid IP address: $ip");
        }

        if ($isIpv4) {
            $this->validateIpv4($ip, $allowPrivate);
        } else {
            $this->validateIpv6($ip, $allowPrivate);
        }
    }

    private function validateIpv4(string $ip, bool $allowPrivate): void {
        $long = ip2long($ip);
        if ($long === false) {
            throw new InvalidArgumentException("Invalid IPv4 address: $ip");
        }

        // Loopback: 127.0.0.0/8 (0x7F000000 to 0x7FFFFFFF)
        if (($long & 0xFF000000) === 0x7F000000) {
            throw new InvalidArgumentException("Loopback address blocked: $ip");
        }

        // 0.0.0.0/8
        if (($long & 0xFF000000) === 0x00000000) {
            throw new InvalidArgumentException("Zero/unspecified address blocked: $ip");
        }

        // Link-local: 169.254.0.0/16
        if (($long & 0xFFFF0000) === 0xA9FE0000) {
            throw new InvalidArgumentException("Link-local / cloud metadata address blocked: $ip");
        }

        // Broadcast / Multicast / Reserved (224.0.0.0/4 and above)
        if (($long & 0xF0000000) === 0xE0000000 || $long === -1) {
            throw new InvalidArgumentException("Multicast/broadcast address blocked: $ip");
        }

        // Private ranges:
        // 10.0.0.0/8 (0x0A000000)
        // 172.16.0.0/12 (0xAC100000 to 0xAC1FFFFF)
        // 192.168.0.0/16 (0xC0A80000)
        $isPrivate = (($long & 0xFF000000) === 0x0A000000)
            || (($long & 0xFFF00000) === 0xAC100000)
            || (($long & 0xFFFF0000) === 0xC0A80000);

        if ($isPrivate && !$allowPrivate) {
            throw new InvalidArgumentException("Private network address blocked: $ip");
        }
    }

    private function validateIpv6(string $ip, bool $allowPrivate): void {
        $packed = inet_pton($ip);
        if ($packed === false) {
            throw new InvalidArgumentException("Invalid IPv6 address: $ip");
        }

        // Loopback ::1
        if ($packed === inet_pton('::1')) {
            throw new InvalidArgumentException("IPv6 loopback address blocked: $ip");
        }

        // Unspecified ::
        if ($packed === inet_pton('::')) {
            throw new InvalidArgumentException("IPv6 unspecified address blocked: $ip");
        }

        // Link-local: fe80::/10
        if ((ord($packed[0]) === 0xfe) && ((ord($packed[1]) & 0xc0) === 0x80)) {
            throw new InvalidArgumentException("IPv6 link-local address blocked: $ip");
        }

        // Unique local (private): fc00::/7 (fc00:: to fdff::)
        if ((ord($packed[0]) & 0xfe) === 0xfc) {
            if (!$allowPrivate) {
                throw new InvalidArgumentException("IPv6 private address blocked: $ip");
            }
        }
    }
}
