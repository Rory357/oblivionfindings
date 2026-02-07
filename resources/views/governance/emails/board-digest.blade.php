@component('mail::message')
# Weekly Board Digest — {{ now()->format('j F Y') }}

## Risk Posture
@if(($metrics['top_risks']['critical'] ?? 0) > 0)
⚠️ **{{ $metrics['top_risks']['critical'] }} Critical risks** require attention
@else
✓ No critical risks this week
@endif

@if(($metrics['top_risks']['high'] ?? 0) > 0)
⚠️ **{{ $metrics['top_risks']['high'] }} High risks** monitored
@endif

## Decisions Awaiting Your Vote
@if($decisionsCount > 0)
🔴 **{{ $decisionsCount }} resolution(s)** awaiting your vote
@component('mail::button', ['url' => route('governance.resolutions.index')])
View Resolutions
@endcomponent
@else
✓ No pending votes
@endif

## Action Items
- **{{ $overdueActions }}** overdue action items
- **{{ $metrics['workforce']['unfilled_shifts'] ?? 0 }}** unfilled shifts this week

## Upcoming Meetings
@forelse($upcomingMeetings as $meeting)
- **{{ $meeting->title }}** — {{ $meeting->scheduled_at->format('j F Y') }}
@empty
No upcoming meetings scheduled
@endforelse

@component('mail::button', ['url' => route('governance.dashboard')])
View Full Dashboard
@endcomponent

---

This is an automated digest from the Oblivion Findings Governance Portal.
You can manage your notification preferences in your account settings.

@endcomponent
