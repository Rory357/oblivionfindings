<?php

namespace App\Exceptions\Finance;

use Symfony\Component\HttpKernel\Exception\HttpException;

class UnauthorizedFinancialOperationException extends HttpException
{
    public function __construct(string $message = 'Unauthorized financial operation.', ?\Throwable $previous = null)
    {
        parent::__construct(403, $message, $previous);
    }
}
