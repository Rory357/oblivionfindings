<?php

namespace Tests\Feature\HealthSafety;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * G4-14 (board approved by Stephan 1 Nov 2026): the Chemical register's SDS reviews overdue count and the one lead
 * block — active chemicals whose safety data sheet review is overdue first, then those with no current sheet. A current
 * sheet never leads. Physical SDS commands need committed fixtures.
 */
final class ChemicalRegisterSummaryTest extends ChemicalCommandTestCase
{
    protected bool $physicalCommits = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function sheet($root, string $review): void
    {
        $file = UploadedFile::fake()->createWithContent('recorded-sds.pdf', "%PDF-1.4\n".str_repeat("recorded sheet\n", 280)."%%EOF\n");
        $this->service()->uploadSds($root, $this->manager, $file,
            $this->command($root, ['version' => 'Provided SDS', 'issue_date' => '2021-03-01', 'review_date' => $review]));
    }

    public function test_overdue_sds_reviews_lead_then_chemicals_with_no_current_sheet(): void
    {
        $missing = $this->catalogue(['name' => 'Oven cleaner']);
        $overdue = $this->catalogue(['name' => 'Bleach, 5 L']);
        $this->sheet($overdue->fresh(), '2026-09-01');
        $current = $this->catalogue(['name' => 'Hand soap']);
        $this->sheet($current->fresh(), '2027-09-01');

        $this->actingAs($this->manager)->getJson('/health-safety/substances')->assertOk()
            ->assertJsonPath('register_summary.sds_overdue', 1)
            ->assertJsonPath('register_summary.lead_total', 2)
            ->assertJsonPath('register_summary.lead.0.id', $overdue->id)
            ->assertJsonPath('register_summary.lead.0.sds_state', 'expired')
            ->assertJsonPath('register_summary.lead.1.id', $missing->id)
            ->assertJsonPath('register_summary.lead.1.sds_state', 'missing')
            ->assertJsonCount(2, 'register_summary.lead');
    }
}
