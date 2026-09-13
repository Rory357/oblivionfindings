<?php
declare(strict_types=1);
require __DIR__.'/audit-bootstrap.php';
use App\Domain\Governance\Models as M;
use App\Domain\Governance\Services as S;

probe('different_body_rules_motion_activates_board_profile', function()use($h,$actors) {
    $r=$h->paper($actors['chair'],['title'=>'Approve voting rules for the unrelated gardening working group','exact_motion'=>'Approve voting rules exclusively for the gardening working group. No change to the governing board rules.','status'=>'closed','outcome'=>'carried']);
    $s=app(S\GovernanceVotingProfileService::class);
    $p=$s->createProfile(['governing_body'=>'board','governing_document_reference'=>'Synthetic governing board trust deed version 12'],$actors['chair']);
    $s->activateProfile($p,$actors['chair'],$r);
    return ['expected'=>'Reject unrelated authority','actual_active'=>$p->fresh()->is_active,'motion'=>$r->exact_motion,'profile_body'=>$p->governing_body];
});
probe('different_strategic_plan_motion_approves_property_plan', function()use($h,$actors) {
    $r=$h->paper($actors['chair'],['title'=>'Approve strategic plan for staff wellbeing','exact_motion'=>'Approve strategic plan for staff wellbeing only. Property planning is deferred.','status'=>'closed','outcome'=>'carried']);
    $p=M\StrategicPlan::create(['title'=>'Property strategic plan','planning_horizon'=>'5_year','period_start'=>'2026-01-01','period_end'=>'2030-12-31','vision_statement'=>'Homes','mission_statement'=>'Support','values'=>['Respect'],'status'=>'draft','created_by'=>$actors['chair']->id]);
    $p->approve($r->id);
    return ['expected'=>'Reject different plan','actual_status'=>$p->fresh()->status,'motion'=>$r->exact_motion,'plan_title'=>$p->title];
});
probe('safe_pack_resolution_id_prefix_collision', function()use($h,$actors,$state) {
    $hidden=M\Resolution::findOrFail($state['private_resolution_id']);
    $safe=$h->paper($actors['chair'],['governance_meeting_id'=>$state['meeting_id'],'title'=>'Safe member paper']);
    // Independent IDs are legitimate; choose an unused ID with the hidden ID as its decimal prefix.
    $target=(int)((string)$hidden->id.'999');
    if(M\Resolution::whereKey($target)->exists())throw new RuntimeException('Synthetic collision target already exists');
    $safe->id=$target;$safe->save();
    $data=['widgets'=>[]];$snapshot=M\DashboardSnapshot::create(['snapshot_data'=>$data,'period_type'=>'month','period_start'=>'2026-09-01','period_end'=>'2026-09-30','checksum'=>M\DashboardSnapshot::generateChecksum($data),'captured_at'=>now(),'captured_by'=>$actors['chair']->id]);
    $p=M\BoardPack::create(['dashboard_snapshot_id'=>$snapshot->id,'governance_meeting_id'=>$state['meeting_id'],'revision_number'=>1,'build_status'=>'published','is_current'=>true,'document_manifest'=>['content_sections'=>['agenda'=>[],'resolutions'=>['items'=>[$safe->fresh()->toArray()],'total'=>1]]],'generated_at'=>now(),'generated_by'=>$actors['chair']->id,'distributed_at'=>now(),'distributed_to'=>[$actors['member']->boardMember->id]]);
    $s=app(S\BoardPackAccessService::class);
    return ['expected'=>'Safe distributed pack is both visible and discoverable','hidden_resolution_id'=>$hidden->id,'safe_resolution_id'=>$safe->id,'can_view'=>$s->canView($actors['member'],$p),'discoverable'=>$s->visibleQuery($actors['member'])->whereKey($p->id)->exists()];
});
probe('budget_direction_changed_after_bound_authority', function()use($h,$actors) {
    $b=$h->budget($actors['chair']);
    $line=M\BudgetLineItem::create(['budget_id'=>$b->id,'category'=>'operations','description'=>'Equipment funding','budget_amount'=>100000,'forecast_amount'=>100000,'actual_amount'=>0]);
    $a=M\BudgetAdjustment::create(['budget_id'=>$b->id,'budget_line_item_id'=>$line->id,'adjustment_type'=>'increase','amount'=>6000,'reason'=>'Equipment increase','proposed_by'=>$actors['secretary']->id,'proposed_at'=>now(),'status'=>'submitted','threshold_applies'=>true]);
    $r=$h->paper($actors['chair'],['title'=>'Approve equipment funding increase','exact_motion'=>'Increase equipment funding by 6000.','status'=>'closed','outcome'=>'carried','cost_impact'=>['amount'=>6000,'budget_adjustment_id'=>$a->id,'adjustment_type'=>'increase','budget_id'=>$b->id,'budget_line_item_id'=>$line->id]]);
    $a->update(['adjustment_type'=>'decrease','reason'=>'Changed after board approval: reduce equipment funding']);
    app(S\GovernanceNestedMutationService::class)->approveBudgetAdjustment($actors['chair'],$b,$a,$r->id);
    return ['expected'=>'Reject changed direction','actual_status'=>$a->fresh()->status,'authorized_direction'=>$r->cost_impact['adjustment_type'],'actual_direction'=>$a->fresh()->adjustment_type,'line_amount'=>$line->fresh()->budget_amount];
});
probe('show_all_priorities_still_truncates_above_100', function()use($actors,$state) {
    for($i=0;$i<70;$i++)M\ActionItem::create(['action_reference'=>'AUDIT4-LIMIT-'.$i,'description'=>'Additional synthetic board follow-up '.$i,'source_type'=>'meeting','source_id'=>$state['meeting_id'],'assigned_to'=>$actors['chair']->id,'created_by'=>$actors['chair']->id,'due_date'=>now()->subDays(80+$i),'priority'=>'high','status'=>'open']);
    $feed=app(S\GovernanceWorkflowService::class)->dashboardWorkflow($actors['member']);
    return ['expected'=>'Show all offers every item in its claimed total','total'=>$feed['summary']['total'],'returned'=>count($feed['actions']),'by_tab'=>$feed['summary']['by_tab']];
});
file_put_contents(__DIR__.'/post-push-probe-results.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
