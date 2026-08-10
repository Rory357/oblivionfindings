<?php

namespace App\Domain\Hr\Exceptions;

use InvalidArgumentException;

final class UnsafeWebhookHeaders extends InvalidArgumentException {}
