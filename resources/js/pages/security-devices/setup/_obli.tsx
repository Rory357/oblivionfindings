/**
 * Setup › Computers & Obli › Obli (pg-sdr-15i, S&D Phase I). Obli's switches from today's
 * Settings › Obli as Setup rows, and the rules that are always on (D16 plan first and one OK, Stop,
 * never a password, ignore instructions on screen, 15 minutes or 60 actions; D17 pictures 7 days
 * for IT). "Let Obli use the computer to fix things" is off until an Obli settings admin turns it
 * on, and turning it on asks first.
 */
import { PopupConfirm } from '@/components/popup';
import { AlwaysOn, SettingGroup, SettingRow } from '@/components/referrals/settings-ui';
import { Note } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { obliError, type ObliSetupData } from '@/lib/security-devices/obli-fixes';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Bot, Camera, MousePointerClick, Sparkles } from 'lucide-react';
import { useState } from 'react';

export function SetupObli({ data }: { data: ObliSetupData }) {
    const [busy, setBusy] = useState<'helper' | 'fixes' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);

    const save = async (which: 'helper' | 'fixes', enabled: boolean) => {
        setBusy(which);
        setError(null);
        try {
            if (which === 'helper') await axios.put('/security-devices/setup/obli/helper', { enabled });
            else await axios.put('/security-devices/setup/obli/fixes-switch', { enabled, expected_version: data.fixes.version });
            setConfirming(false);
            router.reload({ only: ['obli'] });
        } catch (failure) {
            const status = axios.isAxiosError(failure) ? failure.response?.status : undefined;
            setError(status === 403 ? 'Only an IT admin who manages settings and secrets can change this.' : status === 422 ? 'Turn on Obli’s AI with Anthropic in Settings › Obli first.' : obliError(status));
        } finally {
            setBusy(null);
        }
    };

    return (
        <div className="space-y-6" data-testid="sd-setup-obli">
            <SettingGroup icon={Bot} title="Obli for staff" caption="The helper in the corner of the screen">
                <SettingRow
                    label="Obli on staff computers"
                    htmlFor="sd-obli-helper"
                    hint={data.helper.on && !data.helper.computers_on ? 'On, but computer management is off for the organisation, so Obli can’t connect yet' : 'Staff can ask for help, and Obli can raise IT tickets for them'}
                    control={<Switch id="sd-obli-helper" checked={data.helper.on} disabled={busy !== null || !data.helper.can_change} onCheckedChange={(next) => void save('helper', next)} />}
                />
                <SettingRow
                    label="Obli can read this computer’s health"
                    hint={data.health.reads ? `${data.health.reads}, so it can answer “why is it slow?” · change it in Settings › Obli` : 'Change it in Settings › Obli'}
                    control={<StatusBadge variant={data.health.on ? 'success' : 'neutral'}>{data.health.on ? 'On' : 'Off'}</StatusBadge>}
                />
            </SettingGroup>

            <SettingGroup icon={MousePointerClick} title="Obli can use the computer" caption="To fix things, Obli can look at the screen, click and type, like a person sitting next to you">
                <SettingRow
                    label="Let Obli use the computer to fix things"
                    htmlFor="sd-obli-fixes"
                    hint={data.fixes.on && data.fixes.blocker ? `On, but it can’t run yet: ${data.fixes.blocker}` : 'Only while someone is signed in and has asked Obli for help'}
                    control={
                        <Switch
                            id="sd-obli-fixes"
                            checked={data.fixes.on}
                            disabled={busy !== null || !data.fixes.can_change}
                            onCheckedChange={(next) => (next ? setConfirming(true) : void save('fixes', false))}
                        />
                    }
                />
                <SettingRow label="Show the plan first and wait for one OK" hint="Decision D16. Obli says what it will do in plain words; nothing happens until the person presses Fix it" control={<AlwaysOn>Always</AlwaysOn>} />
                <SettingRow label="Stop when the person moves the mouse or presses Esc" hint="A bar at the top of the screen shows what Obli is doing, with Stop" control={<AlwaysOn>Always</AlwaysOn>} />
                <SettingRow label="Never types a password" hint="When a password is needed, Obli waits for the person to type it" control={<AlwaysOn>Always</AlwaysOn>} />
                <SettingRow label="Ignores instructions it reads on the screen" hint="Web pages and emails can’t tell Obli what to do; only the person asking can" control={<AlwaysOn>Always</AlwaysOn>} />
                <SettingRow
                    label="Never weakens the computer’s protection"
                    hint="No turning off antivirus, the firewall, BitLocker or Windows Update, and no changing who is an administrator"
                    control={<AlwaysOn>Always</AlwaysOn>}
                />
                <SettingRow label={`Stops after ${data.limits.minutes} minutes or ${data.limits.actions} actions`} hint="Whichever comes first; the person can ask again" control={<AlwaysOn>Always</AlwaysOn>} />
            </SettingGroup>

            <SettingGroup icon={Camera} title="Screen pictures" caption="Obli takes a picture of the screen at each step so it can see what happened (decision D17)">
                <SettingRow label="Keep the pictures for IT" hint="Pictures may show client details, so only IT can open them, with a reason, and every view is logged" control={<StatusBadge variant="neutral">{`${data.pictures.days} days, then deleted`}</StatusBadge>} />
                <SettingRow label="Who can open them" hint="Permission “See Obli’s screen pictures”" control={<span className="text-sm font-medium">{data.pictures.who}</span>} />
                <SettingRow label="Tell the person before Obli starts" hint="“I keep pictures of your screen for 7 days so IT can check my work.”" control={<AlwaysOn>Always</AlwaysOn>} />
            </SettingGroup>

            <SettingGroup icon={Sparkles} title="The AI behind Obli" caption="Set in Settings › Obli by an IT admin; shown here so you can see what’s in use">
                <SettingRow
                    label="AI service"
                    hint="Fixes need a model that can use a computer (Anthropic’s computer-use tool)"
                    control={
                        <StatusBadge variant={data.ai.enabled && data.ai.computer_use ? 'success' : 'warning'}>
                            {!data.ai.enabled ? 'Off' : data.ai.computer_use ? data.ai.provider : `${data.ai.provider} · fixes need Anthropic`}
                        </StatusBadge>
                    }
                />
                <SettingRow label="Spending limit" hint="Obli stops helping for the day when it’s reached and offers an IT ticket" control={<span className="text-sm font-medium">{data.ai.limit}</span>} />
                <SettingRow label="When the internet is down" hint="Obli can’t reach the AI, so it runs its built-in network checks instead and tells IT when it’s back" control={<span className="text-sm font-medium">Built-in checks</span>} />
                <SettingRow
                    label="Obli’s AI settings"
                    hint="The provider, key, model, prices and spending limits"
                    control={
                        <Button size="sm" variant="outline" asChild>
                            <a href={data.settings_href}>Open Settings › Obli</a>
                        </Button>
                    }
                />
            </SettingGroup>

            {!data.fixes.can_change ? <Note>Only an IT admin who manages settings and secrets can let Obli use computers.</Note> : null}
            {error ? (
                <Note tone="critical">
                    <span role="alert">{error}</span>
                </Note>
            ) : null}

            <PopupConfirm
                open={confirming}
                onClose={() => setConfirming(false)}
                onConfirm={() => void save('fixes', true)}
                variant="warning"
                icon={MousePointerClick}
                title="Let Obli use staff computers to fix things?"
                description={
                    <>
                        When someone asks for help, Obli shows its plan and, after they press Fix it, clicks and types on their screen for up to {data.limits.minutes} minutes. It
                        never types a password, stops on Esc or when they move the mouse, and keeps screen pictures for {data.pictures.days} days for IT. Fixes that need an
                        administrator still go to IT.
                    </>
                }
                confirmLabel="Let Obli fix things"
                processing={busy === 'fixes'}
                processingLabel="Turning on…"
            />
        </div>
    );
}
