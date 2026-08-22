<?php

declare(strict_types=1);

namespace Merlin\Service;

use Psr\Log\LoggerInterface;

/**
 * Führt einen mitgelieferten Bundle-Filter und einen admin-erstellten
 * Custom-Filter zu einer Config zusammen.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * MERGE-SEMANTIK
 * ═══════════════════════════════════════════════════════════════════════════
 *  1. <disable> aus dem Custom-Filter wird ZUERST angewendet – sonst könnte
 *     eine Custom-Regel nicht gegen eine Bundle-Regel gewinnen, die sie
 *     eigentlich abschalten wollte.
 *  2. Listen-Sektionen (pre-filter, post-filter, quotes, images) werden
 *     additiv zusammengeführt, Custom-Regeln stehen HINTER den Bundle-Regeln
 *     und laufen damit später.
 *  3. Schlüssel-Sektionen (fetch/header per @name, json per @id) sind pro
 *     Schlüssel Einzelwerte: ein Custom-Eintrag mit gleichem Schlüssel ersetzt
 *     den Bundle-Eintrag statt ihn zu ergänzen. Zwei Cookie-Header wären im
 *     Request ohnehin nicht sinnvoll.
 *  4. Einzelwert-Felder (metadata/*, category) folgen "Custom gewinnt, sonst
 *     Bundle". Ersetzt wird das ganze Element, nicht einzelne Attribute: sonst
 *     bliebe bei einem Custom-<author json="…"/> der Bundle-XPath als stille
 *     Fallback-Kette aktiv, und niemand könnte nachvollziehen, welche Regel
 *     den Wert geliefert hat.
 *  5. <disable> und <note> werden aus dem Ergebnis entfernt – der Extractor
 *     soll sie nie sehen.
 *
 * Warum DOM und nicht SimpleXML: SimpleXML kann keine Knoten zwischen
 * Dokumenten verschieben und keine Kinder entfernen. Das Ergebnis wird am Ende
 * wieder als SimpleXMLElement zurückgegeben, damit alle bestehenden Konsumenten
 * in ContentExtractorService unverändert bleiben.
 *
 * Die Reihenfolge der Sektionen im Ergebnis wird bewusst NICHT normalisiert:
 * Umsortieren würde die Kommentare der Bundle-Datei von ihren Sektionen
 * trennen, und funktional ist die Reihenfolge irrelevant (jeder Konsument
 * greift per Namen zu).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * DREISTUFIGER MERGE (Bundle < Admin-Custom < User-Custom)
 * ═══════════════════════════════════════════════════════════════════════════
 * merge() kennt nur zwei Eingaben (Basis + eine Custom-Ebene). Drei Ebenen
 * entstehen durch Verkettung im Aufrufer (ContentFilterRepository::getMerged()):
 *
 *   $withAdmin = merge($bundle, $adminXml, $domain, ORIGIN_ADMIN);
 *   $final     = merge(mergeToString($bundle, $adminXml, $domain, ORIGIN_ADMIN),
 *                       $userXml, $domain, ORIGIN_USER);
 *
 * Der zweite Aufruf bekommt als "Basis" das bereits gemergte Bundle+Admin-XML.
 * tagOrigin() darf dessen Knoten deshalb NICHT pauschal auf ORIGIN_BUNDLE
 * zurückstempeln – sie tragen aus dem ersten Durchlauf schon bundle- oder
 * admin-Herkunft. Deshalb überspringt tagOrigin() jeden Knoten, der bereits ein
 * Herkunftsattribut trägt (siehe dort).
 */
class ContentFilterMerger {

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string|null $bundleXml    Rohes Bundle-XML oder das Ergebnis eines
	 *                                  vorherigen merge()-Aufrufs (Verkettung), oder null
	 * @param string|null $customXml    Rohes Custom-XML dieser Ebene, oder null
	 * @param string      $domain       Für Root-Attribut und Logmeldungen
	 * @param string      $customOrigin Herkunftswert, mit dem Regeln aus $customXml
	 *                                  markiert werden (ORIGIN_ADMIN oder ORIGIN_USER)
	 *
	 * @return \SimpleXMLElement|null null nur, wenn beide Eingaben null sind
	 * @throws \RuntimeException wenn eine der Eingaben kein wohlgeformtes Filter-XML ist
	 */
	public function merge(
		?string $bundleXml,
		?string $customXml,
		string $domain,
		string $customOrigin = ContentFilterSchema::ORIGIN_ADMIN,
	): ?\SimpleXMLElement {
		if ($bundleXml === null && $customXml === null) {
			return null;
		}

		$result = $this->createResultDocument($bundleXml, $domain);
		$root   = $result->documentElement;
		if ($root === null) {
			return null;
		}

		// Markiert nur bisher unmarkierte Knoten als Bundle-Herkunft – bei einer
		// Verkettung (siehe Klassen-Docblock) hat $root schon bundle-/admin-
		// getaggte Knoten aus einem früheren Merge-Durchlauf.
		$this->tagOrigin($root, ContentFilterSchema::ORIGIN_BUNDLE);

		if ($customXml !== null) {
			$customDoc  = $this->parse($customXml, 'custom/' . $domain);
			$customRoot = $customDoc->documentElement;
			if ($customRoot !== null) {
				$this->applyDisables($root, $customRoot, $domain);
				$this->mergeSections($root, $customRoot, $customOrigin);
			}
		}

		// Interne Sektionen entfernen, auch falls sie versehentlich in einer
		// Bundle-Datei stehen.
		foreach (ContentFilterSchema::INTERNAL_SECTIONS as $internal) {
			$this->removeDirectChildren($root, $internal);
		}

		$xml = simplexml_import_dom($result);
		return $xml instanceof \SimpleXMLElement ? $xml : null;
	}

	/**
	 * Bequemlichkeit für die Admin-/Personal-UI: gemergtes XML als formatierter
	 * String, z. B. um es als Basis in einen weiteren merge()-Aufruf zu geben.
	 */
	public function mergeToString(
		?string $bundleXml,
		?string $customXml,
		string $domain,
		string $customOrigin = ContentFilterSchema::ORIGIN_ADMIN,
	): ?string {
		$merged = $this->merge($bundleXml, $customXml, $domain, $customOrigin);
		if ($merged === null) {
			return null;
		}
		$raw = $merged->asXML();
		if ($raw === false) {
			return null;
		}

		// Neu einlesen statt den bestehenden Baum umzuhängen: preserveWhiteSpace
		// wirkt nur beim Parsen, und ohne diesen Schritt bleiben die alten
		// Whitespace-Textknoten stehen und formatOutput hat keinen Effekt.
		$dom = new \DOMDocument('1.0', 'UTF-8');
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput       = true;
		if ($dom->loadXML($raw, LIBXML_NONET) === false) {
			return $raw;
		}
		$out = $dom->saveXML();
		return $out === false ? $raw : $out;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Parsen
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Basisdokument für das Ergebnis: eine Kopie des Bundles, oder ein leeres
	 * <domain name="…"/>, wenn es kein Bundle gibt.
	 */
	private function createResultDocument(?string $bundleXml, string $domain): \DOMDocument {
		if ($bundleXml !== null) {
			return $this->parse($bundleXml, 'bundle/' . $domain);
		}
		$doc  = new \DOMDocument('1.0', 'UTF-8');
		$root = $doc->createElement(ContentFilterSchema::ROOT_ELEMENT);
		$root->setAttribute('name', $domain);
		$doc->appendChild($root);
		return $doc;
	}

	/**
	 * Parst Filter-XML streng.
	 *
	 * DOCTYPE wird hart abgelehnt, statt sich auf libxml-Defaults zu verlassen:
	 * eine Entity-Definition in einer Config-Datei hat keinen legitimen
	 * Anwendungsfall, kann aber je nach libxml-Version und Flags externe
	 * Ressourcen laden (XXE) oder den Parser mit rekursiven Entities
	 * beschäftigen (Billion Laughs).
	 *
	 * @throws \RuntimeException
	 */
	private function parse(string $xml, string $context): \DOMDocument {
		if (stripos($xml, '<!DOCTYPE') !== false) {
			throw new \RuntimeException('DOCTYPE ist in Filterdateien nicht erlaubt (' . $context . ')');
		}

		$prev = libxml_use_internal_errors(true);
		$doc  = new \DOMDocument('1.0', 'UTF-8');
		$ok   = $doc->loadXML($xml, LIBXML_NONET);
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		if ($ok === false || $doc->documentElement === null) {
			$first = $errors[0]->message ?? 'unbekannter XML-Fehler';
			throw new \RuntimeException('Filter-XML ist nicht wohlgeformt (' . $context . '): ' . trim($first));
		}
		if ($doc->documentElement->tagName !== ContentFilterSchema::ROOT_ELEMENT) {
			throw new \RuntimeException(
				'Wurzelelement muss <' . ContentFilterSchema::ROOT_ELEMENT . '> sein (' . $context . ')'
			);
		}

		return $doc;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Herkunft markieren
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Heftet jedem noch unmarkierten Regelknoten unterhalb von $root sein
	 * Herkunftsattribut an, damit Admin- und Personal-UI Bundle-, Admin- und
	 * User-Regeln auseinanderhalten können.
	 *
	 * Bereits markierte Knoten werden übersprungen (nicht überschrieben): bei
	 * einer Verkettung zweier merge()-Aufrufe (Bundle+Admin, danach +User, siehe
	 * Klassen-Docblock) ist $root der Ergebnisbaum des ERSTEN Durchlaufs und
	 * trägt schon bundle-/admin-Herkunft. Ein bedingungsloses setAttribute()
	 * würde diese Admin-Herkunft im zweiten Durchlauf stillschweigend auf
	 * "bundle" zurücksetzen.
	 */
	private function tagOrigin(\DOMElement $root, string $origin): void {
		foreach (ContentFilterSchema::SECTIONS as $section => $def) {
			if (in_array($section, ContentFilterSchema::INTERNAL_SECTIONS, true)) {
				continue;
			}
			foreach ($this->ruleNodes($root, $section, $def) as $rule) {
				if ($rule->hasAttribute(ContentFilterSchema::ORIGIN_ATTRIBUTE)) {
					continue;
				}
				$rule->setAttribute(ContentFilterSchema::ORIGIN_ATTRIBUTE, $origin);
			}
		}
	}

	/**
	 * Alle Regelknoten einer Sektion unterhalb von $root.
	 *
	 * @param array<string,mixed> $def
	 * @return list<\DOMElement>
	 */
	private function ruleNodes(\DOMElement $root, string $section, array $def): array {
		$kind = $def['kind'] ?? 'list';

		if ($kind === 'root-list-keyed' || $kind === 'root-text') {
			return $this->directChildren($root, $section);
		}

		$wrapper = $this->firstDirectChild($root, $section);
		if ($wrapper === null) {
			return [];
		}
		return $this->directChildren($wrapper, null);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// <disable>
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Wendet alle <disable>-Blöcke des Custom-Filters auf das Ergebnis an.
	 *
	 * Zwei Formen:
	 *   <disable section="pre-filter" />        → ganze Bundle-Sektion verwerfen
	 *   <disable><pre-filter><remove class="x"/></pre-filter></disable>
	 *                                          → einzelne Bundle-Regeln verwerfen
	 *
	 * Die Sektions-Verschachtelung ist nötig, weil <remove> in pre-filter UND
	 * post-filter vorkommt – ohne sie wäre nicht entscheidbar, welche gemeint ist.
	 */
	private function applyDisables(\DOMElement $resultRoot, \DOMElement $customRoot, string $domain): void {
		foreach ($this->directChildren($customRoot, 'disable') as $disable) {
			$section = trim($disable->getAttribute('section'));

			if ($section !== '') {
				if (!in_array($section, ContentFilterSchema::DISABLABLE_SECTIONS, true)) {
					$this->logger->warning('content-filters: <disable section> mit unbekannter Sektion ignoriert', [
						'domain'  => $domain,
						'section' => $section,
					]);
					continue;
				}
				$this->removeSection($resultRoot, $section);
				continue;
			}

			foreach ($this->directChildren($disable, null) as $spec) {
				$name = $spec->tagName;

				// <json> steht direkt unter <domain>, hat also keinen Wrapper.
				if ($name === 'json') {
					$this->disableRules($resultRoot, $spec, $name);
					continue;
				}

				$def = ContentFilterSchema::section($name);
				if ($def === null || in_array($name, ContentFilterSchema::INTERNAL_SECTIONS, true)) {
					$this->logger->warning('content-filters: <disable> mit unbekanntem Sektionsnamen ignoriert', [
						'domain'  => $domain,
						'element' => $name,
					]);
					continue;
				}

				// <category> und <note> haben keine Regelkinder – der verschachtelte
				// Aufruf wäre ein stiller No-op. Diese Sektionen werden über
				// <disable section="category" /> abgeschaltet.
				if (($def['kind'] ?? '') === 'root-text') {
					$this->logger->warning('content-filters: <disable> mit Textsektion ignoriert, section-Attribut nutzen', [
						'domain'  => $domain,
						'element' => $name,
					]);
					continue;
				}

				$wrapper = $this->firstDirectChild($resultRoot, $name);
				if ($wrapper === null) {
					continue;
				}
				foreach ($this->directChildren($spec, null) as $ruleSpec) {
					$this->disableRules($wrapper, $ruleSpec, $name);
				}
			}
		}
	}

	/**
	 * Entfernt aus $parent alle direkten Kinder, die auf $spec passen.
	 *
	 * Matching ist eine Teilmengen-Prüfung: Jedes auf $spec gesetzte Attribut
	 * muss beim Kandidaten identisch sein; zusätzliche Attribute des Kandidaten
	 * stören nicht. Damit trifft <remove class="ads"/> genau diese eine Regel,
	 * während ein attributloses <author/> die Bundle-Autorenregel unabhängig von
	 * ihrem XPath abschaltet.
	 */
	private function disableRules(\DOMElement $parent, \DOMElement $spec, string $section): void {
		// Der Schlüsselattributwert wird sektionsabhängig normalisiert, damit
		// <disable><fetch><header name="cookie"/></fetch></disable> auch eine
		// Bundle-Regel mit name="Cookie" trifft.
		$keyAttribute = ContentFilterSchema::keyAttribute($section);
		$normalize    = static function (string $name, string $value) use ($section, $keyAttribute): string {
			return $name === $keyAttribute
				? ContentFilterSchema::normalizeKeyValue($section, $value)
				: $value;
		};

		$wanted = [];
		foreach ($this->attributeMap($spec) as $name => $value) {
			$wanted[$name] = $normalize($name, $value);
		}

		$doomed = [];
		foreach ($this->directChildren($parent, $spec->tagName) as $candidate) {
			$attrs = $this->attributeMap($candidate);
			$hit   = true;
			foreach ($wanted as $name => $value) {
				if (!array_key_exists($name, $attrs) || $normalize($name, $attrs[$name]) !== $value) {
					$hit = false;
					break;
				}
			}
			if ($hit) {
				$doomed[] = $candidate;
			}
		}

		foreach ($doomed as $node) {
			$node->parentNode?->removeChild($node);
		}
	}

	/**
	 * Verwirft eine komplette Bundle-Sektion.
	 *
	 * Ein einziger Aufruf genügt für alle Sektionsarten: Wrapper-Sektionen
	 * (<pre-filter>), wrapper-lose Listen (<json>) und Textelemente
	 * (<category>) sind alle direkte Kinder von <domain> mit dem Sektionsnamen
	 * als Tag.
	 */
	private function removeSection(\DOMElement $root, string $section): void {
		$this->removeDirectChildren($root, $section);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Sektionen zusammenführen
	// ──────────────────────────────────────────────────────────────────────────

	private function mergeSections(\DOMElement $resultRoot, \DOMElement $customRoot, string $customOrigin): void {
		foreach (ContentFilterSchema::SECTIONS as $section => $def) {
			if (in_array($section, ContentFilterSchema::INTERNAL_SECTIONS, true)) {
				continue;
			}

			switch ($def['kind']) {
				case 'list':
					$this->mergeList($resultRoot, $customRoot, $section, null, $customOrigin);
					break;
				case 'list-keyed':
					$this->mergeList($resultRoot, $customRoot, $section, (string) $def['key'], $customOrigin);
					break;
				case 'field-group':
					$this->mergeFieldGroup($resultRoot, $customRoot, $section, $customOrigin);
					break;
				case 'root-list-keyed':
					$this->mergeRootKeyed($resultRoot, $customRoot, $section, (string) $def['key'], $customOrigin);
					break;
				case 'root-text':
					$this->mergeRootText($resultRoot, $customRoot, $section, $customOrigin);
					break;
			}
		}
	}

	/**
	 * Additive Sektion. Ist $key gesetzt, ersetzt ein Custom-Eintrag den
	 * Bundle-Eintrag mit gleichem Schlüsselwert.
	 */
	private function mergeList(\DOMElement $resultRoot, \DOMElement $customRoot, string $section, ?string $key, string $customOrigin): void {
		$customWrapper = $this->firstDirectChild($customRoot, $section);
		if ($customWrapper === null) {
			return;
		}
		$customRules = $this->directChildren($customWrapper, null);
		if ($customRules === []) {
			return;
		}

		$resultWrapper = $this->ensureWrapper($resultRoot, $section);

		foreach ($customRules as $rule) {
			$imported = $this->importRule($resultRoot, $rule, $customOrigin);

			if ($key !== null) {
				$keyValue = ContentFilterSchema::normalizeKeyValue($section, $imported->getAttribute($key));
				foreach ($this->directChildren($resultWrapper, $imported->tagName) as $existing) {
					if (ContentFilterSchema::normalizeKeyValue($section, $existing->getAttribute($key)) === $keyValue) {
						$existing->parentNode?->removeChild($existing);
					}
				}
			}

			$resultWrapper->appendChild($imported);
		}
	}

	/**
	 * Sektion mit nach Feldnamen gruppierten Kindern (metadata).
	 *
	 * Pro Feldname gilt "Custom gewinnt": Definiert der Custom-Filter mindestens
	 * eine <author>-Regel, ersetzen seine Regeln die gesamte Bundle-Kette für
	 * author. Mehrere Regeln gleichen Namens bleiben dabei erhalten – der
	 * Extractor probiert sie in Dokumentreihenfolge als Fallback-Kette durch.
	 *
	 * Warum die Feldnamen zuerst gesammelt werden: Würde pro Custom-Feld gelöscht
	 * und angehängt, entfernte die zweite <author>-Regel die gerade eingefügte
	 * erste wieder.
	 */
	private function mergeFieldGroup(\DOMElement $resultRoot, \DOMElement $customRoot, string $section, string $customOrigin): void {
		$customWrapper = $this->firstDirectChild($customRoot, $section);
		if ($customWrapper === null) {
			return;
		}
		$customFields = $this->directChildren($customWrapper, null);
		if ($customFields === []) {
			return;
		}

		$resultWrapper = $this->ensureWrapper($resultRoot, $section);

		$replaced = [];
		foreach ($customFields as $field) {
			if (!isset($replaced[$field->tagName])) {
				$this->removeDirectChildren($resultWrapper, $field->tagName);
				$replaced[$field->tagName] = true;
			}
			$resultWrapper->appendChild($this->importRule($resultRoot, $field, $customOrigin));
		}
	}

	/**
	 * Wrapper-lose, per Schlüssel identifizierte Elemente direkt unter <domain>
	 * (betrifft <json>).
	 */
	private function mergeRootKeyed(\DOMElement $resultRoot, \DOMElement $customRoot, string $element, string $key, string $customOrigin): void {
		foreach ($this->directChildren($customRoot, $element) as $node) {
			$imported = $this->importRule($resultRoot, $node, $customOrigin);
			$keyValue = ContentFilterSchema::normalizeKeyValue($element, $imported->getAttribute($key));

			foreach ($this->directChildren($resultRoot, $element) as $existing) {
				if (ContentFilterSchema::normalizeKeyValue($element, $existing->getAttribute($key)) === $keyValue) {
					$existing->parentNode?->removeChild($existing);
				}
			}

			$resultRoot->appendChild($imported);
		}
	}

	/**
	 * Einzelnes Textelement direkt unter <domain> (betrifft <category>).
	 */
	private function mergeRootText(\DOMElement $resultRoot, \DOMElement $customRoot, string $element, string $customOrigin): void {
		$node = $this->firstDirectChild($customRoot, $element);
		if ($node === null) {
			return;
		}
		$this->removeDirectChildren($resultRoot, $element);
		$resultRoot->appendChild($this->importRule($resultRoot, $node, $customOrigin));
	}

	// ──────────────────────────────────────────────────────────────────────────
	// DOM-Helfer
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Übernimmt einen Knoten aus dem Custom-Dokument in das Ergebnisdokument
	 * und markiert ihn mit seiner Herkunft (ORIGIN_ADMIN oder ORIGIN_USER,
	 * je nachdem, welche Ebene gerade gemergt wird).
	 */
	private function importRule(\DOMElement $resultRoot, \DOMElement $node, string $customOrigin): \DOMElement {
		/** @var \DOMElement $imported */
		$imported = $resultRoot->ownerDocument->importNode($node, true);
		$imported->setAttribute(ContentFilterSchema::ORIGIN_ATTRIBUTE, $customOrigin);
		return $imported;
	}

	private function ensureWrapper(\DOMElement $root, string $section): \DOMElement {
		$wrapper = $this->firstDirectChild($root, $section);
		if ($wrapper !== null) {
			return $wrapper;
		}
		$wrapper = $root->ownerDocument->createElement($section);
		$root->appendChild($wrapper);
		return $wrapper;
	}

	/**
	 * Direkte Kind-Elemente von $parent. $tagName === null liefert alle.
	 *
	 * Bewusst nicht getElementsByTagName(): das liefert auch Nachfahren und
	 * eine live NodeList, die beim Entfernen von Knoten während der Iteration
	 * Einträge überspringt.
	 *
	 * @return list<\DOMElement>
	 */
	private function directChildren(\DOMElement $parent, ?string $tagName): array {
		$out = [];
		foreach ($parent->childNodes as $child) {
			if (!$child instanceof \DOMElement) {
				continue;
			}
			if ($tagName === null || $child->tagName === $tagName) {
				$out[] = $child;
			}
		}
		return $out;
	}

	private function firstDirectChild(\DOMElement $parent, string $tagName): ?\DOMElement {
		return $this->directChildren($parent, $tagName)[0] ?? null;
	}

	private function removeDirectChildren(\DOMElement $parent, string $tagName): void {
		foreach ($this->directChildren($parent, $tagName) as $child) {
			$child->parentNode?->removeChild($child);
		}
	}

	/**
	 * Attribute eines Elements als Name => getrimmter Wert, ohne das interne
	 * Herkunftsattribut.
	 *
	 * @return array<string,string>
	 */
	private function attributeMap(\DOMElement $el): array {
		$out = [];
		foreach ($el->attributes as $attr) {
			if ($attr->nodeName === ContentFilterSchema::ORIGIN_ATTRIBUTE) {
				continue;
			}
			$out[$attr->nodeName] = trim((string) $attr->nodeValue);
		}
		return $out;
	}
}
