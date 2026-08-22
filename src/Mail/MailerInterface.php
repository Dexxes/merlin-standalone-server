<?php

declare(strict_types=1);

namespace Merlin\Mail;

interface MailerInterface {
    public function send(string $toAddress, string $subject, string $bodyText): void;
}
