<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\HsConsultation;
use App\Models\HsRepresentative;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every worker participation write follows the register's Site rule (Stephan, 25 Oct, option A "all participation"):
 * a manager with hazards.manage writes only at their own H&S Sites, healthSafety.viewAllSites covers every Site, and
 * a record with no Site needs that all-Sites access. A new record must name a Site.
 */
final class WorkerParticipationSiteWriteTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/health-safety/worker-participation';

    private const CREATES = ['committee create', 'representative create', 'consultation create'];

    private Site $own;

    private Site $other;

    private User $manager;

    private User $wide;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(Carbon::parse('2026-01-01T00:00:00Z'));
        $this->seed(RbacSeeder::class);
        Notification::fake();
        Storage::fake('private');
        $this->own = Site::factory()->create(['type' => 'house', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['type' => 'house', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->manager = $this->staff(['hazards.view', 'hazards.manage']);
        $this->wide = $this->staff(['hazards.view', 'hazards.manage', 'healthSafety.viewAllSites']);
        $this->member = $this->staff([]);
    }

    /** @return array<string, array{string}> */
    public static function writes(): array
    {
        $writes = [...self::CREATES, 'representative edit', 'consultation edit', 'consultation status', 'consultation document',
            'meeting create', 'meeting edit', 'meeting attendees', 'meeting complete', 'meeting cancel', 'meeting minutes'];

        return array_combine($writes, array_map(fn (string $write): array => [$write], $writes));
    }

    #[DataProvider('writes')]
    public function test_own_site_write_is_allowed(string $write): void
    {
        [$response, $changed] = $this->perform($this->manager, $write, $this->own);

        $this->assertAllowed($response, $changed);
    }

    #[DataProvider('writes')]
    public function test_other_site_write_is_refused(string $write): void
    {
        [$response, $changed] = $this->perform($this->manager, $write, $this->other);

        // A new record naming another Site is refused (403); another Site's record is not found (404), as in the register.
        $response->assertStatus(in_array($write, self::CREATES, true) ? 403 : 404);
        $this->assertFalse($changed(), "{$write} changed another Site's records.");
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    #[DataProvider('writes')]
    public function test_all_sites_access_covers_another_site(string $write): void
    {
        [$response, $changed] = $this->perform($this->wide, $write, $this->other);

        $this->assertAllowed($response, $changed);
    }

    #[DataProvider('writes')]
    public function test_siteless_record_needs_all_sites_access(string $write): void
    {
        [$response, $changed] = $this->perform($this->manager, $write, null);

        if (in_array($write, self::CREATES, true)) {
            // A new record must name a Site, whoever writes it.
            $response->assertUnprocessable()->assertJsonValidationErrors('site_id');
        } else {
            $response->assertNotFound();
        }
        $this->assertFalse($changed(), "{$write} changed a Site-less record without all-Sites access.");

        if (! in_array($write, self::CREATES, true)) {
            [$response, $changed] = $this->perform($this->wide, $write, null);
            $this->assertAllowed($response, $changed);
        }
    }

    private function assertAllowed(TestResponse $response, \Closure $changed): void
    {
        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($changed(), 'The allowed write did not take effect.');
    }

    /**
     * One participation write by $actor against a record at $site (null = a record with no Site).
     *
     * @return array{0: TestResponse, 1: \Closure(): bool}
     */
    private function perform(User $actor, string $write, ?Site $site): array
    {
        $siteId = $site?->id;
        $request = $this->actingAs($actor)->withHeaders(['Accept' => 'application/json']);
        $pdf = fn (string $name): UploadedFile => UploadedFile::fake()->create($name, 8, 'application/pdf');

        switch ($write) {
            case 'committee create':
                $name = 'Probe committee '.Str::random(6);
                return [$request->post(self::BASE.'/committees', ['name' => $name, 'site_id' => $siteId, 'meeting_frequency' => 'monthly',
                    'established_at' => '2025-12-01', 'members' => [$this->member->id]]), fn (): bool => HsCommittee::where('name', $name)->exists()];
            case 'representative create':
                $person = $this->staff([]);
                return [$request->post(self::BASE.'/representatives', ['user_id' => $person->id, 'site_id' => $siteId,
                    'election_method' => 'elected', 'elected_at' => '2025-12-01']), fn (): bool => HsRepresentative::where('user_id', $person->id)->exists()];
            case 'consultation create':
                $title = 'Probe consultation '.Str::random(6);
                return [$request->post(self::BASE.'/consultations', ['title' => $title, 'consultation_type' => 'general',
                    'description' => 'A participation consultation.', 'site_id' => $siteId, 'consultation_date' => '2025-12-15']),
                    fn (): bool => HsConsultation::where('title', $title)->exists()];
            case 'representative edit':
                $representative = $this->representative($siteId);
                return [$request->put(self::BASE.'/representatives/'.$representative->id, ['work_group' => 'Night shift']),
                    fn (): bool => $representative->fresh()->work_group === 'Night shift'];
            case 'consultation edit':
                $consultation = $this->consultation($siteId);
                return [$request->put(self::BASE.'/consultations/'.$consultation->id, ['title' => 'Changed title']),
                    fn (): bool => $consultation->fresh()->title === 'Changed title'];
            case 'consultation status':
                $consultation = $this->consultation($siteId);
                return [$request->put(self::BASE.'/consultations/'.$consultation->id.'/status', ['status' => 'feedback_received']),
                    fn (): bool => $consultation->fresh()->status === 'feedback_received'];
            case 'consultation document':
                $consultation = $this->consultation($siteId);
                return [$request->post(self::BASE.'/consultations/'.$consultation->id.'/documents', ['type' => 'document', 'document' => $pdf('notes.pdf')]),
                    fn (): bool => $consultation->fresh()->document_path !== null];
            case 'meeting create':
                $committee = $this->committee($siteId);
                return [$request->post(self::BASE.'/committees/'.$committee->id.'/meetings', ['scheduled_at' => '2026-01-15T12:30',
                    'scheduled_timezone' => 'Pacific/Auckland', 'attendees' => [$this->member->id]]),
                    fn (): bool => HsCommitteeMeeting::where('hs_committee_id', $committee->id)->exists()];
            case 'meeting edit':
                $meeting = $this->meeting($siteId);
                return [$request->put(self::BASE.'/meetings/'.$meeting->id, ['location' => 'Lounge']),
                    fn (): bool => $meeting->fresh()->location === 'Lounge'];
            case 'meeting attendees':
                $meeting = $this->meeting($siteId);
                return [$request->post(self::BASE.'/meetings/'.$meeting->id.'/attendees', ['user_ids' => [$this->member->id]]),
                    fn (): bool => $meeting->attendeeUsers()->whereKey($this->member->id)->exists()];
            case 'meeting complete':
                $meeting = $this->meeting($siteId);
                return [$request->put(self::BASE.'/meetings/'.$meeting->id.'/complete', ['minutes' => 'Recorded minutes.']),
                    fn (): bool => $meeting->fresh()->status === 'completed'];
            case 'meeting cancel':
                $meeting = $this->meeting($siteId);
                return [$request->put(self::BASE.'/meetings/'.$meeting->id.'/cancel'),
                    fn (): bool => $meeting->fresh()->status === 'cancelled'];
            case 'meeting minutes':
                $meeting = $this->meeting($siteId);
                return [$request->post(self::BASE.'/meetings/'.$meeting->id.'/minutes', ['document' => $pdf('minutes.pdf')]),
                    fn (): bool => $meeting->fresh()->minutes_document_path !== null];
        }

        throw new \LogicException("Unknown participation write {$write}.");
    }

    private function staff(array $permissions): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->own->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null]);
        foreach ($permissions as $key) {
            $user->permissionOverrides()->attach(Permission::where('key', $key)->sole(), ['allowed' => true]);
        }

        return $user;
    }

    private function representative(?int $siteId): HsRepresentative
    {
        return HsRepresentative::create(['user_id' => $this->staff([])->id, 'site_id' => $siteId, 'election_method' => 'elected',
            'elected_at' => '2025-06-01', 'status' => 'active', 'training_days_completed' => 2, 'created_by' => $this->manager->id]);
    }

    private function consultation(?int $siteId): HsConsultation
    {
        return HsConsultation::create(['title' => 'Hoist procedure', 'consultation_type' => 'procedure_change', 'description' => 'Revised transfers.',
            'site_id' => $siteId, 'consultation_date' => '2025-12-15', 'status' => 'open',
            'initiated_by' => $this->manager->id, 'created_by' => $this->manager->id]);
    }

    private function committee(?int $siteId): HsCommittee
    {
        return HsCommittee::create(['name' => 'Site H&S committee', 'site_id' => $siteId, 'meeting_frequency' => 'monthly',
            'established_at' => '2025-01-01', 'status' => 'active', 'members' => [], 'created_by' => $this->manager->id]);
    }

    private function meeting(?int $siteId): HsCommitteeMeeting
    {
        return HsCommitteeMeeting::create(['hs_committee_id' => $this->committee($siteId)->id, 'scheduled_at' => now()->addDays(5),
            'status' => 'scheduled', 'location' => 'Original room', 'created_by' => $this->manager->id]);
    }
}
