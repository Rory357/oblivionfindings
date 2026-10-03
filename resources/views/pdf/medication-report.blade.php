<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $evidence['title'] }}</title>
<style>
@page { margin: 22px; } body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; } h1 { font-size: 17px; margin: 0 0 8px; } h2 { font-size: 12px; margin-top: 18px; } p { margin: 5px 0; } table { width: 100%; border-collapse: collapse; margin-top: 9px; } th, td { border: 1px solid #888; padding: 5px; vertical-align: top; overflow-wrap: anywhere; } th { background: #eee; text-align: left; } thead { display: table-header-group; } tr { page-break-inside: avoid; } .chart { font-size: 7px; } .chart td, .chart th { padding: 3px; text-align: center; } .chart .medicine { width: 155px; text-align: left; } .note { font-size: 8px; } .allergies { border: 2px solid #111; padding: 7px; margin-top: 10px; }
</style></head><body>
<h1>{{ $evidence['title'] }}</h1>
<p><strong>{{ $evidence['scope'] }}</strong></p>
<p>{{ $evidence['period'][0] }} to {{ $evidence['period'][1] }} · Pacific/Auckland</p>
<p>Prepared by {{ $actor->name }} · {{ $generated->timezone('Pacific/Auckland')->format('j M Y H:i T') }} · Purpose: {{ $purpose }}</p>
@if($evidence['allergies'])<div class="allergies"><strong>Recorded allergies:</strong> {{ implode('; ', $evidence['allergies']) }}</div>@endif
@foreach($evidence['notes'] as $note)<p class="note">{{ $note }}</p>@endforeach
@if($evidence['dates'])
<h2>Scheduled doses by NZ day</h2>
@php($labels = ['given' => 'G', 'refused' => 'R', 'withheld' => 'W', 'missed' => 'M', 'away' => 'A', 'not_recorded' => 'NR', 'late' => 'Due', 'due' => 'Due', 'not_due' => 'Later', 'self_managed' => 'Self', 'pending_check' => 'Check'])
<table class="chart"><thead><tr><th class="medicine">Medicine / scheduled time</th>@foreach($evidence['dates'] as $date)<th>{{ \Carbon\CarbonImmutable::parse($date)->format('j') }}</th>@endforeach</tr></thead><tbody>
@forelse($evidence['chart'] as $line)<tr><td class="medicine">{{ $line['medicine'] }}<br>{{ $line['dose'] }} · {{ $line['route'] }}<br>{{ $line['time'] }}</td>@foreach($evidence['dates'] as $date)<td>{{ implode(', ', array_map(fn ($state) => $labels[$state] ?? str_replace('_', ' ', $state), $line['days'][$date] ?? [])) }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($evidence['dates']) + 1 }}">No scheduled dose slots are available in this period.</td></tr>@endforelse
</tbody></table>
<p class="note">G Given · R Refused · W Withheld · M Recorded missed · A Away · NR Not recorded · Due Window open or overdue · Later Not yet due · Self Self managed · Check Waiting for verification</p>
<h2>Orders that overlap this period</h2><table><thead><tr><th>Medicine</th><th>Order instructions</th><th>Route</th><th>Type</th><th>Start / end</th><th>Ceased / superseded</th></tr></thead><tbody>@forelse($evidence['orders'] as $order)<tr><td>{{ $order['medicine'] }}</td><td>{{ $order['dose'] }}</td><td>{{ $order['route'] ?? '—' }}</td><td>{{ $order['as_needed'] ? 'As needed' : 'Scheduled' }}</td><td>{{ $order['start'] ?? 'Not recorded' }} / {{ $order['end'] ?? 'Ongoing' }}</td><td>{{ $order['ceased_at'] ? \Carbon\CarbonImmutable::parse($order['ceased_at'])->timezone('Pacific/Auckland')->format('j M Y H:i T') : ($order['superseded_at'] ? 'Superseded '.\Carbon\CarbonImmutable::parse($order['superseded_at'])->timezone('Pacific/Auckland')->format('j M Y H:i T') : '—') }}</td></tr>@empty<tr><td colspan="6">No named orders available in this scope.</td></tr>@endforelse</tbody></table>
<h2>As-needed administration evidence</h2>
@endif
<table><thead><tr>@foreach($evidence['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead><tbody>@forelse($evidence['rows'] as $row)<tr>@foreach($row as $value)<td>{{ $value ?? 'Not recorded' }}</td>@endforeach</tr>@empty<tr><td colspan="{{ max(1, count($evidence['columns'])) }}">No records in this period and scope.</td></tr>@endforelse</tbody></table>
</body></html>
