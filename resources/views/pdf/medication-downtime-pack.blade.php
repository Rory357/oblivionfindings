<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Downtime pack — {{ $pack['site']['name'] }}</title>
<style>
@page { margin: 64px 26px 62px; }
body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #171717; }
header { position: fixed; top: -49px; left: 0; right: 0; border-bottom: 1px solid #777; padding-bottom: 7px; }
footer { position: fixed; bottom: -45px; left: 0; right: 0; border-top: 1px solid #777; padding-top: 7px; font-size: 8px; }
h1 { font-size: 17px; margin: 0 0 10px; } h2 { font-size: 13px; margin: 13px 0 6px; }
table { width: 100%; border-collapse: collapse; margin: 6px 0 12px; } th, td { border: 1px solid #888; padding: 7px; vertical-align: top; }
thead { display: table-header-group; }
.page-number:after { content: counter(page); }
.paper-identity { font-weight: bold; }
th { background: #efefef; text-align: left; } .writing td { height: 27px; }
.page { page-break-before: always; } .notice { border: 1px solid #777; padding: 8px; margin: 9px 0; }
.muted { color: #555; } .small { font-size: 9px; } .blank { min-width: 60px; }
</style>
</head>
<body>
@php
$printed = \Carbon\CarbonImmutable::parse($pack['printed_at'])->setTimezone('Pacific/Auckland');
$day = \Carbon\CarbonImmutable::parse($pack['nz_date'], 'Pacific/Auckland');
@endphp
<header><strong>{{ $pack['site']['name'] }} · Downtime pack · {{ $day->format('D j M Y') }}</strong><br>Check the current order, allergies and any changes before each round.</header>
<footer><span style="float: right">Page <span class="page-number"></span></span>Printed {{ $printed->format('D j M Y, g:i a T') }} by {{ $pack['printed_by'] }} — for {{ $day->format('D j M Y') }} only. Check for changes before each round.<br>Purpose: {{ $pack['purpose'] }}. Paper records require accountable confirmation and reconciliation.</footer>
@foreach ($pack['people'] as $person)
<section class="{{ $loop->first ? '' : 'page' }}">
<h1>{{ $person['name'] }} — recording sheet</h1>
<div class="notice"><strong>Allergies</strong>
@if (count($person['allergies']) === 0)
Nothing recorded — check the health profile and ask the lead. This does not mean no known allergies.
@else
@foreach ($person['allergies'] as $allergy)
<br>{{ $allergy['allergen'] }}{{ $allergy['reaction'] ? ' — '.$allergy['reaction'] : '' }}{{ $allergy['severity'] ? ' ('.$allergy['severity'].')' : '' }}
@endforeach
@endif
</div>
<h2>Scheduled doses</h2>
<table><thead><tr><th colspan="7" class="paper-identity">Scheduled doses - {{ $person['name'] }}</th></tr><tr><th>Due (NZ)</th><th>Medicine / dose / route / instructions</th><th>At printing</th><th>Outcome / given at</th><th>Giver / initials</th><th>Second person</th><th>Required reading</th></tr></thead><tbody>
@forelse ($person['scheduled'] as $dose)
<tr class="writing"><td>{{ $dose['ordered_time'] }}</td><td><strong>{{ $dose['medicine'] }}</strong><br>{{ $dose['dosage'] }} · {{ $dose['route'] ?? 'Route not recorded' }}<br>{{ $dose['instructions'] }}
@if ($dose['dst_adjustment'])<br><strong>Clock change: {{ $dose['dst_adjustment'] }}</strong>@endif
</td><td>
@if ($dose['outcome'])Already recorded: {{ ucfirst($dose['state']) }} — do not duplicate.
@elseif ($dose['state'] === 'self_managed')Self-managed — informational, staff do not sign as given.
@elseif ($dose['state'] === 'pending_check')Waiting for the order check — ask the lead before recording.
@elseif ($dose['state'] === 'away')Away — check return before giving.
@else {{ ucfirst(str_replace('_', ' ', $dose['state'])) }}
@endif
</td><td></td><td></td><td>{{ $dose['second_person_required'] ? 'Name / signature:' : '—' }}</td><td>{{ implode('; ', $dose['readings']) }}</td></tr>
@empty
<tr><td colspan="7">No scheduled doses in the available projection for this person on this NZ day.</td></tr>
@endforelse
</tbody></table>
<h2>As-needed medicines and limits</h2>
<table><thead><tr><th colspan="5" class="paper-identity">As-needed medicines and limits - {{ $person['name'] }}</th></tr><tr><th>Medicine / dose / route</th><th>When to use / instructions</th><th>Maximum per day</th><th>Minimum gap</th><th>Second person / readings</th></tr></thead><tbody>
@forelse ($person['prn'] as $medicine)
<tr><td>{{ $medicine['medicine'] }}<br>{{ $medicine['dosage'] }} · {{ $medicine['route'] ?? 'Route not recorded' }}@if (! $medicine['verified'])<br><strong>Waiting for the order check</strong>@endif</td><td>{{ $medicine['indication'] }}<br>{{ $medicine['instructions'] }}</td><td>{{ $medicine['max_per_day'] ?? 'Not configured — check the order' }}</td><td>{{ $medicine['min_hours_between_doses'] !== null ? $medicine['min_hours_between_doses'].' hours' : 'Not configured — check the order' }}</td><td>{{ $medicine['second_person_required'] ? 'Second person required' : '—' }}<br>{{ implode('; ', $medicine['readings']) }}</td></tr>
@empty<tr><td colspan="5">No as-needed medicines shown.</td></tr>@endforelse
</tbody></table>
<table><thead><tr><th colspan="6" class="paper-identity">As-needed recording - {{ $person['name'] }}</th></tr><tr><th>As-needed medicine / actual amount</th><th>Reason / reading</th><th>Given at (NZ date and time)</th><th>Actual giver / signature</th><th>Second person / signature</th><th>Follow-up on paper</th></tr></thead><tbody>
@for ($i = 0; $i < 4; $i++)<tr class="writing"><td></td><td></td><td></td><td></td><td></td><td></td></tr>@endfor
</tbody></table>
</section>
@endforeach
<section class="{{ count($pack['people']) ? 'page' : '' }}">
<h1>Round sheet — {{ $pack['site']['name'] }}</h1>
<p>Built from scheduled doses on {{ $day->format('D j M Y') }}. Check any recorded outcome and changes before the round.</p>
@if ($pack['controlled_notice'])<p class="notice">{{ $pack['controlled_notice'] }}</p>@endif
<table><thead><tr><th>Due (NZ)</th><th>Person</th><th>Medicine / dose / route</th><th>At printing</th><th>Actual outcome / time</th><th>Giver</th><th>Second person / required readings</th></tr></thead><tbody>
@forelse ($pack['rounds'] as $dose)
<tr class="writing"><td>{{ $dose['ordered_time'] }}</td><td>{{ $dose['person'] }}</td><td>{{ $dose['medicine'] }}<br>{{ $dose['dosage'] }} · {{ $dose['route'] }}</td><td>{{ ucfirst(str_replace('_', ' ', $dose['state'])) }}</td><td></td><td></td><td>{{ $dose['second_person_required'] ? 'Name / signature:' : '—' }}<br>{{ implode('; ', $dose['readings']) }}</td></tr>
@empty<tr><td colspan="7">No scheduled doses shown.</td></tr>@endforelse
</tbody></table>
</section>
@if (! $pack['controlled_pages_included'])
<section class="page"><h1>Controlled register pages</h1><p class="notice">{{ $pack['controlled_notice'] }}</p></section>
@else
@foreach ($pack['controlled_registers'] as $register)
<section class="page"><h1>Controlled paper register — {{ $register['person'] }}</h1><h2>{{ $register['medicine'] }}</h2>
<p>Balance at printing ({{ $printed->format('D j M Y, g:i a T') }}): <strong>{{ $register['balance'] ?? 'Not recorded — check the register and count' }} {{ $register['unit'] }}</strong>. This is a snapshot at printing, including on a pack for tomorrow.</p>
<p>Every controlled movement needs its accountable giver and witness. Keep the signed original and complete the closing count. Entering paper facts never changes or rebalances this register automatically.</p>
<table><thead><tr><th colspan="6" class="paper-identity">Controlled paper register - {{ $register['person'] }} - {{ $register['medicine'] }}</th></tr><tr><th>Actual NZ date / time</th><th>Movement / quantity / unit</th><th>Reason / person</th><th>Running balance on paper</th><th>Giver / signature</th><th>Witness / signature</th></tr></thead><tbody>
@for ($i = 0; $i < 12; $i++)<tr class="writing"><td></td><td></td><td></td><td></td><td></td><td></td></tr>@endfor
</tbody></table>
<p><strong>Closing count - {{ $register['person'] }} - {{ $register['medicine'] }}</strong><br>Closing physical count: ____________________ Counted by: ____________________ Witness: ____________________ NZ date / time: ____________________</p>
</section>
@endforeach
@endif
<section class="page"><h1>Recording on paper</h1>
<ol>
<li>Check this pack's house, NZ day, print time, current order and allergies before giving any medicine. Check changes with the lead if the app is unavailable.</li>
<li>Record the actual outcome, actual NZ date and time, dose, readings, and the name and signature of the person who gave it. Do not pre-sign or assume a missed line was given.</li>
<li>Where a second person is required, record their name and signature on the original. Controlled medicines still require controlled authority and a witness.</li>
<li>Check previous paper doses and as-needed limits before another dose. Keep all originals securely in the house.</li>
<li>When connected, the house lead records the downtime and collects each paper entry. An entry for another giver waits for that person to confirm. The named second person confirms with their own PIN.</li>
<li>Preview reconciliation and resolve duplicates or conflicts first. Only a successful canonical eMAR posting is “Entered from paper”. Historical controlled posting remains unavailable until the witnessed register writer and closing-count checks are configured.</li>
</ol>
<p>Internet-only interruption: ordinary online-authorised doses may be queued by the approved recording flow. Required witness and controlled entries need the paper process while offline; emergency access cannot be started offline.</p>
</section>
</body></html>
