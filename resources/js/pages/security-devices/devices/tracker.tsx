/**
 * A care tracker's page (S&D rebuild Phase E, drawings pg-sdr-8, 8b and 8c): Location · Console, on
 * the same URL as every device page. The consent reason comes first: the location shows only when
 * the server allows it (location permission and the wearer's active location consent linked to
 * this tracker); otherwise the page says why, who can fix it and what still works. Commands go
 * through pop-up 19 and need "Send a command to a care tracker".
 *
 * Not offered here, on purpose: the Battery & checks and Settings tabs (not drawn for this round),
 * the safe zone, planned outings and the day's trail (no data behind them on this page yet), and
 * pop-up 76 Add a tracker by its IMEI (its setup message would carry the unit's password).
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
    PageLayout,
} from '@/components/page';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { DeviceFormPopup, useDeviceFormOptions } from '@/components/security-devices/device-popups';
import { SendTrackerCommand, type TrackerPopupContext } from '@/components/security-devices/tracker-popups';
import { Note } from '@/components/signature/blocks';
import AppLayout from '@/layouts/app-layout';
import { seenTime } from '@/lib/security-devices/device-time';
import { Head, router } from '@inertiajs/react';
import { CircleCheck, LocateFixed, Pencil, Send } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { fill, popupDevice, useNow } from './_sensor-tracker-parts';
import type { TrackerCommandKey, TrackerPageProps, TrackerTab } from './_sensor-tracker-types';
import { TrackerConsoleTab, TrackerLocationTab } from './_tracker-tabs';

export type { TrackerPageProps } from './_sensor-tracker-types';

/** How often the Console tab reads the unit's messages again while it's open. */
const CONSOLE_REFRESH_MS = 15_000;

export default function TrackerPage(props: TrackerPageProps) {
    const { device, tab, can, privacy, commands } = props;
    const now = useNow();
    const [editing, setEditing] = useState(false);
    const [command, setCommand] = useState<{ initial: TrackerCommandKey | null } | null>(null);
    const [sent, setSent] = useState<string | null>(null);
    const { form, load } = useDeviceFormOptions();
    const base = `/security-devices/devices/${device.id}`;

    // The console shows messages as they arrive: read it again every 15 seconds while it's open.
    useEffect(() => {
        if (tab !== 'console') return;
        const timer = window.setInterval(() => router.reload({ only: ['console'] }), CONSOLE_REFRESH_MS);
        return () => window.clearInterval(timer);
    }, [tab]);

    const go = (next: TrackerTab) => router.get(base, next === 'location' ? {} : { tab: next }, { preserveState: true, preserveScroll: true });

    const consentTag = privacy.person?.kind === 'client' && privacy.person.consent ? (privacy.person.consent.active ? 'Consent active' : 'Consent not active') : null;
    const context: TrackerPopupContext = {
        id: device.id,
        name: device.name,
        note: device.subline.slice(0, -1).map((part) => part.text).filter((text) => text !== device.site).join(' · '),
        tag: consentTag,
        person: privacy.person?.short ?? null,
    };
    const askSettings = can.command && commands.options.some((option) => option.key === 'read_config') ? () => setCommand({ initial: 'read_config' }) : null;

    const header = (
        <PageHeader
            variant="profile"
            backHref={device.back.href}
            icon={iconForKind(device.kind)}
            title={device.name}
            wrapTitle
            titleChip={<PageHeaderStatusChip variant={device.badge.tone}>{device.badge.label}</PageHeaderStatusChip>}
            subline={device.subline.map((part) => fill(part, now)).join(' · ')}
            actions={
                <>
                    {can.locate ? (
                        <PageHeaderGlassButton icon={LocateFixed} onClick={() => setCommand({ initial: 'request_location' })}>
                            Ask for position now
                        </PageHeaderGlassButton>
                    ) : null}
                    {can.edit ? (
                        <PageHeaderGlassButton
                            icon={Pencil}
                            onClick={() => {
                                load();
                                setEditing(true);
                            }}
                        >
                            Edit
                        </PageHeaderGlassButton>
                    ) : null}
                    {can.command ? (
                        <PageHeaderPrimaryButton icon={Send} onClick={() => setCommand({ initial: null })}>
                            Send a command
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    {props.meters.map((meter) => {
                        const target = props.tabs.some((item) => item.key === meter.tab) ? meter.tab : 'location';
                        return (
                            <PageHeaderMeterBlock
                                key={meter.key}
                                label={meter.label}
                                tone={meter.tone}
                                onClick={() => go(target)}
                                ariaLabel={`${meter.label}: open ${props.tabs.find((item) => item.key === target)?.label ?? 'Location'}`}
                            >
                                <PageHeaderMeterBig>{meter.value ?? (meter.time ? seenTime(meter.time, now) : 'Not available')}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>{fill(meter.caption, now)}</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        );
                    })}
                </>
            }
            rail={
                <PageHeaderRail<TrackerTab>
                    ariaLabel={`${device.name}: views`}
                    items={props.tabs.map((item) => ({ key: item.key, label: item.label }))}
                    value={tab}
                    onSelect={go}
                />
            }
        />
    );

    let body: ReactNode = null;
    if (tab === 'location' && props.location) {
        body = <TrackerLocationTab location={props.location} privacy={privacy} now={now} />;
    } else if (tab === 'console' && props.console) {
        body = (
            <TrackerConsoleTab
                console={props.console}
                now={now}
                onSend={can.command ? () => setCommand({ initial: null }) : null}
                onAskSettings={askSettings}
            />
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & devices', href: '/security-devices' },
                { title: 'Devices', href: device.back.href },
                { title: device.name, href: base },
            ]}
        >
            <Head title={device.name} />
            <PageLayout hero={header}>
                {sent ? (
                    <div role="status" className="mb-5">
                        <Note tone="success" icon={CircleCheck}>
                            {sent}
                        </Note>
                    </div>
                ) : null}
                {commands.reason ? (
                    <div className="mb-5">
                        <Note>{commands.reason}</Note>
                    </div>
                ) : null}
                {body}
            </PageLayout>
            <SendTrackerCommand
                open={command !== null}
                tracker={context}
                commands={commands}
                initial={command?.initial ?? null}
                onClose={() => setCommand(null)}
                onSent={(message) => {
                    setCommand(null);
                    setSent(message);
                    router.reload({ only: ['console', 'commands', 'meters'] });
                }}
            />
            <DeviceFormPopup
                open={editing}
                mode="edit"
                deviceId={editing ? device.id : null}
                context={editing ? popupDevice(device) : null}
                form={form}
                canAssign={can.assign}
                onClose={() => setEditing(false)}
            />
        </AppLayout>
    );
}
