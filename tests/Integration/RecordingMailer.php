<?php

namespace Tests\Integration;

use Mailer;
use RuntimeException;

require_once PRIVATE_PATH . '/classes/Mailer.php';

/** Records each email it is asked to send; throws for addresses told to fail. */
final class RecordingMailer implements Mailer
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $sent = [];

    /** @var array<string, string> address => exception message */
    private array $failures = [];

    public function failFor(string $address, string $message): void
    {
        $this->failures[$address] = $message;
    }

    public function send(string $to, string $subject, string $html): void
    {
        if (isset($this->failures[$to])) {
            throw new RuntimeException($this->failures[$to]);
        }
        $this->sent[] = [$to, $subject, $html];
    }
}
