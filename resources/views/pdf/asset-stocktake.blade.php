<!doctype html>
<html><head><meta charset="utf-8"><style>
@page { margin: 32px 32px 42px; }
body { font-family: 'DejaVu Sans', sans-serif; color: #252236; font-size: 9px; line-height: 1.5; }
.hero { background: {{ $brand['colour'] }}; color: white; padding: 20px; }
.logo { float:right; max-width:120px; max-height:44px; }
h1 {font-size:22px;margin:8px 0;} h2 {font-size:13px;color:{{ $brand['colour'] }};margin-top:22px;}
.muted {color:#666;} .summary {padding:14px;background:{{ $brand['tint'] }};margin:14px 0;}
table {width:100%;border-collapse:collapse;table-layout:fixed;} th {background:{{ $brand['colour'] }};color:white;text-align:left;padding:7px;} td {padding:7px;border-bottom:1px solid #dedce4;word-wrap:break-word;vertical-align:top;} tr {page-break-inside:avoid;} tbody tr:nth-child(even){background:#f6f5f9;}
.page {page-break-before:always;} footer {position:fixed;bottom:-25px;font-size:8px;color:#666;}
</style></head><body>
<footer>{{ $brand['name'] }} · ST-{{ $report['id'] }} · Physical stocktake record</footer>
<div class="hero">
@if($brand['logo'])<img class="logo" src="{{ $brand['logo'] }}">
@endif
<strong>{{ $brand['name'] }}</strong><h1>{{ $report['title'] }}</h1>ST-{{ $report['id'] }} · Completed stocktake</div>
<div class="summary"><strong>{{ $report['scope']['site'] }} · {{ $report['scope']['room'] ?? 'Whole site' }}</strong><br>
Counted by {{ $report['scope']['counter'] }} · Started {{ \Carbon\CarbonImmutable::parse($report['counted_at'])->timezone(config('app.worker_timezone'))->format('j M Y, g:i a T') }}<br>
Completed {{ \Carbon\CarbonImmutable::parse($report['completed_at'])->timezone(config('app.worker_timezone'))->format('j M Y, g:i a T') }}<br>
{{ count($report['entries']) }} items · {{ collect($report['entries'])->where('result','found')->count() }} found · {{ collect($report['entries'])->where('result','missing')->count() }} not found</div>
<h2>Review & follow-up</h2><p>{{ $report['review_note'] ?: 'No differences requiring follow-up.' }}</p>
@if($report['follow_up_name'])<p><strong>Follow-up owner:</strong> {{ $report['follow_up_name'] }}</p>
@endif
<p class="muted">This report records physical observations. It does not change assignment, custody, ownership or safe-use status.</p>
<h2>Results</h2><table><thead><tr><th>Asset / tag</th><th>Room at start</th><th>Result</th><th>Observation</th></tr></thead><tbody>
@foreach($report['entries'] as $entry)<tr><td><strong>{{ $entry['name'] }}</strong><br>{{ $entry['asset_tag'] }}<br>{{ $entry['serial_number'] }}</td><td>{{ $entry['room'] ?? 'Unassigned room' }}
@if(!$entry['expected'])<br>Extra item
@endif</td><td>{{ $entry['result'] === 'missing' ? 'Not found' : ucfirst($entry['result']) }}
@if($entry['changed'])<br>Assignment/status changed
@endif</td><td>{{ $entry['source'] }} · {{ $entry['actor'] }}<br>{{ $entry['observed_at'] ? \Carbon\CarbonImmutable::parse($entry['observed_at'])->timezone(config('app.worker_timezone'))->format('j M Y, g:i a T') : 'Not recorded' }}<br>{{ \Illuminate\Support\Str::limit($entry['note'], 120) }}</td></tr>
@endforeach
</tbody></table>
@if(collect($report['entries'])->contains(fn ($entry) => mb_strlen($entry['note'] ?? '') > 120))<h2>Full observation notes</h2>
@foreach($report['entries'] as $entry)
@if(mb_strlen($entry['note'] ?? '') > 120)<p><strong>{{ $entry['name'] }} · {{ $entry['asset_tag'] }}</strong><br>{{ $entry['note'] }}</p>
@endif
@endforeach
@endif
<h2 class="page">Activity</h2><table><thead><tr><th>Time</th><th>Action / item</th><th>Recorded by</th></tr></thead><tbody>
@foreach($report['activity'] as $event)<tr><td>{{ \Carbon\CarbonImmutable::parse($event['at'])->timezone(config('app.worker_timezone'))->format('j M Y, g:i a T') }}</td><td>{{ ucfirst($event['action']) }}<br>{{ $event['name'] ?? '' }}</td><td>{{ $event['actor'] }}<br>{{ $event['source'] ?? '' }}</td></tr>
@endforeach</tbody></table>
</body></html>
