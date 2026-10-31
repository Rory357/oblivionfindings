<?php

namespace Tests\Unit\ControlRoom;

use App\Support\ControlRoom\BroadcastRecipientReadInput;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BroadcastRecipientReadInputTest extends TestCase
{
    public function test_exact_envelope_and_selector_identity(): void
    {
        $input = BroadcastRecipientReadInput::parse('{"kind":"broadcast_recipient","schema_version":1,"expected_actor_id":123,"command_id":"ca2b7941-cd01-45df-8c0d-e53d7df13b7b"}');
        self::assertSame(123, $input->expectedActorId);
        self::assertNotSame($input->fingerprint('bulk', null), $input->fingerprint('single', 'ca2b7941-cd01-45df-8c0d-e53d7df13b7b'));
        self::assertSame(123, BroadcastRecipientReadInput::reconcileActor('expected_actor_id=123'));
    }

    #[DataProvider('invalidBodies')]
    public function test_rejects_malformed_or_ambiguous_body(string $raw): void
    {
        $this->expectException(InvalidArgumentException::class);
        BroadcastRecipientReadInput::parse($raw);
    }

    public static function invalidBodies(): array
    {
        $base = ['kind' => 'broadcast_recipient', 'schema_version' => 1, 'expected_actor_id' => 123, 'command_id' => 'ca2b7941-cd01-45df-8c0d-e53d7df13b7b'];
        $cases = [[''], ['null'], ['[]'], ['{'], ['{"expected_actor_id":123,"expected_actor_id":124}']];
        foreach ([null, [], '123', 0, -1, 9007199254740992, 1.5] as $actor) { $cases[] = [json_encode(array_replace($base, ['expected_actor_id' => $actor]), JSON_THROW_ON_ERROR)]; }
        $cases[] = [json_encode($base + ['extra' => true], JSON_THROW_ON_ERROR)];
        unset($base['command_id']); $cases[] = [json_encode($base, JSON_THROW_ON_ERROR)];
        $cases[] = ['{"kind":"broadcast_recipient","schema_version":1,"expected_actor_id":123,"\u0065xpected_actor_id":124,"command_id":"ca2b7941-cd01-45df-8c0d-e53d7df13b7b"}'];
        return $cases;
    }

    #[DataProvider('invalidQueries')]
    public function test_raw_query_cannot_hide_duplicate_or_array_actor(string $query): void
    {
        $this->expectException(InvalidArgumentException::class);
        BroadcastRecipientReadInput::reconcileActor($query);
    }

    public static function invalidQueries(): array
    {
        return array_map(fn ($value) => [$value], ['', 'expected_actor_id=1&expected_actor_id=2', 'expected_actor_id[]=1',
            'expected_actor_id%5B%5D=1', 'expected_actor_id=%31', 'expected_actor_id=01', 'expected_actor_id=0',
            'expected_actor_id=-1', 'expected_actor_id=1.0', 'expected_actor_id=9007199254740992', 'expected_actor_id=1&other=1']);
    }
}
