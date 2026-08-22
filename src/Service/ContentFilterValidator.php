<?php

declare(strict_types=1);

namespace Merlin\Service;

/**
 * Port von merlin-nextcloud/lib/Service/ContentFilterValidator.php (1:1,
 * nur Namespace-Wechsel) - prüft eine Custom-Filterdatei, bevor sie
 * gespeichert wird.
 *
 * Fail-closed: Unbekannte Elemente und Attribute sind ein FEHLER, kein stilles
 * Ignorieren. Wer einen Tippfehler in einem Regelnamen hat, soll ihn in der UI
 * sehen und nicht wochenlang rätseln, warum der Filter nichts tut.
 *
 * Die Grammatik selbst steht in ContentFilterSchema – diese Klasse enthält nur
 * die Prüflogik.
 */
class ContentFilterValidator {

	/**
	 * Validiert rohes Filter-XML.
	 *
	 * @return list<array{message:string,line:int|null}> leere Liste = gültig
	 */
	public function validate(string $xml, string $domain): array {
		$errors = [];

		if (trim($xml) === '') {
			return [['message' => 'Die Datei ist leer.', 'line' => null]];
		}

		if (strlen($xml) > ContentFilterSchema::MAX_FILE_BYTES) {
			return [[
				'message' => sprintf(
					'Die Datei ist zu gross (%d Bytes, erlaubt sind %d).',
					strlen($xml),
					ContentFilterSchema::MAX_FILE_BYTES
				),
				'line' => null,
			]];
		}

		// DOCTYPE hart ablehnen: In einer Config-Datei gibt es keinen legitimen
		// Grund für Entity-Definitionen, wohl aber Missbrauchsmöglichkeiten
		// (externe Entities, rekursive Entity-Expansion).
		if (stripos($xml, '<!DOCTYPE') !== false) {
			return [['message' => 'DOCTYPE-Deklarationen sind nicht erlaubt.', 'line' => null]];
		}

		$prev = libxml_use_internal_errors(true);
		$doc  = new \DOMDocument('1.0', 'UTF-8');
		$ok   = $doc->loadXML($xml, LIBXML_NONET);
		$xmlErrors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		if ($ok === false) {
			foreach ($xmlErrors as $e) {
				$errors[] = ['message' => 'XML-Fehler: ' . trim($e->message), 'line' => $e->line ?: null];
			}
			if ($errors === []) {
				$errors[] = ['message' => 'Die Datei ist kein wohlgeformtes XML.', 'line' => null];
			}
			return $errors;
		}

		$root = $doc->documentElement;
		if ($root === null || $root->tagName !== ContentFilterSchema::ROOT_ELEMENT) {
			return [[
				'message' => 'Wurzelelement muss <' . ContentFilterSchema::ROOT_ELEMENT . '> sein.',
				'line'    => $root?->getLineNo(),
			]];
		}

		// Fail-closed auch am Wurzelelement: <domain name="x" mode="…"> darf nicht
		// durchrutschen, sonst suggeriert die Datei eine Option, die es nicht gibt.
		foreach ($root->attributes as $attr) {
			if ($attr->nodeName !== 'name') {
				$errors[] = [
					'message' => sprintf('Attribut %s ist bei <domain> nicht erlaubt (erlaubt: name).', $attr->nodeName),
					'line'    => $root->getLineNo(),
				];
			}
		}

		$name = trim($root->getAttribute('name'));
		if ($name === '') {
			$errors[] = [
				'message' => 'Dem Wurzelelement fehlt das Attribut name.',
				'line'    => $root->getLineNo(),
			];
		} elseif ($name !== $domain) {
			// Sonst zeigt die UI Regeln für Domain A, während die Datei für
			// Domain B gilt – der Filter würde nie greifen.
			$errors[] = [
				'message' => sprintf('name="%s" passt nicht zur Domain "%s".', $name, $domain),
				'line'    => $root->getLineNo(),
			];
		}

		$ruleCount = 0;
		/** @var array<string,array<string,true>> $rootKeys Schlüssel der wrapper-losen Sektionen */
		$rootKeys = [];

		foreach ($this->elementChildren($root) as $child) {
			$section = $child->tagName;

			if ($section === 'disable') {
				$this->validateDisable($child, $errors);
				continue;
			}

			$def = ContentFilterSchema::section($section);
			if ($def === null) {
				$errors[] = [
					'message' => sprintf('Unbekannte Sektion <%s>.', $section),
					'line'    => $child->getLineNo(),
				];
				continue;
			}

			switch ($def['kind']) {
				case 'list':
				case 'list-keyed':
					$ruleCount += $this->validateListSection($child, $section, $def, $errors);
					break;
				case 'field-group':
					$ruleCount += $this->validateFieldGroup($child, $section, $def, $errors);
					break;
				case 'root-list-keyed':
					// <json> steht ohne Wrapper direkt unter <domain>: das Element
					// selbst ist die Regel.
					$this->validateRule($child, $section, $def, $errors);
					$ruleCount++;

					// Doppelte Schlüssel in EINER Datei sind stiller Datenverlust:
					// der Merge ersetzt nur Bundle-Einträge, die zweite eigene
					// Quelle würde die erste überschreiben.
					$key      = (string) ($def['key'] ?? '');
					$keyValue = $key === ''
						? ''
						: ContentFilterSchema::normalizeKeyValue($section, $child->getAttribute($key));
					if ($keyValue !== '') {
						if (isset($rootKeys[$section][$keyValue])) {
							$errors[] = [
								'message' => sprintf(
									'<%s> mit %s="%s" ist mehrfach vorhanden.',
									$section,
									$key,
									$keyValue
								),
								'line' => $child->getLineNo(),
							];
						}
						$rootKeys[$section][$keyValue] = true;
					}
					break;
				case 'root-text':
					$this->validateTextElement($child, $errors);
					$ruleCount++;
					break;
			}
		}

		$this->validateUniqueRootSections($root, $errors);

		if ($ruleCount > ContentFilterSchema::MAX_RULES) {
			$errors[] = [
				'message' => sprintf(
					'Zu viele Regeln (%d, erlaubt sind %d).',
					$ruleCount,
					ContentFilterSchema::MAX_RULES
				),
				'line' => null,
			];
		}

		return $errors;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Sektionen
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * @param array<string,mixed>                              $def
	 * @param list<array{message:string,line:int|null}>         $errors
	 * @return int Anzahl geprüfter Regeln
	 */
	private function validateListSection(\DOMElement $wrapper, string $section, array $def, array &$errors): int {
		$this->rejectAttributes($wrapper, $section, $errors);

		$count = 0;
		$seenKeys = [];

		foreach ($this->elementChildren($wrapper) as $rule) {
			$this->validateRule($rule, $section, $def, $errors);
			$count++;

			// Schlüssel-Sektionen: Doppelte Schlüssel innerhalb EINER Datei sind
			// ein Fehler. Der Merge ersetzt nur Bundle-Einträge; zwei gleiche
			// Schlüssel in derselben Custom-Datei wären stiller Datenverlust.
			$key = $def['key'] ?? null;
			if (is_string($key)) {
				// Normalisiert wie im Merger – sonst gälten "Cookie" und "cookie"
				// hier als verschieden, würden dort aber einander ersetzen.
				$value = ContentFilterSchema::normalizeKeyValue($section, $rule->getAttribute($key));
				if ($value !== '' && isset($seenKeys[$rule->tagName][$value])) {
					$errors[] = [
						'message' => sprintf(
							'<%s> mit %s="%s" ist mehrfach vorhanden.',
							$rule->tagName,
							$key,
							$value
						),
						'line' => $rule->getLineNo(),
					];
				}
				$seenKeys[$rule->tagName][$value] = true;
			}
		}

		return $count;
	}

	/**
	 * Prüft eine nach Feldnamen gruppierte Sektion (metadata).
	 *
	 * Mehrere Regeln gleichen Feldnamens sind ausdrücklich erlaubt: der Extractor
	 * probiert sie in Dokumentreihenfolge durch, bis eine einen Wert liefert
	 * (taz.de nutzt das für zwei Autoren-Layouts). Geprüft wird deshalb nur die
	 * Gültigkeit der einzelnen Regeln.
	 *
	 * @param array<string,mixed>                      $def
	 * @param list<array{message:string,line:int|null}> $errors
	 */
	private function validateFieldGroup(\DOMElement $wrapper, string $section, array $def, array &$errors): int {
		$this->rejectAttributes($wrapper, $section, $errors);

		$count = 0;
		foreach ($this->elementChildren($wrapper) as $field) {
			$this->validateRule($field, $section, $def, $errors);
			$count++;
		}

		return $count;
	}

	/**
	 * Sektionen, die höchstens einmal pro Datei vorkommen dürfen. Ein zweiter
	 * <pre-filter>-Block würde vom Merger ignoriert (er liest den ersten) und
	 * seine Regeln wären wirkungslos.
	 *
	 * @param list<array{message:string,line:int|null}> $errors
	 */
	private function validateUniqueRootSections(\DOMElement $root, array &$errors): void {
		$seen = [];
		foreach ($this->elementChildren($root) as $child) {
			$section = $child->tagName;
			$def     = ContentFilterSchema::section($section);
			// <json> darf mehrfach vorkommen, <disable> ebenfalls.
			if ($def === null || ($def['kind'] ?? '') === 'root-list-keyed' || $section === 'disable') {
				continue;
			}
			if (isset($seen[$section])) {
				$errors[] = [
					'message' => sprintf('<%s> ist mehrfach vorhanden – erlaubt ist ein Block je Datei.', $section),
					'line'    => $child->getLineNo(),
				];
			}
			$seen[$section] = true;
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Einzelregel
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * @param array<string,mixed>                      $def
	 * @param list<array{message:string,line:int|null}> $errors
	 */
	private function validateRule(\DOMElement $rule, string $section, array $def, array &$errors): void {
		$tag      = $rule->tagName;
		$children = $def['children'] ?? [];

		if (!isset($children[$tag])) {
			$errors[] = [
				'message' => sprintf(
					'<%s> ist in <%s> nicht erlaubt. Erlaubt: %s.',
					$tag,
					$section,
					implode(', ', array_keys($children))
				),
				'line' => $rule->getLineNo(),
			];
			return;
		}

		$rules   = $children[$tag];
		$allowed = ContentFilterSchema::allowedAttributes($section, $tag);
		$present = [];

		foreach ($rule->attributes as $attr) {
			$attrName  = $attr->nodeName;
			$attrValue = trim((string) $attr->nodeValue);

			if ($attrName === ContentFilterSchema::ORIGIN_ATTRIBUTE) {
				$errors[] = [
					'message' => sprintf(
						'Das Attribut %s wird intern gesetzt und darf nicht in einer Filterdatei stehen.',
						ContentFilterSchema::ORIGIN_ATTRIBUTE
					),
					'line' => $rule->getLineNo(),
				];
				continue;
			}

			if (!in_array($attrName, $allowed, true)) {
				$errors[] = [
					'message' => sprintf(
						'Attribut %s ist bei <%s> nicht erlaubt. Erlaubt: %s.',
						$attrName,
						$tag,
						$allowed === [] ? '(keine)' : implode(', ', $allowed)
					),
					'line' => $rule->getLineNo(),
				];
				continue;
			}

			if ($attrValue === '') {
				// Ein leeres Attribut ist kein "nicht gesetzt": ContentExtractorService
				// prüft mit isset($rule['id']), und isset ist bei id="" wahr. Die Regel
				// würde also //*[@id=""] abfragen und nichts tun, während UI und
				// Validator sie als gültig ausweisen. Genau diese stille Falle
				// verhindert die Meldung hier.
				$errors[] = [
					'message' => sprintf('Attribut %s bei <%s> ist leer – entweder Wert setzen oder Attribut weglassen.', $attrName, $tag),
					'line'    => $rule->getLineNo(),
				];
				continue;
			}

			$present[$attrName] = $attrValue;

			$this->validateAttributeValue($rule, $tag, $attrName, $attrValue, $errors);
		}

		foreach (($rules['required'] ?? []) as $required) {
			if (!isset($present[$required])) {
				$errors[] = [
					'message' => sprintf('<%s> braucht das Attribut %s.', $tag, $required),
					'line'    => $rule->getLineNo(),
				];
			}
		}

		$oneOf = $rules['oneOf'] ?? [];
		if ($oneOf !== []) {
			$hits = array_values(array_intersect($oneOf, array_keys($present)));
			if (count($hits) === 0) {
				$errors[] = [
					'message' => sprintf(
						'<%s> braucht genau eines der Attribute %s.',
						$tag,
						implode(', ', $oneOf)
					),
					'line' => $rule->getLineNo(),
				];
			} elseif (count($hits) > 1) {
				// Der Extractor wertet in applyRemoveRules() nur das erste
				// gesetzte Attribut aus (id vor class vor xpath); zwei gesetzte
				// Attribute wären eine stille Falle.
				$errors[] = [
					'message' => sprintf(
						'<%s> hat mehrere der Attribute %s gesetzt (%s) – erlaubt ist genau eines.',
						$tag,
						implode(', ', $oneOf),
						implode(', ', $hits)
					),
					'line' => $rule->getLineNo(),
				];
			}
		}

		// Regelelemente tragen ihre Information ausschliesslich in Attributen.
		// Unterelemente oder Text darin sind immer ein Missverständnis und würden
		// beim Abruf spurlos ignoriert.
		if ($this->elementChildren($rule) !== []) {
			$errors[] = [
				'message' => sprintf('<%s> darf keine Unterelemente enthalten.', $tag),
				'line'    => $rule->getLineNo(),
			];
		}
		if (trim($rule->textContent) !== '') {
			$errors[] = [
				'message' => sprintf('<%s> darf keinen Textinhalt haben – die Regel steht in den Attributen.', $tag),
				'line'    => $rule->getLineNo(),
			];
		}
	}

	/**
	 * Wertprüfungen, die vom Attributnamen abhängen.
	 *
	 * @param list<array{message:string,line:int|null}> $errors
	 */
	private function validateAttributeValue(
		\DOMElement $rule,
		string $tag,
		string $attrName,
		string $value,
		array &$errors,
	): void {
		if ($value === '') {
			return;
		}

		if (ContentFilterSchema::isXPathAttribute($attrName)) {
			if (!$this->isCompilableXPath($value)) {
				$errors[] = [
					'message' => sprintf('Ungültiger XPath in %s: %s', $attrName, $value),
					'line'    => $rule->getLineNo(),
				];
			}
			return;
		}

		if ($attrName === 'json') {
			if (!$this->isValidJsonPath($value)) {
				$errors[] = [
					'message' => sprintf(
						'Ungültiger JSON-Pfad in json: %s (erwartet z. B. $.author.name oder ld:$.author[0].name)',
						$value
					),
					'line' => $rule->getLineNo(),
				];
			}
			return;
		}

		if ($attrName === 'index') {
			if (preg_match('/^\d+$/', $value) !== 1) {
				$errors[] = [
					'message' => sprintf('index muss eine nicht-negative Ganzzahl sein, war: %s', $value),
					'line'    => $rule->getLineNo(),
				];
			}
			return;
		}

		if ($tag === 'header' && $attrName === 'name') {
			if (!in_array(strtolower($value), ContentFilterSchema::FETCH_HEADER_WHITELIST, true)) {
				$errors[] = [
					'message' => sprintf(
						'Header %s ist nicht erlaubt. Erlaubt sind: %s.',
						$value,
						implode(', ', ContentFilterSchema::FETCH_HEADER_WHITELIST)
					),
					'line' => $rule->getLineNo(),
				];
			}
			return;
		}

		if ($tag === 'header' && $attrName === 'value') {
			// CR/LF im Wert würde beim Request weitere Header-Zeilen einschmuggeln.
			// Der Extractor strippt sie zusätzlich; hier wird der Admin darauf
			// hingewiesen, statt den Wert stillschweigend zu verändern.
			if (preg_match('/[\r\n]/', (string) $rule->getAttribute($attrName)) === 1) {
				$errors[] = [
					'message' => 'Header-Werte dürfen keine Zeilenumbrüche enthalten.',
					'line'    => $rule->getLineNo(),
				];
			}
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// <disable>
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Prüft einen <disable>-Block.
	 *
	 * Die XPath-Werte in Disable-Regeln werden bewusst NICHT kompiliert: sie
	 * werden nie ausgewertet, sondern nur als Zeichenkette mit der Bundle-Regel
	 * verglichen. Ein Ausdruck, der als XPath ungültig wäre, kann in einer
	 * Bundle-Datei trotzdem genau so stehen und soll abschaltbar bleiben.
	 *
	 * @param list<array{message:string,line:int|null}> $errors
	 */
	private function validateDisable(\DOMElement $disable, array &$errors): void {
		$section  = trim($disable->getAttribute('section'));
		$children = $this->elementChildren($disable);

		foreach ($disable->attributes as $attr) {
			if ($attr->nodeName !== 'section') {
				$errors[] = [
					'message' => sprintf('Attribut %s ist bei <disable> nicht erlaubt (erlaubt: section).', $attr->nodeName),
					'line'    => $disable->getLineNo(),
				];
			}
		}

		if ($section !== '' && $children !== []) {
			$errors[] = [
				'message' => '<disable section="…"> darf keine Unterelemente enthalten – entweder ganze Sektion oder einzelne Regeln.',
				'line'    => $disable->getLineNo(),
			];
			return;
		}

		if ($section !== '') {
			if (!in_array($section, ContentFilterSchema::DISABLABLE_SECTIONS, true)) {
				$errors[] = [
					'message' => sprintf(
						'Sektion "%s" kann nicht deaktiviert werden. Möglich: %s.',
						$section,
						implode(', ', ContentFilterSchema::DISABLABLE_SECTIONS)
					),
					'line' => $disable->getLineNo(),
				];
			}
			return;
		}

		if ($children === []) {
			$errors[] = [
				'message' => '<disable> ist leer – erwartet wird section="…" oder ein Sektionsblock mit Regeln.',
				'line'    => $disable->getLineNo(),
			];
			return;
		}

		foreach ($children as $child) {
			$name = $child->tagName;

			// <json> ist wrapper-los und steht deshalb direkt in <disable>.
			if ($name === 'json') {
				$this->validateDisableRule($child, 'json', $errors);
				continue;
			}

			$def = ContentFilterSchema::section($name);
			if ($def === null
				|| in_array($name, ContentFilterSchema::INTERNAL_SECTIONS, true)
				|| ($def['kind'] ?? '') === 'root-text'
			) {
				$errors[] = [
					'message' => sprintf(
						'<%s> ist in <disable> nicht erlaubt. Ganze Sektionen werden über <disable section="%s" /> abgeschaltet.',
						$name,
						$name
					),
					'line' => $child->getLineNo(),
				];
				continue;
			}

			$rules = $this->elementChildren($child);
			if ($rules === []) {
				$errors[] = [
					'message' => sprintf('<%s> in <disable> enthält keine Regeln.', $name),
					'line'    => $child->getLineNo(),
				];
				continue;
			}

			foreach ($rules as $rule) {
				$this->validateDisableRule($rule, $name, $errors);
			}
		}
	}

	/**
	 * Eine einzelne Regel innerhalb von <disable>: Elementname und Attributnamen
	 * müssen zur Sektion passen, Pflichtattribute gelten hier aber NICHT –
	 * ein attributloses <author/> schaltet die Bundle-Autorenregel unabhängig
	 * von ihrem XPath ab (Teilmengen-Matching, siehe ContentFilterMerger).
	 *
	 * @param list<array{message:string,line:int|null}> $errors
	 */
	private function validateDisableRule(\DOMElement $rule, string $section, array &$errors): void {
		$def      = ContentFilterSchema::section($section);
		$children = $def['children'] ?? [];

		if (!isset($children[$rule->tagName])) {
			$errors[] = [
				'message' => sprintf(
					'<%s> gehört nicht zu <%s>. Erlaubt: %s.',
					$rule->tagName,
					$section,
					implode(', ', array_keys($children))
				),
				'line' => $rule->getLineNo(),
			];
			return;
		}

		$allowed = ContentFilterSchema::allowedAttributes($section, $rule->tagName);
		foreach ($rule->attributes as $attr) {
			if (!in_array($attr->nodeName, $allowed, true)) {
				$errors[] = [
					'message' => sprintf(
						'Attribut %s ist bei <%s> nicht erlaubt. Erlaubt: %s.',
						$attr->nodeName,
						$rule->tagName,
						$allowed === [] ? '(keine)' : implode(', ', $allowed)
					),
					'line' => $rule->getLineNo(),
				];
			}
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Hilfsprüfungen
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * true, wenn libxml den Ausdruck als XPath kompilieren kann.
	 *
	 * Geprüft wird mit query() gegen ein leeres Dummy-Dokument, also mit genau
	 * derselben Methode, die der Extractor später benutzt: query() liefert false
	 * sowohl bei Syntaxfehlern als auch bei Ausdrücken, die keine Knotenmenge
	 * ergeben (etwa "1=1"). Beides ist als Filterregel unbrauchbar und soll
	 * schon beim Speichern auffallen, nicht erst beim Import eines Artikels.
	 */
	private function isCompilableXPath(string $expression): bool {
		static $xpath = null;
		if ($xpath === null) {
			$doc = new \DOMDocument('1.0', 'UTF-8');
			$doc->appendChild($doc->createElement('root'));
			$xpath = new \DOMXPath($doc);
		}

		$prev   = libxml_use_internal_errors(true);
		$result = @$xpath->query($expression);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		return $result !== false;
	}

	/**
	 * Spiegelt die von ContentExtractorService::resolveJsonPath() unterstützte
	 * Teilmenge von JSONPath: optionaler "quelle:"-Präfix, dann $ mit
	 * punktgetrennten Schlüsseln und optionalen [n]-Indizes.
	 */
	private function isValidJsonPath(string $value): bool {
		$path = $value;

		if (preg_match('/^([A-Za-z0-9_-]+):(.*)$/', $path, $m) === 1) {
			if ($m[1] === '') {
				return false;
			}
			$path = $m[2];
		}

		if ($path === '' || $path[0] !== '$') {
			return false;
		}

		$path = ltrim($path, '$');
		if (str_starts_with($path, '.')) {
			$path = substr($path, 1);
		}
		if ($path === '') {
			// "$" allein ist zulässig: resolveJsonPath() gibt dann den Wurzelwert
			// zurück, falls er skalar ist.
			return true;
		}

		foreach (explode('.', $path) as $token) {
			if ($token === '') {
				return false;
			}
			// "key[0]" oder "[0]"
			if (preg_match('/^[^\[\]]*(\[\d+\])+$/', $token) === 1) {
				continue;
			}
			// einfacher Schlüssel (JSON-LD nutzt u. a. "@type")
			if (preg_match('/^[^\[\]]+$/', $token) === 1) {
				continue;
			}
			return false;
		}

		return true;
	}

	/**
	 * Text-Sektionen (<category>, <note>) dürfen keine Unterelemente haben.
	 *
	 * @param list<array{message:string,line:int|null}> $errors
	 */
	private function validateTextElement(\DOMElement $el, array &$errors): void {
		foreach ($el->attributes as $attr) {
			$errors[] = [
				'message' => sprintf('Attribut %s ist bei <%s> nicht erlaubt.', $attr->nodeName, $el->tagName),
				'line'    => $el->getLineNo(),
			];
		}
		if ($this->elementChildren($el) !== []) {
			$errors[] = [
				'message' => sprintf('<%s> darf nur Text enthalten.', $el->tagName),
				'line'    => $el->getLineNo(),
			];
		}
	}

	/**
	 * Wrapper-Elemente tragen keine Attribute.
	 *
	 * @param list<array{message:string,line:int|null}> $errors
	 */
	private function rejectAttributes(\DOMElement $wrapper, string $section, array &$errors): void {
		foreach ($wrapper->attributes as $attr) {
			$errors[] = [
				'message' => sprintf('Attribut %s ist bei <%s> nicht erlaubt.', $attr->nodeName, $section),
				'line'    => $wrapper->getLineNo(),
			];
		}
	}

	/**
	 * @return list<\DOMElement>
	 */
	private function elementChildren(\DOMElement $parent): array {
		$out = [];
		foreach ($parent->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				$out[] = $child;
			}
		}
		return $out;
	}
}
