<?php
// Audit-only fixture/probe host. Ordinary roles, disposable database, no live changes.
declare(strict_types=1);
$root=realpath(__DIR__.'/../../../../../..');
if(!$root || !is_file($root.'/phpunit.xml')) throw new RuntimeException('Repository guard failed');
chdir($root);require $root.'/vendor/autoload.php';
$xml=simplexml_load_file($root.'/phpunit.xml');
foreach($xml->php->env as $entry){$k=(string)$entry['name'];$v=(string)$entry['value'];putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
foreach(['DB_DATABASE'=>'oblivion_gov_review_runtime_20260912','APP_CONFIG_CACHE'=>__DIR__.'/unused-config.php','APP_URL'=>'http://127.0.0.1:8777','MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'sync'] as $k=>$v){putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
use App\Domain\Governance\Models as M;
use App\Domain\Governance\Services as S;
use Illuminate\Support\Facades\DB;
class ReviewHost extends Tests\TestCase {
 use Tests\Support\GovernanceTestHelpers;
 public function prepare():array {
  $this->app=$this->createApplication();$this->seedGovernance();
  Illuminate\Support\Facades\Mail::fake();Illuminate\Support\Facades\Notification::fake();
  $this->withoutVite();
  $actors=[];
  foreach(['chair'=>['board_chair','chair'],'secretary'=>['board_secretary','secretary'],'member'=>['board_member','member'],'finance'=>['board_member','member'],'other'=>['board_member','member'],'ceo'=>['ceo',null],'observer'=>['board_observer','observer']] as $key=>[$role,$seat]) {
   $u=$this->createUserWithRole($role,['name'=>in_array($key,['member','other'])?'Review Alex Morgan':'Review '.ucfirst($key),'email'=>'review-'.$key.'@example.test','password'=>Illuminate\Support\Facades\Hash::make('Review-Only-2026!'),'two_factor_secret'=>null,'two_factor_confirmed_at'=>null,'email_verified_at'=>now()]);
   $bm=$seat?$this->createBoardMember($u,['board_role'=>$seat]):null;
   $actors[$key]=['user_id'=>$u->id,'board_member_id'=>$bm?->id,'email'=>$u->email,'role'=>$role];
  }
  $chair=App\Models\User::findOrFail($actors['chair']['user_id']);$member=App\Models\User::findOrFail($actors['member']['user_id']);
  $meeting=$this->createMeeting($chair,['title'=>'Review September Board','scheduled_at'=>now()->addDays(4)->setTime(22,0),'chair_id'=>$actors['chair']['board_member_id'],'secretary_id'=>$actors['secretary']['board_member_id']]);
  $private=$this->createMeeting($chair,['title'=>'REVIEW PRIVATE personnel matter','meeting_type'=>'executive_session','scheduled_at'=>now()->addDays(2),'chair_id'=>$actors['chair']['board_member_id']]);
  M\MeetingAgendaItem::create(['governance_meeting_id'=>$meeting->id,'order'=>1,'title'=>'REVIEW CONFIDENTIAL agenda','item_type'=>'decision','is_confidential'=>true,'duration_minutes'=>15]);
  $res=$this->createResolution($chair,['title'=>'Review equipment renewal','governance_meeting_id'=>$meeting->id,'deadline'=>now()->addDays(3),'quorum_required'=>true]);
  $hidden=$this->createResolution($chair,['title'=>'REVIEW PRIVATE resolution','governance_meeting_id'=>$private->id,'quorum_required'=>true]);
  $action=$this->createActionItem($chair,$member,['source_type'=>'resolution','source_id'=>$hidden->id,'description'=>'REVIEW PRIVATE assigned action','evidence_required'=>true]);
  for($i=0;$i<40;$i++) $this->createActionItem($chair,$i===39?$member:$chair,['source_type'=>'meeting','source_id'=>$meeting->id,'description'=>'Review ordinary follow-up '.$i,'due_date'=>now()->subDays($i+1)]);
  $this->createActionItem($chair,App\Models\User::find($actors['other']['user_id']),['source_type'=>'meeting','source_id'=>$meeting->id,'description'=>'Review SAME NAME other owner','due_date'=>now()->subDays(50)]);
  for($i=0;$i<12;$i++)$this->createRisk($chair,['title'=>'Review risk '.$i,'likelihood_score'=>5,'impact_score'=>5,'control_effectiveness'=>'weak']);
  $committee=M\BoardCommittee::create(['name'=>'Review finance committee','committee_type'=>'finance','chair_id'=>$actors['finance']['board_member_id'],'is_active'=>true]);
  $committee->members()->attach($actors['finance']['board_member_id'],['role'=>'chair','appointed_at'=>'2026-01-01','term_end'=>'2027-01-01','is_active'=>true]);
  $review=M\PerformanceReview::create(['reviewee_id'=>$actors['ceo']['user_id'],'review_cycle'=>'Review cycle','review_type'=>'annual','period_start'=>'2026-01-01','period_end'=>'2026-12-31','status'=>'board_review','overall_assessment'=>'REVIEW PRIVATE raw appraisal','created_by'=>$chair->id]);
  $minute=M\MeetingMinute::create(['governance_meeting_id'=>$meeting->id,'content_blocks'=>[['heading'=>'Review record','content'=>'Approved synthetic minutes']],'status'=>'approved','drafted_by'=>$chair->id,'drafted_at'=>now(),'version_number'=>1]);
  $obligation=$this->createComplianceObligation($chair,['obligation_title'=>'Review annual assurance','due_date'=>now()->subDay(),'evidence_required'=>false]);
  return ['database'=>config('database.connections.mysql.database'),'pid'=>getmypid(),'actors'=>$actors,'meeting_id'=>$meeting->id,'private_meeting_id'=>$private->id,'resolution_id'=>$res->id,'private_resolution_id'=>$hidden->id,'private_action_id'=>$action->id,'review_id'=>$review->id,'minute_id'=>$minute->id,'obligation_id'=>$obligation->id,'committee_id'=>$committee->id];
 }
 public function paper(App\Models\User $u,array $attributes=[]):M\Resolution{return $this->createResolution($u,$attributes);}
}
$host=new ReviewHost('prepare');$state=$host->prepare();
if(!str_starts_with($state['database'],'oblivion_gov_review_runtime_20260912_'))throw new RuntimeException('Disposable database guard failed');
file_put_contents(__DIR__.'/runtime-state.json',json_encode($state,JSON_PRETTY_PRINT));
$chair=App\Models\User::findOrFail($state['actors']['chair']['user_id']);$member=App\Models\User::findOrFail($state['actors']['member']['user_id']);$bm=$member->boardMember;
$out=['checked_at'=>gmdate('c'),'database'=>$state['database'],'cases'=>[]];
function inspectCase(string $name,Closure $fn):void{global $out;DB::beginTransaction();try{$out['cases'][$name]=$fn();}catch(Throwable $e){$out['cases'][$name]=['exception'=>get_class($e),'message'=>substr($e->getMessage(),0,700)];}finally{DB::rollBack();}}
inspectCase('no_approved_profile_opens',function()use($host,$chair){M\GovernanceVotingProfile::query()->update(['is_active'=>false]);$r=$host->paper($chair);app(S\VotingService::class)->openVoting($r);return ['active_profiles'=>M\GovernanceVotingProfile::active()->count(),'status'=>$r->fresh()->status];});
inspectCase('written_disabled_still_opens',function()use($host,$chair){$p=M\GovernanceVotingProfile::active()->first();$r=$host->paper($chair,['governance_meeting_id'=>null]);app(S\VotingService::class)->openVoting($r);return ['written_permitted'=>$p->written_voting_permitted,'status'=>$r->fresh()->status,'bound_profile_id'=>$r->fresh()->voting_profile_id];});
inspectCase('unanimity_with_abstention',function()use($host,$chair,$state){$r=$host->paper($chair,['voting_threshold'=>'unanimous','quorum_required'=>true]);$s=app(S\VotingService::class);$s->openVoting($r);foreach(['chair'=>'for','member'=>'for','finance'=>'abstain'] as $key=>$vote)$s->castVote($r,M\BoardMember::findOrFail($state['actors'][$key]['board_member_id']),$vote);$s->closeVoting($r);return ['status'=>$r->fresh()->status,'outcome'=>$r->fresh()->outcome,'summary'=>$r->fresh()->vote_summary,'electorate_at_open'=>$r->paper_snapshot['electorate']??null];});
inspectCase('draft_resolution_activates_rules',function()use($host,$chair){$r=$host->paper($chair);$p=app(S\GovernanceVotingProfileService::class)->createProfile(['governing_document_reference'=>'Synthetic test constitution'], $chair);$p=app(S\GovernanceVotingProfileService::class)->activateProfile($p,$chair,$r);return ['approval_resolution_status'=>$r->status,'is_confirmed'=>$p->isConfirmed()];});
inspectCase('assigned_private_action',function()use($host,$state,$member){$a=M\ActionItem::findOrFail($state['private_action_id']);$s=app(S\GovernanceRecordAccessService::class);$response=$host->actingAs($member)->get('/governance/actions/'.$a->id);return ['parent_visible'=>$s->canViewMeeting($member,M\GovernanceMeeting::find($state['private_meeting_id'])),'action_visible'=>$s->canViewActionItem($member,$a),'http_status'=>$response->status(),'private_title_in_response'=>str_contains($response->getContent(),'REVIEW PRIVATE assigned action')];});
inspectCase('attendance_grants_private_access',function()use($state,$member){$m=M\GovernanceMeeting::findOrFail($state['private_meeting_id']);$s=app(S\ExecutiveMeetingAccessService::class);$before=$s->canViewMeeting($member,$m);M\MeetingAttendance::create(['governance_meeting_id'=>$m->id,'board_member_id'=>$member->boardMember->id,'status'=>'apology','marked_at'=>now(),'marked_by'=>$state['actors']['chair']['user_id']]);return ['before'=>$before,'after_apology'=>$s->canViewMeeting($member,$m->fresh())];});
inspectCase('fabricated_action_evidence',function()use($host,$state,$member){$a=M\ActionItem::findOrFail($state['private_action_id']);$response=$host->actingAs($member)->post('/governance/actions/'.$a->id.'/complete',['completion_notes'=>'Synthetic claimed completion','evidence_files'=>['does-not-exist.pdf'],'expected_version'=>$a->version_number]);return ['http_status'=>$response->status(),'status'=>$a->fresh()->status,'stored_evidence'=>$a->fresh()->evidence_attachments,'receipt'=>$a->fresh()->completion_receipt];});
inspectCase('stale_action_models_overwrite',function()use($state,$member){$a=M\ActionItem::findOrFail($state['private_action_id']);$b=M\ActionItem::findOrFail($a->id);$v=(int)($a->version_number??1);$a->updateProgress(20,'First actor',$v);$b->updateProgress(80,'Stale second actor',$v);return ['expected_version'=>$v,'final_version'=>$a->fresh()->version_number,'progress'=>$a->fresh()->progress_pct,'notes'=>$a->fresh()->progress_notes];});
inspectCase('closed_action_reopens',function()use($state,$member){$a=M\ActionItem::findOrFail($state['private_action_id']);$a->markComplete($member->id,'Synthetic completion',['test.pdf']);$a->updateProgress(20,'Late stale progress');return $a->fresh()->only(['status','progress_pct','completed_at','completion_receipt']);});
inspectCase('filtered_timeline_array',function()use($member){$p=app(App\Domain\Governance\Support\GovernancePresenter::class);$events=(new ReflectionMethod($p,'buildTimeline'))->invoke($p,$member)['events'];return ['is_list'=>array_is_list($events),'count'=>count($events)];});
inspectCase('approved_minutes_update',function()use($state,$chair){$svc=app(S\MeetingMinuteService::class);$svc->updateMinutes(M\GovernanceMeeting::findOrFail($state['meeting_id']),[['heading'=>'Changed','content'=>'Changed after approval']],$chair,1);return ['accepted'=>true];});
inspectCase('annual_recurrence',fn()=>['next'=>app(S\ComplianceEngineService::class)->calculateNextDueDate('annual',Carbon\Carbon::parse('2026-12-31'))->toDateString()]);
file_put_contents(__DIR__.'/probe-results.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo 'Review fixture ready: '.$state['database'].PHP_EOL;echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
// Preserve this task's database for normal-login browser verification. Bounded by explicit cleanup.
while(!is_file(__DIR__.'/stop-runtime.flag'))sleep(1);
