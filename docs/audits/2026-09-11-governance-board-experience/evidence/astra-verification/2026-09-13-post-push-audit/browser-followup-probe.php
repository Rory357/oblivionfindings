<?php
require __DIR__.'/audit-bootstrap.php';
$r=$h->actingAs($actors['member'])->get('/governance/meetings/'.$state['meeting_id'].'?tab=resolutions&paper='.$state['resolution_id']);
$out=['checked_at'=>gmdate('c'),'http_status'=>$r->status(),'fixture_action'=>'AUDIT4-UI-FOLLOWUP','exception_message'=>'Call to undefined relationship [assignee] on model [App\\Domain\\Governance\\Models\\ActionItem] (from preview log).'];
App\Domain\Governance\Models\ActionItem::where('action_reference','AUDIT4-UI-FOLLOWUP')->delete();
$out['synthetic_action_soft_deleted_to_continue_ui_audit']=true;
file_put_contents(__DIR__.'/browser-followup-result.json',json_encode($out,JSON_PRETTY_PRINT));echo json_encode($out);