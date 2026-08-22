<?php

declare(strict_types=1);

namespace Merlin\Service;

/**
 * Deklarative Grammatik der Content-Filter-XML-Dateien.
 *
 * Warum eine eigene Klasse und keine XSD: Validator, Serializer und Merger
 * brauchen dieselbe Information in unterschiedlicher Form – der Validator prüft
 * gegen sie, der Serializer erzeugt daraus die Element-Reihenfolge, der Merger
 * leitet daraus ab, ob eine Sektion additiv zusammengeführt oder per Schlüssel
 * ersetzt wird. Eine XSD könnte nur die erste dieser drei Fragen beantworten.
 *
 * Es gibt in dieser App genau eine Quelle für diese Regeln. Wer hier etwas
 * ergänzt, muss nichts an drei Stellen nachziehen.
 */
final class ContentFilterSchema {

	/**
	 * Header-Namen, die eine Domain-Config über <fetch> setzen darf (kleingeschrieben).
	 *
	 * Whitelist statt Blacklist, weil die XML-Dateien Konfigurationsdaten sind:
	 * Ein frei wählbarer Header-Name könnte sonst den Request umbiegen (Host),
	 * Zugangsdaten anhängen (Authorization) oder den Body-Parser verwirren
	 * (Content-Length, Transfer-Encoding).
	 */
	public const FETCH_HEADER_WHITELIST = ['cookie', 'user-agent', 'accept-language', 'referer'];

	/**
	 * Internes Attribut, mit dem der Merger jeder Regel ihre Herkunft anheftet,
	 * damit Admin- und Personal-UI Bundle-, Admin- und User-Regeln unterscheiden
	 * können.
	 *
	 * Die Regel-Applier in ContentExtractorService lesen ausschliesslich die
	 * fachlichen Attribute (id/class/xpath/…), das Zusatzattribut ist für sie
	 * inert. In Custom-Dateien ist es verboten (siehe ContentFilterValidator),
	 * damit weder Admin noch Nutzer sich eine falsche Herkunft in die UI
	 * schreiben können.
	 *
	 * Drei statt zwei Werte, seit der Merge dreistufig ist (Bundle < Admin-Custom
	 * < User-Custom, siehe ContentFilterMerger/ContentFilterRepository): ein
	 * binäres bundle/custom könnte nicht mehr unterscheiden, ob eine Regel vom
	 * Admin oder vom Nutzer selbst kommt – für die Personal-Settings-UI (Bundle-
	 * und Admin-Regeln read-only, eigene Regeln editierbar) ist das aber nötig.
	 */
	public const ORIGIN_ATTRIBUTE = 'data-merlin-origin';
	public const ORIGIN_BUNDLE    = 'bundle';
	public const ORIGIN_ADMIN     = 'admin';
	public const ORIGIN_USER      = 'user';

	/** Wurzelelement jeder Filterdatei. */
	public const ROOT_ELEMENT = 'domain';

	/**
	 * Attribute, deren Wert ein XPath-Ausdruck ist und deshalb beim Speichern
	 * kompiliert werden muss. id/class sind bewusst nicht dabei – sie sind
	 * Literalwerte, die der Extractor selbst in XPath einbettet.
	 */
	public const XPATH_ATTRIBUTES = [
		'xpath',
		'container-xpath',
		'caption-xpath',
		'text-xpath',
		'author-xpath',
	];

	/**
	 * Maximale Grösse einer Custom-Datei in Bytes. Eine Filterdatei beschreibt
	 * eine einzelne Domain; alles darüber ist mit hoher Wahrscheinlichkeit ein
	 * Versehen oder ein Versuch, den XML-Parser zu beschäftigen.
	 */
	public const MAX_FILE_BYTES = 262144;

	/** Maximale Anzahl Regelknoten pro Datei (Summe über alle Sektionen). */
	public const MAX_RULES = 500;

	/**
	 * Sektionen in der Reihenfolge, in der sie in einer Datei stehen sollen.
	 * Entspricht der Pipeline-Reihenfolge aus 000.sample.com.xml, damit eine
	 * generierte Datei genauso zu lesen ist wie eine handgeschriebene.
	 */
	public const SECTION_ORDER = [
		'note',
		'disable',
		'fetch',
		'pre-filter',
		'images',
		'quotes',
		'post-filter',
		'json',
		'metadata',
		'category',
	];

	/**
	 * Sektionen, die per <disable section="…"/> komplett verworfen werden dürfen.
	 * 'note' und 'disable' selbst sind ausgenommen – sie sind Metadaten der
	 * Custom-Datei, keine Filterregeln.
	 */
	public const DISABLABLE_SECTIONS = [
		'fetch',
		'pre-filter',
		'images',
		'quotes',
		'post-filter',
		'json',
		'metadata',
		'category',
	];

	/**
	 * Grammatik je Sektion.
	 *
	 * kind:
	 *   'list'            – Wrapper-Element mit beliebig vielen Regelkindern.
	 *                       Merge: Custom-Kinder werden an die Bundle-Kinder
	 *                       angehängt (Custom läuft also später).
	 *   'list-keyed'      – wie 'list', aber Kinder haben einen Identitäts-
	 *                       schlüssel; ein Custom-Kind mit gleichem Schlüssel
	 *                       ERSETZT das Bundle-Kind statt es zu ergänzen.
	 *   'field-group'     – Wrapper-Element, dessen Kinder nach Feldnamen gruppiert
	 *                       sind. Mehrere Kinder gleichen Namens sind erlaubt und
	 *                       bilden eine Fallback-Kette (taz.de nutzt z. B. zwei
	 *                       <author>-Regeln für unterschiedliche Seitenlayouts).
	 *                       Merge: Definiert der Custom-Filter mindestens ein Feld
	 *                       eines Namens, ersetzen SEINE Regeln die komplette
	 *                       Bundle-Kette dieses Namens – nicht einzelne Einträge.
	 *   'root-list-keyed' – wie 'list-keyed', aber ohne Wrapper direkt unter
	 *                       <domain> (betrifft nur <json>).
	 *   'root-text'       – einzelnes Element unter <domain> mit Textinhalt.
	 *                       Merge: Custom ersetzt Bundle.
	 *
	 * children: erlaubte Kindelemente mit ihren Attributregeln:
	 *   required – Attribute, die vorhanden und nicht leer sein müssen
	 *   optional – Attribute, die vorhanden sein dürfen
	 *   oneOf    – genau eines dieser Attribute muss gesetzt sein
	 */
	public const SECTIONS = [
		'fetch' => [
			'kind' => 'list-keyed',
			'key'  => 'name',
			// HTTP-Header-Namen sind laut RFC 9110 case-insensitiv: "Cookie" und
			// "cookie" bezeichnen denselben Header. Ohne dieses Flag würde ein
			// Custom-Eintrag mit abweichender Schreibweise den Bundle-Header nicht
			// ersetzen, sondern doppelt daneben stehen.
			'keyCaseInsensitive' => true,
			'children'           => [
				'header' => ['required' => ['name', 'value']],
			],
		],
		'pre-filter' => [
			'kind'     => 'list',
			'children' => [
				'remove'       => ['oneOf' => ['id', 'class', 'xpath']],
				'infobox'      => ['oneOf' => ['id', 'class', 'xpath']],
				'saveElements' => ['required' => ['xpath', 'class']],
			],
		],
		'images' => [
			'kind'     => 'list',
			'children' => [
				'caption' => ['required' => ['container-xpath', 'caption-xpath']],
			],
		],
		'quotes' => [
			'kind'     => 'list',
			'children' => [
				'quote' => [
					'required' => ['container-xpath'],
					'optional' => ['text-xpath', 'author-xpath'],
				],
			],
		],
		'post-filter' => [
			'kind'     => 'list',
			'children' => [
				'remove' => ['oneOf' => ['id', 'class', 'xpath']],
			],
		],
		'json' => [
			'kind'     => 'root-list-keyed',
			'key'      => 'id',
			'children' => [
				'json' => [
					'required' => ['id', 'xpath'],
					'optional' => ['index'],
				],
			],
		],
		'metadata' => [
			'kind'     => 'field-group',
			'children' => [
				'title'     => ['optional' => ['xpath', 'json']],
				'author'    => ['optional' => ['xpath', 'json']],
				'excerpt'   => ['optional' => ['xpath', 'json']],
				'image'     => ['optional' => ['xpath', 'json']],
				'published' => ['optional' => ['xpath', 'json']],
				'category'  => ['optional' => ['xpath', 'json']],
			],
		],
		'category' => [
			'kind' => 'root-text',
		],
		'note' => [
			'kind' => 'root-text',
		],
	];

	/**
	 * Sektionen, die der Merger nach der Zusammenführung aus dem Ergebnis
	 * entfernt: Sie steuern den Merge selbst bzw. sind reine Notizen und haben
	 * in der Config, die der Extractor sieht, nichts zu suchen.
	 */
	public const INTERNAL_SECTIONS = ['disable', 'note'];

	/**
	 * true, wenn $section eine bekannte Sektion ist (inkl. der internen).
	 */
	public static function isKnownSection(string $section): bool {
		return $section === 'disable' || isset(self::SECTIONS[$section]);
	}

	/**
	 * Die Sektions-Definition oder null, wenn unbekannt.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function section(string $section): ?array {
		return self::SECTIONS[$section] ?? null;
	}

	/**
	 * Alle erlaubten Attributnamen eines Kindelements innerhalb einer Sektion.
	 *
	 * @return list<string>
	 */
	public static function allowedAttributes(string $section, string $child): array {
		$def = self::SECTIONS[$section]['children'][$child] ?? null;
		if ($def === null) {
			return [];
		}
		return array_values(array_unique(array_merge(
			$def['required'] ?? [],
			$def['optional'] ?? [],
			$def['oneOf']    ?? []
		)));
	}

	/**
	 * true, wenn der Wert dieses Attributs als XPath zu behandeln ist.
	 */
	public static function isXPathAttribute(string $attribute): bool {
		return in_array($attribute, self::XPATH_ATTRIBUTES, true);
	}

	/**
	 * Normalisiert den Schlüsselwert einer Sektion für Vergleiche.
	 *
	 * Nur relevant für Sektionen mit keyCaseInsensitive (aktuell <fetch>): dort
	 * müssen Merger, Validator und die <disable>-Auswertung dieselbe Sicht auf
	 * "gleicher Schlüssel" haben, sonst weichen gespeicherte Datei und
	 * tatsächlich gesendeter Header voneinander ab.
	 */
	public static function normalizeKeyValue(string $section, string $value): string {
		$value = trim($value);
		return !empty(self::SECTIONS[$section]['keyCaseInsensitive'])
			? strtolower($value)
			: $value;
	}

	/**
	 * Der Attributname, der eine Regel dieser Sektion identifiziert, oder null.
	 */
	public static function keyAttribute(string $section): ?string {
		$key = self::SECTIONS[$section]['key'] ?? null;
		return is_string($key) ? $key : null;
	}

	/**
	 * Maschinenlesbare Beschreibung der Grammatik für den Regel-Builder im
	 * Frontend.
	 *
	 * Warum das Frontend die Grammatik nicht selbst kennt: Der Builder rendert
	 * pro Regeltyp die passenden Eingabefelder. Wäre die Liste in JavaScript
	 * hinterlegt, müsste jede Formaterweiterung an zwei Stellen nachgezogen
	 * werden – und die UI würde Felder anbieten, die der Validator ablehnt.
	 *
	 * @return array{
	 *   sectionOrder: list<string>,
	 *   disablableSections: list<string>,
	 *   xpathAttributes: list<string>,
	 *   fetchHeaders: list<string>,
	 *   originAttribute: string,
	 *   limits: array{maxBytes:int,maxRules:int},
	 *   sections: array<string,array<string,mixed>>
	 * }
	 */
	public static function describe(): array {
		$sections = [];

		foreach (self::SECTIONS as $name => $def) {
			$children = [];
			foreach (($def['children'] ?? []) as $child => $rules) {
				$children[$child] = [
					'required' => array_values($rules['required'] ?? []),
					'optional' => array_values($rules['optional'] ?? []),
					'oneOf'    => array_values($rules['oneOf'] ?? []),
				];
			}
			$sections[$name] = [
				'kind'     => $def['kind'],
				'key'      => $def['key'] ?? null,
				'children' => $children,
				'internal' => in_array($name, self::INTERNAL_SECTIONS, true),
			];
		}

		return [
			'sectionOrder'       => self::SECTION_ORDER,
			'disablableSections' => self::DISABLABLE_SECTIONS,
			'xpathAttributes'    => self::XPATH_ATTRIBUTES,
			'fetchHeaders'       => self::FETCH_HEADER_WHITELIST,
			'originAttribute'    => self::ORIGIN_ATTRIBUTE,
			'limits'             => [
				'maxBytes' => self::MAX_FILE_BYTES,
				'maxRules' => self::MAX_RULES,
			],
			'sections' => $sections,
		];
	}
}
