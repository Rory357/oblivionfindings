<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Asset labels</title><style>
@page { margin: {{ $sheet ? '8.7mm 9.75mm' : '0' }}; }
body { margin:0; padding:0; font-family:DejaVu Sans,sans-serif; color:#000; background:#fff; }
table { border-collapse:collapse; table-layout:fixed; width:100%; }
td { width:{{ $width }}mm; height:{{ $height }}mm; padding:0; vertical-align:middle; text-align:center; }
.label { height:{{ $height - 2 }}mm; padding:1mm; overflow:hidden; }
.logo { max-height:5mm; max-width:28mm; vertical-align:middle; }
.org { font-size:6pt; height:5mm; overflow:hidden; }
.qr { width:30mm; height:30mm; display:block; margin:0 auto; }
.tag { font-size:8pt; font-weight:bold; line-height:1.15; }
.name { font-size:6pt; line-height:1.2; max-height:4mm; overflow:hidden; }
.page { page-break-after:always; }
</style></head><body>
@php($slots = $sheet ? 18 : 1)
@php($pages = (int) ceil(($copies + $offset) / $slots))
@for($page = 0; $page < $pages; $page++)
<table class="{{ $page < $pages - 1 ? 'page' : '' }}">
@for($row = 0; $row < ($sheet ? 6 : 1); $row++)<tr>
@for($column = 0; $column < ($sheet ? 3 : 1); $column++)
@php($index = $page * $slots + $row * ($sheet ? 3 : 1) + $column)
<td>@if($index >= $offset && $index < $copies + $offset)<div class="label"><div class="org">@if($logo)<img class="logo" src="{{ $logo }}" alt="">@else{{ mb_strimwidth($organisation, 0, 46, '…') }}@endif</div><img class="qr" src="{{ $qr }}" alt="Asset QR"><div class="tag">{{ mb_strimwidth($asset->asset_tag ?: 'AS-'.$asset->id, 0, 30, '…') }}</div><div class="name">{{ mb_strimwidth($asset->name, 0, 45, '…') }}</div></div>@endif</td>
@endfor</tr>@endfor</table>
@endfor</body></html>
