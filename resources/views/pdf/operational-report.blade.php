<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $definition['name'] }}</title>
<style>
@page { margin: 32px 28px 40px; } body { font: 9px "DejaVu Sans", sans-serif; color: #20232b; }
h1 { font-size: 21px; margin-bottom: 5px; } h2 { font-size: 13px; margin-top: 16px; }
.page-number:after { content: counter(page); }
.brand { color: {{ $brand['colour'] }}; font-size: 13px; } .scope { color: #4b5563; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; } th,td { padding: 4px; border-bottom: 1px solid #d1d5db; word-wrap: break-word; vertical-align: top; }
th { text-align: left; background: #f1f3f5; } thead { display: table-header-group; } tr { page-break-inside: avoid; }
footer { position: fixed; bottom: -24px; color: #4b5563; font-size: 8px; }
</style></head><body>
@if(!empty($brand['logo']))<img src="{{ $brand['logo'] }}" style="float:right;max-width:120px;max-height:42px" alt="Company logo">@endif
<div class="brand">{{ $brand['name'] }}</div><h1>{{ $definition['name'] }}</h1>
<p class="scope">{{ \Carbon\CarbonImmutable::parse($payload['source']['window']['from'])->setTimezone('Pacific/Auckland')->format('j M Y H:i') }} - {{ \Carbon\CarbonImmutable::parse($payload['source']['window']['to'])->setTimezone('Pacific/Auckland')->format('j M Y H:i') }} · Pacific/Auckland</p>
<p>{{ config('operational-reports.sources.'.$definition['source'].'.note') }}</p>
<p>{{ $payload['source']['coverage'] }} {{ $payload['result']['row_count'] }} matching source rows. Blank values mean unknown or withheld.</p>
<h2>Measures</h2><table><thead><tr>@foreach($groups[0] as $cell)<th>{{ $cell }}</th>@endforeach</tr></thead><tbody>
@foreach(array_slice($groups,1) as $row)<tr>@foreach($row as $cell)<td>{{ $cell ?? 'Unknown' }}</td>@endforeach</tr>@endforeach
</tbody></table>
<h2>Source rows</h2><table><thead><tr>@foreach($rows[0] as $cell)<th>{{ $cell }}</th>@endforeach</tr></thead><tbody>
@forelse(array_slice($rows,1) as $row)<tr>@foreach($row as $cell)<td>{{ $cell ?? 'Unknown' }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($rows[0]) }}">No matching records within the authorised window.</td></tr>@endforelse
</tbody></table>
<h2>Scope and provenance</h2><table>@foreach(array_slice($summary,1) as $row)<tr><th>{{ $row[0] }}</th><td>{{ $row[1] }}</td></tr>@endforeach</table>
<footer>Private report · Generated {{ $payload['generated_at'] }} · Definitions and access are checked on each download. · Page <span class="page-number"></span></footer>
</body></html>
