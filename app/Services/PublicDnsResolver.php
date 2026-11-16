<?php

namespace App\Services;

/**
 * Resolusi DNS dan validasi alamat IP publik untuk mencegah serangan SSRF dan DNS rebinding.
 */
class PublicDnsResolver
{
    /**
     * Resolusi seluruh alamat IPv4 dan IPv6 dari host target.
     *
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $normalized = strtolower(trim($host, " \t\n\r\0\x0B."));

        if ($normalized === '') {
            return [];
        }

        if (filter_var($normalized, FILTER_VALIDATE_IP) !== false) {
            return [$normalized];
        }

        $ips = [];
        $records = @dns_get_record($normalized, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                $candidate = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    $ips[] = $candidate;
                }
            }
        }

        if ($ips === []) {
            $ipv4List = @gethostbynamel($normalized);
            if (is_array($ipv4List)) {
                foreach ($ipv4List as $candidate) {
                    if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                        $ips[] = $candidate;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Pastikan alamat IP berada pada rentang publik yang valid (bukan loopback, private, link-local, CGNAT, atau reserved).
     */
    public function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return $this->isPublicIpv4($ip);
        }

        if (strlen($packed) === 16) {
            return $this->isPublicIpv6($packed);
        }

        return false;
    }

    private function isPublicIpv4(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        $blockedCidrs = [
            [0x00000000, 0xFF000000], // 0.0.0.0/8 (current network)
            [0x0A000000, 0xFF000000], // 10.0.0.0/8 (RFC 1918)
            [0x64400000, 0xFFC00000], // 100.64.0.0/10 (CGNAT RFC 6598)
            [0x7F000000, 0xFF000000], // 127.0.0.0/8 (loopback)
            [0xA9FE0000, 0xFFFF0000], // 169.254.0.0/16 (link-local / cloud metadata)
            [0xAC100000, 0xFFF00000], // 172.16.0.0/12 (RFC 1918)
            [0xC0000000, 0xFFFFFF00], // 192.0.0.0/24 (IETF protocol assignments)
            [0xC0000200, 0xFFFFFF00], // 192.0.2.0/24 (TEST-NET-1)
            [0xC0A80000, 0xFFFF0000], // 192.168.0.0/16 (RFC 1918)
            [0xC6120000, 0xFFFE0000], // 198.18.0.0/15 (benchmarking)
            [0xC6336400, 0xFFFFFF00], // 198.51.100.0/24 (TEST-NET-2)
            [0xCB007100, 0xFFFFFF00], // 203.0.113.0/24 (TEST-NET-3)
            [0xE0000000, 0xE0000000], // 224.0.0.0/3 (multicast & reserved)
        ];

        foreach ($blockedCidrs as [$network, $mask]) {
            if (($long & $mask) === ($network & $mask)) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIpv6(string $packed): bool
    {
        // :: (unspecified) and ::1 (loopback)
        if ($packed === str_repeat("\x00", 16) || $packed === str_repeat("\x00", 15)."\x01") {
            return false;
        }

        // IPv4-mapped (::ffff:0:0/96) and IPv4-compatible (::/96)
        $prefix12 = substr($packed, 0, 12);
        if ($prefix12 === str_repeat("\x00", 10)."\xff\xff" || $prefix12 === str_repeat("\x00", 12)) {
            $embeddedIpv4 = inet_ntop(substr($packed, 12, 4));

            return $embeddedIpv4 !== false && $this->isPublicIp($embeddedIpv4);
        }

        // 6to4 (2002::/16) — validate embedded IPv4
        if (substr($packed, 0, 2) === "\x20\x02") {
            $embeddedIpv4 = inet_ntop(substr($packed, 2, 4));

            return $embeddedIpv4 !== false && $this->isPublicIp($embeddedIpv4);
        }

        $firstByte = ord($packed[0]);
        $secondByte = ord($packed[1]);

        // fc00::/7 (Unique Local Address)
        if (($firstByte & 0xFE) === 0xFC) {
            return false;
        }

        // fe80::/10 (Link-local) and fec0::/10 (Deprecated site-local)
        if ($firstByte === 0xFE && (($secondByte & 0xC0) === 0x80 || ($secondByte & 0xC0) === 0xC0)) {
            return false;
        }

        // ff00::/8 (Multicast)
        if ($firstByte === 0xFF) {
            return false;
        }

        // 2001:db8::/32 (Documentation)
        if (substr($packed, 0, 4) === "\x20\x01\x0d\xb8") {
            return false;
        }

        return true;
    }
}
