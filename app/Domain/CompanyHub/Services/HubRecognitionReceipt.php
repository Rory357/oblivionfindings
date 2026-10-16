<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\HrRecognitionMedia;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Hub receipts describe the actual canonical HR send, never a text-matched record. */
class HubRecognitionReceipt
{
    public function __construct(
        private readonly HubAccess $access,
        private readonly HubRecognition $recognition,
        private readonly FeedService $feed,
    ) {}

    public function send(User $user, array $input): array
    {
        return app(HrRecognitionMedia::class)->guarded(fn (HrRecognitionMedia $media) => DB::transaction(function () use ($user, $input, $media): array {
            $actor = User::query()->whereKey($user->id)->lockForUpdate()->first();
            abort_unless($actor, 403);
            $this->access->assertEnter($actor);
            abort_unless($actor->canDo('hr.recognition.give'), 403);
            $data = Validator::make($input, [
                ...$this->feed->recognitionRules($input),
                'hub_receipt' => 'required|boolean|accepted',
                'actor_id' => 'required|integer|min:1|max:2147483647',
            ])->validate();
            abort_unless((int) $data['actor_id'] === (int) $actor->id, 409, 'Your signed-in account changed. Reopen recognition before sending.');

            $requestId = $data['client_request_id'] ?? null;
            $fingerprint = $data;
            unset($fingerprint['hub_receipt'], $fingerprint['client_request_id']);
            $fingerprint['images'] = array_map(fn ($image) => ['sha256' => hash_file('sha256', $image->getRealPath()), 'mime' => $image->getMimeType(), 'size' => $image->getSize()], $data['images'] ?? []);
            foreach (['mention_ids', 'to_user_ids'] as $key) {
                if (isset($fingerprint[$key])) {
                    $fingerprint[$key] = array_map('intval', $fingerprint[$key]);
                    sort($fingerprint[$key]);
                }
            }
            ksort($fingerprint);
            $payloadHash = hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));
            if ($requestId) {
                abort_unless(Schema::hasTable('hr_recognition_send_receipts'), 503, 'Recognition receipts are being updated. Keep your message and try again shortly.');
                $previous = DB::table('hr_recognition_send_receipts')->where('user_id', $actor->id)->where('request_id', $requestId)->first();
                if ($previous) {
                    abort_unless(hash_equals($previous->payload_hash, $payloadHash), 409, 'This send was already used for a different message. Start a new shout-out.');

                    return json_decode($previous->receipt, true, flags: JSON_THROW_ON_ERROR);
                }
            }

            if (empty($data['to_department_id'])) {
                $recipients = $data['to_user_ids'] ?? [$data['to_user_id']];
                $allowed = array_column($this->recognition->recipients($actor), 'id');
                if (array_diff(array_map('intval', $recipients), $allowed) !== []) {
                    throw ValidationException::withMessages([
                        isset($data['to_user_ids']) ? 'to_user_ids' : 'to_user_id' => 'Choose current colleagues available within your approved sites.',
                    ]);
                }
            }

            $created = $this->feed->sendRecognition($actor, $data, $media);
            abort_if($created->isEmpty(), 500, 'The recognition send could not be confirmed.');
            $records = $created->map(function (HrKudos $kudos) use ($actor): array {
                $reader = $this->recognition->describe($actor, $kudos);

                return [
                    'kudos_id' => (int) $kudos->id, 'feed_post_id' => (int) $kudos->feed_post_id,
                    'recipient_type' => $kudos->isTeamRecognition() ? 'team' : 'person',
                    'to_user_id' => $kudos->to_user_id === null ? null : (int) $kudos->to_user_id,
                    'to_department_id' => $kudos->isTeamRecognition() ? (int) $kudos->to_department_id : null,
                    'reader_url' => $reader ? HubRecognition::readerUrl((int) $kudos->id) : null,
                ];
            })->values()->all();

            $receipt = ['saved' => true, 'actor_id' => (int) $actor->id, 'records' => $records];
            if ($requestId) {
                DB::table('hr_recognition_send_receipts')->insert(['user_id' => $actor->id, 'request_id' => $requestId,
                    'payload_hash' => $payloadHash, 'receipt' => json_encode($receipt, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }

            return $receipt;
        }));
    }
}
