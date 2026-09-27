import { formatDateOnly } from '@/lib/datetime';
import type { RuleRecord } from './data';
import { directionLabel } from './rule-policy';
import { Facts } from './ui';
const weekdays = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];
export function PolicySummary({
    schedule,
    policy,
}: {
    schedule: RuleRecord['schedule'];
    policy: RuleRecord['policy'];
}) {
    const unit = (value: unknown, suffix: string) =>
        value === null || value === undefined || value === ''
            ? 'Not proposed'
            : String(value) + ' ' + suffix;
    return (
        <div className="flow-stack">
            <Facts
                rows={[
                    [
                        'Timing',
                        policy?.timing === 'always'
                            ? 'All times'
                            : schedule
                              ? 'Scheduled'
                              : 'Not configured',
                    ],
                    [
                        'Crossing',
                        policy?.direction
                            ? directionLabel(policy.direction)
                            : 'Not proposed',
                    ],
                    [
                        'Days',
                        schedule
                            ? schedule.weekdays
                                  .map((d) => weekdays[d - 1])
                                  .join(', ')
                            : 'Not scheduled',
                    ],
                    ['Time zone', schedule?.timezone ?? 'Not scheduled'],
                    [
                        'Date range',
                        schedule
                            ? `${schedule.first_date ? formatDateOnly(schedule.first_date) : 'No start limit'} → ${schedule.last_date ? formatDateOnly(schedule.last_date) : 'No end limit'}`
                            : 'Not scheduled',
                    ],
                    [
                        'Excluded dates',
                        schedule?.exception_dates
                            ?.map((date) => formatDateOnly(date))
                            .join(', ') || 'None',
                    ],
                    ['Accuracy limit', unit(policy?.accuracyM, 'metres')],
                    [
                        'Confirmation time',
                        unit(policy?.confirmationSeconds, 'seconds'),
                    ],
                    ['Boundary buffer', unit(policy?.bufferM, 'metres')],
                    ['Dwell time', unit(policy?.dwellSeconds, 'seconds')],
                    ['Repeat interval', unit(policy?.repeatMinutes, 'minutes')],
                    ['Priority', policy?.priority || 'Not proposed'],
                    ['Responsible team', policy?.owner || 'Not proposed'],
                    [
                        'Acknowledgement target',
                        unit(policy?.acknowledgeMinutes, 'minutes'),
                    ],
                    [
                        'Escalation target',
                        unit(policy?.escalateMinutes, 'minutes'),
                    ],
                    [
                        'Escalation team',
                        policy?.escalationTeam || 'Not proposed',
                    ],
                ]}
            />
            {schedule && (
                <div>
                    <h3 className="font-medium">Time windows</h3>
                    <ul className="space-y-2">
                        {(policy?.windows?.length
                            ? policy.windows
                            : [schedule]
                        ).map((w, i) => (
                            <li key={i} className="text-sm">
                                {w.start}–{w.end}
                                {w.following_day
                                    ? ' · ends the following day'
                                    : ''}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
