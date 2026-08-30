<?php

declare(strict_types=1);

namespace Merlin\Controller;

use Merlin\Http\Request;
use Merlin\Http\Response;
use Merlin\Service\TtsStreamService;

/**
 * Server-Capabilities: Clients (iOS/Android) fragen dies kurz nach dem
 * Login ab, um optionale Funktionen ein-/auszublenden, statt sie erst beim
 * ersten Gebrauch per Fehler zu entdecken (z. B. Vorlesen-Button nur
 * anzeigen, wenn der Piper-Daemon tatsächlich erreichbar ist).
 */
final class CapabilitiesController {
    public function __construct(private readonly TtsStreamService $ttsStream) {
    }

    public function index(Request $request): Response {
        return Response::json([
            'tts' => [
                'available' => $this->ttsStream->isAvailable(),
            ],
        ]);
    }
}
