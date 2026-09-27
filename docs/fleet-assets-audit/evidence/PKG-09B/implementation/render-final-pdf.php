<?php
require getenv('APP_BASE_PATH').'/vendor/autoload.php';
$app = require getenv('APP_BASE_PATH').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$definition=['name'=>'Synthetic report layout check','source'=>'journeys'];
$payload=['source'=>['window'=>['from'=>'2026-09-19T12:00:00Z','to'=>'2026-09-27T10:46:01Z'],'coverage'=>'Complete rows that passed source identity and current authority checks. Unverifiable records are excluded; observation continuity is not guaranteed.'],'result'=>['row_count'=>1],'generated_at'=>'2026-09-27T10:46:01.291005Z'];
$rows=[['Reference','Date','Resource','Site','Status','Recorded distance (km)','Recorded duration (hours)'],[1,'2026-09-25','Example vehicle','Example regional service home','closed',-42.5,1]];
$groups=[['Date','Rows (count)'],['2026-09-25',1]];
$summary=[['Field','Value'],['Report',$definition['name']],['Run','af15f8d8-787e-4a4b-9484-d291b0971cdf'],['Source','journeys'],['From',$payload['source']['window']['from']],['To',$payload['source']['window']['to']],['Timezone','Pacific/Auckland'],['Coverage',$payload['source']['coverage']],['Rows',1],['Generated',$payload['generated_at']],['Definition SHA256',str_repeat('a',64)],['Purpose','Synthetic export layout verification']];
$brand=['name'=>'Example organisation','colour'=>'#5533cc','logo'=>null];
$bytes=Barryvdh\DomPDF\Facade\Pdf::setOption(['defaultFont'=>'DejaVu Sans','isFontSubsettingEnabled'=>true,'isRemoteEnabled'=>false])->loadView('pdf.operational-report',compact('definition','payload','rows','summary','brand','groups'))->setPaper('a4','landscape')->output();
file_put_contents(getenv('REPORT_QA_DIRECTORY').'/layout-verified-report.pdf',$bytes);
echo strlen($bytes).' bytes; synthetic layout only'.PHP_EOL;
