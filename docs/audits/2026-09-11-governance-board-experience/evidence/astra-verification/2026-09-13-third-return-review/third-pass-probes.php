<?php
declare(strict_types=1);
ob_start(); require __DIR__.'/second-pass-probes.php'; ob_end_clean();
$out=['checked_at'=>gmdate('c'),'database'=>$state['database'],'cases'=>[]];
use App\Domain\Governance\Models as M;
use App\Domain\Governance\Services as S;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Cache;
probe('rules_generic_proposal_still_activates',function()use($h,$actors){
 $r=$h->paper($actors['chair'],['title'=>'New laundry appliances','exact_motion'=>'That the Board approves the proposal as presented.','status'=>'closed','outcome'=>'carried']);
 $s=app(S\GovernanceVotingProfileService::class);$p=$s->createProfile(['governing_document_reference'=>'Synthetic trust deed version 9'],$actors['chair']);
 $s->activateProfile($p,$actors['chair'],$r);
 return ['unrelated_subject'=>$r->title,'motion'=>$r->exact_motion,'profile_active'=>$p->fresh()->is_active];
});
probe('strategy_generic_proposal_still_approves',function()use($h,$actors){
 $r=$h->paper($actors['chair'],['title'=>'New laundry appliances','exact_motion'=>'That the Board approves the proposal as presented.','status'=>'closed','outcome'=>'carried']);
 $p=M\StrategicPlan::create(['title'=>'Five-year property replacement strategy','planning_horizon'=>'5_year','period_start'=>'2026-01-01','period_end'=>'2030-12-31','vision_statement'=>'Homes','mission_statement'=>'Support','values'=>['Respect'],'status'=>'draft','created_by'=>$actors['chair']->id]);
 $p->approve($r->id);return ['motion_subject'=>$r->title,'plan'=>$p->title,'status'=>$p->fresh()->status];
});
probe('budget_generic_tokens_still_approve_unbound_adjustment',fn()=>budgetCase($h,$actors,'Annual budget increase'));
probe('quorum_calculators_disagree',function()use($h,$actors){
 $p=app(S\GovernanceVotingProfileService::class)->getActiveProfile('board');$p->update(['quorum_mode'=>'fixed_count','quorum_formula'=>'4']);
 $r=$h->paper($actors['chair']);app(S\VotingService::class)->openVoting($r);
 return ['configured'=>4,'settings_calculator'=>app(S\GovernanceVotingProfileService::class)->calculateQuorumRequired(4,$p),'vote_calculator'=>app(S\VotingService::class)->calculateQuorum(null,$r)['required']];
});
probe('parent_meeting_quorum_changes_open_vote',function()use($h,$actors,$state){
 $m=M\GovernanceMeeting::findOrFail($state['meeting_id']);$m->update(['quorum_required'=>50]);
 $r=$h->paper($actors['chair'],['governance_meeting_id'=>$m->id]);$s=app(S\VotingService::class);$s->openVoting($r);
 $before=$s->calculateQuorum($m->id,$r);$m->update(['quorum_required'=>100]);$after=$s->calculateQuorum($m->id,$r);
 return ['before'=>$before,'after'=>$after,'opening_electorate_count'=>count($r->electorate_at_open)];
});
probe('real_manifest_shape_private_paper_downloads',function()use($h,$actors,$state){
 $source=M\Resolution::findOrFail($state['private_resolution_id']);
 $content=['agenda'=>[],'resolutions'=>['items'=>[$source->toArray()],'total'=>1]];
 $data=['widgets'=>[]];$snap=M\DashboardSnapshot::create(['snapshot_data'=>$data,'period_type'=>'month','period_start'=>'2026-09-01','period_end'=>'2026-09-30','checksum'=>M\DashboardSnapshot::generateChecksum($data),'captured_at'=>now(),'captured_by'=>$actors['chair']->id]);
 $oldRoot=config('filesystems.disks.local.root');config(['filesystems.disks.local.root'=>__DIR__.'/runtime-storage/third-pack']);Storage::forgetDisk('local');
 try{
  Storage::disk('local')->put('synthetic.json',json_encode($content));
  $p=M\BoardPack::create(['dashboard_snapshot_id'=>$snap->id,'governance_meeting_id'=>$state['meeting_id'],'revision_number'=>1,'build_status'=>'published','is_current'=>true,'document_manifest'=>['content_sections'=>$content],'generated_at'=>now(),'generated_by'=>$actors['chair']->id,'file_path'=>'synthetic.json','distributed_at'=>now(),'distributed_to'=>[$actors['member']->boardMember->id]]);
  $a=app(S\BoardPackAccessService::class);$res=$h->actingAs($actors['member'])->get('/governance/packs/'.$p->id.'/download');$bytes='';if($res->getStatusCode()===200){ob_start();$res->sendContent();$bytes=ob_get_clean();}
  return ['shape'=>'content_sections.resolutions.items (actual builder schema)','source_visible'=>app(S\GovernanceRecordAccessService::class)->canViewResolution($actors['member'],$source),'can_view'=>$a->canView($actors['member'],$p),'discoverable'=>$a->visibleQuery($actors['member'])->whereKey($p->id)->exists(),'http_status'=>$res->getStatusCode(),'private_bytes'=>str_contains($bytes,$source->title),'limit'=>'Manufactured published edition uses actual manifest shape and real download controller; no PDF layout claim.'];
 }finally{Storage::disk('local')->delete('synthetic.json');config(['filesystems.disks.local.root'=>$oldRoot]);Storage::forgetDisk('local');}
});
probe('borrowed_private_managed_evidence_completes_action',function()use($actors,$state){
 $oldRoot=config('filesystems.disks.local.root');config(['filesystems.disks.local.root'=>__DIR__.'/runtime-storage/third-evidence']);Storage::forgetDisk('local');
 try{
  $path='private-action-evidence/restricted.txt';Storage::disk('local')->put($path,'Synthetic confidential evidence owned by another action');
  $private=M\ActionItem::findOrFail($state['private_action_id']);$private->update(['evidence_attachments'=>[$path]]);
  $a=M\ActionItem::findOrFail($state['normal_action_id']);$a->update(['evidence_required'=>true]);$receipt=$a->markComplete($actors['member']->id,'Claim borrowed evidence',[$path],$a->version_number);
  return ['source_action_visible'=>app(S\GovernanceRecordAccessService::class)->canViewActionItem($actors['member'],$private),'completed_status'=>$a->fresh()->status,'receipt'=>$receipt,'evidence'=>$a->fresh()->evidence_attachments];
 }finally{Storage::disk('local')->delete('private-action-evidence/restricted.txt');config(['filesystems.disks.local.root'=>$oldRoot]);Storage::forgetDisk('local');}
});
probe('missing_action_version_bypasses_concurrency',function()use($h,$actors,$state){
 $a=M\ActionItem::findOrFail($state['normal_action_id']);$old=$a->version_number;$a->updateProgress(20,'First writer',$old);
 $res=$h->actingAs($actors['member'])->postJson('/governance/actions/'.$a->id.'/progress',['progress_pct'=>80,'progress_notes'=>'Stale client omitted version']);
 return ['http_status'=>$res->getStatusCode(),'client_saw_version'=>$old,'final'=>$a->fresh()->only(['version_number','progress_pct','progress_notes'])];
});
probe('future_policy_attestation_still_saves',function()use($h,$actors){
 $p=M\GovernancePolicy::create(['title'=>'Future policy','policy_code'=>'AUDIT3-FUTURE','category'=>'governance','content'=>'Not effective until next year','version_number'=>1,'status'=>'approved','effective_from'=>'2027-01-01','requires_attestation'=>true,'owner_id'=>$actors['chair']->id,'created_by'=>$actors['chair']->id]);
 $res=$h->actingAs($actors['member'])->post('/governance/policies/'.$p->id.'/attest',['acknowledged'=>true]);
 return ['http_status'=>$res->getStatusCode(),'effective_from'=>$p->effective_from?->toDateString(),'receipt_count'=>M\PolicyAttestation::where('governance_policy_id',$p->id)->count()];
});
function thirdEvaluation($actors,$type){return M\BoardEvaluation::create(['title'=>'Third audit '.$type,'evaluation_type'=>'board','year'=>2026,'period_start'=>'2026-01-01','period_end'=>'2026-12-31','due_date'=>'2026-12-31','audience'=>'all_members','status'=>'open','questions'=>[['id'=>1,'question'=>'Question','type'=>$type]],'created_by'=>$actors['chair']->id]);}
probe('evaluation_ui_yes_answer_rejected',function()use($h,$actors){
 $e=thirdEvaluation($actors,'yes_no');$res=$h->actingAs($actors['member'])->postJson('/governance/evaluations/'.$e->id.'/respond',['answers'=>['0'=>'Yes']]);
 return ['actual_ui_value'=>'Yes','http_status'=>$res->getStatusCode(),'response_count'=>$e->responses()->count()];
});
probe('evaluation_fractional_rating_accepted',function()use($h,$actors){
 $e=thirdEvaluation($actors,'rating');$res=$h->actingAs($actors['member'])->postJson('/governance/evaluations/'.$e->id.'/respond',['answers'=>['0'=>5.9]]);
 return ['http_status'=>$res->getStatusCode(),'response'=>$e->responses()->first()?->answers];
});
probe('evaluation_inactive_member_can_submit',function()use($h,$actors){
 $e=thirdEvaluation($actors,'rating');$actors['member']->boardMember->update(['is_active'=>false]);
 $res=$h->actingAs($actors['member'])->postJson('/governance/evaluations/'.$e->id.'/respond',['answers'=>['0'=>4]]);
 return ['http_status'=>$res->getStatusCode(),'response_count'=>$e->responses()->count()];
});
probe('invalid_escalation_recipient_saved',function()use($h,$actors){
 $res=$h->actingAs($actors['chair'])->putJson('/governance/settings',['settings'=>['compliance.escalation.final_notify_user_id'=>999999.5]]);
 return ['http_status'=>$res->getStatusCode(),'value'=>M\GovernanceSetting::where('key','compliance.escalation.final_notify_user_id')->value('value')];
});
probe('failed_reminder_suppresses_retry',function()use($h,$actors){
 $r=$h->paper($actors['chair']);app(S\VotingService::class)->openVoting($r);$original=Notification::getFacadeRoot();$first=null;
 try{
  $mock=Mockery::mock(Illuminate\Contracts\Notifications\Dispatcher::class);$mock->shouldReceive('send')->once()->andThrow(new RuntimeException('Synthetic transport failure'));Notification::swap($mock);
  $job=new App\Domain\Governance\Jobs\SendVotingReminder($r,$actors['member']->boardMember);try{$job->handle();}catch(Throwable $e){$first=$e->getMessage();}
  Notification::fake();$job->handle();$count=Notification::sent($actors['member'],App\Domain\Governance\Notifications\VotingReminderNotification::class)->count();
  return ['first_attempt_error'=>$first,'retry_notifications'=>$count,'cache_claimed'=>Cache::has('voting_reminder:res_'.$r->id.':bm_'.$actors['member']->boardMember->id)];
 }finally{Notification::swap($original);}
});
probe('verify_invalid_compliance_evidence_claims_provided',function()use($actors,$state){
 $o=M\ComplianceObligation::findOrFail($state['obligation_id']);$o->update(['evidence_provided'=>false]);
 $e=M\ComplianceEvidence::create(['compliance_obligation_id'=>$o->id,'evidence_type'=>'document','title'=>'Future missing evidence','file_path'=>'audit3-missing.pdf','valid_from'=>'2027-01-01','uploaded_by'=>$actors['chair']->id,'uploaded_at'=>now(),'verified'=>false]);
 $e->verify($actors['chair']->id);return ['future_from'=>$e->valid_from?->toDateString(),'file_exists'=>Storage::disk('local')->exists($e->file_path),'evidence_provided'=>$o->fresh()->evidence_provided,'verified'=>$e->fresh()->verified];
});
probe('ordinary_member_admin_gate_from_audit_read',function()use($actors){
 $u=$actors['member'];$keys=['governance.meetings.manage','governance.actions.manage','governance.settings.manage','governance.settings.view','governance.audit.view','governance.executive.view','governance.performance.manage'];$permissions=[];foreach($keys as $k)$permissions[$k]=$u->canDo($k);
 return ['role'=>'board_member','gate_permissions'=>$permissions,'admin_gate'=>in_array(true,$permissions,true)];
});
file_put_contents(__DIR__.'/third-pass-probe-results.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);

