<?php
declare(strict_types=1);
$root=realpath(__DIR__.'/../../../../../..');chdir($root);require $root.'/vendor/autoload.php';
$state=json_decode(file_get_contents(__DIR__.'/runtime-state.json'),true);
if(!preg_match('/^oblivion_gov_review4_runtime_20260913_[0-9]+$/',$state['database']??''))throw new RuntimeException('Disposable database guard failed');
foreach(simplexml_load_file($root.'/phpunit.xml')->php->env as $e){$k=(string)$e['name'];$v=(string)$e['value'];putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
foreach(['DB_DATABASE'=>$state['database'],'APP_CONFIG_CACHE'=>__DIR__.'/unused-config.php','MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'sync'] as $k=>$v){putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Governance\Models as M;
use App\Domain\Governance\Services as S;
use Illuminate\Support\Facades\DB;
class ExtraReview extends Tests\TestCase {
 use Tests\Support\GovernanceTestHelpers;
 public function attach($app):void{$this->app=$app;$this->withoutVite();}
 public function paper($u,$a=[]){return $this->createResolution($u,$a);}
 public function budget($u,$a=[]){return $this->createBudget($u,$a);}
}
$h=new ExtraReview('attach');$h->attach($app);Illuminate\Support\Facades\Mail::fake();Illuminate\Support\Facades\Notification::fake();
$actors=[];foreach($state['actors'] as $k=>$a)$actors[$k]=App\Models\User::findOrFail($a['user_id']);
$out=['checked_at'=>gmdate('c'),'database'=>$state['database'],'cases'=>[]];
function probe($name,Closure $fn){global $out;DB::beginTransaction();try{$out['cases'][$name]=$fn();}catch(Throwable $e){$out['cases'][$name]=['exception'=>get_class($e),'message'=>substr($e->getMessage(),0,800)];}finally{DB::rollBack();}}
