<?php

namespace App\Console\Commands;

use App\Models\ChatCall;
use App\Models\User;
use App\Services\Chat\OblivionRelay;
use Illuminate\Console\Command;

/**
 * Ends a person's Oblivion Relay use by hand (spec 3.7): one call, or all of their live and
 * recent calls. A Settings › Chat button needs a board first, so it isn't in version 1.
 */
final class ChatRelayRevoke extends Command
{
    protected $signature = 'chat:relay-revoke {user : The person\'s user id} {--call= : One call id; all live and recent calls when left out} {--reason=hand : offboarded, left_call, call_ended, access_removed or hand}';

    protected $description = 'End a person\'s Oblivion Relay use at once, for one call or all of them';

    public function handle(OblivionRelay $relay): int
    {
        $person = User::find((int) $this->argument('user'));
        $reason = (string) $this->option('reason');
        if (! $person) {
            $this->error('No such user.');

            return self::FAILURE;
        }
        if (! in_array($reason, OblivionRelay::REASONS, true)) {
            $this->error('The reason must be one of: '.implode(', ', OblivionRelay::REASONS).'.');

            return self::FAILURE;
        }
        if (! $relay->configured()) {
            $this->error('The relay control channel is not configured (CHAT_RELAY_CONTROL_URLS, CHAT_RELAY_CONTROL_SECRET, CHAT_RELAY_CONTROL_KEY_ID).');

            return self::FAILURE;
        }
        $callId = $this->option('call');
        if ($callId !== null) {
            $call = ChatCall::find((string) $callId);
            if (! $call || ! $call->participants()->where('user_id', $person->id)->exists()) {
                $this->error('That person was not in that call.');

                return self::FAILURE;
            }
            $relay->revokeCallMember($call, $person, $reason);
        } else {
            $relay->revokePerson($person, $reason);
        }
        $waiting = $relay->waiting()['count'];
        $this->info($waiting > 0 ? "Revocation sent; {$waiting} still waiting for a relay node and queued for retry." : 'Revocation sent to every relay node.');

        return self::SUCCESS;
    }
}
