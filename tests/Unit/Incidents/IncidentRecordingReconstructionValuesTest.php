<?php

namespace Tests\Unit\Incidents;

use App\Services\Incidents\IncidentRecordingReconstructionValues as Values;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** PURE value fixtures only: no fixture is a current actor/source/policy admission. All prepared, UNRUN. */
final class IncidentRecordingReconstructionValuesTest extends TestCase
{
    public function test_normalised_purpose_replays_exact_payload_but_every_bound_input_changes_fingerprint(): void
    {
        $input=$this->input();$expected=Values::attachFingerprint(7,11,$input);
        $padded=$input;$padded['purpose']='  '.$input['purpose'].'  ';
        $this->assertSame($expected,Values::attachFingerprint(7,11,$padded));
        $newKey=$input;$newKey['idempotency_key']='12345678-1234-4234-8234-123456789abe';
        $this->assertSame($expected,Values::attachFingerprint(7,11,$newKey),'The globally unique key is an independent binding; identical payload cannot create another original attach identity.');
        foreach(['expected_incident_evidence_version'=>2,'evidence_window_revision'=>2,
            'reference_uuid'=>'12345678-1234-4234-8234-123456789abf','purpose'=>'Different evidence purpose']as$field=>$value){
            $changed=$input;$changed[$field]=$value;$this->assertNotSame($expected,Values::attachFingerprint(7,11,$changed));
        }
        $this->assertNotSame($expected,Values::attachFingerprint(7,12,$input));
        $other=$input;$other['expected_actor_id']=8;$this->assertNotSame($expected,Values::attachFingerprint(8,11,$other));
    }

    #[DataProvider('forbiddenAttachValues')]
    public function test_attach_rejects_unknown_derived_nested_and_ambiguous_values(string $field,mixed $value): void
    {
        $input=$this->input();$input[$field]=$value;$this->expectException(ValidationException::class);Values::attachInput($input);
    }
    public static function forbiddenAttachValues(): array
    {
        return [['expected_actor_id','7'],['expected_actor_id',7.0],['expected_actor_id',true],['expected_incident_evidence_version',0],
            ['evidence_window_revision',2147483648],['purpose',['hidden'=>'DTO']],['camera_id',20],['requested_start_at','2026-10-18T00:00:00Z'],
            ['provider','unifi'],['policy_revision',1],['preserved',true],['reference_uuid','12345678-1234-4234-8234-123456789ABC'],
            ['reference_uuid','00000000-0000-0000-0000-000000000000'],['idempotency_key',null],['current_source',['state'=>'ready']],['owner_revision',1]];
    }

    #[DataProvider('invalidPurpose')]
    public function test_purpose_is_bounded_utf8_unicode_scalars_without_controls(mixed $value): void
    {
        $input=$this->input();$input['purpose']=$value;$this->expectException(ValidationException::class);Values::attachInput($input);
    }
    public static function invalidPurpose(): array
    {
        return [['short'],[str_repeat('x',501)],["Purpose\nwith newline"],["Purpose\twith tab"],["Purpose\u{200B}hidden"],["invalid\xC3\x28 UTF8"]];
    }

    public function test_purpose_limit_counts_unicode_scalars_rather_than_bytes(): void
    {
        $input=$this->input();$input['purpose']=str_repeat('界',500);
        $this->assertSame($input['purpose'],Values::attachInput($input)['purpose']);
        $input['purpose'].='界';$this->expectException(ValidationException::class);Values::attachInput($input);
    }
    public function test_fingerprint_never_accepts_other_expected_actor(): void
    {
        $this->expectException(ValidationException::class);Values::attachFingerprint(8,11,$this->input());
    }

    public function test_digest_preserves_lists_but_ignores_object_key_order(): void
    {
        $this->assertSame(Values::digest(['b'=>['z'=>1,'a'=>'é'],'a'=>[1,2]]),Values::digest(['a'=>[1,2],'b'=>['a'=>'é','z'=>1]]));
        $this->assertNotSame(Values::digest([1,2]),Values::digest([2,1]));
    }

    #[DataProvider('notStrictJson')]
    public function test_digest_rejects_noncanonical_json_values(mixed $value): void
    {
        $this->expectException(ValidationException::class);Values::digest($value);
    }
    public static function notStrictJson(): array{return [[1.0],[new \stdClass],[[1=>'sparse']],["\xC3\x28"]];}

    #[DataProvider('badInstants')]
    public function test_window_does_not_reinterpret_invalid_fractional_or_local_instants(string $value): void
    {
        $input=$this->windowInput();$input['start_at']=$value;$this->expectException(ValidationException::class);Values::windowRecord($input);
    }
    public static function badInstants(): array
    {
        return [['2026-02-30T00:00:00Z'],['2026-10-18T24:00:00Z'],['2026-10-18T00:00:60Z'],['2026-10-18T00:00:00.000000Z'],
            ['2026-10-18T13:00:00+13:00'],['2026-10-18 00:00:00'],['2026-10-18T00:00:00Z ']];
    }

    public function test_internal_window_has_explicit_policy_context_and_complete_sorted_source_set_without_a_technical_default(): void
    {
        $input=$this->windowInput();$input['approved_sources']=array_reverse($input['approved_sources']);$window=Values::windowRecord($input);
        Values::assertWindowRecord($window);
        $this->assertSame(20,$window['approved_sources'][0]['camera_id']);
        $this->assertSame($input['start_at'],$window['start_at']);$this->assertSame('2026-10-18T02:00:00Z',$window['end_at']);
        $this->assertSame(Values::digest($window['approved_sources']),$window['source_set_sha256']);
        $this->assertSame(Values::digest($window['context_anchor']),$window['context_anchor_sha256']);
        $this->assertSame(31,$window['policy_change_id'],'Shape only: actual canonical policy/audit/setting identity must be checked by the future writer.');
        $this->assertArrayNotHasKey('owner_revision',$window);$this->assertArrayNotHasKey('current_source',$window);
        $none=$this->windowInput();$none['approved_sources']=[];$empty=Values::windowRecord($none);
        $this->assertSame([],$empty['approved_sources'],'An explicit empty approved set records no source grant or fabricated camera choice.');
        Values::assertWindowRecord($empty);
    }

    #[DataProvider('badWindow')]
    public function test_window_refuses_missing_policy_wrong_context_bad_source_inventory_and_reversed_time(string $change): void
    {
        $input=$this->windowInput();
        if($change==='no_policy'){$input['policy_change_id']=null;}
        elseif($change==='no_rule'){$input['rule_identifier']='';}
        elseif($change==='other_incident'){$input['context_anchor']['incident_id']=12;}
        elseif($change==='no_occurrence'){$input['context_anchor']['occurred_at']=null;}
        elseif($change==='other_site'){$input['approved_sources'][0]['site_id']=99;}
        elseif($change==='duplicate'){$input['approved_sources'][]=$input['approved_sources'][0];}
        elseif($change==='missing_source_set'){unset($input['approved_sources']);}
        elseif($change==='camera_is_recorder'){$input['approved_sources'][0]['recorder_device_id']=20;}
        else{$input['end_at']=$input['start_at'];}
        $this->expectException(ValidationException::class);Values::windowRecord($input);
    }
    public static function badWindow(): array{return [['no_policy'],['no_rule'],['other_incident'],['no_occurrence'],['other_site'],['duplicate'],['missing_source_set'],['camera_is_recorder'],['reversed']];}

    #[DataProvider('tamperedWindow')]
    public function test_original_window_digest_includes_entire_context_and_source_set(string $change): void
    {
        $window=Values::windowRecord($this->windowInput());
        if($change==='extra_source'){$window['approved_sources'][]=['camera_id'=>22,'recorder_device_id'=>30,'site_id'=>4];}
        elseif($change==='journey'){$window['context_anchor']['hs_event_id']=42;}
        elseif($change==='occurrence'){$window['context_anchor']['occurred_at']='2026-10-17T23:59:59.000000Z';}
        elseif($change==='window_start'){$window['start_at']='2026-10-17T23:59:00Z';}
        elseif($change==='rule'){$window['rule_revision']=3;}
        elseif($change==='actor'){$window['actor_user_id']=8;}
        elseif($change==='override'){$window['override_reason']='An altered override reason';}
        else{$window['effective_site_id']=9;}
        $this->expectException(ValidationException::class);Values::assertWindowRecord($window);
    }
    public static function tamperedWindow(): array{return [['extra_source'],['journey'],['occurrence'],['derived_site'],['window_start'],['rule'],['actor'],['override']];}

    public function test_reference_matches_exact_original_uuid_window_source_and_truthful_reference_only_protocol(): void
    {
        $reference=$this->reference();$window=Values::windowRecord($this->windowInput());
        $this->assertSame($reference,Values::referenceForWindow($reference,$window,$reference['reference_uuid']));
        $confirmation=Values::attachConfirmation(7,11,$this->input(),$window,$reference,'12345678-1234-4234-8234-123456789abd',2,'2026-10-18T02:10:00.000000Z');
        $this->assertSame(['schema_version','actor_id','incident_id','command','attachment'],array_keys($confirmation));
        $this->assertFalse($confirmation['attachment']['preserved']);$this->assertSame('not_verified',$confirmation['attachment']['window_coverage']);
        $wire=json_encode($confirmation,JSON_THROW_ON_ERROR);
        foreach(['console-sentinel','provider-camera-sentinel','Original source purpose sentinel','reader_href','current_source','Policy fixture']as$private){$this->assertStringNotContainsString($private,$wire);}
    }

    #[DataProvider('resolverMetadataCases')]
    public function test_inspected_resolver_shape_keeps_whole_second_times_nullable_verification_and_original_creator(bool $unknown): void
    {
        $reference=$this->reference();
        if($unknown){$reference['source_verified_at']=null;$reference['created_by']['name']=' Original creator ';}
        $actual=Values::referenceForWindow($reference,Values::windowRecord($this->windowInput()),$reference['reference_uuid']);
        $this->assertSame($reference,$actual,'Preserve source receipt bytes/values, including creator identity different from attach actor.');
        $this->assertSame('2026-10-18T02:01:00Z',$actual['created_at']);
        $this->assertSame($unknown?null:'2026-10-17T23:00:00Z',$actual['source_verified_at']);
        $this->assertSame('2026-10-18T02:05:00.000000Z',Values::windowRecord($this->windowInput())['recorded_at']);
    }
    public static function resolverMetadataCases(): array{return [[false],[true]];}
    #[DataProvider('badReference')]
    public function test_reference_cannot_claim_preservation_coverage_extra_dto_or_wrong_binding(string $field,mixed $value): void
    {
        $reference=$this->reference();$reference[$field]=$value;
        $this->expectException(ValidationException::class);Values::referenceForWindow($reference,Values::windowRecord($this->windowInput()),$this->input()['reference_uuid']);
    }
    public static function badReference(): array
    {
        return [['preserved',true],['window_coverage','verified'],['recording_verified_at','2026-10-18T02:00:00Z'],['current_source',['state'=>'ready']],
            ['requested_end_at','2026-10-18T01:00:00Z'],['camera_id',99],['site_id',99],['reader_href','https://vendor.invalid/footage'],
            ['reference_uuid','12345678-1234-4234-8234-123456789abf']];
    }

    public function test_safe_confirmation_cannot_rebind_incident_or_skip_evidence_version_increment(): void
    {
        $this->expectException(ValidationException::class);
        Values::attachConfirmation(7,11,$this->input(),Values::windowRecord($this->windowInput()),$this->reference(),
            '12345678-1234-4234-8234-123456789abd',3,'2026-10-18T02:10:00.000000Z');
    }

    public function test_supersession_retains_attribution_and_refuses_the_same_original_target(): void
    {
        $revision=['incident_id'=>11,'incident_evidence_version'=>3,'kind'=>'recording_superseded','window_revision'=>1,
            'attachment_uuid'=>'12345678-1234-4234-8234-123456789abd','predecessor_attachment_uuid'=>'12345678-1234-4234-8234-123456789abc',
            'actor_user_id'=>7,'recorded_at'=>'2026-10-18T02:20:00.000000Z','reason'=>'Explicit correction reason','audit_log_id'=>45];
        $this->assertSame($revision,Values::revisionRecord($revision));
        $revision['predecessor_attachment_uuid']=$revision['attachment_uuid'];
        $this->expectException(ValidationException::class);Values::revisionRecord($revision);
    }

    public function test_initial_evidence_revision_cannot_be_an_attachment_or_correction(): void
    {
        $revision=['incident_id'=>11,'incident_evidence_version'=>1,'kind'=>'window_recorded','window_revision'=>1,
            'attachment_uuid'=>null,'predecessor_attachment_uuid'=>null,'actor_user_id'=>7,'recorded_at'=>'2026-10-18T02:05:00.000000Z',
            'reason'=>null,'audit_log_id'=>41];
        $this->assertSame($revision,Values::revisionRecord($revision));
        $revision['kind']='recording_attached';$revision['attachment_uuid']='12345678-1234-4234-8234-123456789abd';
        $this->expectException(ValidationException::class);Values::revisionRecord($revision);
    }
    public function test_actual_incident_type_is_required_in_the_immutable_anchor(): void
    {
        $input=$this->windowInput(); unset($input['context_anchor']['incident_type']);
        $this->expectException(ValidationException::class); Values::windowRecord($input);
    }

    public function test_changed_incident_type_invalidates_the_original_whole_window_digest(): void
    {
        $window=Values::windowRecord($this->windowInput()); $window['context_anchor']['incident_type']='injury';
        $this->expectException(ValidationException::class); Values::assertWindowRecord($window);
    }

    public function test_incident_type_is_never_trimmed_into_a_supported_rule_trigger(): void
    {
        $input=$this->windowInput(); $input['context_anchor']['incident_type']='fall ';
        $this->expectException(ValidationException::class); Values::windowRecord($input);
    }

    private function input(): array
    {
        return ['expected_actor_id'=>7,'idempotency_key'=>'12345678-1234-4234-8234-123456789aaa','expected_incident_evidence_version'=>1,
            'evidence_window_revision'=>1,'reference_uuid'=>'12345678-1234-4234-8234-123456789abc','purpose'=>'Record reconstruction reference'];
    }
    private function windowInput(): array
    {
        return ['incident_id'=>11,'window_revision'=>1,'introduced_evidence_version'=>1,'actor_user_id'=>7,'recorded_at'=>'2026-10-18T02:05:00.000000Z',
            'start_at'=>'2026-10-18T00:00:00Z','end_at'=>'2026-10-18T02:00:00Z','rule_identifier'=>'Policy fixture only','rule_revision'=>2,
            'policy_change_id'=>31,'rule_snapshot_sha256'=>str_repeat('a',64),'override_reason'=>null,
            'context_anchor'=>['incident_id'=>11,'client_id'=>3,'raw_site_id'=>null,'effective_site_id'=>4,'occurred_at'=>'2026-10-18T01:00:00.000000Z',
                'incident_type'=>'fall','source'=>'manual','journey_sha256'=>str_repeat('b',64),'control_room_alert_id'=>null,'hs_event_id'=>null,'fleet_incident_id'=>null,
                'shift_id'=>null,'respite_stay_id'=>null,'service_context_id'=>null],
            'approved_sources'=>[['camera_id'=>20,'recorder_device_id'=>30,'site_id'=>4],['camera_id'=>21,'recorder_device_id'=>30,'site_id'=>4]],'audit_log_id'=>41];
    }
    /** Field-for-field installed CctvRecordingReferences producer95/time209/href214 and donor HTTP test631-675.
     * Data only; no retained genuine export was available and no actual resolver was invoked in this unit. */
    private function reference(): array
    {
        return ['schema_version'=>1,'reference_uuid'=>$this->input()['reference_uuid'],'camera_id'=>20,'recorder_device_id'=>30,'site_id'=>4,
            'provider'=>'unifi','console_id'=>'console-sentinel','provider_camera_id'=>'provider-camera-sentinel','provider_recorder_id'=>'provider-recorder-sentinel',
            'source_revision'=>2,'protect_version'=>'6.1.1','requested_start_at'=>'2026-10-18T00:00:00Z','requested_end_at'=>'2026-10-18T02:00:00Z',
            'created_at'=>'2026-10-18T02:01:00Z','source_verified_at'=>'2026-10-17T23:00:00Z','recording_verified_at'=>null,'vendor_retention_until'=>null,
            'state'=>'reference_only','preserved'=>false,'binding_basis'=>'reviewed_source_revision','window_coverage'=>'not_verified',
            'created_by'=>['id'=>8,'name'=>'Original creator'],'purpose'=>'Original source purpose sentinel',
            'reader_href'=>'/security-devices/cctv/recording-references/'.$this->input()['reference_uuid']];
    }
}
