/**
 * An alarm panel's page (S&D rebuild Phase E, drawings pg-sdr-5 an Ajax panel that only reports, 5b
 * Zones, 5c an Inner Range Inception panel armed and disarmed from Oblivion, 5d Notes & documents).
 *
 * Arm and Disarm appear only where the maker allows Oblivion to do it and only for someone with the
 * key; Ajax and other report-only panels say "view only" once. Every command states its effect first
 * (pop-ups sdr-17 and sdr-71), then this page shows "Checking…" until the panel confirms the new state.
 * Automations never disarm (D12): nothing here runs without a person.
 *
 * Not built here, on purpose: the Events and Settings tabs (no approved drawing yet), zone bypass (no
 * drawing), and "Told now" names in Disarm (nobody is told by Oblivion today).
 */
import { PageLayout } from '@/components/page';
import { ArmAlarm, ArmArea, DisarmAlarm, type AlarmPopupPanel } from '@/components/security-devices/alarm-popups';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { DeviceFormPopup, useDeviceFormOptions, WatchDevice, type PopupDevice } from '@/components/security-devices/device-popups';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { AlarmOverview, AlarmZones } from './_alarm-tabs';
import { ChecksTabView } from './_device-checks';
import { NotesTabView } from './_device-notes';
import type { DevicePageProps } from './_device-page-types';
import { checkNow, SafetyHeader, useNow, useReadBack } from './_safety-kit-parts';
import type { AlarmPageProps, AlarmTab } from './_safety-kit-types';

export type { AlarmPageProps } from './_safety-kit-types';

type Popup = 'edit' | 'watch' | 'arm' | 'arm_area' | 'disarm' | null;

/** What changes while a command is on its way: read only these again. */
const READ_BACK = ['control', 'panel', 'areas', 'overview', 'meters', 'device'];

export default function AlarmPage(props: AlarmPageProps) {
    const { device, tab, can, control, panel, areas } = props;
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
    const popupPanel: AlarmPopupPanel = { id: device.id, name: device.name, model: device.model, brand: control.brand, stateLabel: panel.label };

    const go = (next: AlarmTab) => router.get(base, next === 'overview' ? {} : { tab: next }, { preserveState: true, preserveScroll: true });
    const open = (next: Popup) => {
        if (next === 'edit' || next === 'watch') load();
        setPopup(next);
    };
    const close = () => setPopup(null);

    // Disarm turns off what is armed now (every area when nothing has reported).
    const armedAreas = areas.filter((area) => area.armed === true);
    const disarmAreas = armedAreas.length > 0 ? armedAreas : areas;
    // The C2 tabs read only these parts of the device page's props.
    const asDevicePage = {
        device: { ...device, group: 'other' },
        can,
        actions: { ping: false, backup: null, remote_href: null },
    } as unknown as DevicePageProps;

    let body: ReactNode = null;
    if (tab === 'overview') {
        body = (
            <AlarmOverview
                props={props}
                now={now}
                onTab={go}
                onArm={() => open('arm')}
                onArmArea={() => open('arm_area')}
                onDisarm={() => open('disarm')}
                onCheck={() => checkNow(READ_BACK)}
            />
        );
    } else if (tab === 'zones') {
        body = <AlarmZones props={props} now={now} />;
    } else if (tab === 'checks' && props.checks) {
        body = (
            <ChecksTabView
                props={asDevicePage}
                checks={props.checks}
                now={now}
                onPing={() => undefined}
                onBackup={() => undefined}
                onCompare={() => undefined}
                onWatch={can.watch && device.state === 'not_watched' ? () => open('watch') : null}
            />
        );
    } else if (tab === 'notes' && props.notes) {
        body = <NotesTabView props={asDevicePage} notes={props.notes} now={now} />;
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
                    <SafetyHeader<AlarmTab>
                        device={device}
                        icon={iconForKind('alarm_panel')}
                        chip={{ label: panel.state === 'hidden' ? device.badge.label : panel.label, tone: panel.state === 'hidden' ? device.badge.tone : panel.tone }}
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
            {control.arm && can.arm ? (
                <>
                    <ArmAlarm
                        open={popup === 'arm'}
                        panel={popupPanel}
                        action={control.arm}
                        areas={areas}
                        zones={control.zones}
                        confirmUrl={control.confirm_identity_url}
                        onChooseAreas={areas.length > 1 ? () => setPopup('arm_area') : null}
                        onClose={close}
                    />
                    <ArmArea
                        open={popup === 'arm_area'}
                        panel={popupPanel}
                        action={control.arm}
                        areas={areas}
                        zones={control.zones}
                        confirmUrl={control.confirm_identity_url}
                        onClose={close}
                    />
                </>
            ) : null}
            {control.disarm && can.disarm ? (
                <DisarmAlarm
                    open={popup === 'disarm'}
                    panel={popupPanel}
                    action={control.disarm}
                    areas={disarmAreas}
                    confirmUrl={control.confirm_identity_url}
                    onClose={close}
                />
            ) : null}
        </AppLayout>
    );
}
