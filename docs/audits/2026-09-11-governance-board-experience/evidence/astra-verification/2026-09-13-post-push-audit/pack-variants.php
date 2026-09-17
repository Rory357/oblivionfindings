<?php
declare(strict_types=1);
ob_start(); require __DIR__.'/extra-probes.php'; ob_end_clean();
use App\Domain\Governance\Models as M;
use App\Domain\Governance\Services as S;
use Illuminate\Support\Facades\Storage;
$out=['checked_at'=>gmdate('c'),'cases'=>[]];
function variantPack(array $attrs){global $actors;$data=['widgets'=>[]];$s=M\DashboardSnapshot::create(['snapshot_data'=>$data,'period_type'=>'month','period_start'=>'2026-09-01','period_end'=>'2026-09-30','checksum'=>M\DashboardSnapshot::generateChecksum($data),'captured_at'=>now(),'captured_by'=>$actors['chair']->id]);return M\BoardPack::create(['dashboard_snapshot_id'=>$s->id]+$attrs);}
probe('restricted_pack_still_in_visible_query',function()use($actors,$state){
 $p=variantPack(['governance_meeting_id'=>$state['meeting_id'],'revision_number'=>1,'build_status'=>'published','is_current'=>true,'document_manifest'=>['content_sections'=>['agenda'=>[['title'=>'REVIEW confidential agenda','is_confidential'=>true]]]],'generated_at'=>now(),'generated_by'=>$actors['chair']->id,'distributed_at'=>now(),'distributed_to'=>[$actors['member']->boardMember->id]]);
 $a=app(S\BoardPackAccessService::class);
 return ['can_view'=>$a->canView($actors['member'],$p),'visible_query_includes'=>$a->visibleQuery($actors['member'])->whereKey($p->id)->exists()];
});
probe('confidential_paper_without_agenda_flag_downloads',function()use($h,$actors,$state){
 $privatePaper=M\Resolution::findOrFail($state['private_resolution_id']);
 $content=['agenda'=>[],'resolutions'=>[$privatePaper->toArray()]];
 $oldRoot=config('filesystems.disks.local.root');config(['filesystems.disks.local.root'=>__DIR__.'/runtime-storage/pack-variant']);Storage::forgetDisk('local');
 try {
  Storage::disk('local')->put('synthetic.json',json_encode($content));
  $p=variantPack(['governance_meeting_id'=>$state['meeting_id'],'revision_number'=>1,'build_status'=>'published','is_current'=>true,'document_manifest'=>['content_sections'=>$content],'generated_at'=>now(),'generated_by'=>$actors['chair']->id,'file_path'=>'synthetic.json','distributed_at'=>now(),'distributed_to'=>[$actors['member']->boardMember->id]]);
  $resp=$h->actingAs($actors['member'])->get('/governance/packs/'.$p->id.'/download');
  $bytes='';if($resp->getStatusCode()===200){ob_start();$resp->sendContent();$bytes=ob_get_clean();}
  return ['private_source_visible'=>app(S\GovernanceRecordAccessService::class)->canViewResolution($actors['member'],$privatePaper),'download_status'=>$resp->getStatusCode(),'private_paper_bytes'=>str_contains($bytes,$privatePaper->title),'scope'=>'Synthetic historical/corrupt manifest tests download recheck; not a claim that current builder creates this exact shape.'];
 }finally{Storage::disk('local')->delete('synthetic.json');config(['filesystems.disks.local.root'=>$oldRoot]);Storage::forgetDisk('local');}
});
file_put_contents(__DIR__.'/pack-variant-results.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
