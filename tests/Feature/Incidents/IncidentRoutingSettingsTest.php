<?php

namespace Tests\Feature\Incidents;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\HealthSafetySettingChange;
use App\Models\Site;
use App\Services\Incidents\IncidentRoutingSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IncidentRoutingSettingsTest extends IncidentRoutingTestCase
{
    public function test_missing_settings_have_no_default_recipient_deadline_or_reviewed_provenance(): void
    {
        $dto = app(IncidentRoutingSettings::class)->editor($this->manager);
        $this->assertSame('unconfigured', $dto['routing_configuration']['state']);
        $this->assertNull($dto['routing_configuration']['configuration']);
        $this->assertNull($dto['routing_configuration']['recorded_at']);
        $this->assertSame(0, HealthSafetySettingChange::where('setting_key', IncidentRoutingSettings::KEY)->count());
    }

    public function test_configured_and_kept_revisions_are_exact_audited_without_modifying_bsp(): void
    {
        AppSetting::create(['key' => 'healthSafety.restraints.plan_approval_policy', 'value' => ['schema_version' => 1, 'mode' => 'separate_specialist']]);
        $a = $this->settings(); $b = $this->settings(revision: 1);
        $this->assertSame(1, $a['revision']); $this->assertSame(2, $b['revision']); $this->assertSame('kept', $b['action']);
        $this->assertSame('separate_specialist', AppSetting::where('key', 'healthSafety.restraints.plan_approval_policy')->value('value')['mode']);
        $this->assertSame(2, HealthSafetySettingChange::where('setting_key', IncidentRoutingSettings::KEY)->count());
        $this->assertSame(2, AuditLog::where('action', 'like', 'incidents.routing.settings.%')->count());
    }

    public function test_uppercase_uuid_replays_same_actor_and_intent_after_later_configuration(): void
    {
        $uuid = (string) Str::uuid(); $a = $this->settings(uuid: strtoupper($uuid));
        $config = $this->config(); $config['routes'][0]['lead_user_id'] = $this->cover->id; $this->settings($config, 1);
        $this->assertSame($a, $this->settings(uuid: $uuid));
        $this->assertSame($a, app(IncidentRoutingSettings::class)->receiptForManager($this->manager, strtoupper($uuid)));
    }

    public function test_changed_intent_and_stale_revision_preserve_current_configuration(): void
    {
        $uuid = (string) Str::uuid(); $a = $this->settings(uuid: $uuid); $changed = $this->config(); $changed['triage']['escalation_after_minutes'] = 91;
        $this->validation(fn () => $this->settings($changed, 0, $uuid), 'request_uuid');
        $this->validation(fn () => $this->settings($changed), 'expected_revision');
        $this->assertSame($a['configuration_digest'], app(IncidentRoutingSettings::class)->editor($this->manager)['routing_configuration']['digest']);
    }

    public function test_warmed_manager_grant_withdrawal_denies_before_configuration_or_history_sql(): void
    {
        $this->settings(); $this->manager->load(['roles.permissions', 'permissionOverrides']); $this->allow($this->manager, IncidentRoutingSettings::PERMISSION, false);
        DB::enableQueryLog(); DB::flushQueryLog();
        try { $this->denial(fn () => app(IncidentRoutingSettings::class)->editor($this->manager)); $sql = strtolower(implode(' ', array_column(DB::getQueryLog(), 'query'))); }
        finally { DB::disableQueryLog(); }
        $this->assertStringNotContainsString('health_safety_setting_changes', $sql);
        $this->assertStringNotContainsString('`value`', $sql);
    }

    public function test_foreign_site_and_inactive_external_or_unapproved_references_do_not_save(): void
    {
        $config = $this->config(); $config['routes'][0]['site_id'] = Site::factory()->create()->id;
        $this->denial(fn () => $this->settings($config), 404);
        // external_clinical_account is not mass assignable and the User model refuses external-to-internal changes,
        // so the fixture row is set and restored directly to keep the later inactive-profile check meaningful.
        foreach (['approved_at' => null, 'external_clinical_account' => true] as $key => $value) {
            DB::table('users')->where('id', $this->lead->id)->update([$key => $value]); $this->validation(fn () => $this->settings(), 'configuration.routes.0.lead_user_id');
            DB::table('users')->where('id', $this->lead->id)->update([$key => $key === 'approved_at' ? now() : false]);
        }
        $this->lead->hrEmployeeProfile()->update(['is_active' => false]);
        $this->validation(fn () => $this->settings(), 'configuration.routes.0.lead_user_id');
        $this->assertSame(0, HealthSafetySettingChange::where('setting_key', IncidentRoutingSettings::KEY)->count());
    }

    public function test_overlapping_rules_incomplete_cover_bad_dates_and_unknown_fields_are_rejected(): void
    {
        $config = $this->config(); $config['routes'][] = $config['routes'][0]; $this->validation(fn () => $this->settings($config), 'configuration.routes.1.effective_from');
        $config = $this->config(); $config['routes'][0]['cover_user_id'] = $this->cover->id; $this->validation(fn () => $this->settings($config), 'configuration.routes.0.cover_user_id');
        foreach (['2026-02-30T00:00:00Z', '2026-10-12T00:00:00+25:00'] as $date) {
            $config = $this->config(); $config['routes'][0]['effective_from'] = $date; $this->validation(fn () => $this->settings($config), 'configuration.routes.0.effective_from');
        }
        $this->validation(fn () => $this->settings($this->config(['unexpected_field' => 1])), 'configuration');
    }

    public function test_missing_triage_is_explicitly_configurable_without_invented_monitored_readiness(): void
    {
        $this->settings($this->config(['triage' => null]));
        $dto = app(IncidentRoutingSettings::class)->editor($this->manager);
        $this->assertNull($dto['routing_configuration']['configuration']['triage']['values']);
        $this->assertNotEmpty($dto['sites']);
    }

    public function test_malformed_or_unanchored_configuration_is_unusable_and_not_silently_defaulted(): void
    {
        $this->settings(); DB::table('app_settings')->where('key', IncidentRoutingSettings::KEY)->update(['value' => json_encode(['schema_version' => 1, 'routes' => [], 'triage' => null])]);
        $this->assertSame('repair_required', app(IncidentRoutingSettings::class)->editor($this->manager)['routing_configuration']['state']);
        $this->assertNull(app(IncidentRoutingSettings::class)->editor($this->manager)['routing_configuration']['configuration']);
    }

    public function test_audit_or_setting_save_veto_rolls_back_revision_and_history(): void
    {
        foreach ([AuditLog::class, AppSetting::class, HealthSafetySettingChange::class] as $class) {
            $this->veto($class, fn () => $this->settings());
            $this->assertSame(0, HealthSafetySettingChange::where('setting_key', IncidentRoutingSettings::KEY)->count());
            $this->assertNull(AppSetting::where('key', IncidentRoutingSettings::KEY)->first());
        }
    }

    public function test_candidates_recheck_current_sites_and_hold_permission_without_granting_incident_access(): void
    {
        $service = app(IncidentRoutingSettings::class); $ids = array_column($service->candidates($this->manager, $this->site->id), 'id');
        $this->assertContains($this->lead->id, $ids); $this->assertNotContains($this->reporter->id, $ids);
        $this->allow($this->lead, 'incidents.owner.hold', false); $this->assertNotContains($this->lead->id, array_column($service->candidates($this->manager, $this->site->id), 'id'));
        $this->denial(fn () => $service->candidates($this->reporter, $this->site->id));
    }

    public function test_mutable_model_cannot_escape_configuration_callback(): void
    {
        $this->expectException(\LogicException::class);
        app(IncidentRoutingSettings::class)->withLockedConfiguration(fn () => $this->manager);
    }
    public function test_picker_discovers_only_selected_site_or_explicit_current_bypass_roster_before_names(): void
    {
        $foreign = $this->staff(['incidents.viewAny', 'incidents.owner.hold'], Site::factory()->create());
        $service = app(IncidentRoutingSettings::class); $this->assertNotContains($foreign->id, array_column($service->candidates($this->manager, $this->site->id), 'id'));
        $this->allow($foreign, 'healthSafety.viewAllSites'); $this->assertContains($foreign->id, array_column($service->candidates($this->manager, $this->site->id), 'id'));
        $foreign->load(['roles.permissions', 'permissionOverrides']); $this->allow($foreign, 'healthSafety.viewAllSites', false);
        $this->assertNotContains($foreign->id, array_column($service->candidates($this->manager, $this->site->id), 'id'));
        DB::enableQueryLog(); DB::flushQueryLog();
        try { $this->denial(fn () => $service->candidates($this->manager, $foreign->hrEmployeeProfile->primary_site_id), 404); $sql = strtolower(implode(' ', array_column(DB::getQueryLog(), 'query'))); }
        finally { DB::disableQueryLog(); }
        $this->assertStringNotContainsString('order by `name`', $sql);
    }

    public function test_retained_reason_or_command_fingerprint_corruption_cannot_replay_configuration_acceptance(): void
    {
        $receipt = $this->settings(); $row = HealthSafetySettingChange::where('command_key', $receipt['request_uuid'])->sole();
        DB::table('health_safety_setting_changes')->where('id', $row->id)->update(['reason' => 'Changed historical reason']);
        $this->validation(fn () => app(IncidentRoutingSettings::class)->receiptForManager($this->manager, $receipt['request_uuid']), 'configuration');
    }
    public function test_scoped_settings_edit_and_history_hide_other_site_routes_and_preserve_them_atomically(): void
    {
        $otherSite = Site::factory()->create(); $otherLead = $this->staff(['incidents.viewAny', 'incidents.owner.hold'], $otherSite);
        $this->allow($this->manager, 'healthSafety.viewAllSites');
        $config = $this->config(); $config['routes'][] = [...$config['routes'][0], 'site_id' => $otherSite->id, 'lead_user_id' => $otherLead->id]; $this->settings($config);
        $this->allow($this->manager, 'healthSafety.viewAllSites', false);
        $dto = app(IncidentRoutingSettings::class)->editor($this->manager);
        $this->assertSame([$this->site->id], array_column($dto['routing_configuration']['configuration']['routes'], 'site_id'));
        $this->assertSame([$this->site->id], array_column($dto['routing_history']['data'][0]['configuration']['routes'], 'site_id'));
        $local = $dto['routing_configuration']['configuration']; $local['triage'] = $local['triage']['values']; $local['routes'][0]['lead_user_id'] = $this->cover->id; $this->settings($local, 1);
        $stored = AppSetting::where('key', IncidentRoutingSettings::KEY)->value('value');
        $this->assertCount(2, $stored['routes']); $this->assertSame($otherLead->id, collect($stored['routes'])->firstWhere('site_id', $otherSite->id)['lead_user_id']);
        $this->assertSame($this->cover->id, collect($stored['routes'])->firstWhere('site_id', $this->site->id)['lead_user_id']);
    }
    public function test_hidden_org_triage_is_explicitly_restricted_and_omission_preserves_exact_references(): void
    {
        $otherSite = Site::factory()->create(); $hidden = $this->staff(['incidents.viewAny', 'incidents.owner.hold', 'incidents.owner.assign'], $otherSite);
        $this->allow($this->manager, 'healthSafety.viewAllSites'); $config = $this->config(); $config['triage']['user_id'] = $hidden->id; $config['triage']['escalation_user_id'] = $hidden->id; $this->settings($config);
        $this->allow($this->manager, 'healthSafety.viewAllSites', false);
        $dto = app(IncidentRoutingSettings::class)->editor($this->manager);
        $this->assertSame(['visibility' => 'restricted', 'state' => 'configured'], $dto['routing_configuration']['configuration']['triage']);
        $this->assertSame(['visibility' => 'restricted', 'state' => 'configured'], $dto['routing_history']['data'][0]['configuration']['triage']);
        $local = ['schema_version' => 1, 'routes' => $dto['routing_configuration']['configuration']['routes']];
        $local['routes'][0]['lead_user_id'] = $this->cover->id; $this->settings($local, 1);
        $stored = AppSetting::where('key', IncidentRoutingSettings::KEY)->value('value'); $this->assertSame($config['triage'], $stored['triage']);
        $this->validation(fn () => $this->settings([...$local, 'triage' => null], 2), 'configuration.triage');
        $forged = $local; $forged['triage'] = $config['triage']; $this->validation(fn () => $this->settings($forged, 2), 'configuration.triage');
    }

    public function test_new_unseen_triage_user_cannot_be_nominated_from_settings_permission_alone(): void
    {
        $outside = $this->staff(['incidents.viewAny', 'incidents.owner.hold', 'incidents.owner.assign'], Site::factory()->create());
        $config = $this->config(); $config['triage']['user_id'] = $outside->id;
        $this->validation(fn () => $this->settings($config), 'configuration.triage.user_id');
        $this->assertSame(0, HealthSafetySettingChange::where('setting_key', IncidentRoutingSettings::KEY)->count());
    }
    public function test_damaged_earlier_history_is_unavailable_without_forged_actor_or_configuration_and_healthy_latest_remains(): void
    {
        $first = $this->settings(); $this->settings(revision: 1);
        DB::table('health_safety_setting_changes')->where('command_key', $first['request_uuid'])->update(['actor_snapshot' => json_encode(['id' => $this->manager->id, 'name' => 'Forged historical name'])]);
        $dto = app(IncidentRoutingSettings::class)->editor($this->manager);
        $this->assertSame('configured', $dto['routing_configuration']['state']);
        $this->assertSame('recorded', $dto['routing_history']['data'][0]['integrity']);
        $this->assertSame('repair_required', $dto['routing_history']['data'][1]['integrity']);
        $this->assertNull($dto['routing_history']['data'][1]['actor']); $this->assertNull($dto['routing_history']['data'][1]['configuration']);
    }
    public function test_selected_reference_beyond_first_thirty_is_currently_scoped_without_directory_or_stale_grant_fallback(): void
    {
        $this->lead->update(['name' => 'ZZZ retained configured lead']);
        for ($index = 0; $index < 31; $index++) {
            $this->staff(['incidents.viewAny', 'incidents.owner.hold'])->update(['name' => 'AAA eligible '.str_pad((string) $index, 2, '0', STR_PAD_LEFT)]);
        }
        $service = app(IncidentRoutingSettings::class);
        $this->assertCount(30, $service->candidates($this->manager, $this->site->id));
        $this->assertNotContains($this->lead->id, array_column($service->candidates($this->manager, $this->site->id), 'id'));
        $selected = $service->candidates($this->manager, $this->site->id, selectedId: $this->lead->id);
        $this->assertSame([['id' => $this->lead->id, 'name' => 'ZZZ retained configured lead']], $selected);
        $this->lead->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $this->allow($this->lead, 'incidents.owner.hold', false);
        $this->assertSame([], $service->candidates($this->manager, $this->site->id, selectedId: $this->lead->id));
        $this->allow($this->lead, 'incidents.owner.hold');
        $this->assertSame($selected, $service->candidates($this->manager, $this->site->id, selectedId: $this->lead->id));
        $foreign = $this->staff(['incidents.viewAny', 'incidents.owner.hold'], \App\Models\Site::factory()->create());
        DB::enableQueryLog(); DB::flushQueryLog();
        try { $this->assertSame([], $service->candidates($this->manager, $this->site->id, selectedId: $foreign->id)); $queries = DB::getQueryLog(); }
        finally { DB::disableQueryLog(); }
        foreach ($queries as $query) {
            if (preg_match('/select\s+(?:`users`\.|"users"\.)?\*\s+from\s+[`"]?users/i', $query['query'])) {
                $this->assertNotContains($foreign->id, $query['bindings']);
            }
        }
        $this->lead->hrEmployeeProfile()->update(['primary_site_id' => $foreign->hrEmployeeProfile->primary_site_id]);
        $this->assertSame([], $service->candidates($this->manager, $this->site->id, selectedId: $this->lead->id));
        $this->denial(fn () => $service->candidates($this->manager, $foreign->hrEmployeeProfile->primary_site_id, selectedId: $foreign->id), 404);
    }
}
