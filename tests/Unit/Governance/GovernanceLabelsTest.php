<?php

namespace Tests\Unit\Governance;

use App\Domain\Governance\Support\GovernanceLabels;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class GovernanceLabelsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $path = base_path('tests/fixtures/governance/labels.json');

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_label_maps_match_the_shared_fixture_used_by_the_typescript_mirror(): void
    {
        $this->assertSame($this->fixture()['labels'], GovernanceLabels::LABELS);
    }

    public function test_humanise_and_sentence_match_the_shared_fixture(): void
    {
        $fixture = $this->fixture();

        foreach ($fixture['humanise'] as $input => $expected) {
            $this->assertSame($expected, GovernanceLabels::humanise((string) $input), "humanise({$input})");
        }

        foreach ($fixture['sentence'] as $input => $expected) {
            $this->assertSame($expected, GovernanceLabels::sentence((string) $input), "sentence({$input})");
        }
    }

    public function test_it_labels_representative_values_with_the_approved_vocabulary(): void
    {
        $this->assertSame('Open for voting', GovernanceLabels::label('resolution_status', 'open'));
        $this->assertSame('Done', GovernanceLabels::label('resolution_status', 'implemented'));
        $this->assertSame('Passed', GovernanceLabels::label('resolution_outcome', 'carried'));
        $this->assertSame('Not passed', GovernanceLabels::label('resolution_outcome', 'defeated'));
        $this->assertSame('No decision — not enough members took part', GovernanceLabels::label('resolution_outcome', 'no_quorum'));
        $this->assertSame('More For than Against', GovernanceLabels::label('voting_threshold', 'simple_majority'));
        $this->assertSame('At least two-thirds For', GovernanceLabels::label('voting_threshold', 'two_thirds'));
        $this->assertSame('Everyone entitled votes For', GovernanceLabels::label('voting_threshold', 'unanimous'));
        $this->assertSame('Board-only session', GovernanceLabels::label('meeting_type', 'executive_session'));
        $this->assertSame('Safety of the people we support', GovernanceLabels::label('risk_category', 'client_safety'));
        $this->assertSame('Reduce it', GovernanceLabels::label('risk_strategy', 'treat'));
        $this->assertSame('Live with it and monitor', GovernanceLabels::label('risk_strategy', 'tolerate'));
        $this->assertSame('Almost certain', GovernanceLabels::label('risk_likelihood', 5));
        $this->assertSame('Ngā Paerewa Health and Disability Services Standard', GovernanceLabels::label('compliance_framework', 'nga_paerewa'));
        $this->assertSame('Not due yet', GovernanceLabels::label('compliance_status', 'not_due'));
        $this->assertSame('Replaced by a newer version', GovernanceLabels::label('policy_status', 'superseded'));
        $this->assertSame('Waiting for the board', GovernanceLabels::label('budget_status', 'proposed'));
        $this->assertSame('Move money between lines', GovernanceLabels::label('budget_change_type', 'reallocate'));
        $this->assertSame('Waiting for approval', GovernanceLabels::label('spend_status', 'submitted'));
        $this->assertSame('Waiting for the board', GovernanceLabels::label('performance_review_status', 'board_review'));
        $this->assertSame('Part of everyday practice', GovernanceLabels::label('te_tiriti_status', 'embedded'));
        $this->assertSame('Partnership', GovernanceLabels::label('te_tiriti_principle', 'partnership'));
        $this->assertSame("Observer (can't vote)", GovernanceLabels::label('board_role', 'observer'));
    }

    public function test_it_resolves_class_names_and_audit_event_keys(): void
    {
        $this->assertSame('Board pack', GovernanceLabels::label('audit_entity_type', 'App\\Domain\\Governance\\Models\\BoardPack'));
        $this->assertSame('Spend request', GovernanceLabels::label('audit_entity_type', 'SpendApproval'));
        $this->assertSame('voted on a resolution', GovernanceLabels::auditEvent('resolution.voted'));
        $this->assertSame('approved minutes', GovernanceLabels::auditEvent('minutes.approved'));
        $this->assertSame('confirmed reading a policy', GovernanceLabels::auditEvent('policy.attested'));
        $this->assertSame('resolution voting reopened', GovernanceLabels::auditEvent('resolution.voting_reopened'));
    }

    public function test_missing_and_unknown_values_never_show_raw_keys(): void
    {
        $this->assertSame('Not set', GovernanceLabels::label('priority', null));
        $this->assertSame('Not set', GovernanceLabels::label('priority', '  '));

        foreach (['brand_new_status', 'some.dotted-key', 'App\\Models\\SomethingNew', 'escalated_to_board'] as $value) {
            foreach (array_keys(GovernanceLabels::LABELS) as $domain) {
                $label = GovernanceLabels::label($domain, $value);
                $this->assertStringNotContainsString('_', $label, "{$domain}:{$value}");
                $this->assertStringNotContainsString('\\', $label, "{$domain}:{$value}");
                $this->assertMatchesRegularExpression('/^\p{Lu}/u', $label, "{$domain}:{$value}");
            }
        }

        $this->assertSame('Escalated to board', GovernanceLabels::label('compliance_status', 'escalated_to_board'));

        foreach (GovernanceLabels::LABELS as $domain => $map) {
            foreach ($map as $value => $label) {
                $this->assertStringNotContainsString('_', $label, "{$domain}.{$value}");
            }
        }
    }

    public function test_money_formats_nzd(): void
    {
        $this->assertSame('$85,000', GovernanceLabels::money(85000));
        $this->assertSame('$85,000.50', GovernanceLabels::money(85000.5));
        $this->assertSame('$85,000.00', GovernanceLabels::money(85000, cents: true));
        $this->assertSame('$85,001', GovernanceLabels::money(85000.5, cents: false));
        $this->assertSame('-$1,200', GovernanceLabels::money(-1200));
        $this->assertSame('$0', GovernanceLabels::money(0));
        $this->assertSame('$1,234.56', GovernanceLabels::money('1234.56'));
        $this->assertSame('Not stated', GovernanceLabels::money(null));
        $this->assertSame('Not stated', GovernanceLabels::money(''));
    }

    public function test_ref_and_threshold_explanation(): void
    {
        $this->assertSame('Ref RES-2026-004', GovernanceLabels::ref('RES-2026-004'));
        $this->assertSame('', GovernanceLabels::ref(null));
        $this->assertStringContainsString('more voting members vote For than Against', GovernanceLabels::thresholdExplanation('simple_majority'));
        $this->assertStringContainsString('two-thirds', GovernanceLabels::thresholdExplanation('special'));
        $this->assertStringContainsString('every voting member votes For', GovernanceLabels::thresholdExplanation('unanimous'));
    }

    public function test_financial_year_uses_the_nz_july_to_june_year(): void
    {
        $this->assertSame('2025/26', GovernanceLabels::financialYear(2026));
        $this->assertSame('2025/26', GovernanceLabels::financialYear('2025-2026'));
        $this->assertSame('2025/26', GovernanceLabels::financialYear('FY2026'));
        $this->assertSame('2025/26', GovernanceLabels::financialYear('2025/26'));
        $this->assertSame('2025/26', GovernanceLabels::financialYear('2026-06'));
        $this->assertSame('2026/27', GovernanceLabels::financialYear('2026-07'));
        $this->assertSame('2025/26', GovernanceLabels::financialYear(CarbonImmutable::parse('2026-06-30', 'Pacific/Auckland')));
        $this->assertSame('2026/27', GovernanceLabels::financialYear(CarbonImmutable::parse('2026-07-01', 'Pacific/Auckland')));
        // 30 June 20:00 UTC is already 1 July in Auckland.
        $this->assertSame('2026/27', GovernanceLabels::financialYear(CarbonImmutable::parse('2026-06-30 20:00:00', 'UTC')));
        $this->assertSame('2099/00', GovernanceLabels::financialYear(2100));
    }

    public function test_date_formats_in_new_zealand_time(): void
    {
        // 05:00 UTC on 7 Sep 2026 is 5:00 pm NZST the same day.
        $instant = CarbonImmutable::parse('2026-09-07 05:00:00', 'UTC');
        $this->assertSame('7 September 2026', GovernanceLabels::date($instant));
        $this->assertSame('7 Sep 2026, 5:00 pm', GovernanceLabels::date($instant, withTime: true));

        // 14:30 UTC on 6 Sep is already 7 Sep in Auckland.
        $this->assertSame('7 Sep 2026, 2:30 am', GovernanceLabels::date('2026-09-06 14:30:00', true));
        $this->assertSame('7 September 2026', GovernanceLabels::date('2026-09-06T14:30:00Z'));

        // Calendar dates never shift day.
        $this->assertSame('7 September 2026', GovernanceLabels::date('2026-09-07'));
        $this->assertSame('Not set', GovernanceLabels::date(null));
        $this->assertSame('Not set', GovernanceLabels::date('not a date'));
        $this->assertSame('Not set', GovernanceLabels::date('2026-02-30'));
    }

    public function test_sentence_keeps_acronyms_and_proper_nouns(): void
    {
        $this->assertSame('CEO report for Q1', GovernanceLabels::sentence('CEO Report For Q1'));
        $this->assertSame('Te Tiriti o Waitangi commitments', GovernanceLabels::sentence('te tiriti o Waitangi Commitments'));
        // All-caps words read as acronyms and keep their capitals.
        $this->assertSame('Review NZTA and ACC reports', GovernanceLabels::sentence('Review NZTA And ACC Reports'));
        $this->assertSame('', GovernanceLabels::sentence('   '));
    }
}
