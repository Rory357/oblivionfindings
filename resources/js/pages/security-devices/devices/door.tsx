/**
 * A door's page (S&D rebuild Phase E, drawings pg-sdr-6 Overview, 6b Events, 6c Access): locked or
 * not, who came in (staff by name, residents only as "Resident fob": D19), and who can open it.
 *
 * "Unlock for N seconds" (pop-up sdr-18) is today's governed timed unlock and locks again by itself;
 * after it is sent this page shows "Checking…" until the door confirms it locked again. The door never
 * unlocks by schedule or automation (D12).
 *
 * Not built here, on purpose: "Keep unlocked" (sdr-70 is drawn in door-popups.tsx, but no governed
 * command can keep a door unlocked yet, so it is never offered), "Keep locked", "Give someone access"
 * (Phase H), "Export", camera clips, and the Settings tab (no approved drawing yet).
 */
import { PageLayout } from '@/components/page';
import { DeviceFormPopup, useDeviceFormOptions, WatchDevice, type PopupDevice } from '@/components/security-devices/device-popups';
import { UnlockDoor, type DoorPopupDoor } from '@/components/security-devices/door-popups';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { DoorAccess, DoorEvents, DoorOverview } from './_door-tabs';
import { checkNow, SafetyHeader, useNow, useReadBack } from './_safety-kit-parts';
import type { DoorPageProps, DoorTab } from './_safety-kit-types';

export type { DoorPageProps } from './_safety-kit-types';

type Popup = 'edit' | 'watch' | 'unlock' | null;

/** What changes while an unlock is on its way: read only these again. */
const READ_BACK = ['control', 'lock', 'overview', 'meters', 'device'];

export default function DoorPage(props: DoorPageProps) {
    const { device, tab, can, control, lock } = props;
    const now = useNow();
    const [popup, setPopup] = useState<Popup>(null);
    const { form, load } = useDeviceFormOptions();
    const base = `/security-devices/devices/${device.id}`;
    useReadBack(control.commands, READ_BACK);

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
    const door: DoorPopupDoor = { id: device.id, name: device.name, model: device.model, site: device.site, lockWords: lock.title.toLowerCase() };

    const visit = (query: Record<string, string | number | undefined>) =>
        router.get(base, Object.fromEntries(Object.entries(query).filter(([, value]) => value !== undefined)), { preserveState: true, preserveScroll: true });
    const go = (next: DoorTab) => visit({ tab: next === 'overview' ? undefined : next });
    const open = (next: Popup) => {
        if (next === 'edit' || next === 'watch') load();
        setPopup(next);
    };
    const close = () => setPopup(null);

    let body: ReactNode = null;
    if (tab === 'overview') {
        body = (
            <DoorOverview
                props={props}
                now={now}
                onTab={go}
                onUnlock={() => open('unlock')}
                onCheck={() => checkNow(READ_BACK)}
            />
        );
    } else if (tab === 'events') {
        const range = props.events?.range ?? '7d';
        body = (
            <DoorEvents
                props={props}
                now={now}
                onRange={(next) => visit({ tab: 'events', range: next === '7d' ? undefined : next })}
                onPage={(page) => visit({ tab: 'events', range: range === '7d' ? undefined : range, page: page > 1 ? page : undefined })}
            />
        );
    } else if (tab === 'access') {
        body = <DoorAccess props={props} />;
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
            <PageLayout
                hero={
                    <SafetyHeader<DoorTab>
                        device={device}
                        icon={Lock}
                        chip={lock.chip}
                        meters={props.meters}
                        tabs={props.tabs}
                        tab={tab}
                        now={now}
                        canEdit={can.edit}
                        watchable={can.watch && device.state === 'not_watched'}
                        onEdit={() => open('edit')}
                        onWatch={() => open('watch')}
                        onTab={go}
                    />
                }
            >
                {body}
            </PageLayout>

            <DeviceFormPopup open={popup === 'edit'} mode="edit" deviceId={popup === 'edit' ? device.id : null} context={popup === 'edit' ? self : null} form={form} canAssign={can.assign} onClose={close} />
            <WatchDevice open={popup === 'watch'} device={popup === 'watch' ? self : null} form={form} onClose={close} />
            {control.unlock && can.unlock && control.seconds ? (
                <UnlockDoor open={popup === 'unlock'} door={door} action={control.unlock} seconds={control.seconds} confirmUrl={control.confirm_identity_url} onClose={close} />
            ) : null}
        </AppLayout>
    );
}
