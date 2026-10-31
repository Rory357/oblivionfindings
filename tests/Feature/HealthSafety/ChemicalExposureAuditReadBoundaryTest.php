<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureHsProjection;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\Audit\AuditLogViewService;
use App\Services\HealthSafety\ChemicalExposureAuditAccessService;
use App\Services\HealthSafety\ChemicalExposureHsEventDeliveryService;
use App\Services\HealthSafety\ChemicalExposureSourceProjectionService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Whole native suite, UNRUN. Shared R2 materializer/route proposals are explicit HTTP prerequisites. */
final class ChemicalExposureAuditReadBoundaryTest extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allow($this->manager, 'audit.viewAny');
    }

    public function test_final_count_binds_copied_client_identity_and_keeps_healthy_neighbor(): void
    {
        $this->allow($this->manager, 'clients.viewAny');
        $row = $this->auditFor($this->exposure());
        $neighbor = $this->auditFor($this->exposure());
        $foreign = Client::factory()->create(['site_id' => $this->foreignSite->id]);
        $this->read(function ($current,$context) use ($row,$neighbor,$foreign): array {
            $query = $this->scoped([$row->id,$neighbor->id],$current,$context);
            DB::table('audit_logs')->where('id',$row->id)->update(['client_id'=>$foreign->id]);
            $this->assertSame(1,(clone $query)->count());
            $this->assertSame([$neighbor->id],(clone $query)->orderBy('id')->pluck('id')->all());
            DB::table('audit_logs')->where('id',$row->id)->update(['client_id'=>null]);
            $this->assertSame(2,(clone $query)->count());
            return [];
        });
    }

    public function test_final_page_denies_changed_subject_and_actor_before_body_and_eager_names(): void
    {
        $root = $this->exposure(); $row = $this->auditFor($root);
        $foreign = SubstanceExposureRecord::withoutEvents(fn()=>SubstanceExposureRecord::factory()->create([
            'hazardous_substance_id'=>$root->hazardous_substance_id,'site_id'=>$this->foreignSite->id,
            'user_id'=>$this->worker->id,'related_incident_id'=>null,'original_context'=>null]));
        $actor = $this->staff([], $this->foreignSite); $actor->updateQuietly(['name'=>'FOREIGN-PRIVATE-AUDIT-ACTOR']);
        $hydrated=[]; AuditLog::retrieved(function ($item) use ($row,&$hydrated): void {
            if ((int)$item->id === (int)$row->id && array_key_exists('meta',$item->getAttributes())) { $hydrated[]=$item->id; }
        });
        try {
            $this->read(function ($current,$context) use ($row,$foreign,$actor,&$hydrated): array {
                $query=$this->scoped([$row->id],$current,$context)->with(['user:id,name','client:id,first_name,last_name']);
                $hydrated=[];
                DB::table('audit_logs')->where('id',$row->id)->update(['auditable_id'=>$foreign->id,'user_id'=>$actor->id]);
                $page=$query->paginate(50)->toArray();
                $this->assertSame(0,$page['total']); $this->assertSame([],$page['data']); $this->assertSame([],$hydrated);
                $this->assertStringNotContainsString('FOREIGN-PRIVATE-AUDIT-ACTOR',json_encode($page,JSON_THROW_ON_ERROR));
                return [];
            });
        } finally { AuditLog::flushEventListeners(); }
    }

    public function test_final_identity_distinguishes_missing_json_null_and_changed_literal(): void
    {
        $root=$this->exposure(); $row=$this->auditFor($root,['after'=>['exposure_id'=>$root->id,'client_id'=>null]]);
        $original=$row->getRawOriginal('meta');
        $this->read(function ($current,$context) use ($row,$root,$original): array {
            $query=$this->scoped([$row->id],$current,$context);
            foreach ([['after'=>['exposure_id'=>$root->id]],['after'=>['exposure_id'=>(string)$root->id,'client_id'=>null]]] as $meta) {
                DB::table('audit_logs')->where('id',$row->id)->update(['meta'=>json_encode($meta,JSON_THROW_ON_ERROR)]);
                $this->assertSame(0,(clone $query)->count());
            }
            DB::table('audit_logs')->where('id',$row->id)->update(['meta'=>$original]);
            $this->assertSame(1,(clone $query)->count());
            return [];
        });
    }

    public function test_case_only_and_trailing_space_audit_rewrites_do_not_match_final_snapshot(): void
    {
        $row=$this->auditFor($this->exposure(),['after'=>['notes'=>'Private Recorded Fact']]);
        $original=$row->getRawOriginal('meta');
        $this->read(function ($current,$context) use ($row,$original): array {
            $query=$this->scoped([$row->id],$current,$context);
            foreach (['private recorded fact','Private Recorded Fact '] as $text) {
                DB::table('audit_logs')->where('id',$row->id)->update(['meta'=>json_encode(['after'=>['notes'=>$text]],JSON_THROW_ON_ERROR)]);
                $this->assertSame(0,(clone $query)->count());
                $this->assertSame([],(clone $query)->get()->all());
            }
            DB::table('audit_logs')->where('id',$row->id)->update(['meta'=>$original]);
            $this->assertSame(1,(clone $query)->count());
            return [];
        });
    }

    public function test_source_fact_mutation_after_scope_invalidates_count_and_body_then_exact_restore(): void
    {
        $root=$this->exposure(['symptoms'=>'Accepted current symptom']); $row=$this->auditFor($root);
        $this->read(function ($current,$context) use ($root,$row): array {
            $query=$this->scoped([$row->id],$current,$context);
            DB::table('substance_exposure_records')->where('id',$root->id)->update(['symptoms'=>'Accepted current symptom ']);
            $this->assertSame(0,(clone $query)->count()); $this->assertSame([],(clone $query)->get()->all());
            DB::table('substance_exposure_records')->where('id',$root->id)->update(['symptoms'=>'Accepted current symptom']);
            $this->assertSame(1,(clone $query)->count());
            return [];
        });
    }

    public function test_original_client_site_changes_after_proof_withhold_only_linked_root(): void
    {
        $this->allow($this->manager,'incidents.viewAny'); $this->allow($this->manager,'clients.viewAny');
        $client=Client::factory()->create(['site_id'=>$this->site->id]);
        $incident=ClientIncident::withoutEvents(fn()=>ClientIncident::factory()->submitted()->create([
            'site_id'=>$this->site->id,'client_id'=>$client->id,'reported_by'=>$this->manager->id,
            'shift_id'=>null,'service_context_id'=>null,'respite_stay_id'=>null]));
        $root=$this->exposure(['related_incident_id'=>$incident->id]);
        $row=$this->auditFor($root); $neighbor=$this->auditFor($this->exposure());
        $this->read(function ($current,$context) use ($client,$row,$neighbor): array {
            $query=$this->scoped([$row->id,$neighbor->id],$current,$context);
            DB::table('clients')->where('id',$client->id)->update(['site_id'=>$this->foreignSite->id]);
            $this->assertSame([$neighbor->id],(clone $query)->orderBy('id')->pluck('id')->all());
            DB::table('clients')->where('id',$client->id)->update(['site_id'=>$this->site->id]);
            $this->assertSame(2,(clone $query)->count());
            return [];
        });
    }

    public function test_current_site_activity_and_staff_snapshot_remain_in_final_statement(): void
    {
        $row=$this->auditFor($this->exposure());
        $this->read(function ($current,$context) use ($row): array {
            $query=$this->scoped([$row->id],$current,$context);
            DB::table('sites')->where('id',$this->site->id)->update(['is_active'=>false]);
            $this->assertSame(0,(clone $query)->count());
            DB::table('sites')->where('id',$this->site->id)->update(['is_active'=>true]);
            $this->assertSame(1,(clone $query)->count());
            DB::table('hr_employee_profiles')->where('user_id',$current->id)->update(['primary_site_id'=>$this->foreignSite->id]);
            $this->assertSame(0,(clone $query)->count());
            DB::table('hr_employee_profiles')->where('user_id',$current->id)->update(['primary_site_id'=>$this->site->id]);
            $this->assertSame(1,(clone $query)->count());
            return [];
        });
    }

    public function test_retained_version_mutation_after_scope_cannot_reuse_accepted_private_identity(): void
    {
        $root=$this->exposure(); $row=$this->auditFor($root);
        $version=ChemicalExposureSourceVersion::where('exposure_id',$root->id)->sole();
        $original=$version->getRawOriginal('content');
        $this->read(function ($current,$context) use ($row,$version,$original): array {
            $query=$this->scoped([$row->id],$current,$context);
            $body=$version->content; $body['root_after']['symptoms']='Changed retained private fact';
            DB::table('chemical_exposure_source_versions')->where('id',$version->id)->update(['content'=>json_encode($body,JSON_THROW_ON_ERROR)]);
            $this->assertSame(0,(clone $query)->count());
            DB::table('chemical_exposure_source_versions')->where('id',$version->id)->update(['content'=>$original]);
            $this->assertSame(1,(clone $query)->count());
            return [];
        });
    }

    public function test_genuine_new_noop_acceptance_is_not_in_the_previously_proved_origin_set(): void
    {
        $root=$this->exposure(['symptoms'=>'Unchanged accepted fact']); $row=$this->auditFor($root);
        $this->read(function ($current,$context) use ($root,$row): array {
            $query=$this->scoped([$row->id],$current,$context);
            $receipt=$this->exposureService()->correct($root,$this->manager,$this->privateCommand($root,[
                'reason'=>'Explicit unchanged retry review.','symptoms'=>'Unchanged accepted fact']));
            $this->assertFalse($receipt['changed']);
            $this->assertSame(0,(clone $query)->count());
            $this->assertSame(1,$this->scoped([$row->id],$current,$context)->count());
            $this->assertSame(2,ChemicalExposureCommandReceipt::where('exposure_id',$root->id)->count());
            return [];
        });
    }

    public function test_recorded_copied_event_requires_actual_destination_marker_not_matching_factory_tuple(): void
    {
        $root=$this->exposure();
        $event=HsEvent::withoutEvents(fn()=>HsEvent::factory()->create(['source_type'=>SubstanceExposureRecord::class,
            'source_id'=>$root->id,'site_id'=>$this->site->id,'staff_id'=>$this->worker->id,'client_id'=>null,
            'shift_id'=>null,'asset_id'=>null,'event_category'=>HsEvent::CATEGORY_EXPOSURE]));
        $row=AuditLog::create(['action'=>'hsevent.updated','auditable_type'=>HsEvent::class,'auditable_id'=>$event->id,
            'user_id'=>$this->manager->id,'meta'=>['after'=>['source_type'=>SubstanceExposureRecord::class,'source_id'=>$root->id]]]);
        $this->assertSame([], $this->read(fn($current,$context)=>$this->scoped([$row->id],$current,$context)->pluck('id')->all()));
        $this->assertSame([], $this->read(fn($current,$context)=>app(ChemicalExposureSourceProjectionService::class)
            ->readableHsEventIdsInContext($current,HsEvent::whereKey($event->id),$context)));
    }

    public function test_actual_marker_damage_and_restoration_gate_copied_audit_body(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure audit row-hiding guard (ChemicalExposureAuditAccessService::materialize/scope) is not mounted, by Stephan\'s decision. The audit pages keep main\'s rows for audit.viewAny holders; the exposure symptoms and treatment fields will be hidden for everyone instead (job 4). This case also builds its H&S event through the unmounted delivery pipeline, which refuses the observer-created event.', 'Job 4 (after the merge): hide exposure symptoms and treatment fields for everyone on the audit pages and CSV; Codex\'s row-hiding guard stays out.'));
        [$root,$event,$marker]=$this->delivered();
        $row=AuditLog::create(['action'=>'hsevent.updated','auditable_type'=>HsEvent::class,'auditable_id'=>$event->id,
            'user_id'=>$this->manager->id,'meta'=>[]]);
        $read=fn()=> $this->read(fn($current,$context)=>$this->scoped([$row->id],$current,$context)->pluck('id')->all());
        $this->assertSame([$row->id],$read());
        $old=$marker->projection_digest;
        DB::table('chemical_exposure_hs_projections')->where('id',$marker->id)->update(['projection_digest'=>str_repeat('a',64)]);
        $this->assertSame([],$read());
        DB::table('chemical_exposure_hs_projections')->where('id',$marker->id)->update(['projection_digest'=>$old]);
        $this->assertSame([$row->id],$read());
        $this->read(function ($current,$context) use ($row,$marker,$old): array {
            $query=$this->scoped([$row->id],$current,$context);
            DB::table('chemical_exposure_hs_projections')->where('id',$marker->id)->update(['projection_digest'=>str_repeat('b',64)]);
            $this->assertSame(0,(clone $query)->count());
            DB::table('chemical_exposure_hs_projections')->where('id',$marker->id)->update(['projection_digest'=>$old]);
            $this->assertSame(1,(clone $query)->count());
            return [];
        });
    }

    public function test_fresh_materializer_revokes_and_restores_warmed_audit_grant(): void
    {
        $row=$this->auditFor($this->exposure());
        $this->manager->load(['roles.permissions','permissionOverrides']);
        $this->allow($this->manager,'audit.viewAny',false);
        $this->denial(fn()=> $this->read(fn($current,$context)=>$this->scoped([$row->id],$current,$context)->count()));
        $denied=$this->manager->fresh()->load(['roles.permissions','permissionOverrides']);
        $this->assertFalse($denied->canDo('audit.viewAny'));
        $this->allow($denied,'audit.viewAny');
        $this->assertFalse($denied->canDo('audit.viewAny'));
        $result=app(ChemicalExposureAuditAccessService::class)->materialize($denied,
            fn($current,$context)=>$this->scoped([$row->id],$current,$context)->count());
        $this->assertSame(1,$result);
    }

    public function test_actual_http_readers_restore_warmed_false_grant_only_with_explicit_route_successor(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s audit "route successor" (re-reading a revoked then restored audit.viewAny on one warmed user object) is not built, and the test grants hr.settings.manage, which only SeedHrPermissionsSeeder creates, not the RbacSeeder it seeds. In real use each request loads the user fresh, so the audit pages follow the current audit.viewAny grant.', 'Build with the audit-page work (job 4) if still wanted; the fixture also needs SeedHrPermissionsSeeder.'));
        $this->allow($this->manager,'hr.settings.manage'); $this->auditFor($this->exposure());
        $urls=['/audit-logs','/settings/audit-logs','/settings/audit-logs/export','/hr/settings/audit-log'];
        $this->allow($this->manager,'audit.viewAny',false);
        $denied=$this->manager->fresh()->load(['roles.permissions','permissionOverrides']);
        $this->actingAs($denied);
        foreach ($urls as $url) { $this->get($url)->assertForbidden(); }
        $this->allow($denied,'audit.viewAny');
        $this->assertFalse($denied->canDo('audit.viewAny'));
        foreach ($urls as $url) { $this->get($url)->assertOk(); }
        $this->allow($denied,'audit.viewAny',false);
        foreach ($urls as $url) { $this->get($url)->assertForbidden(); }
    }

    public function test_unrelated_auditor_without_staff_keeps_ordinary_rows_and_no_context_never_grants_private_rows(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure audit row-hiding guard (ChemicalExposureAuditAccessService::materialize/scope) is not mounted, by Stephan\'s decision. The audit pages keep main\'s rows for audit.viewAny holders; the exposure symptoms and treatment fields will be hidden for everyone instead (job 4).', 'Job 4 (after the merge): hide exposure symptoms and treatment fields for everyone on the audit pages and CSV; Codex\'s row-hiding guard stays out.'));
        $row=$this->auditFor($this->exposure());
        $auditor=User::factory()->create(['approved_at'=>now(),'external_clinical_account'=>false]);
        $this->allow($auditor,'audit.viewAny');
        $ordinary=AuditLog::create(['action'=>'it.change.updated','auditable_type'=>User::class,
            'auditable_id'=>$auditor->id,'user_id'=>$auditor->id,'meta'=>null]);
        $result=app(ChemicalExposureAuditAccessService::class)->materialize($auditor,
            fn($current,$context)=>app(AuditLogViewService::class)->query([], $current,$context)
                ->whereKey([$row->id,$ordinary->id])->orderBy('id')->pluck('id')->all());
        $this->assertSame([$ordinary->id],$result);
        $this->assertSame([$ordinary->id],app(ChemicalExposureAuditAccessService::class)
            ->scope(AuditLog::whereKey([$row->id,$ordinary->id]),$this->manager)->pluck('id')->all());
    }

    public function test_retained_marker_keeps_rewritten_event_private_even_in_default_scope(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure audit row-hiding guard (ChemicalExposureAuditAccessService::materialize/scope) is not mounted, by Stephan\'s decision. The audit pages keep main\'s rows for audit.viewAny holders; the exposure symptoms and treatment fields will be hidden for everyone instead (job 4). This case also builds its H&S event through the unmounted delivery pipeline, which refuses the observer-created event.', 'Job 4 (after the merge): hide exposure symptoms and treatment fields for everyone on the audit pages and CSV; Codex\'s row-hiding guard stays out.'));
        [$root,$event]=$this->delivered();
        $row=AuditLog::create(['action'=>'hsevent.updated','auditable_type'=>HsEvent::class,'auditable_id'=>$event->id,
            'user_id'=>$this->manager->id,'meta'=>[]]);
        $original=$event->source_type;
        $this->read(function ($current,$context) use ($row,$event,$original): array {
            $query=$this->scoped([$row->id],$current,$context);
            DB::table('hs_events')->where('id',$event->id)->update(['source_type'=>ClientIncident::class]);
            $this->assertSame(0,(clone $query)->count());
            $this->assertSame([],app(ChemicalExposureAuditAccessService::class)->scope(AuditLog::whereKey($row->id))->pluck('id')->all());
            $this->assertSame(0,$this->scoped([$row->id],$current,$context)->count());
            DB::table('hs_events')->where('id',$event->id)->update(['source_type'=>$original]);
            $this->assertSame(1,(clone $query)->count());
            return [];
        });
    }

    public function test_original_assigned_client_withdrawal_after_scope_is_not_a_cached_permission(): void
    {
        foreach (['clients.viewAny','incidents.viewAny'] as $key) { $this->allow($this->manager,$key,false); }
        foreach (['clients.viewAssigned','incidents.viewAssigned'] as $key) { $this->allow($this->manager,$key); }
        $client=Client::factory()->create(['site_id'=>$this->site->id]);
        $client->supportWorkers()->attach($this->manager->id);
        $incident=ClientIncident::withoutEvents(fn()=>ClientIncident::factory()->submitted()->create([
            'site_id'=>$this->site->id,'client_id'=>$client->id,'reported_by'=>$this->manager->id,
            'shift_id'=>null,'service_context_id'=>null,'respite_stay_id'=>null]));
        $row=$this->auditFor($this->exposure(['related_incident_id'=>$incident->id]));
        $pivot=DB::table('client_user')->where('client_id',$client->id)->where('user_id',$this->manager->id)->first();
        $this->read(function ($current,$context) use ($row,$pivot): array {
            $query=$this->scoped([$row->id],$current,$context);
            DB::table('client_user')->where('id',$pivot->id)->delete();
            $this->assertSame(0,(clone $query)->count());
            DB::table('client_user')->insert((array)$pivot);
            $this->assertSame(1,(clone $query)->count());
            return [];
        });
    }

    public function test_actual_before_final_query_mutation_is_blocked_in_count_and_body_statement(): void
    {
        $row=$this->auditFor($this->exposure(),['after'=>['notes'=>'Original accepted audit projection']]);
        $original=$row->getRawOriginal('meta');
        $this->read(function ($current,$context) use ($row,$original): array {
            $query=$this->scoped([$row->id],$current,$context);
            $once=false;
            $query->getQuery()->beforeQuery(function () use ($row,&$once): void {
                if ($once) { return; } $once=true;
                DB::table('audit_logs')->where('id',$row->id)->update([
                    'meta'=>json_encode(['after'=>['notes'=>'Original accepted audit projection ']],JSON_THROW_ON_ERROR)]);
            });
            $this->assertSame(0,(clone $query)->count());
            $this->assertTrue($once);
            $this->assertSame([],(clone $query)->get()->all());
            DB::table('audit_logs')->where('id',$row->id)->update(['meta'=>$original]);
            $this->assertSame(1,(clone $query)->count());
            return [];
        });
    }

    public function test_auth_only_inertia_partial_withdrawal_and_restore_always_replace_private_rows_counts_options(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure audit row-hiding guard (ChemicalExposureAuditAccessService::materialize/scope) is not mounted, by Stephan\'s decision. The audit pages keep main\'s rows for audit.viewAny holders; the exposure symptoms and treatment fields will be hidden for everyone instead (job 4).', 'Job 4 (after the merge): hide exposure symptoms and treatment fields for everyone on the audit pages and CSV; Codex\'s row-hiding guard stays out.'));
        $row=$this->auditFor($this->exposure());
        $surfaces=[['/audit-logs','audit/index',['logs','filter_options']],
            ['/settings/audit-logs','settings/audit-logs',['events','users','stats']]];
        $this->actingAs($this->manager);
        foreach ($surfaces as [$url,$component,$keys]) {
            $headers=['X-Inertia'=>'true','X-Inertia-Partial-Component'=>$component,'X-Inertia-Partial-Data'=>'auth'];
            $this->allow($this->manager,'hr.wellbeing.view',false);
            $response=$this->get($url,$headers)->assertOk();
            foreach ($keys as $key) { $response->assertJsonStructure(['props'=>[$key]]); }
            $this->assertStringNotContainsString('Substance Exposure Record',json_encode($response->json('props'),JSON_THROW_ON_ERROR));
            $this->allow($this->manager,'hr.wellbeing.view');
            $response=$this->get($url,$headers)->assertOk();
            $rows=$response->json('props.'.($component==='audit/index'?'logs':'events').'.data');
            $this->assertContains($row->id,array_column($rows,'id'));
            DB::table('hr_employee_profiles')->where('user_id',$this->manager->id)->update(['primary_site_id'=>$this->foreignSite->id]);
            $response=$this->get($url,$headers)->assertOk();
            $this->assertNotContains($row->id,array_column($response->json('props.'.($component==='audit/index'?'logs':'events').'.data'),'id'));
            DB::table('hr_employee_profiles')->where('user_id',$this->manager->id)->update(['primary_site_id'=>$this->site->id]);
            $response=$this->get($url,$headers)->assertOk();
            $this->assertContains($row->id,array_column($response->json('props.'.($component==='audit/index'?'logs':'events').'.data'),'id'));
        }
    }


    public function test_sealed_source_refusal_withholds_only_private_row_and_copied_projection(): void
    {
        $healthy = $this->auditFor($this->exposure());
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'site_id' => $this->site->id, 'client_id' => $client->id, 'reported_by' => $this->manager->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null]));
        $sealed = SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::factory()->create([
            'hazardous_substance_id' => $this->catalogue()->id, 'site_id' => $this->site->id,
            'user_id' => $this->worker->id, 'related_incident_id' => $incident->id, 'original_context' => null,
            'source_schema_version' => 0, 'source_revision' => 0, 'symptoms' => 'SEALED-PRIVATE-FACT']));
        $row = $this->auditFor($sealed);
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
            'source_type' => SubstanceExposureRecord::class, 'source_id' => $sealed->id, 'site_id' => $this->site->id,
            'staff_id' => $this->worker->id, 'client_id' => null, 'shift_id' => null, 'asset_id' => null,
            'event_category' => HsEvent::CATEGORY_EXPOSURE]));
        $this->read(function ($current, $context) use ($healthy, $row, $event): array {
            $query = $this->scoped([$healthy->id, $row->id], $current, $context);
            $this->assertSame(1, (clone $query)->count());
            $this->assertSame([$healthy->id], (clone $query)->pluck('id')->all());
            $projection = app(ChemicalExposureSourceProjectionService::class);
            $this->assertSame([], $projection->readableHsEventIdsInContext($current, HsEvent::whereKey($event->id), $context));
            $detail = $projection->forHsEvent($event->id, $current, $context);
            $this->assertSame('unavailable', $detail['access']);
            $this->assertNull($detail['source']);
            $this->assertStringNotContainsString('SEALED-PRIVATE-FACT', json_encode($detail, JSON_THROW_ON_ERROR));
            return [];
        });
    }

    public function test_unrelated_or_mixed_validation_is_not_concealed_as_canonical_source_refusal(): void
    {
        foreach ([['source' => 'An unrelated validation fault.'],
            ['source' => 'Canonical source provenance requires repair before private body access.', 'other' => 'Unexpected fault.']] as $errors) {
            $this->assertFalse(ChemicalExposureSourceProjectionService::isCanonicalSourceRefusal(
                \Illuminate\Validation\ValidationException::withMessages($errors)));
        }
        $this->assertTrue(ChemicalExposureSourceProjectionService::isCanonicalSourceRefusal(
            \Illuminate\Validation\ValidationException::withMessages([
                'source' => 'Canonical source provenance requires repair before private body access.'])));
    }

    public function test_late_foreign_root_version_insertion_and_receipt_rebind_invalidate_final_identity(): void
    {
        $root = $this->exposure(); $row = $this->auditFor($root); $neighbor = $this->auditFor($this->exposure());
        $foreign = $this->exposure();
        $version = ChemicalExposureSourceVersion::where('exposure_id', $foreign->id)->sole();
        $receipt = ChemicalExposureCommandReceipt::where('exposure_id', $foreign->id)->sole();
        $this->read(function ($current, $context) use ($root, $row, $neighbor, $version, $receipt): array {
            $query = $this->scoped([$row->id, $neighbor->id], $current, $context);
            $values = $version->getRawOriginal(); unset($values['id']);
            $values['aggregate_revision'] += 100;
            $values['source_id'] = $root->id;
            $inserted = DB::table('chemical_exposure_source_versions')->insertGetId($values);
            try {
                $this->assertSame([$neighbor->id], (clone $query)->pluck('id')->all());
                $this->assertSame(1, (clone $query)->count());
            } finally { DB::table('chemical_exposure_source_versions')->where('id', $inserted)->delete(); }
            $this->assertSame(2, (clone $query)->count());
            DB::table('chemical_exposure_command_receipts')->where('id', $receipt->id)->update(['source_id' => $root->id]);
            try { $this->assertSame([$neighbor->id], (clone $query)->pluck('id')->all()); }
            finally { DB::table('chemical_exposure_command_receipts')->where('id', $receipt->id)->update(['source_id' => $receipt->source_id]); }
            $this->assertSame(2, (clone $query)->count());
            return [];
        });
    }

    public function test_late_foreign_child_kind_identity_rebind_invalidates_count_and_body_without_other_parent_payload(): void
    {
        $root = $this->exposure();
        $own = $this->exposureService()->review($root, $this->manager, $this->privateCommand($root, [
            'reviewed_at' => '2026-10-12T01:00:00Z', 'outcome' => 'recorded', 'notes' => 'Accepted review.']));
        $child = \App\Models\ChemicalExposureReview::where('exposure_id', $root->id)->sole();
        $row = AuditLog::create(['action' => 'chemical.exposure.reviewed', 'auditable_type' => $child::class,
            'auditable_id' => $child->id, 'user_id' => $this->manager->id, 'meta' => []]);
        $neighbor = $this->auditFor($this->exposure()); $foreign = $this->exposure();
        $this->exposureService()->review($foreign, $this->manager, $this->privateCommand($foreign, [
            'reviewed_at' => '2026-10-12T01:00:00Z', 'outcome' => 'recorded', 'notes' => 'FOREIGN-PRIVATE-REVIEW']));
        $version = ChemicalExposureSourceVersion::where('exposure_id', $foreign->id)->where('source_type', 'review')->sole();
        $receipt = ChemicalExposureCommandReceipt::where('exposure_id', $foreign->id)->where('source_type', 'review')->sole();
        $this->read(function ($current, $context) use ($row, $neighbor, $child, $version, $receipt): array {
            $query = $this->scoped([$row->id, $neighbor->id], $current, $context);
            foreach (['chemical_exposure_source_versions' => $version, 'chemical_exposure_command_receipts' => $receipt] as $table => $origin) {
                DB::table($table)->where('id', $origin->id)->update(['source_id' => $child->id]);
                try {
                    $this->assertSame(1, (clone $query)->count());
                    $rows = (clone $query)->with(['user:id,name'])->get()->toArray();
                    $this->assertSame([$neighbor->id], array_column($rows, 'id'));
                    $this->assertStringNotContainsString('FOREIGN-PRIVATE-REVIEW', json_encode($rows, JSON_THROW_ON_ERROR));
                } finally { DB::table($table)->where('id', $origin->id)->update(['source_id' => $origin->source_id]); }
                $this->assertSame(2, (clone $query)->count());
            }
            return [];
        });
    }

    public function test_hr_auth_only_partial_always_replaces_copied_private_rows_and_restores_admitted_neighbors(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure audit row-hiding guard (ChemicalExposureAuditAccessService::materialize/scope) is not mounted, by Stephan\'s decision. The audit pages keep main\'s rows for audit.viewAny holders; the exposure symptoms and treatment fields will be hidden for everyone instead (job 4). The HR audit log keeps main\'s rows; the test also grants hr.settings.manage, which RbacSeeder does not create.', 'Job 4 (after the merge): hide exposure symptoms and treatment fields for everyone on the audit pages and CSV; Codex\'s row-hiding guard stays out.'));
        $this->allow($this->manager, 'hr.settings.manage');
        $root = $this->exposure();
        $private = AuditLog::create(['action' => 'hr.exposure.reviewed', 'auditable_type' => User::class,
            'auditable_id' => $this->worker->id, 'user_id' => $this->manager->id,
            'meta' => ['exposure_id' => $root->id, 'after' => ['notes' => 'PRIVATE-HR-COPY']]]);
        $ordinary = AuditLog::create(['action' => 'hr.employee.updated', 'auditable_type' => User::class,
            'auditable_id' => $this->worker->id, 'user_id' => $this->manager->id, 'meta' => null]);
        $this->actingAs($this->manager);
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'hr/settings/audit-log',
            'X-Inertia-Partial-Data' => 'filters'];
        $response = $this->get('/hr/settings/audit-log', $headers)->assertOk();
        $this->assertContains($private->id, array_column($response->json('props.logs.data'), 'id'));
        $this->manager->load(['roles.permissions', 'permissionOverrides']);
        $this->allow($this->manager, 'hr.wellbeing.view', false);
        $response = $this->get('/hr/settings/audit-log', $headers)->assertOk();
        $response->assertJsonStructure(['props' => ['logs', 'actions', 'modelTypes', 'users']]);
        $this->assertContains($ordinary->id, array_column($response->json('props.logs.data'), 'id'));
        $this->assertNotContains($private->id, array_column($response->json('props.logs.data'), 'id'));
        $this->assertStringNotContainsString('PRIVATE-HR-COPY', json_encode($response->json('props'), JSON_THROW_ON_ERROR));
        $this->allow($this->manager, 'hr.wellbeing.view');
        $response = $this->get('/hr/settings/audit-log', $headers)->assertOk();
        $this->assertContains($private->id, array_column($response->json('props.logs.data'), 'id'));
    }

    private function read(callable $consumer): mixed
    {
        return app(ChemicalExposureAuditAccessService::class)->materialize($this->manager,$consumer);
    }

    private function scoped(array $ids, User $current, HealthSafetyReadContext $context): Builder
    {
        return app(ChemicalExposureAuditAccessService::class)->scope(AuditLog::whereKey($ids),$current,$context);
    }

    private function auditFor(SubstanceExposureRecord $root, array $meta=[]): AuditLog
    {
        return AuditLog::create(['action'=>'chemical.exposure.updated','auditable_type'=>SubstanceExposureRecord::class,
            'auditable_id'=>$root->id,'user_id'=>$this->manager->id,'meta'=>$meta]);
    }

    private function delivered(): array
    {
        $root=$this->exposure(['medical_treatment'=>'none']);
        $attempt=ChemicalExposureSourceDelivery::where('exposure_id',$root->id)->where('kind','health_safety')->sole();
        app(ChemicalExposureHsEventDeliveryService::class)->deliver($this->manager,$attempt->id);
        $marker=ChemicalExposureHsProjection::where('exposure_id',$root->id)->sole();
        return [$root,HsEvent::findOrFail($marker->hs_event_id),$marker];
    }
}
