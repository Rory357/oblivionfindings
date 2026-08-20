<?php

namespace App\Services\Integration\Exceptions;

use RuntimeException;

final class WebhookBindingUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The authoritative webhook binding is unavailable.');
    }
}
