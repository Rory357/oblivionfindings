<?php

namespace App\Domain\It\Exceptions;

use DomainException;

final class ItKnowledgeRelationshipUnavailable extends DomainException
{
    public function __construct()
    {
        parent::__construct('Related-record access changed. Select the available records again before saving or publishing.');
    }
}
