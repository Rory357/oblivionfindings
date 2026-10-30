<!doctype html><html><head><meta charset="utf-8"><style>
@page {margin:0;} body {margin:0;font-family:'DejaVu Sans',sans-serif;}
.page {position:relative;width:{{ ($layout['paper'] ?? 'a4') === 'a4' ? 210 : $layout['width'] }}mm;height:{{ ($layout['paper'] ?? 'a4') === 'a4' ? 296 : $layout['height'] - 0.2 }}mm;page-break-after:always;} .page:last-child{page-break-after:auto;}
.label{position:absolute;box-sizing:border-box;text-align:center;overflow:hidden;padding:2mm;}
.brand{height:7mm;font-size:7pt;line-height:4mm;overflow:hidden;} .brand img{max-height:5mm;max-width:28mm;vertical-align:top;}
.tag{font-size:8pt;font-weight:bold;line-height:4mm;height:4mm;overflow:hidden;} .name{font-size:7pt;line-height:3mm;height:3mm;overflow:hidden;}
</style></head><body>
@foreach($pages as $page)<div class="page">
@foreach($page as $index => $label)
@if($label)
<div class="label" style="left:{{ $layout['margin'] + ($index % $layout['columns']) * ($layout['width'] + $layout['gap']) }}mm;top:{{ $layout['margin'] + intdiv($index, $layout['columns']) * ($layout['height'] + $layout['gap']) }}mm;width:{{ $layout['width'] }}mm;height:{{ $layout['height'] }}mm;">
<div class="brand">
@if($brand['logo'])<img src="{{ $brand['logo'] }}">
@else{{ $brand['name'] }}
@endif</div>
<img alt="Asset QR" src="{{ $label['png'] }}" style="width:{{ min($layout['width'] - 4, $layout['height'] - 19) }}mm;height:{{ min($layout['width'] - 4, $layout['height'] - 19) }}mm;display:block;margin:auto;">
<div class="tag">{{ $label['tag'] }}</div><div class="name">{{ $label['name'] }}</div></div>
@endif
@endforeach</div>
@endforeach</body></html>
