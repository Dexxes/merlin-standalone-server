<?php

declare(strict_types=1);

namespace Merlin\Mail;

/**
 * Nutzt PHPs eingebaute mail() (lokaler Sendmail/Postfix auf dem Server).
 * v1 bewusst ohne SMTP - siehe merlin-server/config/config.sample.php. Das
 * MailerInterface hält den Wechsel auf eine spätere SmtpMailer-Implementierung
 * transparent für alle Aufrufer (PasswordResetService etc.).
 */
final class PhpMailMailer implements MailerInterface {
    public function __construct(
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
    }

    public function send(string $toAddress, string $subject, string $bodyText): void {
        $headers = implode("\r\n", [
            'From: ' . $this->encodeHeader($this->fromName) . ' <' . $this->fromAddress . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ]);

        $encodedSubject = $this->encodeHeader($subject);
        $sent = mail($toAddress, $encodedSubject, $bodyText, $headers);

        if (!$sent) {
            throw new \RuntimeException("mail() failed to send to {$toAddress}");
        }
    }

    private function encodeHeader(string $value): string {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
