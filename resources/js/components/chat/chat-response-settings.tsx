import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useId, useState } from 'react';

export type ResponseSchedule = {
    timezone: string;
    response_minutes: number;
    weekly: { day: number; start: string; end: string }[];
    holidays: string[];
};
export type ResponsePolicy = {
    response_expectation: string;
    response_schedule: ResponseSchedule | null;
    site_overrides: Record<
        string,
        {
            response_expectation?: string;
            response_schedule?: ResponseSchedule | null;
            [field: string]: unknown;
        }
    >;
};
const days = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];
const control =
    'min-h-[44px] min-w-0 w-full rounded-lg border bg-background px-3 text-sm';

export function describeResponseSchedule(
    schedule: ResponseSchedule | null | undefined,
): string {
    if (schedule === undefined) return 'Use organisation default';
    if (schedule === null) return 'No response expectation configured';
    return `${schedule.response_minutes} working minutes · ${schedule.timezone} · ${schedule.weekly.map((slot) => `${days[slot.day - 1]} ${slot.start}–${slot.end}`).join('; ')} · ${schedule.holidays.length ? `Closed dates: ${schedule.holidays.join(', ')}` : 'No holiday exclusions configured'}`;
}

export function responsePolicyChanges(
    before: ResponsePolicy,
    after: ResponsePolicy,
    sites: { id: number; name: string }[],
) {
    const changes: { label: string; before: string; after: string }[] = [];
    const compare = (
        scope: string,
        a: Partial<ResponsePolicy>,
        b: Partial<ResponsePolicy>,
    ) => {
        if (a.response_expectation !== b.response_expectation)
            changes.push({
                label: `${scope} · Response wording`,
                before:
                    a.response_expectation ??
                    `Use organisation default: ${before.response_expectation || 'No additional wording'}`,
                after:
                    b.response_expectation ??
                    `Use organisation default: ${after.response_expectation || 'No additional wording'}`,
            });
        if (
            JSON.stringify(a.response_schedule) !==
            JSON.stringify(b.response_schedule)
        )
            changes.push({
                label: `${scope} · Response hours`,
                before:
                    a.response_schedule === undefined
                        ? `Use organisation default: ${describeResponseSchedule(before.response_schedule)}`
                        : describeResponseSchedule(a.response_schedule),
                after:
                    b.response_schedule === undefined
                        ? `Use organisation default: ${describeResponseSchedule(after.response_schedule)}`
                        : describeResponseSchedule(b.response_schedule),
            });
    };
    compare('Organisation defaults', before, after);
    for (const id of new Set([
        ...Object.keys(before.site_overrides),
        ...Object.keys(after.site_overrides),
    ])) {
        compare(
            sites.find((site) => String(site.id) === id)?.name ?? `Site ${id}`,
            before.site_overrides[id] ?? {},
            after.site_overrides[id] ?? {},
        );
    }
    return changes;
}

export function ChatResponseSettings({
    value,
    sites,
    onChange,
    dirty,
    onReview,
    disabled = false,
    policyScope,
}: {
    value: ResponsePolicy;
    sites: { id: number; name: string }[];
    onChange: (value: ResponsePolicy) => void;
    dirty: boolean;
    onReview: () => void;
    disabled?: boolean;
    policyScope?: string;
}) {
    const fieldId = useId();
    const [localScope, setScope] = useState('organisation');
    const scope = policyScope ?? localScope;
    const [scopeNotice, setScopeNotice] = useState(false);
    const override =
        scope === 'organisation' ? null : (value.site_overrides[scope] ?? {});
    const inherited =
        override !== null && !Object.hasOwn(override, 'response_schedule');
    const schedule = inherited
        ? value.response_schedule
        : override
          ? (override.response_schedule ?? null)
          : value.response_schedule;
    const mode = inherited ? 'inherit' : schedule ? 'configured' : 'none';
    function edit(changes: {
        response_schedule?: ResponseSchedule | null;
        response_expectation?: string;
    }) {
        if (override === null) onChange({ ...value, ...changes });
        else
            onChange({
                ...value,
                site_overrides: {
                    ...value.site_overrides,
                    [scope]: { ...override, ...changes },
                },
            });
    }
    function inherit(field: 'response_schedule' | 'response_expectation') {
        const next = { ...override };
        delete next[field];
        const overrides = { ...value.site_overrides };
        if (Object.keys(next).length) overrides[scope] = next;
        else delete overrides[scope];
        onChange({ ...value, site_overrides: overrides });
    }
    function updateSchedule(changes: Partial<ResponseSchedule>) {
        if (schedule) edit({ response_schedule: { ...schedule, ...changes } });
    }
    return (
        <fieldset
            disabled={disabled}
            className={
                policyScope === undefined
                    ? 'space-y-4 rounded-2xl border p-5'
                    : 'space-y-4'
            }
            aria-label={
                policyScope === undefined ? undefined : 'Response hours'
            }
            aria-labelledby={
                policyScope === undefined ? 'chat-response-title' : undefined
            }
        >
            {policyScope === undefined && (
                <h2 id="chat-response-title" className="font-semibold">
                    Family contact
                </h2>
            )}
            {policyScope === undefined && (
                <div className="grid gap-2 text-sm font-medium">
                    <label htmlFor={`${fieldId}-scope`}>Policy scope</label>
                    <Select
                        value={scope}
                        disabled={disabled}
                        onValueChange={(next) => {
                            if (dirty) {
                                setScopeNotice(true);
                                return;
                            }
                            setScope(next);
                            setScopeNotice(false);
                        }}
                    >
                        <SelectTrigger
                            id={`${fieldId}-scope`}
                            className={control}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="organisation">
                                Organisation defaults
                            </SelectItem>
                            {sites.map((site) => (
                                <SelectItem
                                    key={site.id}
                                    value={String(site.id)}
                                >
                                    {site.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            )}
            {policyScope === undefined && (
                <p className="text-sm text-muted-foreground">
                    {override === null
                        ? 'Applies to approved sites without an override.'
                        : `Applies to eligible people and care relationships at ${sites.find((site) => String(site.id) === scope)?.name}. Other sites keep their settings.`}
                </p>
            )}
            {scopeNotice && dirty && (
                <div role="status" className="rounded-lg border p-3 text-sm">
                    Your changes are still here. Review and save, or use Discard
                    below before changing scope.
                    <Button
                        variant="outline"
                        className="mt-2 min-h-[44px]"
                        onClick={onReview}
                    >
                        Review changes
                    </Button>
                </div>
            )}
            <div className="grid gap-2 text-sm font-medium">
                <label htmlFor={`${fieldId}-expectation`}>
                    Response expectation
                </label>
                <Select
                    value={mode}
                    disabled={disabled}
                    onValueChange={(next) => {
                        if (next === 'inherit') inherit('response_schedule');
                        else
                            edit({
                                response_schedule:
                                    next === 'none'
                                        ? null
                                        : (schedule ?? {
                                              timezone: 'Pacific/Auckland',
                                              response_minutes: 240,
                                              weekly: [1, 2, 3, 4, 5].map(
                                                  (day) => ({
                                                      day,
                                                      start: '08:00',
                                                      end: '18:00',
                                                  }),
                                              ),
                                              holidays: [],
                                          }),
                            });
                    }}
                >
                    <SelectTrigger
                        id={`${fieldId}-expectation`}
                        className={control}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {override !== null && (
                            <SelectItem value="inherit">
                                Inherited organisation default
                            </SelectItem>
                        )}
                        <SelectItem value="none">
                            No response expectation configured
                        </SelectItem>
                        <SelectItem value="configured">
                            Working-hours target
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
            {inherited && (
                <p className="rounded-lg bg-muted/40 p-3 text-sm break-words">
                    Inherited: {describeResponseSchedule(schedule)}
                </p>
            )}
            {schedule && !inherited && (
                <div className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="grid gap-2 text-sm font-medium">
                            Response target (working minutes)
                            <Input
                                className="min-h-[44px]"
                                type="number"
                                min={1}
                                max={10080}
                                value={schedule.response_minutes}
                                onChange={(event) =>
                                    updateSchedule({
                                        response_minutes: Number(
                                            event.target.value,
                                        ),
                                    })
                                }
                            />
                        </label>
                        <label className="grid gap-2 text-sm font-medium">
                            Service timezone
                            <Input
                                className="min-h-[44px]"
                                value={schedule.timezone}
                                onChange={(event) =>
                                    updateSchedule({
                                        timezone: event.target.value,
                                    })
                                }
                            />
                        </label>
                    </div>
                    <fieldset className="space-y-3">
                        <legend className="mb-2 text-sm font-medium">
                            Service hours
                        </legend>
                        <p className="text-sm text-muted-foreground">
                            Use local opening and closing times. Split overnight
                            hours across two days; 24:00 means the end of the
                            day.
                        </p>
                        {schedule.weekly.map((slot, index) => (
                            <div
                                key={index}
                                className="grid grid-cols-2 gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_1fr_1fr_auto]"
                            >
                                <div className="grid gap-1 text-sm">
                                    <label htmlFor={`${fieldId}-day-${index}`}>
                                        Day
                                    </label>
                                    <Select
                                        value={String(slot.day)}
                                        disabled={disabled}
                                        onValueChange={(next) =>
                                            updateSchedule({
                                                weekly: schedule.weekly.map(
                                                    (entry, i) =>
                                                        i === index
                                                            ? {
                                                                  ...entry,
                                                                  day: Number(
                                                                      next,
                                                                  ),
                                                              }
                                                            : entry,
                                                ),
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            id={`${fieldId}-day-${index}`}
                                            className={`${control} min-w-0`}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {days.map((day, i) => (
                                                <SelectItem
                                                    key={day}
                                                    value={String(i + 1)}
                                                >
                                                    {day}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                {(['start', 'end'] as const).map((field) => (
                                    <label
                                        key={field}
                                        className="grid gap-1 text-sm"
                                    >
                                        {field === 'start' ? 'Opens' : 'Closes'}
                                        <Input
                                            className="min-h-[44px] min-w-0"
                                            aria-label={`${days[slot.day - 1]} ${field === 'start' ? 'opens' : 'closes'} ${index + 1}`}
                                            placeholder="HH:mm"
                                            maxLength={5}
                                            value={slot[field]}
                                            onChange={(event) =>
                                                updateSchedule({
                                                    weekly: schedule.weekly.map(
                                                        (entry, i) =>
                                                            i === index
                                                                ? {
                                                                      ...entry,
                                                                      [field]:
                                                                          event
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : entry,
                                                    ),
                                                })
                                            }
                                        />
                                    </label>
                                ))}
                                <Button
                                    variant="outline"
                                    className="min-h-[44px] self-end"
                                    aria-label={`Remove ${days[slot.day - 1]} interval ${index + 1}`}
                                    onClick={() =>
                                        updateSchedule({
                                            weekly: schedule.weekly.filter(
                                                (_, i) => i !== index,
                                            ),
                                        })
                                    }
                                >
                                    Remove
                                </Button>
                            </div>
                        ))}
                        <Button
                            variant="outline"
                            className="min-h-[44px]"
                            disabled={schedule.weekly.length >= 28}
                            onClick={() =>
                                updateSchedule({
                                    weekly: [
                                        ...schedule.weekly,
                                        {
                                            day: 1,
                                            start: '08:00',
                                            end: '18:00',
                                        },
                                    ],
                                })
                            }
                        >
                            Add opening interval
                        </Button>
                    </fieldset>
                    <label className="grid gap-2 text-sm font-medium">
                        Public holidays and closed dates
                        <textarea
                            className="min-h-[88px] w-full rounded-lg border bg-background p-3 font-normal"
                            rows={3}
                            placeholder="YYYY-MM-DD, one date per line"
                            value={schedule.holidays.join('\n')}
                            onChange={(event) =>
                                updateSchedule({
                                    holidays: event.target.value
                                        .replace(/\r/g, '')
                                        .split('\n'),
                                })
                            }
                        />
                    </label>
                    <p className="text-sm text-muted-foreground">
                        Enter each date the service is closed, including
                        observed public holidays. No holiday calendar is
                        assumed. Empty means no exclusions.
                    </p>
                </div>
            )}
            <p className="rounded-lg bg-muted/40 p-3 text-sm">
                Unconfigured services show no response-time promise. Configured
                clocks count working hours and exclude configured holidays.
                These are response targets, not emergency support.
            </p>
            <label className="grid gap-2 text-sm font-medium">
                Additional response wording
                <textarea
                    className="w-full rounded-lg border bg-background p-3 font-normal"
                    rows={3}
                    maxLength={500}
                    value={
                        override
                            ? (override.response_expectation ??
                              value.response_expectation)
                            : value.response_expectation
                    }
                    onChange={(event) =>
                        edit({ response_expectation: event.target.value })
                    }
                />
            </label>
            <p className="text-sm text-muted-foreground">
                Wording does not create a calculated deadline.
                {override !== null
                    ? ` ${override.response_expectation === undefined ? 'Wording is inherited.' : 'Wording is overridden for this site.'}`
                    : ''}
            </p>
            {override !== null &&
                Object.hasOwn(override, 'response_expectation') && (
                    <Button
                        variant="outline"
                        className="min-h-[44px]"
                        onClick={() => inherit('response_expectation')}
                    >
                        Reset wording to inherited
                    </Button>
                )}
        </fieldset>
    );
}
