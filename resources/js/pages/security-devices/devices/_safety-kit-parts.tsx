/**
 * What the alarm panel's page and the door's page share (S&D rebuild Phase E): the record header,
 * the line for each recent arm, disarm or unlock ("Checking…" until the device confirms it), and the
 * quiet refresh that reads the state back while a command is on its way.
 */
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
} from '@/components/page';
import { Ring, type SgTone } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import type { StatusVariant } from '@/components/ui/status-badge';
import { agoWords, timedText } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import { BellPlus, Pencil, type LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import type { CommandPhase, SafetyCommand, SafetyKitDevice, SafetyKitMeter } from './_safety-kit-types';

export function useNow(): Date {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 30_000);
        return () => window.clearInterval(timer);
    }, []);
    return now;
}

export function SafetyHeader<T extends string>({
    device,
    icon,
    chip,
    meters,
    tabs,
    tab,
    now,
    canEdit,
    watchable,
    onEdit,
    onWatch,
    onTab,
}: {
    device: SafetyKitDevice;
    icon: LucideIcon;
    chip: { label: string; tone: StatusVariant };
    meters: SafetyKitMeter[];
    tabs: { key: T; label: string }[];
    tab: T;
    now: Date;
    canEdit: boolean;
    watchable: boolean;
    onEdit: () => void;
    onWatch: () => void;
    onTab: (tab: T) => void;
}) {
    return (
        <PageHeader
            variant="profile"
            backHref={device.back.href}
            icon={icon}
            title={device.name}
            wrapTitle
            titleChip={<PageHeaderStatusChip variant={chip.tone}>{chip.label}</PageHeaderStatusChip>}
            subline={device.subline.map((part) => timedText(part, now)).join(' · ')}
            actions={
                <>
                    {canEdit ? (
                        <PageHeaderGlassButton icon={Pencil} onClick={onEdit}>
                            Edit
                        </PageHeaderGlassButton>
                    ) : null}
                    {watchable ? (
                        <PageHeaderPrimaryButton icon={BellPlus} onClick={onWatch}>
                            Watch it
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                meters.length > 0 ? (
                    <>
                        {meters.map((meter) => {
                            const target = tabs.find((item) => item.key === meter.tab) ?? tabs[0];
                            return (
                                <PageHeaderMeterBlock
                                    key={meter.key}
                                    label={meter.label}
                                    tone={meter.tone}
                                    onClick={() => onTab(target.key)}
                                    ariaLabel={`${meter.label}: open ${target.label}`}
                                >
                                    <PageHeaderMeterBig>{meter.value ?? 'Not available'}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>{timedText(meter.caption, now)}</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            );
                        })}
                    </>
                ) : undefined
            }
            rail={
                <PageHeaderRail<T>
                    ariaLabel={`${device.name}: views`}
                    items={tabs.map((item) => ({ key: item.key, label: item.label }))}
                    value={tab}
                    onSelect={onTab}
                />
            }
        />
    );
}

const PHASE_TONE: Record<CommandPhase, SgTone> = {
    step_up: 'none',
    approval: 'info',
    ready: 'info',
    checking: 'brand',
    done: 'success',
    mismatch: 'critical',
    unconfirmed: 'warning',
    stopped: 'warning',
};

const ACTION_WORDS: Record<SafetyCommand['action'], string> = {
    arm: 'Arm',
    disarm: 'Disarm',
    unlock: 'Unlock',
};

/** One recent request in words, with the one next step that belongs to the viewer. */
function CommandLineText({
    command,
    area,
    now,
    deviceId,
    onCheck,
}: {
    command: SafetyCommand;
    area: string | null;
    now: Date;
    deviceId: number;
    onCheck: () => void;
}) {
    const [sending, setSending] = useState(false);
    const who = command.by ? `asked by ${command.mine ? 'you' : command.by}` : 'asked';
    const run = () => {
        setSending(true);
        router.post(`/security-devices/devices/${deviceId}/safety/commands/${command.id}/run`, {}, { preserveScroll: true, onFinish: () => setSending(false) });
    };
    return (
        <span className="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1.5">
            <span className="min-w-0 flex-1">
                <b className="font-semibold">
                    {ACTION_WORDS[command.action]}
                    {area ? ` · ${area}` : ''}: {command.words}
                </b>
                <span className="text-caption block">
                    {who}
                    {command.at ? ` ${agoWords(command.at, now)}` : ''}
                    {command.reason ? ` · ${command.reason}` : ''}
                    {command.approved_by ? ` · approved by ${command.approved_by}` : ''}
                    {command.failure ? ` · ${command.failure}` : ''}
                </span>
            </span>
            {command.can_run ? (
                <Button type="button" size="sm" onClick={run} disabled={sending}>
                    {sending ? 'Sending…' : 'Send now'}
                </Button>
            ) : command.phase === 'unconfirmed' || command.phase === 'checking' ? (
                <Button type="button" size="sm" variant="outline" onClick={onCheck}>
                    Check again
                </Button>
            ) : null}
        </span>
    );
}

/**
 * The recent arm, disarm and unlock requests as lines (ring + words): what's happening to each, who
 * asked and why, and the viewer's next step (send it once approved, check again).
 */
export function commandLineItems({
    commands,
    areaName,
    now,
    deviceId,
    onCheck,
}: {
    commands: SafetyCommand[];
    areaName?: (id: string) => string | null;
    now: Date;
    deviceId: number;
    onCheck: () => void;
}): { state: SgTone; text: ReactNode }[] {
    return commands.map((command) => ({
        state: PHASE_TONE[command.phase],
        text: <CommandLineText key={command.id} command={command} area={command.area && areaName ? areaName(command.area) : null} now={now} deviceId={deviceId} onCheck={onCheck} />,
    }));
}

/** The same lines on their own, for a block that has no list of its own. */
export function CommandList({ items }: { items: { state: SgTone; text: ReactNode }[] }) {
    if (items.length === 0) return null;
    return (
        <ul className="grid gap-2" aria-label="Recent commands" aria-live="polite">
            {items.map((item, index) => (
                <li key={index} className="flex items-start gap-2.5 text-sm">
                    <span className="pt-1">
                        <Ring tone={item.state} />
                    </span>
                    {item.text}
                </li>
            ))}
        </ul>
    );
}

/**
 * While a command is on its way, read the state back every few seconds (only the parts that change);
 * while one waits for approval, now and then. Stops after a few minutes; "Check again" is always there.
 */
export function useReadBack(commands: SafetyCommand[], only: string[]) {
    const checking = commands.some((command) => command.phase === 'checking');
    const waiting = commands.some((command) => command.phase === 'approval' || command.phase === 'ready');
    const signature = commands.map((command) => `${command.id}:${command.phase}`).join('|');
    const started = useRef<number>(0);
    const onlyKey = only.join(',');

    useEffect(() => {
        started.current = Date.now();
    }, [signature]);

    useEffect(() => {
        if (!checking && !waiting) return;
        const every = checking ? 3_000 : 20_000;
        const limit = checking ? 3 * 60_000 : 10 * 60_000;
        const timer = window.setInterval(() => {
            if (Date.now() - started.current > limit) {
                window.clearInterval(timer);
                return;
            }
            router.reload({ only: onlyKey.split(',') });
        }, every);
        return () => window.clearInterval(timer);
    }, [checking, waiting, signature, onlyKey]);
}

export function checkNow(only: string[]) {
    router.reload({ only });
}
