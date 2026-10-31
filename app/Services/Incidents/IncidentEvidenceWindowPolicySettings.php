<?php

namespace App\Services\Incidents;

use App\Models\{AppSetting, AuditLog, HealthSafetySettingChange, Site, User};
use App\Services\{AuditLogger, CurrentAuthorizationReads, UserSiteAccessService};
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Governed configuration only. No source access, consent, retention or operational defaults. */
final class IncidentEvidenceWindowPolicySettings
{
    public const KEY = 'healthSafety.incidents.evidence_window_policy';
    public const REVISION_KEY = 'healthSafety.incidents.evidence_window_policy_revision';
    public const PERMISSION = 'healthSafety.settings.manage';
    public const SCHEMA_VERSION = 1;
    private const EVENT = 'health_safety.settings.incident_evidence_window.';

    public function __construct(private readonly IncidentRoutingSettings $managers, private readonly UserSiteAccessService $sites) {}

    /** A new window writer must call this before incident locks and retain the lock through commit. */
    public function withLockedPolicy(callable $operation): mixed
    {
        return $this->transaction(function () use ($operation) {
            $this->mutex();
            return CurrentAuthorizationReads::within(fn ($reads) => $operation($this->snapshot($reads)));
        });
    }

    /** Read-only equality proof while the caller still owns the original policy mutex transaction. */
    public function assertLockedSnapshot(array $original, CurrentAuthorizationReads $reads): void
    {
        $reads->assertActive();
        // Unlike withLockedPolicy, this never creates/recreates the mutex or starts a transaction.
        abort_unless($reads->query(AppSetting::query()->where('key', self::REVISION_KEY))->exists()
            && $this->snapshot($reads) === $original, 404);
    }

    public function editor(User $actor, int $page = 1): array
    {
        return $this->transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($actor, $page) {
            $current = $this->managers->current($actor, $reads);
            $siteIds = $this->readableSettingSiteIds($current,$reads);
            $history = $reads->query(HealthSafetySettingChange::query()->where('setting_key', self::KEY)->orderByDesc('revision'))->paginate(25, ['*'], 'window_policy_page', max(1, $page));
            $history->setCollection($history->getCollection()->map(fn ($row) => [
                'id'=>(int)$row->id,'revision'=>$row->revision,'action'=>$row->action,'origin'=>$row->origin,
                'before_value'=>$this->scopedPolicy($row->before_value,$siteIds),'after_value'=>$this->scopedPolicy($row->after_value,$siteIds),'before_digest'=>$row->before_digest,'after_digest'=>$row->after_digest,
                'scope_complete'=>$this->scopeComplete($row->after_value,$siteIds),
                'actor'=>$row->actor_snapshot,'recorded_at'=>$row->recorded_at->utc()->toIso8601String(),'reason'=>$row->reason,'audit_id'=>$row->audit_id,
            ]));
            $snapshot=$this->snapshot($reads);
            $retained=$reads->query(HealthSafetySettingChange::query()->where('setting_key',self::KEY)->orderByDesc('revision'))->first();
            $raw=$reads->query(AppSetting::query()->where('key',self::KEY))->first();
            $snapshot['scope_complete']=$this->scopeComplete($raw?->value,$siteIds) && $this->scopeComplete($retained?->after_value,$siteIds);
            $snapshot['policy_snapshot']=$this->scopedPolicy($snapshot['policy_snapshot'],$siteIds);
            return ['policy'=>$snapshot,'can_manage'=>$snapshot['scope_complete'],'history'=>$history->toArray(),
                'options'=>['triggers'=>array_map(fn ($value,$label)=>compact('value','label'),array_keys(IncidentEvidenceWindowPolicyValues::TRIGGERS),array_values(IncidentEvidenceWindowPolicyValues::TRIGGERS)),
                    'source_types'=>array_map(fn ($value,$label)=>compact('value','label'),IncidentEvidenceWindowPolicyValues::SOURCE_TYPES,['Sensors','Cameras','Door and access events','Staff location','Supported-person location','Response history']),
                    'sites'=>$reads->query(Site::query()->whereIn('id',$siteIds)->orderBy('name'))->get(['id','name','is_active'])->map(fn($site)=>['id'=>(int)$site->id,'name'=>$site->name,'is_active'=>(bool)$site->is_active])->all()]];
        }));
    }

    public function save(User $actor, array $input): array
    {
        return $this->transaction(function () use ($actor,$input) {
            $mutex = $this->mutex();
            return CurrentAuthorizationReads::within(function ($reads) use ($actor,$input,$mutex) {
                $current = $this->managers->current($actor,$reads);
                $v = IncidentRecordingReconstructionValues::class;
                $v::exact($input,['expected_actor_id','expected_policy_revision','idempotency_key','reason','policy'],'command');
                abort_unless($v::positive($input['expected_actor_id'],'expected_actor_id') === (int)$current->id,403);
                if (!is_int($input['expected_policy_revision']) || $input['expected_policy_revision'] < 0 || $input['expected_policy_revision'] >= 2147483647) { $v::fail('expected_policy_revision','Use a valid nonnegative policy revision.'); }
                $input['idempotency_key']=$v::uuid($input['idempotency_key'],'idempotency_key');
                $input['reason']=$v::text($input['reason'],'reason',10,2000);
                if (!is_array($input['policy'])) { $v::fail('policy','Use an explicit policy object.'); }
                $policy=IncidentEvidenceWindowPolicyValues::policy($input['policy']); $input['policy']=$policy;
                $fingerprint=$v::digest(['setting_key'=>self::KEY,'actor_id'=>(int)$current->id,'command'=>'save_evidence_window_policy','payload'=>$input]);
                $prior=$reads->query(HealthSafetySettingChange::query()->where('command_key',$input['idempotency_key']))->first();
                if ($prior) {
                    abort_unless($prior->setting_key===self::KEY && $prior->actor_id===(int)$current->id,404);
                    if (!hash_equals((string)$prior->fingerprint,$fingerprint)) { $v::fail('idempotency_key','This key belongs to different saved work.'); }
                    $this->assertChange($prior,$reads);
                    return $this->receipt($prior,true,$current,$reads);
                }
                $before=$this->snapshot($reads);
                if ($before['policy_revision']!==$input['expected_policy_revision']) { $v::fail('expected_policy_revision','The policy changed or its revision is invalid. Review current settings; your entries were not saved.'); }
                $siteIds=$this->sites->accessibleSiteIds($current,UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,$reads);
                $readableSiteIds=$this->readableSettingSiteIds($current,$reads);
                abort_unless($this->scopeComplete($before['policy_snapshot'],$readableSiteIds),403);
                $retained=$reads->query(HealthSafetySettingChange::query()->where('setting_key',self::KEY)->orderByDesc('revision'))->first();
                abort_unless($this->scopeComplete($retained?->after_value,$readableSiteIds),403);
                foreach ($policy['rules'] as $rule) {
                    if ($rule['site_id']!==null) {
                        abort_unless(in_array($rule['site_id'],$siteIds,true),404);
                        $reads->query(Site::query()->whereKey($rule['site_id'])->where('is_active',true))->firstOrFail(['id']);
                    }
                }
                $config=AppSetting::query()->where('key',self::KEY)->lockForUpdate()->first();
                // A corrupt current snapshot cannot be used to erase hidden retained Site rules.
                abort_unless($this->scopeComplete($config?->value,$readableSiteIds),403);
                $raw=$config?->value; $digest=$v::digest($policy); $revision=$before['policy_revision']+1; $at=now()->utc();
                $action=$before['policy_digest']===$digest?'kept':($reads->query(HealthSafetySettingChange::query()->where('setting_key',self::KEY)->where('after_digest',$digest))->exists()?'restored':'changed');
                AppSetting::query()->updateOrCreate(['key'=>self::KEY],['value'=>$policy]); $mutex->update(['value'=>$revision]);
                $saved=$reads->query(AppSetting::query()->whereIn('key',[self::KEY,self::REVISION_KEY]))->get()->keyBy('key');
                if ($saved->get(self::REVISION_KEY)?->value!==$revision || !$saved->get(self::KEY) || $v::digest($saved->get(self::KEY)->value)!==$digest) { throw new LogicException('The accepted policy and revision must both be persisted.'); }
                $meta=['actor_id'=>(int)$current->id,'setting_key'=>self::KEY,'schema_version'=>1,'policy_revision'=>$revision,'idempotency_key'=>$input['idempotency_key'],'fingerprint'=>$fingerprint,'after_digest'=>$digest,'action'=>$action];
                $request=clone request(); $request->setUserResolver(fn()=>$current);
                AuditLogger::logOrFail(self::EVENT.$action,$mutex,$meta,$request);
                $audits=$reads->query(AuditLog::query()->where('user_id',$current->id)->where('action',self::EVENT.$action)->where('auditable_type',$mutex->getMorphClass())->where('auditable_id',$mutex->id)->where('meta->idempotency_key',$input['idempotency_key']))->get();
                if ($audits->count()!==1) { throw new LogicException('An accepted evidence-window policy requires exactly one strict audit.'); }
                $accepted=['accepted'=>true,'idempotency_key'=>$input['idempotency_key'],'setting_key'=>self::KEY,'schema_version'=>1,'policy_revision'=>$revision,'policy_digest'=>$digest,'policy_snapshot'=>$policy,'action'=>$action,'origin'=>'organisation_setting','recorded_at'=>$at->toIso8601String()];
                $row=HealthSafetySettingChange::create(['setting_key'=>self::KEY,'revision'=>$revision,'schema_version'=>1,'action'=>$action,'origin'=>'organisation_setting','before_value'=>$raw,'after_value'=>$policy,'before_digest'=>$raw===null?null:$v::digest($raw),'after_digest'=>$digest,
                    'actor_id'=>$current->id,'actor_snapshot'=>['id'=>(int)$current->id,'name'=>$current->name,'permissions'=>[self::PERMISSION=>true]],'recorded_at'=>$at,'reason'=>$input['reason'],'audit_id'=>$audits->sole()->id,'command_key'=>$input['idempotency_key'],'fingerprint'=>$fingerprint,'accepted_result'=>$accepted]);
                $retained=$reads->query(HealthSafetySettingChange::query()->whereKey($row->id))->firstOrFail(); $this->assertChange($retained,$reads);
                return $this->receipt($retained,false,$current,$reads);
            });
        });
    }

    /** Actor-private recovery reauthorizes manager capability before disclosing receipt existence. */
    public function recover(User $actor, int $expectedActorId, string $key): array
    {
        IncidentRecordingReconstructionValues::uuid($key,'idempotency_key');
        return $this->transaction(fn()=>CurrentAuthorizationReads::within(function($reads)use($actor,$expectedActorId,$key){
            $current=$this->managers->current($actor,$reads); abort_unless((int)$current->id===$expectedActorId,403);
            $row=$reads->query(HealthSafetySettingChange::query()->where('setting_key',self::KEY)->where('actor_id',$current->id)->where('command_key',$key))->firstOrFail();
            $this->assertChange($row,$reads); return $this->receipt($row,true,$current,$reads);
        }));
    }

    /** Historical proof deliberately never compares with the current organisation policy. */
    public function assertRecordedWindowPolicy(array $validatedWindow, CurrentAuthorizationReads $reads): void
    {
        IncidentRecordingReconstructionValues::assertWindowRecord($validatedWindow);
        $row=$reads->query(HealthSafetySettingChange::query()->whereKey($validatedWindow['policy_change_id'])->where('setting_key',self::KEY))->first();
        if (!$row) { IncidentRecordingReconstructionValues::fail('window','The original approved policy evidence is missing.'); }
        $policy=$this->assertChange($row,$reads);
        if ($row->revision!==$validatedWindow['rule_revision'] || !hash_equals($row->after_digest,$validatedWindow['rule_snapshot_sha256']) || !$row->recorded_at->lt($validatedWindow['recorded_at'])) { IncidentRecordingReconstructionValues::fail('window','The recorded policy identity, revision, digest or unambiguous prior approval time does not match.'); }
        $next=$reads->query(HealthSafetySettingChange::query()->where('setting_key',self::KEY)->where('revision','>',$row->revision)->orderBy('revision'))->first();
        if ($next) {
            $this->assertChange($next,$reads);
            if ($next->revision!==$row->revision+1 || !$next->recorded_at->gt($validatedWindow['recorded_at'])) { IncidentRecordingReconstructionValues::fail('window','The selected policy had already changed, or its effective interval at capture cannot be established.'); }
        }
        IncidentEvidenceWindowPolicyValues::assertWindow($validatedWindow,$policy);
    }

    private function snapshot(CurrentAuthorizationReads $reads): array
    {
        $rows=$reads->query(AppSetting::query()->whereIn('key',[self::KEY,self::REVISION_KEY]))->get()->keyBy('key');
        $stored=$rows->get(self::KEY); $revisionRow=$rows->get(self::REVISION_KEY); $revision=$revisionRow ? $revisionRow->value : 0;
        $latest=$reads->query(HealthSafetySettingChange::query()->where('setting_key',self::KEY)->orderByDesc('revision'))->first();
        $state='invalid'; $policy=$digest=$change=null;
        if (!$stored && !$latest && $revision===0) { $state='unconfigured'; }
        elseif (is_int($revision) && $revision>0 && $revision<2147483647 && $latest && $latest->revision===$revision && $stored) {
            try {
                $candidate=$this->assertChange($latest,$reads);
                if (IncidentRecordingReconstructionValues::digest($stored->value)===IncidentRecordingReconstructionValues::digest($candidate)) { $policy=$candidate; $digest=$latest->after_digest; $change=(int)$latest->id; $state=$policy['enabled']?'configured':'disabled'; }
            } catch (ValidationException) { /* Invalid persisted evidence is visible, never a default. */ }
        }
        return ['setting_key'=>self::KEY,'schema_version'=>1,'configuration_state'=>$state,'configuration_valid'=>in_array($state,['configured','disabled'],true),'policy_revision'=>is_int($revision)&&$revision>=0&&$revision<2147483647&&(!$latest||$latest->revision<=$revision)?$revision:null,'setting_change_id'=>$change,'policy_digest'=>$digest,'policy_snapshot'=>$policy];
    }

    private function assertChange(HealthSafetySettingChange $row, CurrentAuthorizationReads $reads): array
    {
        $v=IncidentRecordingReconstructionValues::class; $reads->assertActive();
        if ($row->setting_key!==self::KEY || $row->schema_version!==1 || $row->revision<1 || !is_array($row->after_value) || !is_array($row->accepted_result) || !is_array($row->actor_snapshot) || $row->actor_id<1 || ($row->actor_snapshot['id']??null)!==$row->actor_id || ($row->actor_snapshot['permissions'][self::PERMISSION]??null)!==true || $row->origin!=='organisation_setting' || !in_array($row->action,['changed','restored','kept'],true)) { $v::fail('policy','The original policy provenance is invalid.'); }
        $policy=IncidentEvidenceWindowPolicyValues::policy($row->after_value); $digest=$v::digest($policy);
        $reason=$v::text($row->reason,'policy.reason',10,2000);
        $payload=['expected_actor_id'=>$row->actor_id,'expected_policy_revision'=>$row->revision-1,'idempotency_key'=>$row->command_key,'reason'=>$reason,'policy'=>$policy];
        $fingerprint=$v::digest(['setting_key'=>self::KEY,'actor_id'=>$row->actor_id,'command'=>'save_evidence_window_policy','payload'=>$payload]);
        if (!hash_equals((string)$row->fingerprint,$fingerprint) || ($row->before_value===null ? $row->before_digest!==null : $row->before_digest!==$v::digest($row->before_value))) { $v::fail('policy','The original policy command fingerprint or before-value digest changed.'); }
        $expected=['accepted'=>true,'idempotency_key'=>$v::uuid($row->command_key,'policy.command_key'),'setting_key'=>self::KEY,'schema_version'=>1,'policy_revision'=>$row->revision,'policy_digest'=>$digest,'policy_snapshot'=>$policy,'action'=>$row->action,'origin'=>'organisation_setting','recorded_at'=>$row->recorded_at->utc()->toIso8601String()];
        if ($v::digest($row->after_value)!==$digest || !hash_equals((string)$row->after_digest,$digest) || $v::digest($row->accepted_result)!==$v::digest($expected)) { $v::fail('policy','The accepted original policy no longer matches its immutable receipt.'); }
        $audit=$reads->query(AuditLog::query()->whereKey($row->audit_id))->first();
        $meta=['actor_id'=>$row->actor_id,'setting_key'=>self::KEY,'schema_version'=>1,'policy_revision'=>$row->revision,'idempotency_key'=>$row->command_key,'fingerprint'=>$v::hash($row->fingerprint,'policy.fingerprint'),'after_digest'=>$digest,'action'=>$row->action];
        if (!$audit || (int)$audit->user_id!==$row->actor_id || $audit->action!==self::EVENT.$row->action || $audit->auditable_type!==(new AppSetting)->getMorphClass()) { $v::fail('policy','The original policy audit is missing or mismatched.'); }
        foreach ($meta as $key=>$value) { if (($audit->meta[$key]??null)!==$value) { $v::fail('policy','The original policy audit binding changed.'); } }
        $target=$reads->query(AppSetting::query()->whereKey($audit->auditable_id)->where('key',self::REVISION_KEY))->first();
        if (!$target) { $v::fail('policy','The canonical policy audit target is missing.'); }
        return $policy;
    }

    private function mutex(): AppSetting
    {
        AppSetting::query()->insertOrIgnore(['key'=>self::REVISION_KEY,'value'=>'0','created_at'=>now(),'updated_at'=>now()]);
        return AppSetting::query()->where('key',self::REVISION_KEY)->lockForUpdate()->firstOrFail();
    }

    private function scopeComplete(mixed $policy,array $siteIds): bool
    {
        if ($policy===null) { return true; }
        if (!is_array($policy) || !is_array($policy['rules']??null)) { return true; }
        foreach ($policy['rules'] as $rule) { if (!is_array($rule) || (($rule['site_id']??null)!==null && !in_array($rule['site_id'],$siteIds,true))) { return false; } }
        return true;
    }

    /** Invalid raw settings are not safe DTOs; preserve them solely in immutable server history. */
    private function scopedPolicy(mixed $policy,array $siteIds): ?array
    {
        if (!is_array($policy)) { return null; }
        try { $policy=IncidentEvidenceWindowPolicyValues::policy($policy); }
        catch (ValidationException) { return null; }
        $policy['rules']=array_values(array_filter($policy['rules'],fn($rule)=>$rule['site_id']===null || in_array($rule['site_id'],$siteIds,true)));
        return $policy;
    }

    private function receipt(HealthSafetySettingChange $row,bool $replayed,User $actor,CurrentAuthorizationReads $reads): array
    {
        $siteIds=$this->readableSettingSiteIds($actor,$reads);
        abort_unless($this->scopeComplete($row->after_value,$siteIds),404);
        return [...$row->accepted_result,'setting_change_id'=>(int)$row->id,'replayed'=>$replayed];
    }

    /** Retained settings metadata only: inactive assigned Sites remain reviewable, never selectable or a source-read grant. */
    private function readableSettingSiteIds(User $actor,CurrentAuthorizationReads $reads): array
    {
        $query=Site::query()->notArchived()->whereNull('archived_at');
        if (!$actor->canDo('healthSafety.viewAllSites')) {
            $profile=$reads->query(\App\Domain\Hr\Models\HrEmployeeProfile::query()->where('user_id',$actor->id))->first();
            $ids=[$profile?->primary_site_id,...(is_array($profile?->secondary_site_ids)?$profile->secondary_site_ids:[])];
            $ids=array_values(array_filter($ids,fn($id)=>(is_int($id)||is_string($id)) && ctype_digit((string)$id) && (int)$id>0));
            $query->whereIn('id',$ids);
        }
        return $reads->query($query)->pluck('id')->map(fn($id)=>(int)$id)->all();
    }

    private function transaction(callable $operation): mixed
    {
        try { return DB::transaction($operation); }
        catch (QueryException $error) {
            if (in_array((int)($error->errorInfo[1]??0),[1062,1205,1213,3572],true)) { IncidentRecordingReconstructionValues::fail('expected_policy_revision','The policy or current authority changed concurrently. Keep your entries and review before retrying.'); }
            throw $error;
        }
    }
}
