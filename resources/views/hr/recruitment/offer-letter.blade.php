<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Offer of Employment — {{ $candidate->full_name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; font-size: 12px; color: #2a2a2a; padding: 40px 48px; line-height: 1.5; }
        .header { border-bottom: 2px solid #4338ca; padding-bottom: 12px; margin-bottom: 24px; }
        .header h1 { font-size: 22px; color: #4338ca; }
        .header .org { font-size: 11px; color: #666; margin-top: 2px; }
        .meta { color: #666; font-size: 11px; margin-bottom: 20px; }
        h2 { font-size: 14px; margin: 18px 0 8px; color: #1f1f1f; }
        p { margin-bottom: 10px; }
        .terms { width: 100%; border-collapse: collapse; margin: 6px 0 14px; }
        .terms td { padding: 6px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
        .terms td.label { width: 34%; color: #666; font-weight: bold; }
        .conditions { background: #f7f7fb; border: 1px solid #e5e5f0; padding: 10px 14px; margin: 8px 0 14px; }
        .sign { margin-top: 28px; }
        .sign-line { border-top: 1px solid #999; width: 260px; padding-top: 4px; margin-top: 34px; color: #666; font-size: 11px; }
        .accepted { margin-top: 22px; padding: 12px 14px; background: #eefaf0; border: 1px solid #cdeed4; color: #1f6f37; }
        .footer { margin-top: 30px; font-size: 10px; color: #999; border-top: 1px solid #ddd; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Offer of Employment</h1>
        <div class="org">{{ $orgName }}</div>
    </div>

    <div class="meta">{{ now()->timezone(config('app.worker_timezone', 'Pacific/Auckland'))->format('j F Y') }}</div>

    <p>Kia ora {{ $candidate->first_name ?: $candidate->full_name }},</p>

    <p>We are delighted to offer you the position of <strong>{{ $offer->position_title ?: 'a role with our team' }}</strong>@if($site) at <strong>{{ $site->name }}</strong>@endif. This letter sets out the key terms of your offer.</p>

    <h2>Your offer at a glance</h2>
    <table class="terms">
        <tr>
            <td class="label">Position</td>
            <td>{{ $offer->position_title ?: '—' }}</td>
        </tr>
        @if($site)
        <tr>
            <td class="label">Location</td>
            <td>{{ $site->name }}</td>
        </tr>
        @endif
        <tr>
            <td class="label">Employment type</td>
            <td>{{ ucwords(str_replace('_', ' ', (string) ($offer->employment_type ?: 'Permanent'))) }}</td>
        </tr>
        @if($offer->proposed_start_date)
        <tr>
            <td class="label">Proposed start date</td>
            <td>{{ $offer->proposed_start_date->format('l, j F Y') }}</td>
        </tr>
        @endif
        @if($offer->hours_per_week)
        <tr>
            <td class="label">Hours per week</td>
            <td>{{ rtrim(rtrim(number_format((float) $offer->hours_per_week, 2), '0'), '.') }} hours</td>
        </tr>
        @endif
        @if($offer->hourly_rate)
        <tr>
            <td class="label">Rate of pay</td>
            <td>${{ number_format((float) $offer->hourly_rate, 2) }} per hour</td>
        </tr>
        @elseif($offer->annual_salary)
        <tr>
            <td class="label">Salary</td>
            <td>${{ number_format((float) $offer->annual_salary, 0) }} per annum</td>
        </tr>
        @endif
    </table>

    @if($offer->conditions)
    <h2>Conditions</h2>
    <div class="conditions">{!! nl2br(e($offer->conditions)) !!}</div>
    @endif

    <p>This offer is made in good faith and is subject to the conditions above and to satisfactory pre-employment checks. A full employment agreement setting out your complete terms and conditions will be provided for your review and signing.</p>

    <p>We are excited about the prospect of you joining the team and the contribution you will make to the people we support. If you have any questions, please don't hesitate to get in touch.</p>

    @if($offer->response === 'accepted' && $offer->signed_full_name)
        <div class="accepted">
            <strong>Accepted</strong> by {{ $offer->signed_full_name }}@if($offer->signed_at) on {{ $offer->signed_at->timezone(config('app.worker_timezone', 'Pacific/Auckland'))->format('j F Y') }}@endif.
        </div>
    @else
        <div class="sign">
            <p>Ngā mihi,</p>
            <div class="sign-line">For and on behalf of {{ $orgName }}</div>
        </div>
    @endif

    <div class="footer">
        Generated on {{ now()->timezone(config('app.worker_timezone', 'Pacific/Auckland'))->format('j M Y, g:ia') }}. This document summarises your offer; your employment agreement is the binding contract.
    </div>
</body>
</html>
