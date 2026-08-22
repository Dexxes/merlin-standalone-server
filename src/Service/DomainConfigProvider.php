<?php

declare(strict_types=1);

namespace Merlin\Service;

/**
 * Schnittstelle, die ContentExtractorService braucht, um pro Domain eine
 * Scraping-Regel-Config zu laden. Implementiert von Db\ContentFilterRepository
 * mit denselben drei Ebenen wie merlin-nextcloud (Bundle < Admin-Custom <
 * User-Custom, DB-gestützt für die beiden Custom-Ebenen).
 */
interface DomainConfigProvider {
    public function normalizeUrlDomain(string $url): string;

    public function getMerged(string $domain, ?string $userId = null): ?\SimpleXMLElement;
}
