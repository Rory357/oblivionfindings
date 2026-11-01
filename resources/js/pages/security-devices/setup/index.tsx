/**
 * Setup (S&D rebuild drawing pg-sdr-15 and its sections), on Fleet's settings structure: one
 * header with the numbers that need attention, the sections across the top, sub-tabs, an
 * Overview of review cards first, titled groups of On/Off rows and a "Review changes" bar.
 */
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import { useDeviceFormOptions } from '@/components/security-devices/device-popups';
import { HubPopups, useHubPopups } from '@/components/security-devices/hub-popups';
import { AddNetwork } from '@/components/security-devices/scan-popups';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { History, KeyRound, ListChecks, Network, Plug, Settings2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ChangeHistory } from './_history';
import { SetupNetworks } from './_networks';
import { SetupOverview } from './_overview';
import { ConnectedServices, SavedLogins, SupportedDevices } from './_services';
import { SettingsSave, useSettingsDraft } from './_settings';
import type { SetupMeter, SetupProps, SetupSection } from './_types';

const TAB_ICONS = { networks: Network, connected: Plug, logins: KeyRound, supported: ListChecks } as const;

export function setupUrl(section: SetupSection, tab?: string | null, page?: number): string {
    const base = section === 'overview' ? '/security-devices/setup' : `/security-devices/setup/${section}`;
    const query = new URLSearchParams();
    if (tab) query.set('tab', tab);
    if (page && page > 1) query.set('page', String(page));
    const text = query.toString();
    return text ? `${base}?${text}` : base;
}

export default function SetupPage(props: SetupProps) {
    const { section, tab, sections, meters, can } = props;
    const { form, load } = useDeviceFormOptions();
    const hubs = useHubPopups(load);
    const draft = useSettingsDraft(props.settings);
    const [query, setQuery] = useState('');
    const [addingNetwork, setAddingNetwork] = useState(false);
    const [leaving, setLeaving] = useState<string | null>(null);
    const allowLeave = useRef(false);
    // Times are read once per visit, so "6:00 am" and "3 min ago" agree across the page.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    const now = useMemo(() => new Date(), [props]);
    const current = sections.find((entry) => entry.key === section);

    useEffect(() => setQuery(''), [section, tab]);

    // Unsaved switches: leaving asks first (Fleet's unsaved-draft guard).
    useEffect(() => {
        if (!draft.dirty) return;
        const beforeUnload = (event: BeforeUnloadEvent) => {
            if (allowLeave.current) return;
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', beforeUnload);
        const remove = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (allowLeave.current || visit.method !== 'get' || visit.only.length > 0) return;
            event.preventDefault();
            setLeaving(visit.url.toString());
        });
        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            remove();
        };
    }, [draft.dirty]);

    const go = (next: SetupSection, nextTab?: string | null, page?: number) =>
        router.get(setupUrl(next, nextTab ?? null, page), {}, { preserveScroll: next === section });

    const openMeter = (meter: SetupMeter) => {
        if (meter.href) router.visit(meter.href);
        else go(meter.section ?? 'overview', meter.tab);
    };

    const breadcrumbs = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Security & devices', href: '/security-devices' },
        { title: 'Setup', href: '/security-devices/setup' },
        ...(section !== 'overview' && current ? [{ title: current.label, href: setupUrl(section, tab) }] : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Setup · Security & devices" />
            <div className="space-y-5">
                <PageHeader
                    icon={Settings2}
                    title="Setup"
                    subline="Organisation · hubs, networks, connected services and saved logins"
                    actions={
                        <>
                            <PageHeaderSearch value={query} onChange={setQuery} placeholder="Search setup" />
                            {sections.some((entry) => entry.key === 'history') ? (
                                <PageHeaderGlassButton icon={History} onClick={() => go('history')}>
                                    Changes
                                </PageHeaderGlassButton>
                            ) : null}
                        </>
                    }
                    meters={
                        <>
                            {meters.map((meter) => (
                                <PageHeaderMeterBlock
                                    key={meter.key}
                                    label={meter.label}
                                    tone={meter.tone ?? 'brand'}
                                    onClick={() => openMeter(meter)}
                                    ariaLabel={meter.href ? `Open ${meter.label.toLowerCase()} in Devices` : `Open ${meter.label.toLowerCase()}`}
                                >
                                    <PageHeaderMeterBig>{meter.value}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>{meter.caption}</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ))}
                        </>
                    }
                    rail={
                        <PageHeaderRail<SetupSection>
                            ariaLabel="Setup sections"
                            items={sections.map((entry) => ({ key: entry.key, label: entry.label }))}
                            value={section}
                            onSelect={(key) => go(key)}
                        />
                    }
                />

                {current && current.tabs.length > 1 ? (
                    <TierTwoTabs
                        tabs={current.tabs.map((entry) => ({ key: entry.key, label: entry.label, icon: TAB_ICONS[entry.key as keyof typeof TAB_ICONS] ?? Settings2 }))}
                        activeTab={tab ?? current.tabs[0].key}
                        onTab={(key) => go(section, key)}
                        testIdPrefix="sd-setup"
                        ariaLabel={`${current.label} sections`}
                        renderLink={(entry, className, inner, accessibility) => (
                            <Button variant="ghost" key={entry.key} className={className} {...accessibility} onClick={() => go(section, entry.key)}>
                                {inner}
                            </Button>
                        )}
                    />
                ) : null}

                {props.overview ? (
                    <SetupOverview
                        data={props.overview}
                        query={query}
                        now={now}
                        onSection={(next, nextTab) => go(next, nextTab)}
                        onAddHub={(siteId) => hubs.openAdd(siteId)}
                        onOpenHub={(hubId) => hubs.openPanel(hubId)}
                    />
                ) : null}
                {props.networks ? (
                    <SetupNetworks
                        data={props.networks}
                        draft={draft}
                        canAdd={can.add_network}
                        query={query}
                        now={now}
                        onAdd={() => {
                            load();
                            setAddingNetwork(true);
                        }}
                    />
                ) : null}
                {props.connected ? <ConnectedServices data={props.connected} query={query} now={now} /> : null}
                {props.logins ? <SavedLogins data={props.logins} query={query} now={now} /> : null}
                {props.supported ? <SupportedDevices data={props.supported} query={query} canOpenDevices={can.open_devices} /> : null}
                {props.history ? (
                    <ChangeHistory data={props.history} canExport={can.export_history} query={query} now={now} onPage={(page) => go('history', null, page)} />
                ) : null}

                {props.settings ? <SettingsSave draft={draft} /> : null}
            </div>

            <AddNetwork
                open={addingNetwork}
                form={form}
                onClose={() => {
                    setAddingNetwork(false);
                    router.reload({ except: ['deviceForm'] });
                }}
            />
            <HubPopups state={hubs} form={form} canApprove={can.approve_hubs} canStopScan={can.manage} />
            <ConfirmDialog
                open={leaving !== null}
                onClose={() => setLeaving(null)}
                onConfirm={() => {
                    const target = leaving;
                    allowLeave.current = true;
                    setLeaving(null);
                    if (target) router.visit(target);
                }}
                title="Leave without saving?"
                description={draft.changes.length === 1 ? 'Your change to 1 setting hasn’t been saved and will be lost.' : `Your changes to ${draft.changes.length} settings haven’t been saved and will be lost.`}
                confirmText="Leave without saving"
                cancelText="Keep editing"
            />
        </AppLayout>
    );
}
