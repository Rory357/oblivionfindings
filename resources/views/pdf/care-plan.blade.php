@php
    /** @var \App\Models\CarePlan $plan */
    $client = $plan->client;
    $about = $content['about_me'] ?? [];
    $egl = $content['egl'] ?? [];
    $funding = $content['funding'] ?? [];
    $domains = collect($content['domains'] ?? [])->filter(fn ($d) => filled($d['label'] ?? null));
    $supportNeeds = collect($content['support_needs'] ?? [])->filter(fn ($v) => $v)->keys();
    $eglPrinciples = collect($egl['principles'] ?? [])->filter();

    $fmtDate = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('j M Y') : '—';
    $titleCase = fn ($v) => ucwords(str_replace(['_', '-'], ' ', (string) $v));

    $roleLabels = [
        'client' => 'Client', 'whanau' => 'Whānau', 'eor_guardian' => 'EOR / Welfare guardian',
        'key_worker' => 'Key worker', 'nasc' => 'NASC', 'other' => 'Other',
    ];
    $signOffs = $plan->signOffs ?? collect();
    // Roles we want a signature line for even when not yet captured.
    $coreRoles = ['client', 'whanau', 'key_worker'];
    $capturedRoles = $signOffs->pluck('party_role')->all();
    $blankRoles = array_values(array_diff($coreRoles, $capturedRoles));

    $aboutFields = [
        'dreams' => 'My dreams & aspirations',
        'important_to_me' => "What's important TO me",
        'important_for_me' => "What's important FOR me",
        'ideal_day' => 'My ideal day',
        'likes' => 'Things I like',
        'dislikes' => "Things I don't like",
        'how_to_support' => 'How to support me best',
    ];
    $hasAbout = collect($about)->filter(fn ($v) => filled($v))->isNotEmpty();
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #1f2333; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 16px 0 6px; color: #4338ca; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
        .muted { color: #6b7280; }
        .header { border-bottom: 2px solid #4338ca; padding-bottom: 10px; margin-bottom: 12px; }
        .meta-table { width: 100%; border-collapse: collapse; }
        .meta-table td { vertical-align: top; padding: 1px 0; }
        .meta-table .right { text-align: right; }
        .badge { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 10px; font-weight: bold; }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-draft { background: #f3f4f6; color: #374151; }
        .badge-review { background: #fef3c7; color: #92400e; }
        .badge-archived { background: #e5e7eb; color: #4b5563; }
        .egl-banner { background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 6px; padding: 9px 12px; margin-bottom: 12px; }
        .egl-banner .label { font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: #4338ca; font-weight: bold; }
        .chip { display: inline-block; background: #eef2ff; color: #3730a3; border-radius: 10px; padding: 2px 8px; font-size: 10px; margin: 0 3px 3px 0; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.data th, table.data td { border: 1px solid #d1d5db; padding: 5px 7px; text-align: left; vertical-align: top; }
        table.data th { background: #f3f4f6; font-weight: bold; font-size: 10px; }
        .domain { margin-bottom: 10px; }
        .domain .domain-head { font-weight: bold; font-size: 12px; margin-bottom: 3px; }
        .strategy { padding: 3px 0 3px 12px; border-left: 2px solid #c7d2fe; margin: 3px 0; }
        .strategy .owner { color: #6b7280; font-size: 10px; }
        .about-cell .label { font-size: 9px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; font-weight: bold; margin-bottom: 2px; }
        .section-body { white-space: pre-wrap; }
        .signature-line { border-bottom: 1px solid #9ca3af; height: 22px; }
        .footer { text-align: center; font-size: 9px; color: #9ca3af; margin-top: 18px; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="header">
        <table class="meta-table">
            <tr>
                <td>
                    <h1>{{ $plan->title ?? 'Care & support plan' }}</h1>
                    <div class="muted">
                        {{ trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')) ?: 'Client' }}
                        @if ($client?->date_of_birth) &middot; DOB {{ $fmtDate($client->date_of_birth) }} @endif
                    </div>
                </td>
                <td class="right">
                    @php $st = $plan->status ?? 'draft'; @endphp
                    <span class="badge badge-{{ $st }}">{{ strtoupper($st) }}</span>
                    <div class="muted" style="margin-top:4px;">
                        {{ $titleCase($plan->plan_type) }} &middot; Version {{ $plan->version ?? 1 }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    @if (filled($egl['vision'] ?? null) || $eglPrinciples->isNotEmpty())
        <div class="egl-banner">
            <div class="label">Enabling Good Lives — vision</div>
            @if (filled($egl['vision'] ?? null))
                <div style="margin:3px 0 5px;">{{ $egl['vision'] }}</div>
            @endif
            @foreach ($eglPrinciples as $p)
                <span class="chip">{{ $p }}</span>
            @endforeach
        </div>
    @endif

    <table class="meta-table" style="margin-bottom:6px;">
        <tr>
            <td>
                <span class="muted">Owner:</span> {{ $plan->creator->name ?? '—' }}<br>
                <span class="muted">Starts:</span> {{ $fmtDate($plan->starts_at) }}
                &nbsp; <span class="muted">Ends:</span> {{ $fmtDate($plan->ends_at) }}
            </td>
            <td class="right">
                <span class="muted">Last reviewed:</span> {{ $fmtDate($plan->reviewed_at) }}
                @if ($plan->reviewer) by {{ $plan->reviewer->name }} @endif<br>
                <span class="muted">Next review:</span> {{ $fmtDate($plan->next_review_at) }}
            </td>
        </tr>
    </table>

    @if ($hasAbout)
        <h2>About {{ $client->first_name ?? 'the person' }}</h2>
        <table class="data">
            @foreach ($aboutFields as $key => $label)
                @if (filled($about[$key] ?? null))
                    <tr>
                        <td style="width:28%;" class="about-cell"><div class="label">{{ $label }}</div></td>
                        <td class="section-body">{{ $about[$key] }}</td>
                    </tr>
                @endif
            @endforeach
        </table>
    @endif

    @if ($supportNeeds->isNotEmpty())
        <h2>Support needs</h2>
        <div>
            @foreach ($supportNeeds as $need)
                <span class="chip">{{ $titleCase($need) }}</span>
            @endforeach
        </div>
    @endif

    @if ($domains->isNotEmpty())
        <h2>Support domains &amp; strategies</h2>
        @foreach ($domains as $domain)
            <div class="domain">
                <div class="domain-head">
                    {{ $domain['label'] }}
                    <span class="muted" style="font-weight:normal;">— {{ $titleCase($domain['status'] ?? 'active') }}</span>
                </div>
                @foreach (collect($domain['strategies'] ?? [])->filter(fn ($s) => filled($s['text'] ?? null)) as $strategy)
                    <div class="strategy">
                        {{ $strategy['text'] }}
                        @if (filled($strategy['owner'] ?? null))<div class="owner">Owner: {{ $strategy['owner'] }}</div>@endif
                    </div>
                @endforeach
            </div>
        @endforeach
    @endif

    @foreach (['risk_factors' => 'Risk factors', 'support_strategies' => 'Support strategies', 'communication_preferences' => 'Communication preferences'] as $key => $label)
        @if (filled($content[$key] ?? null))
            <h2>{{ $label }}</h2>
            <div class="section-body">{{ $content[$key] }}</div>
        @endif
    @endforeach

    @if (filled($funding['nasc_organisation'] ?? null) || filled($funding['needs_assessment_ref'] ?? null) || $agreement || filled($funding['allocated_hours'] ?? null) || filled($funding['funding_notes'] ?? null))
        <h2>Funding &amp; NASC</h2>
        <table class="data">
            @if (filled($funding['nasc_organisation'] ?? null))
                <tr><th style="width:32%;">NASC organisation</th><td>{{ $funding['nasc_organisation'] }}</td></tr>
            @endif
            @if (filled($funding['needs_assessment_ref'] ?? null))
                <tr><th>Needs assessment ref</th><td>{{ $funding['needs_assessment_ref'] }} @if(filled($funding['needs_assessment_date'] ?? null)) &middot; {{ $fmtDate($funding['needs_assessment_date']) }} @endif</td></tr>
            @endif
            @if ($agreement)
                <tr><th>Service agreement</th><td>{{ $agreement->title ?? ('Agreement #' . $agreement->id) }} @if(filled($agreement->whaikaha_reference ?? null)) &middot; {{ $agreement->whaikaha_reference }} @endif</td></tr>
            @endif
            @if (filled($funding['allocated_hours'] ?? null))
                <tr><th>Allocated hours / week</th><td>{{ $funding['allocated_hours'] }}</td></tr>
            @endif
            @if (filled($funding['funding_notes'] ?? null))
                <tr><th>Notes</th><td class="section-body">{{ $funding['funding_notes'] }}</td></tr>
            @endif
        </table>
    @endif

    @if (($plan->goals ?? collect())->isNotEmpty())
        <h2>Goals</h2>
        <table class="data">
            <thead><tr><th>Goal</th><th style="width:18%;">Status</th><th style="width:14%;">Progress</th></tr></thead>
            <tbody>
                @foreach ($plan->goals as $goal)
                    <tr>
                        <td>{{ $goal->title }}@if(filled($goal->category)) <span class="muted">&middot; {{ $titleCase($goal->category) }}</span>@endif</td>
                        <td>{{ $titleCase($goal->status ?? 'not started') }}</td>
                        <td>{{ $goal->progress_percentage ?? 0 }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Agreement &amp; sign-off</h2>
    <table class="data">
        <thead>
            <tr><th style="width:24%;">Party</th><th>Name</th><th style="width:18%;">Agreed on</th><th style="width:18%;">Signature</th></tr>
        </thead>
        <tbody>
            @foreach ($signOffs as $s)
                <tr>
                    <td>{{ $roleLabels[$s->party_role] ?? $titleCase($s->party_role) }}@if(filled($s->relationship)) <span class="muted">({{ $s->relationship }})</span>@endif</td>
                    <td>{{ $s->party_name }}@if(filled($s->method)) <span class="muted">&middot; {{ $titleCase($s->method) }}</span>@endif</td>
                    <td>{{ $fmtDate($s->agreed_on) }}</td>
                    <td class="signature-line"></td>
                </tr>
            @endforeach
            @foreach ($blankRoles as $role)
                <tr>
                    <td>{{ $roleLabels[$role] ?? $titleCase($role) }}</td>
                    <td class="signature-line"></td>
                    <td class="signature-line"></td>
                    <td class="signature-line"></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Generated {{ $generatedAt->format('j M Y, g:ia') }} &middot; Confidential — supported-living care plan
    </div>
</body>
</html>
