<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class TaskProviderNavigationException extends RuntimeException
{
    public function __construct(
        public readonly string $providerClass,
        public readonly string $sourceKey,
        public readonly int $userId,
        Throwable $previous,
    ) {
        parent::__construct(
            "Task navigation badge provider failed for [{$sourceKey}].",
            previous: $previous,
        );
    }

    /**
     * @return array{task_provider: string, task_source: string, user_id: int, surface: string}
     */
    public function context(): array
    {
        return [
            'task_provider' => $this->providerClass,
            'task_source' => $this->sourceKey,
            'user_id' => $this->userId,
            'surface' => 'shared_navigation_badge',
        ];
    }
}
