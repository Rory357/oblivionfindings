<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\SecurityDevices\Endpoints\Models\EndpointDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/** Private content only. Canonical result and command owners remain unchanged. */
final class EndpointScriptOutputs
{
    public const VIEW = 'securityDevices.endpoints.scriptOutputView';

    public function receive(EndpointDelivery $delivery, stdClass $command, string $attemptUuid, stdClass $header, string $bytes): array
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        $result = $this->result($delivery, $command);
        try {
            EndpointScriptContract::output($header, $bytes, $result, $command, $delivery->payload_hash);
        } catch (InvalidArgumentException) {
            abort(422, 'Invalid script output.');
        }
        $row = $this->row($delivery);
        if ($row) {
            // A successful historical acknowledgement never restores purged content.
            $receipt = $this->receipt($row, $delivery, $command, $attemptUuid);
            $this->expire($row);

            return ['receipt' => $receipt, 'created' => false];
        }
        $expiry = $delivery->completed_at->addHours($command->parameters->manifest->execution_policy->output_retention_hours)->startOfSecond();
        abort_unless($expiry->isFuture(), 410);
        $receipt = (object) [
            'command_uuid' => $command->command_uuid, 'attempt_uuid' => $attemptUuid,
            'payload_hash' => $delivery->payload_hash, 'sha256' => $header->sha256,
            'size_bytes' => $header->size_bytes, 'truncated' => $header->truncated,
            'received_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $expiry->utc()->format('Y-m-d\TH:i:s\Z'), 'accepted' => true,
        ];
        $json = EndpointScriptContract::encode($receipt);
        DB::table('endpoint_script_outputs')->insert([
            'endpoint_delivery_id' => $delivery->id, 'receipt_json' => $json, 'receipt_hash' => hash('sha256', $json),
            'encrypted_content' => Crypt::encryptString($bytes), 'expires_at' => $expiry,
        ]);

        return ['receipt' => $receipt, 'created' => true];
    }

    public function read(EndpointDelivery $delivery, stdClass $command, string $attemptUuid, bool $content): array
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        $row = $this->row($delivery);
        abort_unless($row, 404);
        $receipt = $this->receipt($row, $delivery, $command, $attemptUuid);
        $this->expire($row);
        $available = $row->purged_at === null && $row->encrypted_content !== null;
        $response = ['receipt' => $receipt, 'content_available' => $available,
            'purged_at' => $row->purged_at ? CarbonImmutable::parse($row->purged_at, 'UTC')->format('Y-m-d\TH:i:s\Z') : null];
        if ($content && $available) {
            try {
                $bytes = Crypt::decryptString($row->encrypted_content);
                EndpointScriptContract::output((object) array_intersect_key(get_object_vars($receipt), array_flip(['command_uuid', 'payload_hash', 'sha256', 'size_bytes', 'truncated'])),
                    $bytes, $this->result($delivery, $command), $command, $delivery->payload_hash);
            } catch (DecryptException|InvalidArgumentException) {
                abort(409);
            }
            $response['bytes'] = $bytes;
        }

        return $response;
    }

    public function purge(int $batch = 100): int
    {
        abort_unless($batch >= 1 && $batch <= 500, 422);
        $ids = DB::table('endpoint_script_outputs')->whereNull('purged_at')->where('expires_at', '<=', now())->orderBy('id')->limit($batch)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            try {
                $count += DB::transaction(function () use ($id): int {
                    $row = DB::table('endpoint_script_outputs')->where('id', $id)->lock('for update nowait')->first();

                    return $row && $this->expire($row) ? 1 : 0;
                });
            } catch (QueryException $error) {
                if (! in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                    throw $error;
                }
            }
        }

        return $count;
    }

    private function result(EndpointDelivery $delivery, stdClass $command): stdClass
    {
        abort_unless($delivery->result_hash && $delivery->completed_at && is_array($delivery->result), 409);
        $data = $delivery->result;
        ksort($data);
        abort_unless(hash_equals($delivery->result_hash, hash('sha256', EndpointScriptContract::encode((object) $data))), 409);
        $result = (object) $data;
        EndpointScriptContract::result($result, $command, $delivery->payload_hash);

        return $result;
    }

    private function row(EndpointDelivery $delivery): ?object
    {
        return DB::table('endpoint_script_outputs')->where('endpoint_delivery_id', $delivery->id)->lock('for update nowait')->first();
    }

    private function receipt(object $row, EndpointDelivery $delivery, stdClass $command, string $attemptUuid): stdClass
    {
        abort_unless(hash_equals($row->receipt_hash, hash('sha256', $row->receipt_json)), 409);
        $receipt = EndpointScriptContract::decode($row->receipt_json, 4096);
        $keys = array_keys(get_object_vars($receipt));
        sort($keys);
        $expected = ['command_uuid', 'attempt_uuid', 'payload_hash', 'sha256', 'size_bytes', 'truncated', 'received_at', 'expires_at', 'accepted'];
        sort($expected);
        abort_unless($keys === $expected && is_string($receipt->received_at)
            && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $receipt->received_at), 409);
        $result = $this->result($delivery, $command);
        $expiry = $delivery->completed_at->addHours($command->parameters->manifest->execution_policy->output_retention_hours)->startOfSecond();
        abort_unless(count(get_object_vars($receipt)) === 9 && ($receipt->accepted ?? null) === true
            && ($receipt->command_uuid ?? null) === $command->command_uuid && ($receipt->attempt_uuid ?? null) === $attemptUuid
            && ($receipt->payload_hash ?? null) === $delivery->payload_hash
            && $receipt->sha256 === $result->output_sha256 && $receipt->size_bytes === $result->output_bytes && $receipt->truncated === $result->output_truncated
            && ($receipt->expires_at ?? null) === $expiry->utc()->format('Y-m-d\TH:i:s\Z')
            && CarbonImmutable::parse($row->expires_at, 'UTC')->equalTo($expiry), 409);
        abort_unless(($row->encrypted_content === null) === ($row->purged_at !== null), 409);

        return $receipt;
    }

    private function expire(object $row): bool
    {
        if ($row->purged_at !== null || CarbonImmutable::parse($row->expires_at, 'UTC')->isFuture()) {
            return false;
        }
        $row->encrypted_content = null;
        $row->purged_at = now()->utc()->format('Y-m-d H:i:s');
        DB::table('endpoint_script_outputs')->where('id', $row->id)->update(['encrypted_content' => null, 'purged_at' => $row->purged_at]);

        return true;
    }
}
