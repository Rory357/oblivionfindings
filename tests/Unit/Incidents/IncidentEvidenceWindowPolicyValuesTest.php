<?php

namespace Tests\Unit\Incidents;

use App\Services\Incidents\IncidentEvidenceWindowPolicyValues as Policy;
use App\Services\Incidents\IncidentRecordingReconstructionValues as Values;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Boots the app: invalid policies throw ValidationException, which needs the validator binding. */
final class IncidentEvidenceWindowPolicyValuesTest extends TestCase
{
    public function test_site_override_is_exact_and_does_not_expand_source_permissions(): void
    {
        $policy=$this->policy(); $override=$policy['rules'][0]; $override['identifier']='fall-site'; $override['site_id']=4; $override['source_types']=['response']; $policy['rules'][]=$override;
        $this->assertSame(['response'],Policy::effectiveRule($policy,'fall',4)['source_types']);
        $this->assertSame('fall-base',Policy::effectiveRule($policy,'fall',5)['identifier']);
        $this->assertNull(Policy::effectiveRule($policy,'unrecognised_legacy',4));
        $this->assertNull(Policy::effectiveRule(['schema_version'=>1,'enabled'=>false,'rules'=>[]],'fall',4));
    }

    public function test_rule_and_source_order_normalize_without_materialising_values(): void
    {
        $policy=$this->policy(); $policy['rules'][0]['source_types']=['response','camera'];
        $a=Policy::policy($policy); $policy['rules'][0]['source_types']=['camera','response'];
        $this->assertSame(Values::digest($a),Values::digest(Policy::policy($policy)));
        $this->assertSame(31,$a['rules'][0]['before_seconds']);
    }

    #[DataProvider('invalidChanges')]
    public function test_invalid_policies_never_become_disabled_or_default(string $change): void
    {
        $policy=$this->policy();
        if ($change==='missing_duration') { unset($policy['rules'][0]['before_seconds']); }
        elseif ($change==='boolean_duration') { $policy['rules'][0]['before_seconds']=true; }
        elseif ($change==='numeric_string') { $policy['rules'][0]['after_seconds']='42'; }
        elseif ($change==='unknown_trigger') { $policy['rules'][0]['trigger']='legacy_unknown'; }
        elseif ($change==='unknown_source') { $policy['rules'][0]['source_types']=['all']; }
        elseif ($change==='duplicate_source') { $policy['rules'][0]['source_types']=['camera','camera']; }
        elseif ($change==='inverted_bound') { $policy['rules'][0]['max_before_seconds']=30; }
        elseif ($change==='empty_enabled') { $policy['rules']=[]; }
        elseif ($change==='disabled_with_rules') { $policy['enabled']=false; }
        elseif ($change==='duplicate_selection') { $copy=$policy['rules'][0]; $copy['identifier']='another-id'; $policy['rules'][]=$copy; }
        else { $policy['rules'][0]['retention_days']=14; }
        $this->expectException(ValidationException::class); Policy::policy($policy);
    }

    public static function invalidChanges(): array { return array_map(fn($change)=>[$change],['missing_duration','boolean_duration','numeric_string','unknown_trigger','unknown_source','duplicate_source','inverted_bound','empty_enabled','disabled_with_rules','duplicate_selection','invented_retention']); }

    private function policy(): array { return ['schema_version'=>1,'enabled'=>true,'rules'=>[['identifier'=>'fall-base','trigger'=>'fall','site_id'=>null,'before_seconds'=>31,'after_seconds'=>42,'max_before_seconds'=>55,'max_after_seconds'=>70,'source_types'=>['camera']]]]; }
}
