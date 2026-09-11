<?php
// Audit-only probes against the previously created synthetic database.
$root=dirname(__DIR__,4); chdir($root); require $root.'/vendor/autoload.php';
$state=json_decode(file_get_contents(__DIR__.'/runtime-state.json'),true);
if(!preg_match('/^oblivion_gov_audit_20260911_\d+$/',$state['database'])) throw new RuntimeException('Wrong database');
$xml=simplexml_load_file($root.'/phpunit.xml');
foreach($xml->php->env as $entry){$k=(string)$entry['name'];$v=(string)$entry['value'];putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
foreach(['DB_DATABASE'=>$state['database'],'APP_CONFIG_CACHE'=>__DIR__.'/unused-config.php','CACHE_STORE'=>'array'] as $k=>$v){putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
$app=require $root.'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Governance\Models as M;
use App\Domain\Governance\Services as S;
use Illuminate\Support\Facades\DB;
$chair=App\Models\User::findOrFail($state['actors']['chair']['user_id']);
$member=App\Models\User::findOrFail($state['actors']['member']['user_id']);
// Correct synthetic risk inputs: model creation calculates residual from inputs.
foreach(M\RiskRegisterEntry::where('title','like','Audit above-appetite%')->get() as $risk) $risk->update(['likelihood_score'=>5,'impact_score'=>5,'control_effectiveness'=>'weak']);
if(!M\PerformanceReview::exists()) M\PerformanceReview::create(['reviewee_id'=>$state['actors']['ceo']['user_id'],'review_cycle'=>'Audit 2026','review_type'=>'annual','period_start'=>'2026-01-01','period_end'=>'2026-12-31','status'=>'board_review','overall_assessment'=>'AUDIT PRIVATE CEO assessment','created_by'=>$chair->id]);
$out=['database'=>$state['database'],'role_permissions'=>[]];
foreach($state['actors'] as $key=>$a){$u=App\Models\User::find($a['user_id']);$out['role_permissions'][$key]=['board_role'=>$u->boardMember?->board_role,'can_vote_model'=>$u->boardMember?->canVote(),'permissions'=>collect(['governance.view','governance.meetings.view','governance.meetings.manage','governance.packs.view','governance.packs.manage','governance.resolutions.view','governance.resolutions.vote','governance.resolutions.manage','governance.performance.view','governance.performance.manage','governance.executive.view','governance.budgets.view','governance.budgets.manage','governance.actions.manage'])->mapWithKeys(fn($p)=>[$p=>$u->canDo($p)])->all()];}
$out['risks']=['canonical'=>M\RiskRegisterEntry::count(),'above_appetite'=>M\RiskRegisterEntry::where('within_appetite',false)->count(),'aggregate'=>app(S\DashboardAggregatorService::class)->getTopRisks()];
function probe($name,$fn){global $out;DB::beginTransaction();try{$out[$name]=$fn();}catch(Throwable $e){$out[$name]=['error'=>get_class($e),'message'=>substr($e->getMessage(),0,500)];}finally{DB::rollBack();}}
probe('minutes_sign',function()use($state,$chair){$m=M\MeetingMinute::findOrFail($state['minute_id']);$m->sign($chair->id);return $m->fresh()->only(['status','signed_by','signed_at','version_number']);});
probe('minutes_approved_update_controller',function()use($state,$chair){auth()->setUser($chair);$r=Illuminate\Http\Request::create('/audit','PUT',['content_blocks'=>[['heading'=>'Audit replaced approved minutes']]]);$r->setUserResolver(fn()=>$chair);app(App\Domain\Governance\Http\Controllers\GovernanceMeetingController::class)->updateMinutes($r,M\GovernanceMeeting::findOrFail($state['meeting_id']));return M\MeetingMinute::find($state['minute_id'])->only(['status','content_blocks','version_number','version_history']);});
probe('resolution_follow_up',function()use($state,$chair){$r=M\Resolution::find($state['resolution_id']);$r->follow_up_actions=[['title'=>'Audit follow-up','description'=>'Do audit action','assigned_to'=>$chair->id]];$r->generateActionItems();return M\ActionItem::latest('id')->first()->toArray();});
probe('strategy_approve_draft_resolution',function()use($state){$p=M\StrategicPlan::find($state['strategy_id']);$p->approve($state['private_resolution_id']);return $p->fresh()->only(['status','approval_resolution_id']);});
$out['recurrence']=app(S\ComplianceEngineService::class)->calculateNextDueDate('annual',Carbon\Carbon::parse('2026-12-31'))->toDateString();
$out['executive_access_member']=app(S\ExecutiveMeetingAccessService::class)->canViewMeeting($member,M\GovernanceMeeting::find($state['executive_meeting_id']));
file_put_contents(__DIR__.'/probe-results.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo 'Audit probes saved'.PHP_EOL;
