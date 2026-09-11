<?php

namespace App\Domain\It\Enums;

/** Trusted adapter provenance. Never derive this argument from submitted form fields. */
enum ItTicketCommandChannel: string
{
    case Browser = 'browser';
    case Email = 'email';
    case ServiceApi = 'service_api';
}
