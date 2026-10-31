<?php

namespace App\Services\HealthSafety;

use App\Models\HsCommittee;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Only a named attendee's current minimal invitation; private register bodies are separate. */
final class WorkerParticipationInvitationReadService
{
    public function __construct(private readonly WorkerParticipationRecipientSourceService $sources) {}

    public function show(User $recipient, int $meetingId, ?HealthSafetyReadContext $context = null): array
    {
        return $this->read($recipient, $meetingId, $context, false);
    }

    /** Native JSON encoding occurs before the original source collector finishes. */
    public function json(User $recipient, int $meetingId): string
    {
        return $this->read($recipient, $meetingId, null, true);
    }

    private function read(User $recipient, int $meetingId, ?HealthSafetyReadContext $context, bool $encode): array|string
    {
        return $this->sources->withSource($recipient, $meetingId, 'attendee', null,
            function ($current, $reads, $active, $proof, $descriptor, $source) use ($meetingId, $encode): array|string {
                foreach ([$current->id, $descriptor['meeting_id'], $descriptor['committee_id'], $descriptor['site_id']] as $id) {
                    abort_unless($id === null || ((int) $id > 0 && (int) $id <= 9007199254740991), 404);
                }
                $committee = $proof->protect($reads->query(HsCommittee::query())->whereKey($descriptor['committee_id'])
                    ->whereExists((clone $source)->selectRaw('1')->toBase()))->first(['id', 'name']);
                abort_unless($committee, 404);
                $site = $descriptor['site_id'] === null ? null : $proof->protect($reads->query(Site::query())->whereKey($descriptor['site_id'])
                    ->whereExists((clone $source)->selectRaw('1')->toBase()))->first(['id', 'name']);
                abort_unless($descriptor['site_id'] === null || $site, 404);
                $attendance = $reads->query(DB::table('hs_meeting_attendees'))->where('meeting_id', $meetingId)->where('user_id', $current->id)
                    ->whereExists((clone $source)->selectRaw('1')->toBase())->first(['response', 'attended']);
                abort_unless($attendance, 404);
                $canOpenRegister = $current->canDo('hazards.view');
                $data = ['meeting' => ['id' => $meetingId,
                    'scheduled_at' => CarbonImmutable::parse($descriptor['scheduled_at'], 'UTC')->toJSON(),
                    'location' => $descriptor['location'], 'status' => $descriptor['meeting_status'],
                    'committee' => ['id' => (int) $committee->id, 'name' => $committee->name],
                    'site' => $site === null ? null : ['id' => (int) $site->id, 'name' => $site->name],
                    'site_ownership' => $site === null ? 'unconfirmed' : 'recorded',
                    'attendance' => ['response' => (string) $attendance->response, 'attended' => (bool) $attendance->attended]],
                    'context' => ['can_open_register' => $canOpenRegister, 'register_href' => $canOpenRegister
                        ? '/health-safety/worker-participation?tab=meetings&meeting='.$meetingId : null],
                    'source_viewer_id' => (int) $current->id];
                return $encode ? json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $data;
            }, $context);
    }
}
