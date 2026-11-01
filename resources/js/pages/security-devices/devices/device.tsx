/**
 * A device's page (S&D rebuild C2, drawings pg-sdr-3, 3c, 9, 9b, 9c, 10, 16, 17, 20, 21, 22 and
 * the shared Notes & documents tab 5d) for network gear, computers, printers, UPSs, recorders,
 * sensor gateways and other devices. The kind decides the tabs and the overview. Cameras and the
 * safety kit keep today's profile (show.tsx) until Phase E.
 *
 * Not offered here, on purpose: installing firmware (pop-up 62 shows an update a maker's source
 * reports and opens the maker's app), port on/off/restart (never), and acknowledge and quiet time
 * (Phase D). A computer's Obli help tab (Phase I, 9d) is _device-obli-help.tsx.
 */
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import type { TrendRangeKey } from '@/components/security-devices/device-blocks';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import {
    deviceMenu,
    DeviceFormPopup,
    MoveDevices,
    PingViewer,
    RetireDevice,
    useDeviceFormOptions,
    WatchDevice,
    type DeviceAction,
    type PopupDevice,
} from '@/components/security-devices/device-popups';
import { FirmwareUpdatePopup } from '@/components/security-devices/firmware-update-popup';
import AppLayout from '@/layouts/app-layout';
import { durationWords, timedText } from '@/lib/security-devices/device-time';
import { RemoteConnectionDialog } from '@/pages/it/remote-support/_dialogs';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Activity, BellPlus, CircleArrowUp, Globe, HardDriveDownload, KeyRound, MonitorUp, Pencil } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { BackupSettings } from './_device-backup';
import { SavedLoginPopup } from './_device-saved-login';
import { SettingsChangedPopup } from './_device-settings-changed';
import { ChecksTabView } from './_device-checks';
import { ComputerHealthTab, ComputerOverviewTab, RemoteSupportTab } from './_device-overview-computer';
import { GatewayOverviewTab } from './_gateway-overview';
import { NetworkOverviewTab, TroubleOverview } from './_device-overview-network';
import { OtherOverviewTab } from './_device-overview-other';
import { NotesTabView } from './_device-notes';
import { ObliHelpTab } from './_device-obli-help';
import { TerminalTab } from './_device-terminal';
import { WebPagePopup } from './_device-web-page';
import type { DevicePageMeter, DevicePageProps, DevicePageTab } from './_device-page-types';

export type { DevicePageProps } from './_device-page-types';

type Popup =
    | { kind: 'ping'; device: PopupDevice }
    | { kind: 'edit'; device: PopupDevice }
    | { kind: 'watch'; device: PopupDevice }
    | { kind: 'move'; device: PopupDevice }
    | { kind: 'remove'; device: PopupDevice }
    | { kind: 'backup' }
    | { kind: 'compare'; snapshotId: number }
    | { kind: 'update' }
    | { kind: 'login' }
    | null;

function useNow(): Date {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 60_000);
        return () => window.clearInterval(timer);
    }, []);
    return now;
}

function meterValue(meter: DevicePageMeter, now: Date): string {
    if (meter.value !== null) return meter.value;
    if (meter.from) return durationWords(meter.from, now);
    if (meter.ago) return `${durationWords(meter.ago, now)} ago`;
    return 'Not available';
}

export default function DevicePage(props: DevicePageProps) {
    const { device, tab, can, actions } = props;
    const now = useNow();
    const [popup, setPopup] = useState<Popup>(null);
    const [webOpen, setWebOpen] = useState(false);
    const { form, load } = useDeviceFormOptions();
    const base = `/security-devices/devices/${device.id}`;

    const self: PopupDevice = {
        id: device.id,
        name: device.name,
        sub: device.model,
        kind: device.kind,
        state: device.state,
        address: device.address,
        site: device.site,
        site_id: device.site_id,
        watching: device.watching,
    };

    const go = (changes: { tab?: DevicePageTab; range?: TrendRangeKey }) => {
        const next = { tab: changes.tab ?? tab, range: changes.range ?? props.range };
        router.get(
            base,
            Object.fromEntries(Object.entries({ tab: next.tab === 'overview' ? undefined : next.tab, range: next.range === '24h' ? undefined : next.range }).filter(([, value]) => value !== undefined)),
            { preserveState: true, preserveScroll: true },
        );
    };

    const open = (next: Popup) => {
        if (next && ['edit', 'watch', 'move'].includes(next.kind)) load();
        setPopup(next);
    };
    const close = () => setPopup(null);
    const act = (action: DeviceAction, target: PopupDevice) => {
        if (action === 'ping') open({ kind: 'ping', device: target });
        if (action === 'edit') open({ kind: 'edit', device: target });
        if (action === 'watch') open({ kind: 'watch', device: target });
        if (action === 'move') open({ kind: 'move', device: target });
        if (action === 'remove') open({ kind: 'remove', device: target });
    };
    const menuFor = (target: PopupDevice) => deviceMenu(target, can, act);
    // S&D Phase G (pop-up 24): a backup beside the one before it.
    const compare = (snapshotId: number) => open({ kind: 'compare', snapshotId });

    const watchable = can.watch && device.state === 'not_watched';
    // S&D Phase G (pop-up 22): remote support starts here, in IT's own remote support dialog (request, Allow, screen).
    const actorId = usePage<SharedData>().props.auth?.user?.id ?? null;
    const [remoteOpen, setRemoteOpen] = useState(false);
    const primary = actions.remote_href ? (
        <PageHeaderPrimaryButton icon={MonitorUp} onClick={() => (actorId ? setRemoteOpen(true) : router.visit(actions.remote_href as string))}>
            Start remote support
        </PageHeaderPrimaryButton>
    ) : watchable ? (
        <PageHeaderPrimaryButton icon={BellPlus} onClick={() => open({ kind: 'watch', device: self })}>
            Watch it
        </PageHeaderPrimaryButton>
    ) : null;

    const header = (
        <PageHeader
            variant="profile"
            backHref={device.back.href}
            icon={iconForKind(device.kind)}
            title={device.name}
            wrapTitle
            titleChip={<PageHeaderStatusChip variant={device.badge.tone}>{device.badge.label}</PageHeaderStatusChip>}
            subline={device.subline.map((part) => timedText(part, now)).join(' · ')}
            actions={
                <>
                    {actions.ping ? (
                        <PageHeaderGlassButton icon={Activity} onClick={() => open({ kind: 'ping', device: self })}>
                            Ping now
                        </PageHeaderGlassButton>
                    ) : null}
                    {actions.backup ? (
                        <PageHeaderGlassButton icon={HardDriveDownload} onClick={() => open({ kind: 'backup' })}>
                            Back up settings
                        </PageHeaderGlassButton>
                    ) : null}
                    {actions.web?.ok ? (
                        <PageHeaderGlassButton icon={Globe} onClick={() => setWebOpen(true)}>
                            Open web page
                        </PageHeaderGlassButton>
                    ) : null}
                    {actions.update ? (
                        <PageHeaderGlassButton icon={CircleArrowUp} onClick={() => open({ kind: 'update' })}>
                            Firmware update
                        </PageHeaderGlassButton>
                    ) : null}
                    {actions.login?.failed_at ? (
                        <PageHeaderGlassButton icon={KeyRound} onClick={() => open({ kind: 'login' })}>
                            Update saved login
                        </PageHeaderGlassButton>
                    ) : null}
                    {can.edit ? (
                        <PageHeaderGlassButton icon={Pencil} onClick={() => open({ kind: 'edit', device: self })}>
                            Edit
                        </PageHeaderGlassButton>
                    ) : null}
                    {primary}
                </>
            }
            meters={
                props.meters.length > 0 ? (
                    <>
                        {props.meters.map((meter) => (
                            <PageHeaderMeterBlock
                                key={meter.key}
                                label={meter.label}
                                tone={meter.tone}
                                onClick={() => go({ tab: props.tabs.some((item) => item.key === meter.tab) ? meter.tab : 'overview' })}
                                ariaLabel={`${meter.label}: open ${props.tabs.find((item) => item.key === meter.tab)?.label ?? 'Overview'}`}
                            >
                                <PageHeaderMeterBig>{meterValue(meter, now)}</PageHeaderMeterBig>
                                {meter.bar !== null ? <PageHeaderMeterBar percent={meter.bar} /> : null}
                                <PageHeaderMeterCaption>{timedText(meter.caption, now)}</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        ))}
                    </>
                ) : undefined
            }
            rail={
                <PageHeaderRail<DevicePageTab>
                    ariaLabel={`${device.name}: views`}
                    items={props.tabs.map((item) => ({ key: item.key, label: item.label }))}
                    value={tab}
                    onSelect={(key) => go({ tab: key })}
                />
            }
        />
    );

    let body: ReactNode = null;
    if (tab === 'overview') {
        if (props.trouble) body = <TroubleOverview props={props} trouble={props.trouble} now={now} />;
        else if (device.group === 'network') body = <NetworkOverviewTab props={props} now={now} onRange={(range) => go({ range })} onCompare={compare} />;
        else if (device.group === 'computer') body = <ComputerOverviewTab props={props} now={now} />;
        else if (device.kind === 'sensor_gateway') body = <GatewayOverviewTab props={props} now={now} menuFor={menuFor} onWatch={can.watch ? () => open({ kind: 'watch', device: self }) : null} />;
        else body = <OtherOverviewTab props={props} now={now} onRange={(range) => go({ range })} menuFor={menuFor} />;
    } else if (tab === 'health' && props.health) {
        body = <ComputerHealthTab health={props.health} now={now} />;
    } else if (tab === 'obli' && props.obli) {
        body = <ObliHelpTab data={props.obli} now={now} />;
    } else if (tab === 'remote' && props.remote) {
        body = <RemoteSupportTab remote={props.remote} now={now} />;
    } else if (tab === 'checks' && props.checks) {
        body = (
            <ChecksTabView
                props={props}
                checks={props.checks}
                now={now}
                onPing={() => open({ kind: 'ping', device: self })}
                onBackup={() => open({ kind: 'backup' })}
                onCompare={compare}
                onWatch={watchable ? () => open({ kind: 'watch', device: self }) : null}
            />
        );
    } else if (tab === 'notes' && props.notes) {
        body = <NotesTabView props={props} notes={props.notes} now={now} />;
    } else if (tab === 'terminal' && props.terminal) {
        body = <TerminalTab deviceId={device.id} deviceName={device.name} terminal={props.terminal} now={now} />;
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
            <PageLayout hero={header}>{body}</PageLayout>

            <PingViewer open={popup?.kind === 'ping'} device={popup?.kind === 'ping' ? popup.device : null} onClose={close} />
            {actions.web?.ok ? <WebPagePopup open={webOpen} device={{ id: device.id, name: device.name, address: device.address }} hub={actions.web.hub} onClose={() => setWebOpen(false)} /> : null}
            <DeviceFormPopup
                open={popup?.kind === 'edit'}
                mode="edit"
                deviceId={popup?.kind === 'edit' ? popup.device.id : null}
                context={popup?.kind === 'edit' ? popup.device : null}
                form={form}
                canAssign={can.assign}
                onClose={close}
            />
            <WatchDevice open={popup?.kind === 'watch'} device={popup?.kind === 'watch' ? popup.device : null} form={form} onClose={close} />
            <MoveDevices open={popup?.kind === 'move'} devices={popup?.kind === 'move' ? [popup.device] : []} form={form} onClose={close} />
            <RetireDevice open={popup?.kind === 'remove'} device={popup?.kind === 'remove' ? popup.device : null} onClose={close} />
            {actions.backup ? (
                <BackupSettings open={popup?.kind === 'backup'} device={device} backup={actions.backup} onClose={close} />
            ) : null}
            <SettingsChangedPopup
                open={popup?.kind === 'compare'}
                deviceId={device.id}
                deviceName={device.name}
                snapshotId={popup?.kind === 'compare' ? popup.snapshotId : null}
                onBackup={actions.backup?.available ? () => open({ kind: 'backup' }) : null}
                onClose={close}
                now={now}
            />
            {actions.update ? <FirmwareUpdatePopup open={popup?.kind === 'update'} update={actions.update} onClose={close} now={now} /> : null}
            {actions.login ? (
                <SavedLoginPopup
                    open={popup?.kind === 'login'}
                    device={{ id: device.id, name: device.name, model: device.model, kind: device.kind, site: device.site }}
                    login={actions.login}
                    onClose={close}
                    now={now}
                />
            ) : null}
            {actions.remote_href && actorId ? (
                <RemoteConnectionDialog
                    open={remoteOpen}
                    onClose={() => {
                        setRemoteOpen(false);
                        router.reload({ only: ['remote'] });
                    }}
                    actorId={actorId}
                    device={{ id: device.id, name: device.name }}
                />
            ) : null}
        </AppLayout>
    );
}
