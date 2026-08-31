<?php

declare(strict_types=1);

namespace Merlin\Db;

use Merlin\Service\ContentFilterMerger;
use Merlin\Service\ContentFilterSchema;
use Merlin\Service\DomainConfigProvider;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * PDO-Port von merlin-nextcloud/lib/Service/ContentFilterRepository.php:
 * Storage-Layer der Content-Filter. Mitgelieferte Bundle-Filter bleiben
 * Dateien (content-filters/*.xml), admin- und nutzererstellte Custom-Filter
 * liegen in der Tabelle content_filters (Migration 004). Liefert über
 * getMerged() die dreistufig zusammengeführte Config (Bundle < Admin-Custom
 * < User-Custom), implementiert dafür DomainConfigProvider und ersetzt
 * damit BundleContentFilterProvider als Abhängigkeit von
 * ContentExtractorService.
 *
 * scope-Spalte statt zweier Tabellen: Admin- und User-Ebene unterscheiden
 * sich nur in der WHERE-Bedingung, nicht im Schema. user_id trägt für
 * Admin-Zeilen den Sentinel ADMIN_SENTINEL_USER_ID (0, keine echte Nutzer-ID
 * in dieser Tabelle - siehe Migration 004: bewusst kein FK auf users(id),
 * damit ein Admin-Filter beim Löschen des zuletzt speichernden Admins nicht
 * mitgelöscht wird).
 */
final class ContentFilterRepository implements DomainConfigProvider {

	private const TABLE = 'content_filters';

	public const SCOPE_ADMIN = 'admin';
	public const SCOPE_USER  = 'user';

	/** @see Klassen-Docblock */
	public const ADMIN_SENTINEL_USER_ID = 0;

	/** @see merlin-nextcloud ContentFilterRepository::DOC_FILE_PREFIX */
	private const DOC_FILE_PREFIX = '000';

	/**
	 * Gecachte Merge-Ergebnisse pro (Domain, Nutzer) innerhalb eines Requests.
	 *
	 * @var array<string,\SimpleXMLElement|null>
	 */
	private array $mergedCache = [];

	/**
	 * Noch nicht gespeicherter Admin-Custom-Entwurf für die Dauer DIESES
	 * Requests (Testlauf vor dem Speichern), keyed nach Domain.
	 *
	 * @var array<string,string|null>
	 */
	private array $pendingAdminCustom = [];

	/**
	 * Wie $pendingAdminCustom, aber für die private User-Ebene, keyed nach
	 * "{domain}|{userId}".
	 *
	 * @var array<string,string|null>
	 */
	private array $pendingUserCustom = [];

	public function __construct(
		private readonly PDO $db,
		private readonly LoggerInterface $logger,
		private readonly ContentFilterMerger $merger,
	) {
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Pfade (Bundle bleibt Datei)
	// ──────────────────────────────────────────────────────────────────────────

	public function getBundleDir(): ?string {
		$dir = realpath(__DIR__ . '/../../content-filters');
		return $dir === false ? null : $dir;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Domain-Validierung
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * @see merlin-nextcloud ContentFilterRepository::isValidDomain
	 *
	 * Ein optionales führendes "_." markiert eine Wildcard-Domain (Ersatz für
	 * "*.", das im Dateisystem nicht als Dateiname zulässig ist).
	 */
	public function isValidDomain(string $domain): bool {
		if ($domain === '' || strlen($domain) > 253) {
			return false;
		}
		if ($domain !== strtolower($domain)) {
			return false;
		}
		$base = str_starts_with($domain, '_.') ? substr($domain, 2) : $domain;
		return preg_match(
			'/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/',
			$base
		) === 1;
	}

	public function normalizeUrlDomain(string $url): string {
		$host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
		return (string) preg_replace('/^www\./i', '', $host);
	}

	/** @see merlin-nextcloud ContentFilterRepository::domainMatchesFilterKey */
	public function domainMatchesFilterKey(string $urlDomain, string $filterDomain): bool {
		if ($urlDomain === $filterDomain) {
			return true;
		}
		if (!str_starts_with($filterDomain, '_.')) {
			return false;
		}
		return str_ends_with($urlDomain, '.' . substr($filterDomain, 2));
	}

	/**
	 * @see merlin-nextcloud ContentFilterRepository::lookupCandidates
	 * @return list<string>
	 */
	private function lookupCandidates(string $domain): array {
		$candidates = [$domain];
		$labels     = explode('.', $domain);
		for ($i = 1; $i < count($labels) - 1; $i++) {
			$candidates[] = '_.' . implode('.', array_slice($labels, $i));
		}
		return $candidates;
	}

	/**
	 * @throws \InvalidArgumentException bei ungültigem Domainnamen
	 */
	private function assertValidDomain(string $domain): void {
		if (!$this->isValidDomain($domain)) {
			throw new \InvalidArgumentException('Ungültiger Domainname: ' . $domain);
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Lesen: Bundle
	// ──────────────────────────────────────────────────────────────────────────

	public function readBundle(string $domain): ?string {
		$this->assertValidDomain($domain);
		$dir = $this->getBundleDir();
		return $dir === null ? null : $this->readFile($dir . '/' . $domain . '.xml');
	}

	public function hasBundle(string $domain): bool {
		return $this->readBundle($domain) !== null;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Lesen: Admin-Custom (scope='admin', DB)
	// ──────────────────────────────────────────────────────────────────────────

	public function readAdminCustom(string $domain): ?string {
		$this->assertValidDomain($domain);

		if (array_key_exists($domain, $this->pendingAdminCustom)) {
			return $this->pendingAdminCustom[$domain];
		}

		return $this->readCustomRow(self::SCOPE_ADMIN, self::ADMIN_SENTINEL_USER_ID, $domain);
	}

	/**
	 * @throws \InvalidArgumentException bei ungültigem Domainnamen
	 */
	public function setPendingAdminCustom(string $domain, ?string $xml): void {
		$this->assertValidDomain($domain);
		$this->pendingAdminCustom[$domain] = $xml;
		$this->invalidateMergedCacheForDomain($domain);
	}

	public function hasCustom(string $domain): bool {
		return $this->readAdminCustom($domain) !== null;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Lesen: User-Custom (scope='user', DB)
	// ──────────────────────────────────────────────────────────────────────────

	public function readUserCustom(string $domain, int $userId): ?string {
		$this->assertValidDomain($domain);

		$pendingKey = $this->pendingKey($domain, $userId);
		if (array_key_exists($pendingKey, $this->pendingUserCustom)) {
			return $this->pendingUserCustom[$pendingKey];
		}

		return $this->readCustomRow(self::SCOPE_USER, $userId, $domain);
	}

	public function userHasOverride(string $domain, int $userId): bool {
		return $this->readUserCustom($domain, $userId) !== null;
	}

	/**
	 * @throws \InvalidArgumentException bei ungültigem Domainnamen
	 */
	public function setPendingUserCustom(int $userId, string $domain, ?string $xml): void {
		$this->assertValidDomain($domain);
		$this->pendingUserCustom[$this->pendingKey($domain, $userId)] = $xml;
		unset($this->mergedCache[$this->cacheKey($domain, (string) $userId)]);
	}

	/**
	 * Domains, für die $userId einen eigenen Override hat, alphabetisch.
	 *
	 * @return list<string>
	 */
	public function listUserOverrideDomains(int $userId): array {
		$stmt = $this->db->prepare(
			'SELECT domain FROM ' . self::TABLE . ' WHERE scope = :scope AND user_id = :user_id ORDER BY domain'
		);
		$stmt->execute(['scope' => self::SCOPE_USER, 'user_id' => $userId]);
		return $stmt->fetchAll(PDO::FETCH_COLUMN);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Liste + Merge
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Alle bekannten Domains mit ihrer Herkunft, alphabetisch sortiert.
	 * userOverrideCount zeigt nur die ANZAHL an Nutzern mit eigenem Override,
	 * nie deren Inhalt - User-Filter sind komplett privat.
	 *
	 * @return list<array{domain:string,hasBundle:bool,hasCustom:bool,userOverrideCount:int}>
	 */
	public function listFilters(): array {
		$domains = [];

		$bundleDir = $this->getBundleDir();
		if (is_string($bundleDir) && is_dir($bundleDir)) {
			foreach ((glob($bundleDir . '/*.xml') ?: []) as $path) {
				$name = basename($path, '.xml');
				if (str_starts_with($name, self::DOC_FILE_PREFIX)) {
					continue;
				}
				if (!$this->isValidDomain($name)) {
					$this->logger->warning('content-filters: Datei mit ungültigem Domainnamen übersprungen', [
						'file' => $path,
					]);
					continue;
				}
				$domains[$name] ??= $this->emptyListEntry($name);
				$domains[$name]['hasBundle'] = true;
			}
		}

		foreach ($this->adminCustomDomains() as $name) {
			$domains[$name] ??= $this->emptyListEntry($name);
			$domains[$name]['hasCustom'] = true;
		}

		foreach ($this->userOverrideCounts() as $name => $count) {
			$domains[$name] ??= $this->emptyListEntry($name);
			$domains[$name]['userOverrideCount'] = $count;
		}

		ksort($domains);
		return array_values($domains);
	}

	/** @return array{domain:string,hasBundle:bool,hasCustom:bool,userOverrideCount:int} */
	private function emptyListEntry(string $domain): array {
		return ['domain' => $domain, 'hasBundle' => false, 'hasCustom' => false, 'userOverrideCount' => 0];
	}

	/**
	 * Zusammengeführte Config einer Domain für einen bestimmten Nutzer
	 * (Bundle < Admin-Custom < User-Custom), oder null, wenn es für die Domain
	 * gar keine Config gibt. $userId === null überspringt die dritte Ebene.
	 */
	public function getMerged(string $domain, ?string $userId = null): ?\SimpleXMLElement {
		if ($domain === '' || !$this->isValidDomain($domain)) {
			return null;
		}

		$cacheKey = $this->cacheKey($domain, $userId ?? '');
		if (array_key_exists($cacheKey, $this->mergedCache)) {
			return $this->mergedCache[$cacheKey];
		}

		$candidates  = $this->lookupCandidates($domain);
		$bundle      = $this->firstNonNull($candidates, fn (string $c) => $this->readBundle($c));
		$adminCustom = $this->firstNonNull($candidates, fn (string $c) => $this->readAdminCustom($c));
		$userCustom  = $userId !== null && $userId !== ''
			? $this->firstNonNull($candidates, fn (string $c) => $this->readUserCustom($c, (int) $userId))
			: null;

		$withAdminXml = $this->mergeBundleAndAdmin($bundle, $adminCustom, $domain);
		$final        = $this->mergeWithUser($withAdminXml, $userCustom, $domain);

		return $this->mergedCache[$cacheKey] = $final;
	}

	/**
	 * @param list<string> $candidates
	 * @param callable(string): ?string $read
	 */
	private function firstNonNull(array $candidates, callable $read): ?string {
		foreach ($candidates as $candidate) {
			$value = $read($candidate);
			if ($value !== null) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * Stufe 1: Bundle + Admin-Custom, als String (Basis für Stufe 2).
	 * Fail-open: ein kaputter Admin-Custom-Filter darf eine vorher
	 * funktionierende Domain nicht unlesbar machen.
	 */
	private function mergeBundleAndAdmin(?string $bundle, ?string $adminCustom, string $domain): ?string {
		try {
			return $this->merger->mergeToString($bundle, $adminCustom, $domain, ContentFilterSchema::ORIGIN_ADMIN);
		} catch (\Throwable $e) {
			$this->logger->error('content-filters: Admin-Merge fehlgeschlagen, Admin-Custom-Filter wird ignoriert', [
				'domain'    => $domain,
				'exception' => $e,
			]);
			try {
				return $bundle === null
					? null
					: $this->merger->mergeToString($bundle, null, $domain, ContentFilterSchema::ORIGIN_ADMIN);
			} catch (\Throwable $inner) {
				$this->logger->error('content-filters: auch der mitgelieferte Filter ist unlesbar', [
					'domain'    => $domain,
					'exception' => $inner,
				]);
				return null;
			}
		}
	}

	/**
	 * Stufe 2: (Bundle+Admin) + User-Custom, gleiches Fail-open-Muster.
	 */
	private function mergeWithUser(?string $withAdminXml, ?string $userCustom, string $domain): ?\SimpleXMLElement {
		try {
			return $this->merger->merge($withAdminXml, $userCustom, $domain, ContentFilterSchema::ORIGIN_USER);
		} catch (\Throwable $e) {
			$this->logger->error('content-filters: User-Merge fehlgeschlagen, User-Custom-Filter wird ignoriert', [
				'domain'    => $domain,
				'exception' => $e,
			]);
			try {
				return $withAdminXml === null
					? null
					: $this->merger->merge($withAdminXml, null, $domain, ContentFilterSchema::ORIGIN_USER);
			} catch (\Throwable $inner) {
				$this->logger->error('content-filters: auch das Bundle+Admin-Ergebnis ist unlesbar', [
					'domain'    => $domain,
					'exception' => $inner,
				]);
				return null;
			}
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Schreiben: Admin-Custom
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * @throws \InvalidArgumentException bei ungültigem Domainnamen
	 */
	public function saveAdminCustom(string $domain, string $xml, int $updatedBy): void {
		$this->assertValidDomain($domain);
		$this->upsert(self::SCOPE_ADMIN, self::ADMIN_SENTINEL_USER_ID, $domain, $xml, $updatedBy);
		unset($this->pendingAdminCustom[$domain]);
		$this->invalidateMergedCacheForDomain($domain);
	}

	public function deleteAdminCustom(string $domain): void {
		$this->assertValidDomain($domain);
		$this->deleteRow(self::SCOPE_ADMIN, self::ADMIN_SENTINEL_USER_ID, $domain);
		unset($this->pendingAdminCustom[$domain]);
		$this->invalidateMergedCacheForDomain($domain);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Schreiben: User-Custom
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * @throws \InvalidArgumentException bei ungültigem Domainnamen
	 */
	public function saveUserCustom(int $userId, string $domain, string $xml): void {
		$this->assertValidDomain($domain);
		$this->upsert(self::SCOPE_USER, $userId, $domain, $xml, $userId);
		unset($this->pendingUserCustom[$this->pendingKey($domain, $userId)]);
		unset($this->mergedCache[$this->cacheKey($domain, (string) $userId)]);
	}

	public function deleteUserCustom(int $userId, string $domain): void {
		$this->assertValidDomain($domain);
		$this->deleteRow(self::SCOPE_USER, $userId, $domain);
		unset($this->pendingUserCustom[$this->pendingKey($domain, $userId)]);
		unset($this->mergedCache[$this->cacheKey($domain, (string) $userId)]);
	}

	/**
	 * Entfernt ALLE privaten Overrides eines Nutzers, domainübergreifend.
	 * Aufgerufen von AdminController::deleteUser() bei Nutzerlöschung -
	 * Pendant zu UserDeletedListener in merlin-nextcloud, da diese Tabelle
	 * keinen FK-Cascade auf users(id) hat (siehe Klassen-Docblock).
	 */
	public function deleteAllUserCustom(int $userId): void {
		$stmt = $this->db->prepare(
			'DELETE FROM ' . self::TABLE . ' WHERE scope = :scope AND user_id = :user_id'
		);
		$stmt->execute(['scope' => self::SCOPE_USER, 'user_id' => $userId]);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Intern: DB-Zugriff
	// ──────────────────────────────────────────────────────────────────────────

	private function readCustomRow(string $scope, int $userId, string $domain): ?string {
		try {
			$stmt = $this->db->prepare(
				'SELECT xml FROM ' . self::TABLE . ' WHERE scope = :scope AND domain = :domain AND user_id = :user_id'
			);
			$stmt->execute(['scope' => $scope, 'domain' => $domain, 'user_id' => $userId]);
			$xml = $stmt->fetchColumn();
			return $xml === false ? null : (string) $xml;
		} catch (\Throwable $e) {
			$this->logger->error('content-filters: Custom-Filter konnte nicht aus der DB gelesen werden', [
				'scope'     => $scope,
				'domain'    => $domain,
				'exception' => $e,
			]);
			return null;
		}
	}

	private function findRowId(string $scope, int $userId, string $domain): ?int {
		$stmt = $this->db->prepare(
			'SELECT id FROM ' . self::TABLE . ' WHERE scope = :scope AND domain = :domain AND user_id = :user_id'
		);
		$stmt->execute(['scope' => $scope, 'domain' => $domain, 'user_id' => $userId]);
		$id = $stmt->fetchColumn();
		return $id === false ? null : (int) $id;
	}

	/**
	 * Legt eine Custom-Zeile an oder aktualisiert sie (SELECT-dann-INSERT/UPDATE).
	 * Race-Window zwischen SELECT und INSERT: scheitert der INSERT am
	 * Unique-Index, wird einmalig auf UPDATE zurückgefallen.
	 */
	private function upsert(string $scope, int $userId, string $domain, string $xml, int $updatedBy): void {
		$existingId = $this->findRowId($scope, $userId, $domain);
		$now        = gmdate('c');

		if ($existingId !== null) {
			$this->updateRow($existingId, $xml, $now, $updatedBy);
			return;
		}

		try {
			$this->insertRow($scope, $userId, $domain, $xml, $now, $updatedBy);
		} catch (\Throwable $e) {
			$retryId = $this->findRowId($scope, $userId, $domain);
			if ($retryId === null) {
				throw $e;
			}
			$this->updateRow($retryId, $xml, $now, $updatedBy);
		}
	}

	private function insertRow(string $scope, int $userId, string $domain, string $xml, string $now, int $updatedBy): void {
		$stmt = $this->db->prepare(
			'INSERT INTO ' . self::TABLE . ' (scope, user_id, domain, xml, updated_at, updated_by)
			 VALUES (:scope, :user_id, :domain, :xml, :updated_at, :updated_by)'
		);
		$stmt->execute([
			'scope' => $scope,
			'user_id' => $userId,
			'domain' => $domain,
			'xml' => $xml,
			'updated_at' => $now,
			'updated_by' => $updatedBy,
		]);
	}

	private function updateRow(int $id, string $xml, string $now, int $updatedBy): void {
		$stmt = $this->db->prepare(
			'UPDATE ' . self::TABLE . ' SET xml = :xml, updated_at = :updated_at, updated_by = :updated_by WHERE id = :id'
		);
		$stmt->execute(['xml' => $xml, 'updated_at' => $now, 'updated_by' => $updatedBy, 'id' => $id]);
	}

	private function deleteRow(string $scope, int $userId, string $domain): void {
		$stmt = $this->db->prepare(
			'DELETE FROM ' . self::TABLE . ' WHERE scope = :scope AND domain = :domain AND user_id = :user_id'
		);
		$stmt->execute(['scope' => $scope, 'domain' => $domain, 'user_id' => $userId]);
	}

	/** @return list<string> */
	private function adminCustomDomains(): array {
		$stmt = $this->db->prepare('SELECT domain FROM ' . self::TABLE . ' WHERE scope = :scope');
		$stmt->execute(['scope' => self::SCOPE_ADMIN]);
		return $stmt->fetchAll(PDO::FETCH_COLUMN);
	}

	/** @return array<string,int> domain => Anzahl Nutzer mit eigenem Override */
	private function userOverrideCounts(): array {
		$stmt = $this->db->prepare(
			'SELECT domain, COUNT(user_id) AS cnt FROM ' . self::TABLE . ' WHERE scope = :scope GROUP BY domain'
		);
		$stmt->execute(['scope' => self::SCOPE_USER]);

		$out = [];
		foreach ($stmt->fetchAll() as $row) {
			$out[(string) $row['domain']] = (int) $row['cnt'];
		}
		return $out;
	}

	private function pendingKey(string $domain, int $userId): string {
		return $domain . '|' . $userId;
	}

	private function cacheKey(string $domain, string $userId): string {
		return $domain . '|' . $userId;
	}

	/**
	 * Verwirft alle gecachten Merge-Ergebnisse einer Domain, über alle Nutzer
	 * hinweg - nötig, weil eine Änderung am Admin-Layer jeden nutzerspezifischen
	 * Merge dieser Domain betrifft.
	 */
	private function invalidateMergedCacheForDomain(string $domain): void {
		$prefix = $domain . '|';
		foreach (array_keys($this->mergedCache) as $key) {
			if (str_starts_with($key, $prefix)) {
				unset($this->mergedCache[$key]);
			}
		}
	}

	private function readFile(string $path): ?string {
		if (!is_file($path)) {
			return null;
		}
		$raw = @file_get_contents($path);
		if ($raw === false) {
			$this->logger->warning('content-filters: Datei nicht lesbar', ['file' => $path]);
			return null;
		}
		return $raw;
	}
}
