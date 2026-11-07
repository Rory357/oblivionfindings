/**
 * A camera's page (S&D rebuild Phase E, drawings pg-sdr-4, 4b and 4c): Live · Recordings ·
 * Checks & alerts · Settings, on the same URL as every device page. Privacy is the point: a reason
 * before every live view (D6), the latest picture only when asked for, and during the camera's
 * privacy hours no picture anywhere (the server refuses every picture; this page only says so).
 *
 * Not offered here, on purpose: the History tab (round 3, not approved), the camera's own web page
 * (Phase G), pan and zoom (not drawn), a privacy mask (no driver can send one) and Snapshot in the
 * full view.
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
import { CameraFullView, PrivacyHoursPopup, TurnOffPrivacyHours, WatchLiveReason, type CameraContext } from '@/components/security-devices/camera-popups';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { DeviceFormPopup, PingViewer, RetireDevice, useDeviceFormOptions, type PopupDevice } from '@/components/security-devices/device-popups';
import { PopupConfirm } from '@/components/popup';
import AppLayout from '@/layouts/app-layout';
import { privacyTime, privacyUntilWords } from '@/lib/security-devices/camera-privacy';
import { timedText } from '@/lib/security-devices/device-time';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Clapperboard, HardDrive, Pencil } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { recordingPage, type RecordingPage } from '../cctv/_recording-contract';
import { RecordingReferenceDialog } from '../cctv/_recording-dialog';
import type { CameraMeter, CameraPageProps, CameraPageTab } from './_camera-page-types';
import { CameraLiveTab } from './_camera-live';
import { CameraRecordingsTab } from './_camera-recordings';
import { CameraSettingsTab } from './_camera-settings';
import { ChecksTabView } from './_device-checks';
import type { DevicePageProps } from './_device-page-types';

export type { CameraPageProps } from './_camera-page-types';

type Popup = 'ping' | 'edit' | 'remove' | 'reason' | 'full' | 'privacy' | 'privacy-off' | 'clip' | 'clip-failed' | null;

function useNow(): Date {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 60_000);
        return () => window.clearInterval(timer);
    }, []);
    return now;
}

/** Read the page's privacy state again when privacy hours start or end, so no picture outlives them. */
function usePrivacyEdges(privacy: CameraPageProps['privacy']) {
    useEffect(() => {
        const edge = privacy.active ? privacy.until : privacy.next_start;
        if (!edge) return;
        const wait = Date.parse(edge) - Date.now();
        if (!Number.isFinite(wait) || wait > 24 * 60 * 60 * 1000) return;
        const timer = window.setTimeout(() => router.reload({ only: ['privacy', 'live', 'meters', 'can'] }), Math.max(0, wait) + 1000);
        return () => window.clearTimeout(timer);
    }, [privacy.active, privacy.until, privacy.next_start]);
}

const meterValue = (meter: CameraMeter) => meter.value ?? 'Not available';

export default function CameraPage(props: CameraPageProps) {
    const { device, tab, can, actions, privacy, live } = props;
    const now = useNow();
    const page = usePage<SharedData>();
    const viewerName = page.props.auth?.user?.name ?? 'You';
    const [popup, setPopup] = useState<Popup>(null);
    const [purpose, setPurpose] = useState<string | null>(null);
    const [clipPage, setClipPage] = useState<RecordingPage | null>(null);
    const { form, load } = useDeviceFormOptions();
    const base = `/security-devices/devices/${device.id}`;
    usePrivacyEdges(privacy);

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
    const camera: CameraContext = { id: device.id, name: device.name, site: device.site, model: device.model };
    const recorder = actions.recorder_href && props.settings?.recorder
        ? { name: props.settings.recorder, href: actions.recorder_href }
        : actions.recorder_href
          ? { name: props.meters.find((meter) => meter.key === 'recording')?.value ?? 'Recorder', href: actions.recorder_href }
          : null;

    const go = (next: CameraPageTab) =>
        router.get(base, next === 'live' ? {} : { tab: next }, { preserveState: true, preserveScroll: true });
    const close = () => setPopup(null);

    const openEdit = () => {
        load();
        setPopup('edit');
    };

    const saveClip = async () => {
        try {
            const { data } = await axios.get(`${base}/recording-references`, { headers: { Accept: 'application/json' } });
            const loaded = recordingPage(data, (data as { actor_id?: number }).actor_id ?? 0, device.id);
            if (!loaded.permissions.can_create || !loaded.create_href) throw Error('not ready');
            setClipPage(loaded);
            setPopup('clip');
        } catch {
            setPopup('clip-failed');
        }
    };

    const header = (
        <PageHeader
            variant="profile"
            backHref={device.back.href}
            icon={iconForKind('camera')}
            title={device.name}
            wrapTitle
            titleChip={<PageHeaderStatusChip variant={device.badge.tone}>{device.badge.label}</PageHeaderStatusChip>}
            subline={device.subline.map((part) => timedText(part, now)).join(' · ')}
            actions={
                <>
                    {actions.recorder_href ? (
                        <PageHeaderGlassButton icon={HardDrive} asChild>
                            <Link href={actions.recorder_href}>Open recorder</Link>
                        </PageHeaderGlassButton>
                    ) : null}
                    {can.edit ? (
                        <PageHeaderGlassButton icon={Pencil} onClick={openEdit}>
                            Edit
                        </PageHeaderGlassButton>
                    ) : null}
                    {can.save_clip ? (
                        <PageHeaderPrimaryButton icon={Clapperboard} onClick={() => void saveClip()}>
                            Save a clip reference
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    {props.meters.map((meter) => {
                        const target = props.tabs.some((item) => item.key === meter.tab) ? meter.tab : 'live';
                        return (
                            <PageHeaderMeterBlock
                                key={meter.key}
                                label={meter.label}
                                tone={meter.tone}
                                onClick={() => go(target)}
                                ariaLabel={`${meter.label}: open ${props.tabs.find((item) => item.key === target)?.label ?? 'Live'}`}
                            >
                                <PageHeaderMeterBig>{meterValue(meter)}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {meter.key === 'picture' && privacy.active
                                        ? privacyUntilWords(privacy.until, now)
                                        : meter.key === 'picture' && privacy.next_start && meter.caption.at === privacy.next_start
                                          ? `Privacy hours from ${privacyTime(privacy.next_start, now)}`
                                          : timedText(meter.caption, now)}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        );
                    })}
                </>
            }
            rail={
                <PageHeaderRail<CameraPageTab>
                    ariaLabel={`${device.name}: views`}
                    items={props.tabs.map((item) => ({ key: item.key, label: item.label }))}
                    value={tab}
                    onSelect={go}
                />
            }
        />
    );

    // The Checks & alerts tab is C2's shared one; it reads only the device, its actions and the tab.
    const checksProps: DevicePageProps = {
        device: { ...device, group: 'other' },
        tab: 'checks',
        tabs: [],
        range: '24h',
        meters: [],
        can,
        actions: { ping: actions.ping, backup: null, remote_href: null },
        trouble: null,
        overview: null,
        health: null,
        remote: null,
        checks: props.checks,
        notes: null,
    };

    let body: ReactNode = null;
    if (tab === 'live') {
        body = <CameraLiveTab props={props} now={now} onWatch={() => setPopup('reason')} />;
    } else if (tab === 'recordings') {
        body = <CameraRecordingsTab props={props} now={now} onSaveClip={() => void saveClip()} />;
    } else if (tab === 'checks' && props.checks) {
        body = (
            <ChecksTabView
                props={checksProps}
                checks={props.checks}
                now={now}
                onPing={() => setPopup('ping')}
                onBackup={() => undefined}
                onCompare={() => undefined}
                onWatch={null}
            />
        );
    } else if (tab === 'settings') {
        body = (
            <CameraSettingsTab
                props={props}
                now={now}
                onEditPrivacy={() => setPopup('privacy')}
                onPrivacyOff={() => setPopup('privacy-off')}
                onRemove={() => setPopup('remove')}
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
            <PageLayout hero={header}>{body}</PageLayout>

            <WatchLiveReason
                open={popup === 'reason'}
                camera={camera}
                onClose={close}
                onWatch={(reason) => {
                    setPurpose(reason);
                    setPopup('full');
                }}
            />
            <CameraFullView
                open={popup === 'full'}
                onClose={() => {
                    setPurpose(null);
                    close();
                    router.reload({ only: ['overview'] });
                }}
                camera={camera}
                live={live && can.watch_live ? { actor_id: live.actor_id, source_revision: live.source_revision, start_href: live.start_href } : null}
                purpose={purpose}
                viewerName={viewerName}
                recorder={recorder}
                canSaveClip={can.save_clip}
                onSaveClip={() => void saveClip()}
                onStartFailed={() => router.reload({ only: ['privacy', 'live', 'meters', 'can'] })}
            />
            <PrivacyHoursPopup
                open={popup === 'privacy'}
                onClose={close}
                camera={camera}
                spot={props.settings?.camera.location || null}
                ranges={privacy.ranges}
            />
            <TurnOffPrivacyHours open={popup === 'privacy-off'} onClose={close} camera={camera} ranges={privacy.ranges} />
            {clipPage ? (
                <RecordingReferenceDialog
                    page={clipPage}
                    open={popup === 'clip'}
                    onClose={close}
                    onSaved={() => router.reload({ only: ['recordings', 'overview'] })}
                />
            ) : null}
            <PopupConfirm
                open={popup === 'clip-failed'}
                onClose={close}
                onConfirm={close}
                variant="alert"
                title="A clip can’t be saved just now"
                description="This camera’s recorder link couldn’t be confirmed. Try again in a minute, or ask IT to check the camera’s setup."
                confirmLabel="Close"
            />
            <PingViewer open={popup === 'ping'} device={popup === 'ping' ? self : null} onClose={close} />
            <DeviceFormPopup
                open={popup === 'edit'}
                mode="edit"
                deviceId={popup === 'edit' ? device.id : null}
                context={popup === 'edit' ? self : null}
                form={form}
                canAssign={can.assign}
                onClose={close}
            />
            <RetireDevice open={popup === 'remove'} device={popup === 'remove' ? self : null} onClose={close} />
        </AppLayout>
    );
}
