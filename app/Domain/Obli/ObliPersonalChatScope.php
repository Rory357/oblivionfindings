<?php

namespace App\Domain\Obli;

use App\Models\User;
use Closure;

final readonly class ObliPersonalChatScope implements ObliChatScope
{
    public function __construct(private ObliSignIn $signIn, #[\SensitiveParameter] private ?string $token, private ObliKnowledge $knowledge) {}

    public function kind(): string
    {
        return 'personal';
    }

    public function identity(): array
    {
        return [];
    }

    public function within(Closure $operation): mixed
    {
        return $this->signIn->withSession($this->token, $operation);
    }

    public function prepare(User $actor, #[\SensitiveParameter] array $messages, array $policy): array
    {
        $this->knowledge->assertBindings($actor, array_values(array_map(fn ($message) => ['article_id' => (int) $message['knowledge']['article_id'], 'version' => $message['knowledge']['version']], array_filter($messages, fn ($message) => isset($message['knowledge'])))));
        [$rendered,$bindings] = $this->knowledge->messages($actor, $messages, $policy['diagnostic_categories']);

        return [$rendered, $bindings, null];
    }

    public function revalidate(User $actor, object $row): void
    {
        abort_unless(($row->context_kind ?? 'personal') === 'personal', 404);
    }
}
