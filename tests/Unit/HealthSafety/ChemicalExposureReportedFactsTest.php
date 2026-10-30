<?php

namespace Tests\Unit\HealthSafety;

use App\Services\HealthSafety\ChemicalExposureReportedFacts as Facts;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ChemicalExposureReportedFactsTest extends TestCase
{
    private function identity(array $changes = []): array
    {
        return ['intent_id' => 10, 'revision' => 1, 'actor_id' => 20, 'exposure_id' => 30,
            'worker_id' => 40, 'site_id' => 50, 'substance_id' => 60, ...$changes];
    }
    private function tuple(array $changes = []): array
    {
        return ['exposure_id' => 30, 'worker_id' => 40, 'site_id' => 50, 'substance_id' => 60, ...$changes];
    }
    private function snapshot(array $changes = []): array
    {
        return ['medical_attention_sought' => null, 'incident_reported' => null, 'medical_treatment' => null, ...$changes];
    }

    public function test_omission_explicit_unknown_and_no_have_distinct_truthful_origins(): void
    {
        $s = $this->snapshot(['incident_reported' => false]);
        $c = Facts::capture($s, ['medical_attention_sought' => null, 'incident_reported' => false], $this->identity());
        $this->assertSame('reported_unknown', $c['medical_attention_sought']['origin']);
        $this->assertSame('reported', $c['incident_reported']['origin']);
        $this->assertSame('unanswered', $c['medical_treatment']['origin']);
        $this->assertNull(Facts::project($c, $this->tuple())['medical_attention_sought']);
        $this->assertFalse(Facts::project($c, $this->tuple())['incident_reported']);
    }

    public function test_attention_yes_and_first_aid_or_none_remain_independent(): void
    {
        foreach (['first_aid', 'none'] as $category) {
            $s = $this->snapshot(['medical_attention_sought' => true, 'medical_treatment' => $category]);
            $c = Facts::capture($s, ['medical_attention_sought' => true, 'medical_treatment' => $category], $this->identity());
            $this->assertTrue(Facts::project($c, $this->tuple())['medical_attention_sought']);
            $this->assertSame($category, Facts::project($c, $this->tuple())['medical_treatment']);
        }
    }

    public function test_category_only_never_manufactures_an_attention_answer(): void
    {
        $s = $this->snapshot(['medical_treatment' => 'hospitalisation']);
        $c = Facts::capture($s, ['medical_treatment' => 'hospitalisation'], $this->identity());
        $this->assertNull($c['medical_attention_sought']['value']);
        $this->assertSame('unanswered', $c['medical_attention_sought']['origin']);
        $this->assertSame('high', Facts::response($c, $this->tuple())['response_floor']);
    }

    public function test_correction_omission_retains_original_actor_and_evidence(): void
    {
        $s = $this->snapshot(['medical_attention_sought' => true]);
        $c = Facts::capture($s, ['medical_attention_sought' => true], $this->identity());
        $after = [...$s, 'medical_treatment' => 'first_aid'];
        $next = Facts::transition($c, $s, $after, ['medical_treatment' => 'first_aid'], $this->identity(['intent_id' => 11, 'revision' => 2, 'actor_id' => 21]));
        $this->assertSame($c['medical_attention_sought'], $next['contract']['medical_attention_sought']);
        $this->assertSame(21, $next['contract']['medical_treatment']['reported_in']['actor_id']);
    }

    public function test_legacy_same_value_explicit_confirmation_is_a_provenance_change(): void
    {
        $s = $this->snapshot(['medical_attention_sought' => false]); $legacy = Facts::legacy($s);
        $next = Facts::transition($legacy, $s, $s, ['medical_attention_sought' => false], $this->identity());
        $this->assertTrue($next['provenance_changed']);
        $this->assertSame('reported', $next['contract']['medical_attention_sought']['origin']);
        $this->assertFalse(Facts::project($next['contract'], $this->tuple())['medical_attention_sought']);
        $this->assertNull(Facts::project($legacy, $this->tuple())['medical_attention_sought']);
    }

    public function test_identical_already_reported_confirmation_retains_original_evidence_as_noop(): void
    {
        $s = $this->snapshot(['incident_reported' => false]); $c = Facts::capture($s, ['incident_reported' => false], $this->identity());
        $next = Facts::transition($c, $s, $s, ['incident_reported' => false], $this->identity(['intent_id' => 11, 'revision' => 2, 'actor_id' => 21]));
        $this->assertFalse($next['provenance_changed']); $this->assertSame($c, $next['contract']);
    }

    public function test_explicit_unknown_is_not_the_same_as_omitted_unanswered(): void
    {
        $s = $this->snapshot(); $c = Facts::capture($s, [], $this->identity());
        $next = Facts::transition($c, $s, $s, ['incident_reported' => null], $this->identity(['intent_id' => 11, 'revision' => 2]));
        $this->assertTrue($next['provenance_changed']);
        $this->assertSame('reported_unknown', $next['contract']['incident_reported']['origin']);
    }

    public function test_child_transition_does_not_certify_any_legacy_answer(): void
    {
        $s = $this->snapshot(['medical_attention_sought' => false, 'medical_treatment' => 'medical']); $c = Facts::legacy($s);
        $next = Facts::transition($c, $s, $s, [], $this->identity());
        $this->assertFalse($next['provenance_changed']); $this->assertSame($c, $next['contract']);
        $this->assertSame('operational_triage', Facts::response($c, $this->tuple())['basis']);
    }

    public function test_rebound_worker_tuple_does_not_relabel_old_reports(): void
    {
        $s = $this->snapshot(['medical_attention_sought' => true]); $c = Facts::capture($s, ['medical_attention_sought' => true], $this->identity());
        $p = Facts::project($c, $this->tuple(['worker_id' => 41]));
        $this->assertNull($p['medical_attention_sought']); $this->assertTrue($p['fact_states']['medical_attention_sought']['recorded_value']);
        $this->assertSame('prior_context', $p['fact_states']['medical_attention_sought']['state']);
        $next = Facts::transition($c, $s, $s, ['medical_attention_sought' => true], $this->identity(['worker_id' => 41, 'revision' => 2]));
        $this->assertTrue($next['provenance_changed']);
        $this->assertTrue(Facts::project($next['contract'], $this->tuple(['worker_id' => 41]))['medical_attention_sought']);
    }

    public function test_selected_response_policy_preserves_category_floors_and_separate_review_need(): void
    {
        foreach (['none' => 'low', 'first_aid' => 'medium', 'medical' => 'high', 'hospitalisation' => 'high', 'death' => 'critical'] as $category => $floor) {
            $s = $this->snapshot(['medical_attention_sought' => true, 'medical_treatment' => $category]);
            $c = Facts::capture($s, ['medical_attention_sought' => true, 'medical_treatment' => $category], $this->identity());
            $r = Facts::response($c, $this->tuple()); $this->assertSame($floor, $r['response_floor']);
            $this->assertSame(in_array($category, ['none', 'first_aid'], true), $r['care_review_needed']);
            $this->assertSame('unassessed', $r['harm_assessment']);
        }
    }

    public function test_yes_without_category_is_high_and_unknown_is_medium_operational_triage(): void
    {
        $s = $this->snapshot(['medical_attention_sought' => true]);
        $yes = Facts::capture($s, ['medical_attention_sought' => true], $this->identity());
        $this->assertSame('high', Facts::response($yes, $this->tuple())['response_floor']);
        $unknown = Facts::capture($this->snapshot(), [], $this->identity()); $r = Facts::response($unknown, $this->tuple());
        $this->assertSame('medium', $r['response_floor']); $this->assertSame('operational_triage', $r['basis']);
        $this->assertTrue($r['care_review_needed']); $this->assertSame('unassessed', $r['harm_assessment']);
    }

    public function test_full_attention_category_matrix_marks_both_discordant_directions_without_overriding_facts(): void
    {
        foreach ([true, false, null] as $attention) {
            foreach ([null, ...Facts::CATEGORIES] as $category) {
                $s = $this->snapshot(['medical_attention_sought' => $attention, 'medical_treatment' => $category]);
                $c = Facts::capture($s, ['medical_attention_sought' => $attention, 'medical_treatment' => $category], $this->identity());
                $r = Facts::response($c, $this->tuple());
                $expected = $attention === null || $category === null
                    || ($attention === true && in_array($category, ['none', 'first_aid'], true))
                    || ($attention === false && in_array($category, ['medical', 'hospitalisation', 'death'], true));
                $this->assertSame($expected, $r['care_review_needed']);
                $this->assertSame($category, $r['recorded_category']);
                $this->assertSame('unassessed', $r['harm_assessment']);
                $this->assertSame($attention, Facts::project($c, $this->tuple())['medical_attention_sought']);
            }
        }
        $s = $this->snapshot(['medical_attention_sought' => false, 'medical_treatment' => 'hospitalisation']);
        $legacy = Facts::response(Facts::legacy($s), $this->tuple());
        $this->assertSame('medium', $legacy['response_floor']); $this->assertTrue($legacy['care_review_needed']);
        $c = Facts::capture($s, ['medical_attention_sought' => false, 'medical_treatment' => 'hospitalisation'], $this->identity());
        $prior = Facts::response($c, $this->tuple(['worker_id' => 41]));
        $this->assertSame('prior_context', $prior['category_state']); $this->assertSame('medium', $prior['response_floor']);
        $this->assertTrue($prior['care_review_needed']);
    }
    public function test_unknown_keys_malformed_scalar_and_false_reporting_evidence_refuse(): void
    {
        $c = Facts::capture($this->snapshot(), [], $this->identity());
        $bad = [];
        $bad[] = [...$c, 'diagnosis' => 'Invented'];
        $x = $c; $x['incident_reported']['value'] = 'false'; $bad[] = $x;
        $x = $c; $x['incident_reported']['origin'] = 'reported'; $bad[] = $x;
        $x = $c; $x['incident_reported']['reported_in'] = $this->identity(); $bad[] = $x;
        foreach ($bad as $contract) {
            try { Facts::assertContract($contract, $this->snapshot()); $this->fail('Malformed proof must refuse.'); }
            catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
        }
    }

    public function test_silently_changed_omitted_answer_and_minted_capture_default_refuse(): void
    {
        $s = $this->snapshot(); $c = Facts::capture($s, [], $this->identity());
        try { Facts::transition($c, $s, [...$s, 'incident_reported' => false], [], $this->identity()); $this->fail('Omitted value changed.'); }
        catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
        $this->expectException(LogicException::class); Facts::capture([...$s, 'incident_reported' => false], [], $this->identity());
    }

    public function test_last_unsigned_source_revision_is_representable_but_overflow_and_incomplete_snapshot_refuse(): void
    {
        $c = Facts::capture($this->snapshot(), [], $this->identity(['revision' => 4294967295]));
        $this->assertSame('unanswered', $c['incident_reported']['origin']);
        try { Facts::capture($this->snapshot(), [], $this->identity(['revision' => 4294967296])); $this->fail('Revision overflow accepted.'); }
        catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
        $this->expectException(LogicException::class); Facts::legacy(['medical_attention_sought' => false]);
    }
    public function test_string_or_missing_identity_and_wrong_current_tuple_refuse(): void
    {
        foreach ([$this->identity(['actor_id' => '20']), array_diff_key($this->identity(), ['revision' => true])] as $identity) {
            try { Facts::capture($this->snapshot(), [], $identity); $this->fail('Invalid exact identity accepted.'); }
            catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
        }
        $this->expectException(LogicException::class); Facts::project(Facts::legacy($this->snapshot()), $this->tuple(['site_id' => '50']));
    }
}
