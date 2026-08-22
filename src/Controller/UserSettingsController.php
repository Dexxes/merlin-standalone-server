<?php

declare(strict_types=1);

namespace Merlin\Controller;

use Merlin\Db\UserSettingsRepository;
use Merlin\Http\Request;
use Merlin\Http\Response;

/**
 * Port von merlin-nextcloud/lib/Controller/SettingsController.php - gleiche
 * Default-Werte, gleiche Typ-Tabelle, gleiches Cast-Verhalten (get()/update()
 * liefern immer kanonisch typisierte Werte, damit die Clients (Web/iOS/
 * Android) nicht zwischen Zahl/Bool/String flackern), nur IConfig durch
 * UserSettingsRepository ersetzt.
 */
final class UserSettingsController {
    private const DEFAULT_SETTINGS = [
        'theme' => 'auto',
        'fontSize' => '17',
        'fontFamily' => 'default',
        'lineHeight' => '1.6',
        'defaultView' => 'unread',
        'saveProgress' => '1',
        'resumeOnOpen' => '1',
        'progressEdge' => 'left',
        'reportBackendUrl' => '',
        'accentColor' => '#FF3B30',
        'excludedTagIds' => '[]',
    ];

    private const SETTINGS_TYPES = [
        'theme' => 'string',
        'fontSize' => 'int',
        'fontFamily' => 'string',
        'lineHeight' => 'float',
        'defaultView' => 'string',
        'saveProgress' => 'bool',
        'resumeOnOpen' => 'bool',
        'progressEdge' => 'string',
        'reportBackendUrl' => 'string',
        'accentColor' => 'string',
        'excludedTagIds' => 'string',
    ];

    public function __construct(private readonly UserSettingsRepository $settings) {
    }

    public function get(Request $request): Response {
        $userId = $request->authUserId();
        $stored = $this->settings->getAllForUser($userId);

        $result = [];
        foreach (self::DEFAULT_SETTINGS as $key => $defaultValue) {
            $result[$key] = $this->castForResponse($key, $stored[$key] ?? $defaultValue);
        }

        return Response::json($result);
    }

    public function update(Request $request): Response {
        $userId = $request->authUserId();

        $saved = [];
        foreach (self::DEFAULT_SETTINGS as $key => $defaultValue) {
            $value = $request->input($key);
            if ($value === null) {
                continue;
            }
            $stored = $this->castForStorage($key, $value);
            $this->settings->setForUser($userId, $key, $stored);
            $saved[$key] = $this->castForResponse($key, $stored);
        }

        return Response::json(['success' => true, 'settings' => $saved]);
    }

    private function castForResponse(string $key, string $raw): string|int|float|bool {
        return match (self::SETTINGS_TYPES[$key] ?? 'string') {
            'bool' => $raw === '1',
            'int' => (int) $raw,
            'float' => (float) $raw,
            default => $raw,
        };
    }

    private function castForStorage(string $key, string $value): string {
        return match (self::SETTINGS_TYPES[$key] ?? 'string') {
            'bool' => in_array(strtolower($value), ['1', 'true'], true) ? '1' : '0',
            'int' => (string) (int) $value,
            'float' => (string) (float) $value,
            default => $value,
        };
    }
}
