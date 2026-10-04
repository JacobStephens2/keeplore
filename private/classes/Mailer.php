<?php

/**
 * Sends one HTML email. The transport decides who it is from and who
 * replies go to.
 */
interface Mailer
{
    /** @throws RuntimeException when the mail is not sent. */
    public function send(string $to, string $subject, string $html): void;
}
