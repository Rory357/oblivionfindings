<?php

namespace Oblivion\Collector\Access;

use Oblivion\Collector\Runtime\OnvifCameraNetwork;
use Throwable;

/**
 * S&D Phase H (H1): the UniFi Access changes a hub makes for Oblivion, one request at a time, over
 * pinned HTTPS. Administration only: people, their doors, cards and visitor passes. Nothing here
 * unlocks a door or sets an emergency mode.
 *
 * Each step returns a finished row (`state`, `result`, `reason`) or, for a card enrolment still
 * waiting for its tap, null.
 */
final class UnifiAccessAdmin
{
    private const int CONNECT_TIMEOUT = 3;

    private const int RESPONSE_TIMEOUT = 10;

    private const int MAX_BYTES = 1_000_000;

    /** Whether a change was sent this run: no answer after that means "maybe", never "no". */
    private bool $wrote = false;

    public function __construct(private readonly OnvifCameraNetwork $network) {}

    /**
     * @param  array<string, mixed>  $details  signed job details (already checked)
     * @return array{state: string, result?: array<string, mixed>, reason?: string, enrol_session?: string}
     */
    public function run(array $details, string $token): array
    {
        $params = $details['params'];
        $this->wrote = false;
        $call = fn (string $method, string $path, ?array $body = null): array => $this->call($details, $method, $path, $body, $token);

        try {
            return match ($details['kind']) {
                'user_create' => $this->userCreate($call, $params),
                'user_status' => $this->userStatus($call, $params),
                'user_policies' => $this->userPolicies($call, $params),
                'card_enrol' => $this->enrolStart($call, $params),
                'card_remove' => $this->cardRemove($call, $params),
                'visitor_create' => $this->visitorCreate($call, $params),
                'visitor_delete' => $this->visitorDelete($call, $params),
            };
        } catch (ConsoleRefused $refused) {
            return ['state' => 'failed', 'reason' => $refused->getMessage()];
        } catch (ConsoleUnreachable) {
            return $this->wrote
                ? ['state' => 'uncertain', 'reason' => 'The hub sent the change but got no clear answer. Check UniFi Access before trying again.']
                : ['state' => 'failed', 'reason' => 'The hub couldn’t reach the UniFi Access console. Nothing was changed.'];
        } catch (Throwable) {
            return $this->wrote
                ? ['state' => 'uncertain', 'reason' => 'UniFi Access sent an answer the hub couldn’t read. Check UniFi Access before trying again.']
                : ['state' => 'failed', 'reason' => 'UniFi Access sent an answer the hub couldn’t read.'];
        }
    }

    /**
     * One look at a card enrolment: null while still waiting for the tap.
     *
     * @param  array<string, mixed>  $details
     * @return array{state: string, result?: array<string, mixed>, reason?: string}|null
     */
    public function enrolPoll(array $details, string $token, string $session, bool $deadlinePassed): ?array
    {
        $call = fn (string $method, string $path, ?array $body = null): array => $this->call($details, $method, $path, $body, $token);
        try {
            $payload = $call('GET', '/api/v1/developer/credentials/nfc_cards/sessions/'.rawurlencode($session));
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $cardToken = $data['token'] ?? null;
            if (! is_string($cardToken) || $cardToken === '') {
                if (! $deadlinePassed) {
                    return null;
                }
                $this->forgetSession($call, $session);

                return ['state' => 'failed', 'reason' => 'No card was tapped on the reader in time. Nothing was added.'];
            }
            $call('PUT', '/api/v1/developer/users/'.rawurlencode($details['params']['user_id']).'/nfc_cards', ['token' => $cardToken, 'force_add' => false]);

            return ['state' => 'succeeded', 'result' => [
                'token_hash' => hash('sha256', $cardToken),
                'display_id' => is_string($data['card_id'] ?? null) ? mb_substr($data['card_id'], 0, 32) : null,
            ]];
        } catch (ConsoleRefused $refused) {
            $this->forgetSession($call, $session);

            return ['state' => 'failed', 'reason' => $refused->getMessage()];
        } catch (ConsoleUnreachable) {
            // The card may or may not have been added: say so, never guess.
            return $deadlinePassed ? ['state' => 'uncertain', 'reason' => 'The hub lost the console while adding the card. Check the person in UniFi Access.'] : null;
        } catch (Throwable) {
            return ['state' => 'uncertain', 'reason' => 'UniFi Access sent an answer the hub couldn’t read. Check the person in UniFi Access.'];
        }
    }

    /** @param array<string, mixed> $params */
    private function userCreate(callable $call, array $params): array
    {
        $created = $call('POST', '/api/v1/developer/users', [
            'first_name' => $params['first_name'],
            'last_name' => $params['last_name'],
            'employee_number' => $params['employee_number'],
        ]);
        $userId = UnifiAccessReader::id($created['data']['id'] ?? null);
        if ($userId === null) {
            return ['state' => 'uncertain', 'reason' => 'UniFi Access didn’t say who it added. Check UniFi Access before trying again.'];
        }
        if ($params['policy_ids'] !== []) {
            try {
                $call('PUT', '/api/v1/developer/users/'.rawurlencode($userId).'/access_policies', ['access_policy_ids' => $params['policy_ids']]);
            } catch (Throwable) {
                return ['state' => 'failed', 'reason' => 'Added to UniFi Access, but their doors couldn’t be set. Set them on the People tab.'];
            }
        }

        return ['state' => 'succeeded', 'result' => ['user_id' => $userId]];
    }

    /** @param array<string, mixed> $params */
    private function userStatus(callable $call, array $params): array
    {
        $path = '/api/v1/developer/users/'.rawurlencode($params['user_id']);
        try {
            $call('PUT', $path, ['status' => strtoupper($params['status'])]);
        } catch (ConsoleUnreachable) {
            // No answer: read it back before saying anything.
        }
        $status = strtolower((string) ($call('GET', $path)['data']['status'] ?? ''));

        return $status === $params['status']
            ? ['state' => 'succeeded', 'result' => ['status' => $status]]
            : ['state' => 'failed', 'reason' => 'UniFi Access still shows them as '.($status === '' ? 'unknown' : $status).'.'];
    }

    /** @param array<string, mixed> $params */
    private function userPolicies(callable $call, array $params): array
    {
        $path = '/api/v1/developer/users/'.rawurlencode($params['user_id']);
        try {
            $call('PUT', $path.'/access_policies', ['access_policy_ids' => $params['policy_ids']]);
        } catch (ConsoleUnreachable) {
            // No answer: read it back before saying anything.
        }
        $user = $call('GET', $path.'?expand%5B%5D=access_policy')['data'] ?? [];
        $ids = [];
        foreach (is_array($user['access_policy_ids'] ?? null) ? $user['access_policy_ids'] : [] as $id) {
            $ids[] = UnifiAccessReader::id($id);
        }
        foreach (is_array($user['access_policies'] ?? null) ? $user['access_policies'] : [] as $policy) {
            $ids[] = is_array($policy) ? UnifiAccessReader::id($policy['id'] ?? null) : null;
        }
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));
        $wanted = $params['policy_ids'];
        sort($ids);
        sort($wanted);

        return $ids === $wanted
            ? ['state' => 'succeeded', 'result' => ['policy_ids' => $ids]]
            : ['state' => 'failed', 'reason' => 'UniFi Access didn’t take the new doors.'];
    }

    /** @param array<string, mixed> $params */
    private function enrolStart(callable $call, array $params): array
    {
        $payload = $call('POST', '/api/v1/developer/credentials/nfc_cards/sessions', ['device_id' => $params['reader_id'], 'reset_ua_card' => false]);
        $session = $payload['data']['session_id'] ?? null;
        if (! is_string($session) || preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $session) !== 1) {
            return ['state' => 'failed', 'reason' => 'The reader couldn’t start listening for a card.'];
        }

        return ['state' => 'waiting', 'enrol_session' => $session];
    }

    /** @param array<string, mixed> $params */
    private function cardRemove(callable $call, array $params): array
    {
        $path = '/api/v1/developer/users/'.rawurlencode($params['user_id']);
        $cards = $call('GET', $path)['data']['nfc_cards'] ?? [];
        foreach (is_array($cards) ? $cards : [] as $card) {
            $cardToken = is_array($card) ? ($card['token'] ?? null) : null;
            if (is_string($cardToken) && hash_equals($params['token_hash'], hash('sha256', $cardToken))) {
                $call('PUT', $path.'/nfc_cards/delete', ['token' => $cardToken]);

                return ['state' => 'succeeded', 'result' => ['removed' => true]];
            }
        }

        // Already gone from this person: what was asked for is true.
        return ['state' => 'succeeded', 'result' => ['removed' => true]];
    }

    /** @param array<string, mixed> $params */
    private function visitorCreate(callable $call, array $params): array
    {
        $payload = $call('POST', '/api/v1/developer/visitors', [
            'first_name' => $params['first_name'],
            'last_name' => $params['last_name'],
            'start_time' => $params['starts_at'],
            'end_time' => $params['ends_at'],
            'visit_reason' => 'Others',
            'resources' => array_map(static fn (string $id): array => ['id' => $id, 'type' => 'door'], $params['door_ids']),
        ]);
        $visitorId = UnifiAccessReader::id($payload['data']['id'] ?? null);

        return $visitorId === null
            ? ['state' => 'uncertain', 'reason' => 'UniFi Access didn’t say which pass it made. Check UniFi Access before trying again.']
            : ['state' => 'succeeded', 'result' => ['visitor_id' => $visitorId]];
    }

    /** @param array<string, mixed> $params */
    private function visitorDelete(callable $call, array $params): array
    {
        $call('DELETE', '/api/v1/developer/visitors/'.rawurlencode($params['visitor_id']).'?is_force=true');

        return ['state' => 'succeeded', 'result' => ['deleted' => true]];
    }

    private function forgetSession(callable $call, string $session): void
    {
        try {
            $call('DELETE', '/api/v1/developer/credentials/nfc_cards/sessions/'.rawurlencode($session));
        } catch (Throwable) {
            // The console drops it by itself.
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function call(array $details, string $method, string $path, ?array $body, string $token): array
    {
        $address = (string) $details['address'];
        $origin = 'https://'.(str_contains($address, ':') ? '['.$address.']' : $address).':'.(int) $details['port'];
        if ($method !== 'GET') {
            $this->wrote = true;
        }
        $response = $this->network->request(
            $method,
            $origin.$path,
            ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'],
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            (string) $details['trusted_pin'],
            self::CONNECT_TIMEOUT,
            self::RESPONSE_TIMEOUT,
            self::MAX_BYTES,
        );
        $status = (int) $response['status'];
        if ($status === 0 || ($status >= 500 && $method !== 'GET')) {
            throw new ConsoleUnreachable;
        }
        if ($status === 401 || $status === 403) {
            throw new ConsoleRefused('UniFi Access refused the token. It needs rights to change users, cards and visitors.');
        }
        if ($status === 404) {
            throw new ConsoleRefused('UniFi Access doesn’t have that person, card, reader or pass any more.');
        }
        if ($status === 429) {
            throw new ConsoleRefused('UniFi Access asked the hub to slow down. Try again in a minute.');
        }
        try {
            $payload = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ConsoleRefused('UniFi Access answered '.$status.'.');
        }
        if ($status !== 200 || ! is_array($payload) || ($payload['code'] ?? null) !== 'SUCCESS') {
            $message = is_array($payload) && is_string($payload['msg'] ?? null) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/', '', $payload['msg']) ?? ''), 0, 120) : '';
            throw new ConsoleRefused('UniFi Access didn’t make the change'.($message !== '' ? ': '.$message : '.'));
        }

        return $payload;
    }
}
