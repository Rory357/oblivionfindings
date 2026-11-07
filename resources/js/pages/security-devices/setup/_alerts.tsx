/**
 * Setup › Alerts & checks › Alert rules (pg-sdr-15g): who is told when a rule doesn't say, how
 * each notice goes out (including the 6:00 am summary), and overnight quiet hours for device
 * alerts. Safety alerts never wait, nor does a router, gateway or hub that stops answering.
 */
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { OnOff, SettingGroup, SettingRow } from '@/components/referrals/settings-ui';
import { Note } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, Moon, Send, ShieldAlert, Users } from 'lucide-react';
import { ChannelGroup, formatClock, labelWithBadge, MinutesRow, QUIET_HOURS_HINT, RoleRow, SETTING_WORDS, type SettingsDraft } from './_settings';
import type { SetupProps } from './_types';

export function SetupAlertRules({ data, draft }: { data: NonNullable<SetupProps['alerts']>; draft: SettingsDraft }) {
    return (
        <div className="space-y-5">
            <SettingGroup icon={Users} title="Who is told when a rule doesn’t say" caption="Defaults for every Site; each rule can change them">
                <SettingRow
                    label="Safety alerts"
                    hint="Panic button, duress, alarm going off, possible fall, water leak, door forced open, tampering"
                    control={<span className="text-subtle">Staff on shift at that house · Control Room straight away</span>}
                />
                <RoleRow settingKey="alerts.devices.role" draft={draft} />
                <SettingRow
                    label="Low batteries"
                    hint="Below 20% unless a sensor has its own limit · panic buttons, alarms, doors, cameras and care sensors also tell staff on shift straight away"
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
            <SafetyAndCareGroup draft={draft} />
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

/**
 * Safety fix round (7 Nov 2026): when an unanswered safety or care alert goes to the on-call manager,
 * and whether these alerts may be snoozed at all (Stephan: "Default not snoozable but there should be
 * a setting to enable snooze and be able to set time"). A longer time or allowing snoozes loosens a
 * safety check: Review changes asks why, and Change history keeps it.
 */
function SafetyAndCareGroup({ draft }: { draft: SettingsDraft }) {
    const snooze = draft.settings?.['alerts.safety.snooze'];
    if (!snooze || snooze.type !== 'bool') return null;
    const allowed = draft.value('alerts.safety.snooze') === true;
    const label = SETTING_WORDS['alerts.safety.snooze'].label;

    return (
        <SettingGroup icon={ShieldAlert} title="If nobody says they’re on it" caption="Safety and care alerts at a house: the staff on shift hear first, then the on-call manager after">
            <MinutesRow settingKey="alerts.escalate.urgent" draft={draft} />
            <MinutesRow settingKey="alerts.escalate.care" draft={draft} />
            <SettingRow
                label={labelWithBadge(label, draft, ['alerts.safety.snooze'])}
                hint={snooze.can_change ? SETTING_WORDS['alerts.safety.snooze'].hint : `${SETTING_WORDS['alerts.safety.snooze'].hint}. You can’t change this here.`}
                control={<OnOff label={label} checked={allowed} onChange={(next) => draft.set('alerts.safety.snooze', next)} disabled={!snooze.can_change} />}
            />
            <MinutesRow settingKey="alerts.safety.snooze_max" draft={draft} disabled={!allowed} disabledText="Not snoozable" />
        </SettingGroup>
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
            caption="Device alerts wait for the 6:00 am summary; safety alerts never wait"
        >
            <SettingRow
                label={labelWithBadge('Hold device alerts overnight', draft, ['alerts.quiet_hours.on'])}
                hint={QUIET_HOURS_HINT}
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
