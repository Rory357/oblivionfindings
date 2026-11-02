/**
 * Setup › Alerts & checks › Alert rules (pg-sdr-15g): who is told when a rule doesn't say, and
 * how. Quiet hours and the morning summary aren't built yet, so they aren't shown.
 */
import { OnOff, SettingGroup, SettingRow } from '@/components/referrals/settings-ui';
import { Note } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, Send, Users } from 'lucide-react';
import { ChannelGroup, RoleRow, type SettingsDraft } from './_settings';
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
                ]}
            />
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
            label={label}
            hint="From Rostering and clock-ins, at the moment it happens. Off, or nobody on shift: everyone at that Site who can acknowledge alerts"
            control={<OnOff label={label} checked={draft.value('alerts.safety.roster') === true} onChange={(next) => draft.set('alerts.safety.roster', next)} disabled={!row.can_change} />}
        />
    );
}
