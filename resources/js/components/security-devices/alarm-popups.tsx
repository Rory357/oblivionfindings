/**
 * The alarm panel's pop-ups (S&D rebuild drawings sdr-17 Disarm …? and its Arm …? variant, and sdr-71
 * Arm an area), on the pop-up kit at kit widths. Offered only where the panel's maker allows Oblivion
 * to arm and disarm it (Inner Range Inception, Gallagher) and only to someone with the key for it;
 * Ajax and other report-only panels never show them.
 *
 * Each states its effect before anything is sent. They are today's governed commands: when the
 * framework wants the person to confirm it's them first, a second person's approval, or the alarm's
 * name typed for a disarm, the pop-up says so and asks for it. Nothing is loosened here.
 */
import { PopupCallout, PopupConfirm, PopupField, PopupForm } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { AlarmArea, AlarmZone, GovernedAction } from '@/pages/security-devices/devices/_safety-kit-types';
import { ShieldCheck, ShieldOff, Siren } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { confirmIdentity, UNCONFIRMED_WORDS, useSafetyRequest } from './safety-request';

export type AlarmPopupPanel = {
    id: number;
    name: string;
    model: string;
    brand: string | null;
    stateLabel: string;
};

const RELOAD = ['control', 'panel', 'areas', 'overview', 'meters'];

/** The open zones a person should close before arming, by name. */
function openZones(zones: AlarmZone[], areas?: AlarmArea[]): AlarmZone[] {
    const names = areas ? new Set(areas.map((area) => area.name)) : null;
    return zones.filter((zone) => zone.state === 'open' && (names === null || zone.area === null || names.has(zone.area)));
}

function openZoneWarning(zones: AlarmZone[]): string | null {
    if (zones.length === 0) return null;
    const names = zones.map((zone) => zone.name);
    const list = names.length === 1 ? names[0] : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
    return `${list} ${zones.length === 1 ? 'is' : 'are'} open. Close ${zones.length === 1 ? 'it' : 'them'} first, or ${zones.length === 1 ? 'it' : 'they'} won’t be watched while armed.`;
}

/** What the governed framework will ask for, said once in the pop-up. */
function GovernanceNotes({ action, verb }: { action: GovernedAction; verb: string }) {
    return (
        <>
            {action.step_up ? (
                <PopupCallout tone="info">For your safety, confirm it’s you (your password) before you {verb}. You’ll come straight back here.</PopupCallout>
            ) : null}
            {action.approval ? (
                <PopupCallout tone="info">A second person who approves device commands needs to say yes before it’s sent. You’ll see it waiting on this page.</PopupCallout>
            ) : null}
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  17 (also) · Arm …?                                                 */
/* ------------------------------------------------------------------ */

export function ArmAlarm({
    open,
    panel,
    action,
    areas,
    zones,
    confirmUrl,
    onChooseAreas,
    onClose,
}: {
    open: boolean;
    panel: AlarmPopupPanel;
    action: GovernedAction;
    areas: AlarmArea[];
    zones: AlarmZone[];
    confirmUrl: string;
    onChooseAreas: (() => void) | null;
    onClose: () => void;
}) {
    const { state, send, check } = useSafetyRequest(open, 'arm');
    const warning = openZoneWarning(openZones(zones));
    const stepUp = action.step_up;

    const submit = () => {
        if (state.unconfirmed) return check(RELOAD, onClose);
        if (stepUp) return confirmIdentity(confirmUrl);
        send(`/security-devices/devices/${panel.id}/safety/arm`, { areas: areas.map((area) => area.id) }, onClose);
    };

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={submit}
            variant="plain"
            icon={ShieldCheck}
            title={`Arm ${panel.name}?`}
            description={
                <>
                    <b className="font-semibold text-foreground">{panel.name}</b> will arm {areas.length > 1 ? 'all areas' : 'the whole house'}. Anyone still inside should leave before the exit delay ends.
                </>
            }
            error={state.unconfirmed ? undefined : state.error}
            confirmLabel={state.unconfirmed ? 'Check result' : stepUp ? 'Confirm it’s you' : 'Arm'}
            processing={state.processing}
            processingLabel={state.unconfirmed ? 'Checking…' : 'Arming…'}
            confirmDisabled={!action.available}
            secondary={
                onChooseAreas && !state.unconfirmed ? (
                    <Button type="button" variant="outline" onClick={onChooseAreas} disabled={state.processing}>
                        Choose areas…
                    </Button>
                ) : null
            }
            testId="sd-arm-alarm"
        >
            {state.unconfirmed ? <PopupCallout tone="warning">{UNCONFIRMED_WORDS}</PopupCallout> : null}
            {!action.available && action.reason ? <PopupCallout tone="warning">{action.reason}</PopupCallout> : null}
            {warning ? <PopupCallout tone="warning">{warning}</PopupCallout> : null}
            <GovernanceNotes action={action} verb="arm it" />
        </PopupConfirm>
    );
}

/* ------------------------------------------------------------------ */
/*  17 · Disarm …?                                                     */
/* ------------------------------------------------------------------ */

export function DisarmAlarm({
    open,
    panel,
    action,
    areas,
    confirmUrl,
    onClose,
}: {
    open: boolean;
    panel: AlarmPopupPanel;
    action: GovernedAction;
    /** The areas that will be disarmed: those armed now, or all of them. */
    areas: AlarmArea[];
    confirmUrl: string;
    onClose: () => void;
}) {
    const { state, send, check } = useSafetyRequest(open, 'disarm');
    const [reason, setReason] = useState('');
    const [typed, setTyped] = useState('');
    useEffect(() => {
        if (open) {
            setReason('');
            setTyped('');
        }
    }, [open]);
    const stepUp = action.step_up;
    const nameOk = !action.type_name || typed.trim() === panel.name;
    const reasonOk = reason.trim().length >= 10;

    const submit = () => {
        if (state.unconfirmed) return check(RELOAD, onClose);
        if (stepUp) return confirmIdentity(confirmUrl);
        send(
            `/security-devices/devices/${panel.id}/safety/disarm`,
            { areas: areas.map((area) => area.id), reason: reason.trim(), ...(action.type_name ? { confirmation_text: typed.trim() } : {}) },
            onClose,
        );
    };

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={submit}
            variant="destructive"
            icon={ShieldOff}
            title={`Disarm ${panel.name}?`}
            description={
                <>
                    <b className="font-semibold text-foreground">{panel.name}</b> will be disarmed
                    {areas.length > 1 ? ` in ${areas.length} areas` : ''}, so it stops watching for intruders until someone arms it again.
                </>
            }
            error={state.unconfirmed ? undefined : state.error}
            confirmLabel={state.unconfirmed ? 'Check result' : stepUp ? 'Confirm it’s you' : 'Disarm'}
            processing={state.processing}
            processingLabel={state.unconfirmed ? 'Checking…' : 'Disarming…'}
            confirmDisabled={!action.available || (!stepUp && !state.unconfirmed && (!reasonOk || !nameOk))}
            testId="sd-disarm-alarm"
        >
            {state.unconfirmed ? <PopupCallout tone="warning">{UNCONFIRMED_WORDS}</PopupCallout> : null}
            {!action.available && action.reason ? <PopupCallout tone="warning">{action.reason}</PopupCallout> : null}
            {stepUp ? null : (
                <>
                    <PopupField label="Reason" htmlFor="sd-disarm-reason" required hint="Kept in the alarm’s history" error={state.fields.reason}>
                        <Textarea
                            id="sd-disarm-reason"
                            rows={2}
                            maxLength={1000}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            placeholder="e.g. Staff arriving for the 10 am shift"
                        />
                    </PopupField>
                    {action.type_name ? (
                        <PopupField
                            label="Type the alarm’s name to confirm"
                            htmlFor="sd-disarm-name"
                            required
                            hint={`Oblivion asks for this before every disarm: ${panel.name}`}
                            error={state.fields.confirmation_text}
                        >
                            <Input id="sd-disarm-name" autoComplete="off" value={typed} onChange={(event) => setTyped(event.target.value)} />
                        </PopupField>
                    ) : null}
                </>
            )}
            <GovernanceNotes action={action} verb="disarm it" />
        </PopupConfirm>
    );
}

/* ------------------------------------------------------------------ */
/*  71 · Arm an area                                                   */
/* ------------------------------------------------------------------ */

/** Inside motion would go off with people at home, so it starts unticked (drawing sdr-71). */
const insideArea = (area: AlarmArea) => /inside|motion/i.test(area.name);

function areaHint(area: AlarmArea, zones: AlarmZone[]): string {
    if (area.armed) return 'armed already';
    if (insideArea(area)) return 'leave off while people are home';
    const own = zones.filter((zone) => zone.area === area.name);
    const open = own.filter((zone) => zone.state === 'open');
    if (open.length > 0) return `${open.length} open: ${open.map((zone) => zone.name.toLowerCase()).join(', ')}`;
    return own.length > 0 ? 'all closed' : 'no zone reports yet';
}

export function ArmArea({
    open,
    panel,
    action,
    areas,
    zones,
    confirmUrl,
    onClose,
}: {
    open: boolean;
    panel: AlarmPopupPanel;
    action: GovernedAction;
    areas: AlarmArea[];
    zones: AlarmZone[];
    confirmUrl: string;
    onClose: () => void;
}) {
    const { state, send, check } = useSafetyRequest(open, 'arm-area');
    const initial = useMemo(() => areas.filter((area) => !area.armed && !insideArea(area)).map((area) => area.id), [areas]);
    const [chosen, setChosen] = useState<string[]>(initial);
    useEffect(() => {
        if (open) setChosen(initial);
    }, [open, initial]);
    const picked = areas.filter((area) => chosen.includes(area.id));
    const warning = openZoneWarning(openZones(zones, picked));
    const stepUp = action.step_up;

    const submit = () => {
        if (state.unconfirmed) return check(RELOAD, onClose);
        if (stepUp) return confirmIdentity(confirmUrl);
        send(`/security-devices/devices/${panel.id}/safety/arm`, { areas: chosen }, onClose);
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="sm"
            icon={ShieldCheck}
            title="Arm an area"
            description="Arm part of the house, like the outside doors at night"
            context={{
                title: panel.name,
                tag: panel.stateLabel,
                note: [panel.model, panel.brand ? `through ${panel.brand}’s own interface` : null].filter(Boolean).join(' · '),
                icon: Siren,
            }}
            error={state.unconfirmed ? undefined : state.error}
            callout={state.unconfirmed ? { tone: 'warning', content: UNCONFIRMED_WORDS } : !action.available && action.reason ? { tone: 'warning', content: action.reason } : undefined}
            submitLabel={state.unconfirmed ? 'Check result' : stepUp ? 'Confirm it’s you' : 'Arm'}
            processing={state.processing}
            processingLabel={state.unconfirmed ? 'Checking…' : 'Arming…'}
            submitDisabled={!action.available || (!stepUp && !state.unconfirmed && chosen.length === 0)}
            onSubmit={submit}
            testId="sd-arm-area"
        >
            <fieldset className="grid gap-2.5">
                <legend className="sr-only">Areas to arm</legend>
                {areas.map((area) => {
                    const id = `sd-arm-area-${area.id}`;
                    const disabled = area.armed === true;
                    return (
                        <div key={area.id} className="flex items-start gap-2.5">
                            <Checkbox
                                id={id}
                                checked={!disabled && chosen.includes(area.id)}
                                disabled={disabled || state.processing}
                                onCheckedChange={(value) =>
                                    setChosen((current) => (value === true ? [...current, area.id] : current.filter((key) => key !== area.id)))
                                }
                                className="mt-0.5"
                            />
                            <Label htmlFor={id} className="grid gap-0.5 font-normal">
                                <span className="text-sm font-semibold text-foreground">{area.name}</span>
                                <span className="text-caption">{areaHint(area, zones)}</span>
                            </Label>
                        </div>
                    );
                })}
            </fieldset>
            {state.fields.areas ? <p className="text-xs text-status-critical">{state.fields.areas}</p> : null}
            {warning ? <PopupCallout tone="warning">{warning}</PopupCallout> : null}
            <GovernanceNotes action={action} verb="arm it" />
        </PopupForm>
    );
}
