<?php
// Second independent return review. All cases use the guarded disposable state.
// Prior probes run first; new cases vary the examples and check residual boundaries.
require __DIR__.'/extra-probes.php';
use App\Domain\Governance\Models as M;
use App\Domain\Governance\Services as S;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Carbon;
$out=['checked_at'=>gmdate('c'),'database'=>$state['database'],'cases'=>[]];
probe('completed_private_action_still_leaks',function()use($actors,$state){
 $a=M\ActionItem::findOrFail($state['private_action_id']);$a->update(['status'=>'complete','completed_at'=>now(),'completion_notes'=>'Private follow-up']);
 $feed=app(S\GovernanceWorkQuery::class)->queryFeed($actors['member'],['status'=>'completed']);
 return ['can_view'=>app(S\GovernanceRecordAccessService::class)->canViewActionItem($actors['member'],$a),'private_title_in_completed_feed'=>str_contains(json_encode($feed),'REVIEW PRIVATE assigned action')];
});
probe('present_attendance_still_invites',function()use($actors,$state){
 $m=M\GovernanceMeeting::findOrFail($state['private_meeting_id']);
 M\MeetingAttendance::create(['governance_meeting_id'=>$m->id,'board_member_id'=>$actors['member']->boardMember->id,'status'=>'present','marked_at'=>now(),'marked_by'=>$actors['chair']->id]);
 return ['can_view_without_explicit_invitation'=>app(S\ExecutiveMeetingAccessService::class)->canViewMeeting($actors['member'],$m->fresh())];
});
probe('expired_committee_still_grants_access',function()use($actors,$state){
 $m=M\GovernanceMeeting::findOrFail($state['private_meeting_id']);$m->update(['board_committee_id'=>$state['committee_id']]);
 M\CommitteeMembership::where('board_committee_id',$state['committee_id'])->update(['term_end'=>'2025-12-31']);
 return ['expired_member_can_view'=>app(S\ExecutiveMeetingAccessService::class)->canViewMeeting($actors['finance'],$m->fresh())];
});
probe('rules_change_after_open_changes_outcome',function()use($h,$actors){
 $p=app(S\GovernanceVotingProfileService::class)->getActiveProfile('board');$p->update(['written_unanimity_required'=>true]);
 $r=$h->paper($actors['chair']);$s=app(S\VotingService::class);$s->openVoting($r);
 $s->castVote($r,$actors['chair']->boardMember,'for');$s->castVote($r,$actors['member']->boardMember,'for');$s->castVote($r,$actors['finance']->boardMember,'abstain');
 $p->update(['written_unanimity_required'=>false]);$s->closeVoting($r);
 return ['opening_written_unanimity'=>true,'profile_changed_after_open'=>true,'outcome'=>$r->fresh()->outcome,'frozen_rule_payload'=>$r->paper_snapshot['voting_profile']??null];
});
probe('quorum_denominator_changes_after_open',function()use($h,$actors){
 $r=$h->paper($actors['chair']);$s=app(S\VotingService::class);$s->openVoting($r);$before=$s->calculateQuorum(null,$r);
 $actors['other']->boardMember->update(['is_active'=>false]);$after=$s->calculateQuorum(null,$r);
 return ['opening_electorate_count'=>count($r->electorate_at_open),'before'=>$before,'after'=>$after];
});
probe('rules_authority_keyword_bypass',function()use($h,$actors){
 $r=$h->paper($actors['chair'],['title'=>'Approve routine resolution for office stationery','exact_motion'=>'Buy printer paper for the office','status'=>'closed','outcome'=>'carried']);
 $s=app(S\GovernanceVotingProfileService::class);$p=$s->createProfile(['governing_document_reference'=>'Synthetic charter version 7'],$actors['chair']);
 $s->activateProfile($p,$actors['chair'],$r);
 return ['unrelated_motion'=>$r->exact_motion,'activated'=>$p->fresh()->is_active];
});
probe('strategy_authority_keyword_bypass',function()use($h,$actors){
 $r=$h->paper($actors['chair'],['title'=>'Approve employee wellbeing proposal','exact_motion'=>'Buy employee wellbeing resources','status'=>'closed','outcome'=>'carried']);
 $p=M\StrategicPlan::create(['title'=>'Five-year property replacement strategy','planning_horizon'=>'5_year','period_start'=>'2026-01-01','period_end'=>'2030-12-31','vision_statement'=>'Homes','mission_statement'=>'Support','values'=>['Respect'],'status'=>'draft','created_by'=>$actors['chair']->id]);
 $p->approve($r->id);return ['motion'=>$r->exact_motion,'unrelated_plan_status'=>$p->fresh()->status];
});
function budgetCase($h,$actors,$title,$explicit=false){
 $b=$h->budget($actors['chair']);$line=M\BudgetLineItem::create(['budget_id'=>$b->id,'category'=>'operations','description'=>'Accessible bathroom equipment','budget_amount'=>100000,'forecast_amount'=>100000,'actual_amount'=>0]);
 $a=M\BudgetAdjustment::create(['budget_id'=>$b->id,'budget_line_item_id'=>$line->id,'adjustment_type'=>'increase','amount'=>6000,'reason'=>$explicit?'Catering equipment budget':'Bathroom equipment','proposed_by'=>$actors['secretary']->id,'proposed_at'=>now(),'status'=>'submitted','threshold_applies'=>true]);
 $r=$h->paper($actors['chair'],['title'=>$title,'exact_motion'=>$title,'status'=>'closed','outcome'=>'carried','cost_impact'=>['amount'=>6000]+($explicit?['budget_adjustment_id'=>$a->id]:[])]);
 app(S\GovernanceNestedMutationService::class)->approveBudgetAdjustment($actors['chair'],$b,$a,$r->id);
 return ['motion'=>$r->exact_motion,'explicit_adjustment_id'=>$r->cost_impact['budget_adjustment_id']??null,'adjustment_status'=>$a->fresh()->status,'budget_total'=>$b->fresh()->total_budget];
}
probe('budget_authority_keyword_bypass',fn()=>budgetCase($h,$actors,'Approve vehicle capital purchase'));
probe('legitimate_catering_authority_rejected',fn()=>budgetCase($h,$actors,'Approve catering equipment budget adjustment',true));
probe('action_public_asset_is_accepted_evidence',function()use($actors,$state){
 $a=M\ActionItem::findOrFail($state['normal_action_id']);$a->update(['evidence_required'=>true]);
 $receipt=$a->markComplete($actors['member']->id,'Claim unrelated public website file',['robots.txt'],$a->version_number);
 return ['file_is_public_website_asset'=>file_exists(public_path('robots.txt')),'completed'=>$a->fresh()->status,'receipt'=>$receipt,'evidence'=>$a->fresh()->evidence_attachments];
});
probe('completed_action_can_be_blocked_again',function()use($actors,$state){
 $a=M\ActionItem::findOrFail($state['normal_action_id']);$a->update(['status'=>'complete','completion_receipt'=>'SYNTHETIC-PRIOR','completed_at'=>now()]);
 $a->block('Block a completed task',$a->version_number);return $a->fresh()->only(['status','completion_receipt','completed_at','version_number']);
});
probe('duplicate_name_followup_wrong_owner',function()use($h,$actors){
 $r=$h->paper($actors['chair'],['status'=>'closed','outcome'=>'carried','follow_up_actions'=>[['title'=>'Assign the other Alex','assignee_name'=>$actors['other']->name,'due_date'=>'2026-10-01']]]);
 $r->generateActionItems();return ['intended_user_id'=>$actors['other']->id,'same_name_users'=>[$actors['member']->id,$actors['other']->id],'actual_assigned_to'=>$r->actionItems()->first()?->assigned_to];
});
probe('draft_policy_can_be_attested',function()use($h,$actors){
 $p=M\GovernancePolicy::create(['title'=>'Unapproved draft safety policy','policy_code'=>'REVIEW-DRAFT','category'=>'governance','content'=>'Unapproved text','version_number'=>1,'status'=>'draft','requires_attestation'=>true,'owner_id'=>$actors['chair']->id,'created_by'=>$actors['chair']->id]);
 $resp=$h->actingAs($actors['member'])->post('/governance/policies/'.$p->id.'/attest',['acknowledged'=>true]);
 return ['http_status'=>$resp->getStatusCode(),'policy_status'=>$p->status,'receipt_count'=>$p->attestations()->count()];
});
probe('evaluation_accepts_missing_and_wrong_type_answers',function()use($h,$actors){
 $e=M\BoardEvaluation::create(['title'=>'Restricted evaluation','evaluation_type'=>'committee','year'=>2026,'status'=>'open','audience'=>'chair_only','due_date'=>'2026-10-01','questions'=>[['id'=>1,'question'=>'Rating','type'=>'rating'],['id'=>2,'question'=>'Yes or no','type'=>'yes_no']],'created_by'=>$actors['chair']->id]);
 $resp=$h->actingAs($actors['member'])->post('/governance/evaluations/'.$e->id.'/respond',['answers'=>[null,['not','boolean']]]);
 return ['http_status'=>$resp->getStatusCode(),'stored_audience'=>$e->audience,'responses'=>$e->responses()->get()->map->only(['board_member_id','answers'])];
});
probe('future_unverified_compliance_evidence_passes',function()use($actors,$state){
 $oldRoot=config('filesystems.disks.local.root');config(['filesystems.disks.local.root'=>__DIR__.'/runtime-storage/evidence-probe']);Storage::forgetDisk('local');
 try{
  Storage::disk('local')->put('future-evidence.txt','Synthetic future-dated evidence bytes');
  $o=M\ComplianceObligation::findOrFail($state['obligation_id']);$o->update(['evidence_required'=>true]);
  $e=M\ComplianceEvidence::create(['compliance_obligation_id'=>$o->id,'evidence_type'=>'document','title'=>'Future evidence','file_path'=>'future-evidence.txt','valid_from'=>'2027-01-01','valid_until'=>'2027-12-31','verified'=>false,'uploaded_by'=>$actors['chair']->id,'uploaded_at'=>now()]);
  app(S\ComplianceEngineService::class)->completeObligation($o,$actors['chair'],[$e->id],'Claim future evidence',$o->version_number);
  return ['valid_from'=>$e->valid_from,'verified'=>$e->verified,'obligation_status'=>$o->fresh()->status,'evidence_provided'=>$o->fresh()->evidence_provided];
 }finally{Storage::disk('local')->delete('future-evidence.txt');config(['filesystems.disks.local.root'=>$oldRoot]);Storage::forgetDisk('local');}
});
probe('settings_partial_write_on_validation_failure',function()use($h,$actors){
 $before=M\GovernanceSetting::where('key','spend_approval.threshold.capex')->value('value');
 $resp=$h->actingAs($actors['chair'])->putJson('/governance/settings',['settings'=>['spend_approval.threshold.capex'=>12345,'spend_approval.threshold.opex'=>'bad-number']]);
 return ['http_status'=>$resp->getStatusCode(),'capex_before'=>$before,'capex_after_failed_request'=>M\GovernanceSetting::where('key','spend_approval.threshold.capex')->value('value')];
});
probe('settings_negative_threshold_saved',function()use($h,$actors){
 $resp=$h->actingAs($actors['chair'])->putJson('/governance/settings',['settings'=>['spend_approval.threshold.capex'=>-999]]);
 return ['http_status'=>$resp->getStatusCode(),'saved'=>M\GovernanceSetting::where('key','spend_approval.threshold.capex')->value('value')];
});
probe('reminder_duplicates_across_hour_boundary',function()use($h,$actors){
 $r=$h->paper($actors['chair']);app(S\VotingService::class)->openVoting($r);Notification::fake();
 try{Carbon::setTestNow('2026-09-13 10:59:00');$j=new App\Domain\Governance\Jobs\SendVotingReminder($r,$actors['member']->boardMember);$j->handle();Carbon::setTestNow('2026-09-13 11:01:00');$j->handle();
 return ['elapsed_minutes'=>2,'claimed_window_hours'=>4,'notifications'=>Notification::sent($actors['member'],App\Domain\Governance\Notifications\VotingReminderNotification::class)->count()];
 }finally{Carbon::setTestNow();}
});
file_put_contents(__DIR__.'/second-pass-probe-results.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);

