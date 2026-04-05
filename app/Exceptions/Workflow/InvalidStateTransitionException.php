<?php

namespace App\Exceptions\Workflow;

class InvalidStateTransitionException extends \RuntimeException
{
    public function __construct(string $from, string $to, string $model, ?\Throwable $previous = null)
    {
        parent::__construct("Invalid state transition from '{$from}' to '{$to}' on {$model}.", 0, $previous);
    }
}
