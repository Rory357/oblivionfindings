<?php
// Audit-only disposable fixture host. No application source or live database changes.
declare(strict_types=1);
$root = dirname(__DIR__, 4);
chdir($root);
require $root.'/vendor/autoload.php';
$xml = simplexml_load_file($root.'/phpunit.xml');
foreach ($xml->php->env as $entry) {
    $key = (string)$entry['name']; $value = (string)$entry['value'];
    putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach (['DB_DATABASE'=>'oblivion_gov_audit_20260911', 'APP_CONFIG_CACHE'=>__DIR__.'/unused-config.php', 'APP_URL'=>'http://127.0.0.1:8776'] as $key=>$value) {
    putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
}
class GovernanceAuditHost extends Tests\TestCase {
    use Tests\Support\GovernanceTestHelpers;
    public function fixture(): array {
        $this->app = $this->createApplication();
        $this->seedGovernance();
        $actors=[];
        foreach (['member'=>['board_member','member'], 'chair'=>['board_chair','chair'], 'secretary'=>['board_secretary','secretary'], 'treasurer'=>['board_member','treasurer'], 'ceo'=>['ceo',null], 'observer'=>['board_observer','observer'], 'duplicate'=>['board_member','member']] as $key=>[$role,$boardRole]) {
            $u=$this->createUserWithRole($role, ['name'=>in_array($key,['member','duplicate'])?'Audit Alex Morgan':'Audit '.ucfirst($key), 'email'=>'gov-'.$key.'@example.test', 'password'=>Illuminate\Support\Facades\Hash::make('Audit-Only-2026!'), 'two_factor_secret'=>null,'two_factor_confirmed_at'=>null,'email_verified_at'=>now()]);
            $actors[$key]=['user_id'=>$u->id,'role'=>$role,'email'=>$u->email,'board_member_id'=>$boardRole?$this->createBoardMember($u,['board_role'=>$boardRole])->id:null];
        }
        $chair=App\Models\User::findOrFail($actors['chair']['user_id']);
        $member=App\Models\User::findOrFail($actors['member']['user_id']);
        $m=$this->createMeeting($chair,['title'=>'Audit September board meeting','scheduled_at'=>now()->addDays(4)->setTime(10,0),'chair_id'=>$actors['chair']['board_member_id'],'secretary_id'=>$actors['secretary']['board_member_id'],'location'=>'Audit boardroom']);
        App\Domain\Governance\Models\MeetingAgendaItem::create(['governance_meeting_id'=>$m->id,'order'=>1,'title'=>'Audit decision paper: equipment renewal','description'=>'Review cost, alternatives and service continuity.','duration_minutes'=>20,'item_type'=>'decision','is_confidential'=>false]);
        $exec=$this->createMeeting($chair,['title'=>'AUDIT PRIVATE executive employment discussion','meeting_type'=>'executive_session','scheduled_at'=>now()->addDays(2),'chair_id'=>$actors['chair']['board_member_id']]);
        $r=$this->createResolution($chair,['title'=>'Audit equipment renewal decision','context'=>'Choose whether to renew essential equipment this quarter.','options'=>[['label'=>'Renew now','description'=>'Reduce outages; pay this quarter'],['label'=>'Defer','description'=>'Preserve cash; retain outage exposure']],'recommendation'=>'Renew in two stages.','status'=>'open','deadline'=>now()->addDays(3),'governance_meeting_id'=>$m->id]);
        $hidden=$this->createResolution($chair,['title'=>'AUDIT PRIVATE executive resolution','governance_meeting_id'=>$exec->id]);
        $noDeadline=$this->createResolution($chair,['title'=>'Audit open decision without deadline','status'=>'open','deadline'=>null]);
        for($i=1;$i<=24;$i++) $this->createActionItem($chair, $i===24?$member:$chair,['source_id'=>$m->id,'description'=>'Audit follow-up '.$i,'due_date'=>now()->subDays(25-$i),'priority'=>'high']);
        $this->createActionItem($chair,App\Models\User::find($actors['duplicate']['user_id']),['source_id'=>$m->id,'description'=>'Audit same-name owner task','due_date'=>now()->subDays(30),'priority'=>'critical']);
        $this->createActionItem($chair,$member,['source_id'=>$m->id,'description'=>'Audit blocked work','status'=>'blocked','due_date'=>now()->subDay(),'blocked_reason'=>'Awaiting supplier evidence']);
        for($i=1;$i<=12;$i++) $this->createRisk($chair,['title'=>'Audit above-appetite risk '.$i,'within_appetite'=>false,'residual_score'=>20,'status'=>'active']);
        $ob=$this->createComplianceObligation($chair,['obligation_title'=>'Audit overdue annual assurance','due_date'=>now()->subDay(),'status'=>'overdue']);
        $budget=$this->createBudget($chair,['title'=>'Audit 2026 operating budget','status'=>'approved']);
        $plan=$this->createStrategicPlan($chair,['title'=>'Audit service quality strategy','status'=>'active']);
        $minute=App\Domain\Governance\Models\MeetingMinute::create(['governance_meeting_id'=>$m->id,'content_blocks'=>[['heading'=>'Audit record','content'=>'Approved synthetic minutes']],'status'=>'approved','drafted_by'=>$chair->id,'drafted_at'=>now(),'version_number'=>1]);
        $snapshot=App\Domain\Governance\Models\DashboardSnapshot::create(['snapshot_data'=>['widgets'=>[]],'period_type'=>'month','period_start'=>now()->startOfMonth(),'period_end'=>now(),'checksum'=>hash('sha256','audit'),'captured_at'=>now(),'captured_by'=>$chair->id,'data_freshness'=>[]]);
        $pdf=app('dompdf.wrapper')->loadHTML('<h1>Audit board pack</h1><p>Synthetic equipment renewal paper. Renew now or defer.</p>')->output();
        $path=__DIR__.'/audit-pack.pdf'; file_put_contents($path,$pdf);
        $pack=App\Domain\Governance\Models\BoardPack::create(['governance_meeting_id'=>$m->id,'dashboard_snapshot_id'=>$snapshot->id,'document_manifest'=>[],'generated_at'=>now(),'generated_by'=>$chair->id,'file_path'=>$path,'file_size'=>strlen($pdf),'checksum'=>hash('sha256',$pdf),'distributed_at'=>now(),'distributed_to'=>array_values(array_filter(array_column($actors,'board_member_id'))),'download_tracking'=>[],'read_tracking'=>[]]);
        return ['database'=>config('database.connections.mysql.database'),'actors'=>$actors,'meeting_id'=>$m->id,'executive_meeting_id'=>$exec->id,'resolution_id'=>$r->id,'private_resolution_id'=>$hidden->id,'no_deadline_resolution_id'=>$noDeadline->id,'pack_id'=>$pack->id,'minute_id'=>$minute->id,'budget_id'=>$budget->id,'strategy_id'=>$plan->id,'obligation_id'=>$ob->id,'checkout'=>$root??base_path(),'pid'=>getmypid()];
    }
}
$host=new GovernanceAuditHost('fixture');
$state=$host->fixture();
file_put_contents(__DIR__.'/runtime-state.json',json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "Disposable Governance fixture ready: ".$state['database'].PHP_EOL;
while(!file_exists(__DIR__.'/stop-runtime')) { sleep(1); }
echo "Audit fixture shutdown; TestCase registered cleanup will drop only this process database.".PHP_EOL;
