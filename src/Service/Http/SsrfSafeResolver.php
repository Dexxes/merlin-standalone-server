<?php

declare(strict_types=1);

namespace Merlin\Service\Http;

/**
 * SSRF-Guard für ausgehende HTTP-Requests: löst einen Host auf und prüft, dass
 * KEINE der zurückgegebenen Adressen privat/reserviert ist (RFC1918, Loopback,
 * Link-Local inkl. 169.254.169.254 Cloud-Metadata, …), plus das dazugehörige
 * CURLOPT_RESOLVE-Pinning gegen DNS-Rebinding zwischen Prüfung und Connect.
 *
 * Extrahiert aus ContentExtractorService (dort seit jeher für den
 * Artikel-Fetch verwendet), damit Service\Login\* denselben Schutz nutzen
 * kann, ohne die curl-/Redirect-Logik von ContentExtractorService
 * mitzuschleppen. Reine Extraktion, keine Verhaltensänderung.
 */
trait SsrfSafeResolver {

    /**
     * @return list<string> geprüfte IPs
     * @throws \Exception bei unerlaubtem Schema, nicht auflösbarem Host oder
     *                     privater/reservierter Ziel-IP
     */
    private function assertPublicHostAndResolve(string $url): array {
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \Exception('Nicht unterstütztes URL-Schema: ' . ($scheme ?: '(keins)'));
        }

        $host = $parsed['host'] ?? '';
        if ($host === '') {
            throw new \Exception('URL ohne Host: ' . $url);
        }

        $ips = $this->resolveHostIps($host);
        if (empty($ips)) {
            throw new \Exception('Host konnte nicht aufgelöst werden: ' . $host);
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new \Exception('Host löst auf eine private/reservierte Adresse auf: ' . $host . ' -> ' . $ip);
            }
        }

        return $ips;
    }

    /**
     * Löst einen Hostnamen (oder eine IP-Literal) zu allen bekannten IPv4-/IPv6-
     * Adressen auf. Bei einer IP-Literal wird diese direkt zurückgegeben, ohne
     * DNS-Lookup.
     *
     * @return list<string>
     */
    private function resolveHostIps(string $host): array {
        $bareHost = trim($host, '[]'); // IPv6-Literale kommen aus parse_url() ohne eckige Klammern,
                                        // zur Sicherheit trotzdem defensiv strippen.

        if (filter_var($bareHost, FILTER_VALIDATE_IP) !== false) {
            return [$bareHost];
        }

        $ips = [];

        $v4 = @gethostbynamel($bareHost);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        $v6records = @dns_get_record($bareHost, DNS_AAAA);
        if (is_array($v6records)) {
            foreach ($v6records as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * true, wenn $ip weder in einem privaten (RFC1918 / IPv6 Unique-Local) noch in
     * einem reservierten Range liegt (Loopback, Link-Local inkl. 169.254.169.254
     * Cloud-Metadata, Multicast, Broadcast, Dokumentations-Ranges, …).
     */
    private function isPublicIp(string $ip): bool {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Baut CURLOPT_RESOLVE-Einträge, die $host:$port fest auf die geprüften $ips
     * pinnen. Schließt die TOCTOU-Lücke zwischen assertPublicHostAndResolve() und
     * dem eigentlichen curl-Connect: ohne Pinning könnte ein zweiter, vom
     * Angreifer kontrollierter DNS-Lookup (DNS-Rebinding) zwischen Prüfung und
     * Verbindungsaufbau eine private Adresse liefern. TLS-SNI und der Host-Header
     * bleiben unberührt, da curl den Hostnamen für beides weiterverwendet.
     *
     * @param list<string> $ips
     * @return list<string>
     */
    private function buildResolvePin(string $host, int $port, array $ips): array {
        $bareHost = trim($host, '[]');
        $pins     = [];
        foreach ($ips as $ip) {
            // IPv6-Adressen müssen in CURLOPT_RESOLVE in eckigen Klammern stehen.
            $formatted = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $pins[]    = $bareHost . ':' . $port . ':' . $formatted;
        }
        return $pins;
    }
}
