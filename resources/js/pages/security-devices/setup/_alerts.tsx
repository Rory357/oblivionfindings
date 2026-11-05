/**
 * Setup › Alerts & checks › Alert rules (pg-sdr-15g): who is told when a rule doesn't say, how
 * each notice goes out (including the 6:00 am summary), and overnight quiet hours for device and
 * hub alerts. Safety alerts never wait.
 */
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { OnOff, SettingGroup, SettingRow } from '@/components/referrals/settings-ui';
import { Note } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, Moon, Send, Users } from 'lucide-react';
import { ChannelGroup, formatClock, labelWithBadge, RoleRow, type SettingsDraft } from './_settings';
import type { SetupProps } from './_types';

export function SetupAlertRules({ data, draft }: { data: NonNullable<SetupProps['alerts']>; draft: SettingsDraft }) {
    return (
        <div className="space-y-5">
            <SettingGroup icon={Users} title="Who is told when a rule doesn’t say" caption="Defaults for every Site; each rule can change them">
                <SettingRow
                    label="Safety alerts"
                    hint="Panic button, alarm going off, water leak, door forced open, tampering"
                    control={<span className="text-subtle">Staff on shift at that house · Control Room straight away</span>}
                />
                <RoleRow settingKey="alerts.devices.role" draft={draft} />
                <SettingRow
                    label="Low batteries"
                    hint="Sensors and care trackers below 20%"
                    control={<span className="text-subtle">One summary at 6:00 am</span>}
                />
                <RosterRow draft={draft} />
            </SettingGroup>
            <ChannelGroup
                icon={Send}
                title="How people are told"
                caption="Push reaches people who turned on notifications on their phone or browser"
                draft={draft}
                rows={[
                    {
                        label: 'Safety alerts',
                        hint: 'Always in the app; can’t be turned off',
                        keys: [null, 'alerts.safety.email', 'alerts.safety.push'],
                    },
                    {
                        label: 'Devices and hubs not answering',
                        hint: 'Whoever is told above',
                        keys: ['alerts.devices.app', 'alerts.devices.email', 'alerts.devices.push'],
                    },
                    {
                        label: 'New device found by a scan',
                        hint: 'People who manage connected services at that Site · one notice per scan',
                        keys: ['notify.new_devices.app', 'notify.new_devices.email', 'notify.new_devices.push'],
                    },
                    {
                        label: 'Daily summary',
                        hint: 'Low batteries, overnight alerts and hub updates, at 6:00 am',
                        keys: ['alerts.summary.app', 'alerts.summary.email', 'alerts.summary.push'],
                    },
                ]}
            />
            <QuietHoursGroup draft={draft} />
            <Note>
                {data.rules === 0 ? 'No rules of your own yet, so these defaults apply everywhere.' : data.rules === 1 ? '1 rule of your own changes these for some devices.' : `${data.rules} rules of your own change these for some devices.`}{' '}
                <Button variant="link" className="h-auto px-0" asChild>
                    <Link href="/security-devices/alerts?tab=rules">
                        Open Rules & automations <ArrowUpRight className="size-4" />
                    </Link>
                </Button>
            </Note>
        </div>
    );
}

/** "Use the roster to find who is on shift" inside the "Who is told" group. */
function RosterRow({ draft }: { draft: SettingsDraft }) {
    const row = draft.settings?.['alerts.safety.roster'];
    if (!row || row.type !== 'bool') return null;
    const label = 'Use the roster to find who is on shift';

    return (
        <SettingRow
            label={labelWithBadge(label, draft, ['alerts.safety.roster'])}
            hint="From Rostering and clock-ins, at the moment it happens. Off, or nobody on shift: everyone at that Site who can acknowledge alerts"
            control={<OnOff label={label} checked={draft.value('alerts.safety.roster') === true} onChange={(next) => draft.set('alerts.safety.roster', next)} disabled={!row.can_change} />}
        />
    );
}

function QuietHoursGroup({ draft }: { draft: SettingsDraft }) {
    const toggle = draft.settings?.['alerts.quiet_hours.on'];
    const startRow = draft.settings?.['alerts.quiet_hours.start'];
    const endRow = draft.settings?.['alerts.quiet_hours.end'];
    if (!toggle || toggle.type !== 'bool' || !startRow || startRow.type !== 'time' || !endRow || endRow.type !== 'time') {
        return null;
    }
    const on = draft.value('alerts.quiet_hours.on') === true;
    const startValue = typeof draft.value('alerts.quiet_hours.start') === 'string' ? (draft.value('alerts.quiet_hours.start') as string) : '22:00';
    const endValue = typeof draft.value('alerts.quiet_hours.end') === 'string' ? (draft.value('alerts.quiet_hours.end') as string) : '06:00';
    const canEditTimes = on && startRow.can_change && endRow.can_change;

    return (
        <SettingGroup
            icon={Moon}
            title="Quiet hours"
            caption="Device and hub alerts wait for the 6:00 am summary; safety alerts never wait"
        >
            <SettingRow
                label={labelWithBadge('Hold device alerts overnight', draft, ['alerts.quiet_hours.on'])}
                hint="Safety alerts, care rules, emergencies and internet-exposure checks never wait"
                control={
                    <OnOff
                        label="Hold device alerts overnight"
                        checked={on}
                        onChange={(next) => draft.set('alerts.quiet_hours.on', next)}
                        disabled={!toggle.can_change}
                    />
                }
            />
            <SettingRow
                label={labelWithBadge('Quiet hours', draft, ['alerts.quiet_hours.start', 'alerts.quiet_hours.end'])}
                hint="NZ time · alerts still show on Alerts right away, and go out together in the morning summary"
                control={
                    canEditTimes ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="w-44">
                                <TimePicker
                                    id="setting-quiet-hours-start"
                                    label="Quiet hours start"
                                    value={startValue}
                                    onChange={(next) => draft.set('alerts.quiet_hours.start', next)}
                                    compact
                                />
                            </div>
                            <span className="text-sm text-muted-foreground">until</span>
                            <div className="w-44">
                                <TimePicker
                                    id="setting-quiet-hours-end"
                                    label="Quiet hours end"
                                    value={endValue}
                                    onChange={(next) => draft.set('alerts.quiet_hours.end', next)}
                                    compact
                                />
                            </div>
                        </div>
                    ) : (
                        <span className="text-subtle">
                            {on ? `${formatClock(startValue)} until ${formatClock(endValue)}` : 'Off'}
                        </span>
                    )
                }
            />
        </SettingGroup>
    );
}
