    <style>
        @page { margin: 30mm 15mm 18mm 15mm; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { color: #211d2f; font-size: 9pt; line-height: 1.45; }
        .page-head { position: fixed; top: -24mm; left: 0; right: 0; height: 20mm; }
        .page-head .bar { height: 2.5mm; background: {{ $brand['colour'] }}; }
        .page-head table { width: 100%; border-collapse: collapse; margin-top: 3mm; }
        .page-head td { vertical-align: middle; }
        .ring { width: 9mm; height: 9mm; border: 1.6mm solid {{ $brand['colour'] }}; border-radius: 50%; }
        .logo { max-height: 11mm; max-width: 32mm; }
        .org { font-size: 11pt; font-weight: bold; letter-spacing: .06em; text-transform: uppercase; }
        .crumb { font-size: 8pt; color: #625d73; }
        .page-foot { position: fixed; bottom: -11mm; left: 0; right: 0; height: 7mm; border-top: .4pt solid #dcdae3; padding-top: 1.5mm; font-size: 7pt; color: #625d73; }
        .page-foot table { width: 100%; border-collapse: collapse; }
        .page-number:after { content: "Page " counter(page); }
        h1 { font-size: 19pt; margin: 0 0 1mm; }
        h2 { font-size: 13pt; margin: 0 0 1mm; }
        h3 { font-size: 10pt; margin: 5mm 0 2mm; }
        .muted { color: #625d73; }
        .accent { color: {{ $brand['colour'] }}; }
        .vehicle { font-size: 12pt; font-weight: bold; margin-top: 4mm; }
        table.kpis { width: 100%; border-collapse: separate; border-spacing: 2mm 0; margin: 4mm 0; }
        table.kpis td { width: 25%; background: {{ $brand['tint'] }}; padding: 3mm 3.5mm; vertical-align: top; }
        .kpi-label { font-size: 7pt; font-weight: bold; letter-spacing: .06em; color: #625d73; text-transform: uppercase; }
        .kpi-value { font-size: 15pt; font-weight: bold; color: {{ $brand['colour'] }}; margin-top: 1mm; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 2mm; }
        table.data th { background: {{ $brand['colour'] }}; color: #ffffff; font-size: 7.5pt; text-align: left; padding: 1.6mm 2mm; }
        table.data td { border-bottom: .4pt solid #e3e1ea; padding: 1.6mm 2mm; vertical-align: top; font-size: 8pt; }
        table.data td.num, table.data th.num { text-align: right; white-space: nowrap; }
        .route-to { color: #625d73; }
        .notes { margin-top: 5mm; }
        .notes p { margin: 0 0 1.5mm; font-size: 8pt; color: #3d384d; }
        .trip { page-break-inside: auto; margin-top: 7mm; }
        .trip.new-page { page-break-before: always; margin-top: 0; }
        table.facts { width: 100%; border-collapse: collapse; margin-top: 3mm; }
        table.facts td { width: 25%; border: .4pt solid #e3e1ea; padding: 2mm 2.5mm; vertical-align: top; }
        .fact-label { font-size: 7pt; color: #625d73; }
        .fact-value { font-size: 10pt; font-weight: bold; margin-top: .5mm; }
        .route-sketch { margin-top: 3mm; border: .4pt solid #dcdae3; }
        .route-sketch img { width: 100%; }
        .legend { font-size: 7.5pt; color: #625d73; margin-top: 1mm; }
        .dot { display: inline-block; width: 2.4mm; height: 2.4mm; border-radius: 50%; }
        .source { margin-top: 2.5mm; font-size: 7.5pt; color: #625d73; }
    </style>
