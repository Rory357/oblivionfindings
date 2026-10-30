<?php

namespace App\Domain\Obli;

use App\Models\User;
use Closure;

interface ObliChatScope
{
    public function kind(): string;

    public function identity(): array;

    public function within(Closure $operation): mixed;

    /** @return array{0:array,1:array,2:array|null} */
    public function prepare(User $actor, #[\SensitiveParameter] array $messages, array $policy): array;

    public function revalidate(User $actor, object $row): void;
}
