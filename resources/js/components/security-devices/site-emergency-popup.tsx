/**
 * Pop-up sdr-86: lockdown or evacuation at a Site, and ending it (S&D Phase H). A warning confirm
 * with a red main button and a required reason. It names what happens to the doors, says people
 * inside can always leave (every door has an exit button and a handle), and who is told.
 *
 * Each is a governed command on the Site's UniFi Access console, through its hub, with every step
 * the framework asks for: confirm it's you, two-step sign-in, the typed name, and a second person's
 * approval (shown as waiting on the Access page). Nothing here skips one.
 */
import { PopupCallout, PopupConfirm, PopupField } from '@/components/popup';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { GovernedAction } from '@/pages/security-devices/devices/_safety-kit-types';
import { DoorOpen, Siren } from 'lucide-react';
import { useEffect, useState } from 'react';
import { confirmIdentity, UNCONFIRMED_WORDS, useSafetyRequest } from './safety-request';

export type SiteEmergencyMode = 'lockdown' | 'evacuate';

export type SiteEmergencySite = {
    deviceId: number;
    site: string;
    /** normal | lockdown | evacuation, as UniFi Access last said. */
    mode: string;
    /** The name the framework asks to be typed (the Site's doors). */
    typeName: string;
    lockdown: GovernedAction | null;
    evacuate: GovernedAction | null;
    end: GovernedAction | null;
    confirmUrl: string;
};

const RELOAD = ['emergency', 'doors', 'meters'];

const BODY: Record<SiteEmergencyMode, string> = {
    lockdown: 'Cards, fobs, PINs and phones stop opening the doors from outside until someone ends it. Everyone inside can still leave: every door opens from the inside, always.',
    evacuate: 'Every door unlocks so everyone can get out and the fire service can get in. They stay unlocked until someone ends the evacuation.',
};

export function SiteEmergency({ open, site, start = 'lockdown', onClose }: { open: boolean; site: SiteEmergencySite; start?: SiteEmergencyMode; onClose: () => void }) {
    const [mode, setMode] = useState<SiteEmergencyMode>(start);
    const [reason, setReason] = useState('');
    const [typed, setTyped] = useState('');
    const { state, send, check } = useSafetyRequest(open, `site-${mode}`);
    useEffect(() => {
        if (open) {
            setMode(start);
            setReason('');
            setTyped('');
        }
    }, [open, start]);
    const action = mode === 'lockdown' ? site.lockdown : site.evacuate;
    const stepUp = action?.step_up ?? false;
    const needsName = action?.type_name ?? false;
    const ready = action !== null && action.available && reason.trim().length >= 10 && (!needsName || typed.trim() === site.typeName);

    const submit = () => {
        if (state.unconfirmed) return check(RELOAD, onClose);
        if (stepUp) return confirmIdentity(site.confirmUrl);
        send(`/security-devices/devices/${site.deviceId}/safety/site/${mode}`, { reason: reason.trim(), confirmation_text: needsName ? typed.trim() : null }, onClose);
    };

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={submit}
            variant="warning"
            icon={Siren}
            confirmDestructive
            title={mode === 'lockdown' ? `Lockdown at ${site.site}?` : `Evacuate ${site.site}?`}
            description={<b className="font-semibold text-foreground">{BODY[mode]}</b>}
            error={state.unconfirmed ? undefined : (state.error ?? (action && !action.available ? (action.reason ?? undefined) : undefined))}
            confirmLabel={state.unconfirmed ? 'Check result' : stepUp ? 'Confirm it’s you' : mode === 'lockdown' ? 'Start lockdown' : 'Start evacuation'}
            processing={state.processing}
            processingLabel={state.unconfirmed ? 'Checking…' : 'Asking…'}
            confirmDisabled={action === null || !action.available || (!stepUp && !state.unconfirmed && !ready)}
            testId="sd-site-emergency"
        >
            {mode === 'evacuate' ? <PopupCallout tone="critical">If there’s a fire, call 111 first.</PopupCallout> : null}
            {state.unconfirmed ? <PopupCallout tone="warning">{UNCONFIRMED_WORDS}</PopupCallout> : null}
            <PopupField label="What do you need">
                <ToggleGroup
                    type="single"
                    variant="outline"
                    aria-label="What do you need"
                    value={mode}
                    onValueChange={(next) => {
                        if (next === 'lockdown' || next === 'evacuate') setMode(next);
                    }}
                    className="flex flex-wrap justify-start gap-1.5 rounded-none shadow-none data-[variant=outline]:shadow-none"
                >
                    <ToggleGroupItem value="lockdown" className="rounded-full! border! px-3 data-[state=on]:border-primary data-[state=on]:bg-primary-fill/10 data-[state=on]:text-primary">
                        Lockdown
                    </ToggleGroupItem>
                    <ToggleGroupItem value="evacuate" className="rounded-full! border! px-3 data-[state=on]:border-primary data-[state=on]:bg-primary-fill/10 data-[state=on]:text-primary">
                        Evacuation
                    </ToggleGroupItem>
                </ToggleGroup>
            </PopupField>
            {stepUp ? (
                <PopupCallout tone="info">For everyone’s safety, confirm it’s you (your password) first. You’ll come straight back here.</PopupCallout>
            ) : (
                <>
                    <PopupField label="Reason" htmlFor="sd-site-emergency-reason" required hint="Kept in the history and sent with the alert" error={state.fields.reason}>
                        <Textarea
                            id="sd-site-emergency-reason"
                            rows={2}
                            maxLength={1000}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            placeholder={mode === 'lockdown' ? 'e.g. Police asked us to keep the doors shut' : 'e.g. Smoke alarm going off in the kitchen'}
                        />
                    </PopupField>
                    {needsName ? (
                        <PopupField label={`Type ${site.typeName} to confirm`} htmlFor="sd-site-emergency-name" required error={state.fields.confirmation_text}>
                            <Input id="sd-site-emergency-name" value={typed} onChange={(event) => setTyped(event.target.value)} autoComplete="off" />
                        </PopupField>
                    ) : null}
                </>
            )}
            <PopupField label="Who is told">
                <p className="text-sm font-semibold text-foreground">Control Room, and the house manager and staff on shift at {site.site}</p>
            </PopupField>
            <PopupCallout tone="warning">
                Visitor passes stop too. It ends only when someone ends it here or in UniFi Access.
                {action?.approval ? ' A second person who approves device commands says yes first; you’ll see it waiting on the Access page.' : ''}
            </PopupCallout>
        </PopupConfirm>
    );
}

/** Ending a lockdown or evacuation: the doors go back to their usual schedules and lock. */
export function EndSiteEmergency({ open, site, onClose }: { open: boolean; site: SiteEmergencySite; onClose: () => void }) {
    const [reason, setReason] = useState('');
    const { state, send, check } = useSafetyRequest(open, 'site-end');
    useEffect(() => {
        if (open) setReason('');
    }, [open]);
    const action = site.end;
    const stepUp = action?.step_up ?? false;
    const what = site.mode === 'evacuation' ? 'evacuation' : 'lockdown';

    const submit = () => {
        if (state.unconfirmed) return check(RELOAD, onClose);
        if (stepUp) return confirmIdentity(site.confirmUrl);
        send(`/security-devices/devices/${site.deviceId}/safety/site/end`, { reason: reason.trim() }, onClose);
    };

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={submit}
            variant="warning"
            icon={DoorOpen}
            title={`End the ${what} at ${site.site}?`}
            description="The doors go back to their usual schedules and lock again. Everyone on shift is told."
            error={state.unconfirmed ? undefined : (state.error ?? (action && !action.available ? (action.reason ?? undefined) : undefined))}
            confirmLabel={state.unconfirmed ? 'Check result' : stepUp ? 'Confirm it’s you' : `End ${what}`}
            processing={state.processing}
            processingLabel={state.unconfirmed ? 'Checking…' : 'Asking…'}
            confirmDisabled={action === null || !action.available || (!stepUp && !state.unconfirmed && reason.trim().length < 10)}
            testId="sd-site-emergency-end"
        >
            {state.unconfirmed ? <PopupCallout tone="warning">{UNCONFIRMED_WORDS}</PopupCallout> : null}
            {stepUp ? (
                <PopupCallout tone="info">For everyone’s safety, confirm it’s you (your password) first. You’ll come straight back here.</PopupCallout>
            ) : (
                <PopupField label="Reason" htmlFor="sd-site-emergency-end-reason" required hint="Kept in the history" error={state.fields.reason}>
                    <Textarea
                        id="sd-site-emergency-end-reason"
                        rows={2}
                        maxLength={1000}
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        placeholder="e.g. Fire service says it’s safe to go back in"
                    />
                </PopupField>
            )}
        </PopupConfirm>
    );
}
