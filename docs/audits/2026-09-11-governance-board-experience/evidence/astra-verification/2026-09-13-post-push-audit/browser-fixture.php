<?php
// Deliberately persistent synthetic fixture additions, destroyed with this audit database.
declare(strict_types=1);
require __DIR__.'/audit-bootstrap.php';
use App\Domain\Governance\Models as M;
use App\Domain\Governance\Services as S;
Illuminate\Support\Facades\Auth::setUser($actors['chair']);
$r=M\Resolution::findOrFail($state['resolution_id']);
M\ActionItem::create(['action_reference'=>'AUDIT4-UI-FOLLOWUP','title'=>'Arrange equipment delivery','description'=>'Arrange equipment delivery following the synthetic board decision.','source_type'=>'resolution','source_id'=>$r->id,'assigned_to'=>$actors['member']->id,'created_by'=>$actors['chair']->id,'due_date'=>now()->addDays(7),'priority'=>'medium','status'=>'open']);
if($r->status==='draft')app(S\VotingService::class)->openVoting($r);
echo json_encode(['database'=>$state['database'],'paper_id'=>$r->id,'status'=>$r->fresh()->status,'synthetic_only'=>true]);
