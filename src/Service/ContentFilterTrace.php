<?php

declare(strict_types=1);

namespace Merlin\Service;

/**
 * Sammelt beim Testlauf eines Filters, wie viele Elemente jede einzelne Regel
 * getroffen hat.
 *
 * Warum überhaupt: Ohne diese Zahl ist das Schreiben eines XPath ein Blindflug –
 * bleibt der Artikel unverändert, weiss der Admin nicht, ob die Regel nicht
 * greift oder ob Readability das Element ohnehin schon entfernt hatte. "0 Treffer"
 * beantwortet genau diese Frage.
 *
 * Warum ein durchgereichtes Objekt und kein Service-Zustand: Der
 * ContentExtractorService ist ein von Nextcloud verwalteter Singleton. Ein
 * mutables Trace-Feld darauf würde bedeuten, dass ein Artikel-Import, der
 * zufällig im selben Request läuft, in die Diagnose eines anderen Aufrufs
 * schreibt. Der Trace wird deshalb als optionaler Parameter übergeben; ohne ihn
 * (also im Normalbetrieb) entsteht kein Aufwand.
 */
class ContentFilterTrace {

	/**
	 * @var list<array{
	 *   section: string,
	 *   element: string,
	 *   origin: string|null,
	 *   attributes: array<string,string>,
	 *   matches: int,
	 *   error: string|null
	 * }>
	 */
	private array $entries = [];

	/**
	 * Ergebnis einer Regel festhalten.
	 *
	 * Identifiziert wird die Regel über Sektion, Elementname und Attribute – also
	 * über genau die Felder, die die Admin-UI ohnehin schon aus dem Filter kennt.
	 * Ein künstlicher Regel-Schlüssel müsste zwischen PHP und JavaScript
	 * synchron gehalten werden und wäre die erste Stelle, die auseinanderläuft.
	 */
	public function record(string $section, \SimpleXMLElement $rule, int $matches, ?string $error = null): void {
		$attributes = [];
		$origin     = null;

		foreach ($rule->attributes() ?? [] as $name => $value) {
			$name  = (string) $name;
			$value = trim((string) $value);
			if ($name === ContentFilterSchema::ORIGIN_ATTRIBUTE) {
				$origin = $value;
				continue;
			}
			$attributes[$name] = $value;
		}

		$this->add($section, $rule->getName(), $attributes, $origin, $matches, $error);
	}

	/**
	 * Variante für Aufrufer, die die Regel nicht als XML-Knoten vorliegen haben.
	 *
	 * @param array<string,string> $attributes
	 */
	public function recordRaw(
		string $section,
		string $element,
		array $attributes,
		?string $origin,
		int $matches,
		?string $error = null
	): void {
		$this->add($section, $element, $attributes, $origin, $matches, $error);
	}

	/**
	 * @param array<string,string> $attributes
	 */
	private function add(
		string $section,
		string $element,
		array $attributes,
		?string $origin,
		int $matches,
		?string $error
	): void {
		ksort($attributes);

		// Dieselbe Regel kann mehrfach durchlaufen werden (etwa wenn ein Applier
		// zweimal aufgerufen wird). Treffer werden dann summiert, statt zwei Zeilen
		// mit verwirrend halbierten Zahlen anzuzeigen.
		//
		// Die Herkunft gehört mit in den Vergleich: Der Merge dedupliziert
		// Listen-Sektionen nicht, eine eigene Regel darf also identisch zu einer
		// mitgelieferten sein. Beide laufen dann wirklich – würden sie hier
		// zusammenfallen, sähe der Admin nur die Bundle-Zeile und hielte seine
		// eigene Regel für verschwunden.
		foreach ($this->entries as $index => $entry) {
			if ($entry['section'] === $section
				&& $entry['element'] === $element
				&& $entry['origin'] === $origin
				&& $entry['attributes'] === $attributes
			) {
				$this->entries[$index]['matches'] += $matches;
				if ($error !== null) {
					$this->entries[$index]['error'] = $error;
				}
				return;
			}
		}

		$this->entries[] = [
			'section'    => $section,
			'element'    => $element,
			'origin'     => $origin,
			'attributes' => $attributes,
			'matches'    => $matches,
			'error'      => $error,
		];
	}

	/**
	 * @return list<array{
	 *   section: string,
	 *   element: string,
	 *   origin: string|null,
	 *   attributes: array<string,string>,
	 *   matches: int,
	 *   error: string|null
	 * }>
	 */
	public function toArray(): array {
		return $this->entries;
	}

	/** Anzahl Regeln, die kein einziges Element getroffen haben. */
	public function countMisses(): int {
		$misses = 0;
		foreach ($this->entries as $entry) {
			if ($entry['matches'] === 0) {
				$misses++;
			}
		}
		return $misses;
	}

	/** Anzahl Regeln, die gar nicht ausgewertet werden konnten. */
	public function countErrors(): int {
		$errors = 0;
		foreach ($this->entries as $entry) {
			if ($entry['error'] !== null) {
				$errors++;
			}
		}
		return $errors;
	}
}
