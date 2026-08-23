<?php

declare(strict_types=1);

namespace Merlin\Db;

/**
 * Status-Konstanten für Paywall-Abo-Zugangsdaten (z. B. Tagesspiegel Plus).
 * Port von merlin-nextcloud/lib/Db/SiteCredential.php - dort eine
 * OCP\AppFramework\Db\Entity, hier bewusst nur der Konstanten-Teil: merlin-
 * server nutzt kein Entity/QBMapper-Äquivalent, SiteCredentialRepository
 * arbeitet direkt mit PDO-Zeilen (Arrays), siehe dortiges toPublicArray().
 */
final class SiteCredential {
    /** Letzter Login-Versuch war erfolgreich, Cookie (falls vorhanden) gilt als gültig. */
    public const STATUS_OK = 'ok';
    /** Letzter Login-Versuch ist an falschen Zugangsdaten gescheitert. */
    public const STATUS_INVALID_CREDENTIALS = 'invalid_credentials';
    /** Login-Ablauf der Seite konnte nicht (mehr) durchlaufen werden (Formular/Endpoint geändert). */
    public const STATUS_LOGIN_FLOW_BROKEN = 'login_flow_broken';
    /** Noch nie versucht (frisch angelegter Eintrag). */
    public const STATUS_PENDING = 'pending';
}
