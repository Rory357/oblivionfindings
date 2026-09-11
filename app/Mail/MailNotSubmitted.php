<?php

namespace App\Mail;

use Symfony\Component\Mailer\Exception\TransportException;

/** Only for local preflight failures before any mail submission to a provider. */
final class MailNotSubmitted extends TransportException {}
