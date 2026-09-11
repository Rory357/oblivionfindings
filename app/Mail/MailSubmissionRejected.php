<?php

namespace App\Mail;

use Symfony\Component\Mailer\Exception\TransportException;

/** An explicit provider rejection or failed authorization; acceptance did not occur. */
final class MailSubmissionRejected extends TransportException {}
