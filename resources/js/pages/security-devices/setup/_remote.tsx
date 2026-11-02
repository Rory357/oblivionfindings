/**
 * Setup › Computers & Obli › Remote support (pg-sdr-15k, S&D Phase G). "Allow remote support" is
 * today's remote support setting (also on Settings › Obli). The person at the computer always has
 * to click Allow, and sessions are never recorded (D7): facts, not switches. The relay status is
 * honest. Obli's own tabs come with Phase I, so they aren't shown.
 */
import { AlwaysOn, SettingGroup, SettingRow } from '@/components/referrals/settings-ui';
import { Note } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { MonitorSmartphone, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import type { RemoteSetupData } from './_types';

export function SetupRemote({ data }: { data: RemoteSetupData }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const change = async (allowed: boolean) => {
        setBusy(true);
        setError(null);
        try {
            await axios.put('/security-devices/setup/computers/remote-support', { allowed });
            router.reload({ only: ['remote'] });
        } catch (failure) {
            const status = axios.isAxiosError(failure) ? failure.response?.status : undefined;
            setError(status === 403 ? 'Only an IT admin who manages settings and secrets can change this.' : status === 409 ? 'Someone else changed remote support. Reload and try again.' : 'It wasn’t changed. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="space-y-6" data-testid="sd-setup-remote">
            <SettingGroup icon={MonitorSmartphone} title="Remote support" caption="IT sees (and, where allowed, uses) a staff computer’s screen, through Obli on that computer">
                <SettingRow
                    label="Allow remote support"
                    htmlFor="sd-remote-allowed"
                    hint={
                        data.allowed && !data.computers_on
                            ? 'On, but computer management is off for the organisation, so nobody can start it yet'
                            : `IT admins only. ${data.sessions_30_days === 1 ? '1 session' : `${data.sessions_30_days} sessions`} allowed in the last 30 days`
                    }
                    control={<Switch id="sd-remote-allowed" checked={data.allowed} disabled={busy || !data.can_change} onCheckedChange={(next) => void change(next)} />}
                />
                <SettingRow label="The person at the computer must click Allow" hint="Every time, or as IT set for that computer; they can stop it any time" control={<AlwaysOn />} />
                <SettingRow
                    label="Record sessions"
                    hint="Off: only who connected, when and for how long are kept (decision D7). Oblivion can’t record a session"
                    control={<StatusBadge variant="neutral">Never</StatusBadge>}
                />
            </SettingGroup>
            <SettingGroup icon={ShieldCheck} title="How it connects" caption="Through a relay, so no computer is opened to the internet">
                <SettingRow
                    label="Relay"
                    hint={data.relay_ready ? `${data.relays === 1 ? '1 relay' : `${data.relays} relays`} set up` : 'Remote support needs a relay (TURN) set up in Settings › Obli'}
                    control={<StatusBadge variant={data.relay_ready ? 'success' : 'warning'}>{data.relay_ready ? 'Relay set up' : 'No relay set up'}</StatusBadge>}
                />
                <SettingRow
                    label="Relay and Obli settings"
                    hint="The relay address and secret, and Obli’s own switches"
                    control={
                        <Button size="sm" variant="outline" asChild>
                            <a href={data.settings_href}>Open Settings › Obli</a>
                        </Button>
                    }
                />
            </SettingGroup>
            {!data.can_change ? <Note>Only an IT admin who manages settings and secrets can turn remote support on or off.</Note> : null}
            {error ? (
                <Note tone="critical">
                    <span role="alert">{error}</span>
                </Note>
            ) : null}
        </div>
    );
}
