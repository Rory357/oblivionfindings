<?php

namespace App\Exceptions\Privacy;

class ConsentExpiredException extends \RuntimeException
{
    public function __construct(string $message = 'The required consent has expired or been withdrawn.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
