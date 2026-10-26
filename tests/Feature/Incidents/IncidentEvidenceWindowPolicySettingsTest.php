<?php

namespace Tests\Feature\Incidents;

use App\Models\{AppSetting, AuditLog, HealthSafetySettingChange};
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\{IncidentEvidenceWindowPolicySettings as Policy, IncidentRecordingReconstructionValues as Values};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Source-prepared integration tests. Requires the admitted MySQL candidate; never SQLite proof. */
final class IncidentEvidenceWindowPolicySettingsTest extends IncidentRoutingTestCase
{
    public function test_absent_is_unconfigured_and_does_not_materialize_default_or_history(): void
    {
        $dto=app(Policy::class)->editor($this->manager);
        $this->assertSame('unconfigured',$dto['policy']['configuration_state']);
        $this->assertNull($dto['policy']['policy_snapshot']);
        $this->assertSame(0,AppSetting::whereIn('key',[Policy::KEY,Policy::REVISION_KEY])->count());
        $this->assertSame(0,HealthSafetySettingChange::where('setting_key',Policy::KEY)->count());
    }

    public function test_disabled_is_explicit_audited_and_historical_configured_window_remains_valid(): void
    {
        $payload=$this->command(); $first=app(Policy::class)->save($this->manager,$payload);
        $window=$this->window($first);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-12T03:02:00Z'));
        $disabled=$this->command(1); $disabled['policy']=['schema_version'=>1,'enabled'=>false,'rules'=>[]];
        app(Policy::class)->save($this->manager,$disabled);
        $this->assertSame('disabled',app(Policy::class)->editor($this->manager)['policy']['configuration_state']);
        $this->prove($window);
        $replay=app(Policy::class)->save($this->manager,$payload);
        $this->assertTrue($replay['replayed']); $this->assertSame($first['setting_change_id'],$replay['setting_change_id']);
        $this->assertSame(2,HealthSafetySettingChange::where('setting_key',Policy::KEY)->count());
        $this->assertSame($first['setting_change_id'],app(Policy::class)->recover($this->manager,(int)$this->manager->id,$payload['idempotency_key'])['setting_change_id']);
    }

    public function test_missing_audit_is_invalid_and_never_a_positive_historical_policy_proof(): void
    {
        $receipt=app(Policy::class)->save($this->manager,$this->command()); $window=$this->window($receipt);
        $row=HealthSafetySettingChange::findOrFail($receipt['setting_change_id']);
        DB::table('audit_logs')->where('id',$row->audit_id)->update(['meta'=>json_encode(['sentinel'=>'tampered'])]);
        $this->assertSame('invalid',app(Policy::class)->editor($this->manager)['policy']['configuration_state']);
        $this->validation(fn()=>$this->prove($window),'policy');
    }

    public function test_original_policy_must_govern_capture_and_equal_timestamps_are_ambiguous(): void
    {
        $first=app(Policy::class)->save($this->manager,$this->command());
        $window=$this->window($first);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-12T03:02:00Z'));
        app(Policy::class)->save($this->manager,$this->command(1));
        // A later policy does not invalidate an earlier genuinely governed capture.
        $this->prove($window);
        foreach (['2026-10-12T03:00:00.000000Z','2026-10-12T03:02:00.000000Z','2026-10-12T03:03:00.000000Z'] as $capturedAt) {
            $input=array_diff_key($window,array_flip(['effective_site_id','client_id','context_anchor_sha256','source_set_sha256','window_sha256']));
            $input['recorded_at']=$capturedAt; $candidate=Values::windowRecord($input);
            $this->validation(fn()=>$this->prove($candidate),'window');
        }
    }

    public function test_rule_type_window_bounds_and_expansion_purpose_cannot_be_forged(): void
    {
        $receipt=app(Policy::class)->save($this->manager,$this->command());
        foreach (['type','bounds','purpose','digest'] as $change) {
            $window=$this->window($receipt); unset($window['context_anchor_sha256'],$window['source_set_sha256'],$window['window_sha256'],$window['client_id'],$window['effective_site_id']);
            if ($change==='type') { $window['context_anchor']['incident_type']='injury'; }
            if ($change==='bounds') { $window['start_at']='2026-10-12T02:58:00Z'; }
            if ($change==='purpose') { $window['start_at']='2026-10-12T02:59:10Z'; }
            if ($change==='digest') { $window['rule_snapshot_sha256']=str_repeat('a',64); }
            $window=Values::windowRecord($window);
            $this->validation(fn()=>$this->prove($window),$change==='purpose'?'override_reason':'window');
        }
    }

    public function test_stale_version_changed_intent_and_revoked_manager_preserve_configuration(): void
    {
        $payload=$this->command(); app(Policy::class)->save($this->manager,$payload);
        $this->validation(fn()=>app(Policy::class)->save($this->manager,$this->command()),'expected_policy_revision');
        $payload['reason']='Another deliberately different reason';
        $this->validation(fn()=>app(Policy::class)->save($this->manager,$payload),'idempotency_key');
        $this->allow($this->manager,Policy::PERMISSION,false);
        $this->denial(fn()=>app(Policy::class)->recover($this->manager,(int)$this->manager->id,$payload['idempotency_key']));
        $this->assertSame(1,HealthSafetySettingChange::where('setting_key',Policy::KEY)->count());
    }

    public function test_explicit_repair_retains_invalid_raw_before_value_and_no_default_history(): void
    {
        $raw=['schema_version'=>999,'unsafe_legacy'=>'synthetic'];
        AppSetting::create(['key'=>Policy::KEY,'value'=>$raw]);
        $this->assertSame('invalid',app(Policy::class)->editor($this->manager)['policy']['configuration_state']);
        $receipt=app(Policy::class)->save($this->manager,$this->command());
        $row=HealthSafetySettingChange::findOrFail($receipt['setting_change_id']);
        // The JSON column reorders object keys; the retained raw content and its digest stay exact.
        $this->assertSame(\App\Support\JsonEvidence::canonicalise($raw),\App\Support\JsonEvidence::canonicalise($row->before_value)); $this->assertSame(Values::digest($raw),$row->before_digest);
        $this->assertSame(1,$row->revision); $this->assertSame('configured',app(Policy::class)->editor($this->manager)['policy']['configuration_state']);
    }

    public function test_foreign_and_inactive_new_site_rules_are_denied_and_inactive_history_stays_reviewable(): void
    {
        $foreign=\App\Models\Site::factory()->create(['is_active'=>true]);
        $payload=$this->command(); $payload['policy']['rules'][0]['site_id']=(int)$foreign->id;
        $this->denial(fn()=>app(Policy::class)->save($this->manager,$payload),404);
        $payload['policy']['rules'][0]['site_id']=(int)$this->site->id;
        $this->site->update(['is_active'=>false]);
        // Inactive Sites are outside the actor's current selectable Sites, so the refusal is the same generic 404.
        $this->denial(fn()=>app(Policy::class)->save($this->manager,$payload),404);
        $this->assertSame(0,HealthSafetySettingChange::where('setting_key',Policy::KEY)->count());
        $this->site->update(['is_active'=>true]);
        $receipt=app(Policy::class)->save($this->manager,$payload);
        $this->site->update(['is_active'=>false]);
        $dto=app(Policy::class)->editor($this->manager);
        $this->assertSame((int)$this->site->id,$dto['policy']['policy_snapshot']['rules'][0]['site_id']);
        $this->assertFalse(collect($dto['options']['sites'])->firstWhere('id',(int)$this->site->id)['is_active']);
        // Deliberate replacement removes the inactive override without changing historical proof.
        $replacement=$this->command(1); app(Policy::class)->save($this->manager,$replacement);
        $this->assertSame(2,HealthSafetySettingChange::where('setting_key',Policy::KEY)->count());
    }

    public function test_strict_audit_failure_rolls_back_setting_revision_and_history(): void
    {
        $dispatcher=\Illuminate\Database\Eloquent\Model::getEventDispatcher();
        \Illuminate\Database\Eloquent\Model::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(function($audit) { if (str_starts_with($audit->action,'health_safety.settings.incident_evidence_window.')) { throw new \RuntimeException('Synthetic strict audit failure'); } });
        try { app(Policy::class)->save($this->manager,$this->command()); $this->fail('Expected strict audit failure'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('Synthetic strict audit failure',$error->getMessage()); }
        finally { \Illuminate\Database\Eloquent\Model::setEventDispatcher($dispatcher); }
        $this->assertSame(0,AppSetting::whereIn('key',[Policy::KEY,Policy::REVISION_KEY])->count());
        $this->assertSame(0,HealthSafetySettingChange::where('setting_key',Policy::KEY)->count());
    }

    public function test_current_site_loss_redacts_editor_denies_whole_receipt_and_prevents_hidden_rule_deletion(): void
    {
        $payload=$this->command(); $payload['policy']['rules'][0]['site_id']=(int)$this->site->id;
        app(Policy::class)->save($this->manager,$payload);
        $other=\App\Models\Site::factory()->create(['is_active'=>true]);
        \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id',$this->manager->id)->firstOrFail()->update(['primary_site_id'=>$other->id,'secondary_site_ids'=>[]]);
        $dto=app(Policy::class)->editor($this->manager);
        $this->assertFalse($dto['can_manage']); $this->assertFalse($dto['policy']['scope_complete']);
        $this->assertSame([],$dto['policy']['policy_snapshot']['rules']);
        $this->denial(fn()=>app(Policy::class)->recover($this->manager,(int)$this->manager->id,$payload['idempotency_key']),404);
        $this->denial(fn()=>app(Policy::class)->save($this->manager,$this->command(1)),403);
        $this->assertSame(1,HealthSafetySettingChange::where('setting_key',Policy::KEY)->count());
    }

    private function command(int $revision=0): array
    {
        return ['expected_actor_id'=>(int)$this->manager->id,'expected_policy_revision'=>$revision,'idempotency_key'=>(string)Str::uuid(),'reason'=>'Explicit synthetic policy review only',
            'policy'=>['schema_version'=>1,'enabled'=>true,'rules'=>[['identifier'=>'fall-reviewed','trigger'=>'fall','site_id'=>null,'before_seconds'=>40,'after_seconds'=>80,'max_before_seconds'=>60,'max_after_seconds'=>100,'source_types'=>['camera']]]]];
    }

    private function window(array $receipt): array
    {
        return Values::windowRecord(['incident_id'=>11,'window_revision'=>1,'introduced_evidence_version'=>1,'actor_user_id'=>(int)$this->manager->id,'recorded_at'=>'2026-10-12T03:01:00.000000Z',
            'start_at'=>'2026-10-12T02:59:20Z','end_at'=>'2026-10-12T03:01:20Z','rule_identifier'=>'fall-reviewed','rule_revision'=>$receipt['policy_revision'],'policy_change_id'=>$receipt['setting_change_id'],'rule_snapshot_sha256'=>$receipt['policy_digest'],'override_reason'=>null,
            'context_anchor'=>['incident_id'=>11,'client_id'=>null,'raw_site_id'=>null,'effective_site_id'=>(int)$this->site->id,'occurred_at'=>'2026-10-12T03:00:00.000000Z','incident_type'=>'fall','source'=>'manual','journey_sha256'=>str_repeat('b',64),'control_room_alert_id'=>null,'hs_event_id'=>null,'fleet_incident_id'=>null,'shift_id'=>null,'respite_stay_id'=>null,'service_context_id'=>null],
            'approved_sources'=>[],'audit_log_id'=>41]);
    }

    private function prove(array $window): void { DB::transaction(fn()=>CurrentAuthorizationReads::within(fn($reads)=>app(Policy::class)->assertRecordedWindowPolicy($window,$reads))); }
}
