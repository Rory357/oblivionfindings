/**
 * Sites (S&D rebuild drawing pg-sdr-11): one card per house and office, how its devices are doing
 * and which hub watches it. Problems come first in the "Need attention" tab.
 */
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { ScanNetwork, ScanningViewer } from '@/components/security-devices/scan-popups';
import { useDeviceFormOptions } from '@/components/security-devices/device-popups';
import { PlaceCards, QuietLine } from '@/components/signature/blocks';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Cpu, House, Radar } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { FactText, SiteFlagBadge } from './_parts';
import type { SiteCard, SitesIndexProps, SitesTab } from './_types';

export type { SitesIndexProps } from './_types';

const BASE = '/security-devices/sites';

const TAB_LABEL: Record<SitesTab, string> = {
    all: 'All',
    attention: 'Need attention',
    not_set_up: 'Not set up',
};

function useNow(): Date {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 60_000);
        return () => window.clearInterval(timer);
    }, []);
    return now;
}

type Popup = { kind: 'scan'; siteId: number } | { kind: 'scanning'; runId: number } | null;

export default function SitesIndex({ tab, filters, regions, tabs, meters, cards, can }: SitesIndexProps) {
    const now = useNow();
    const [search, setSearch] = useState(filters.q ?? '');
    const [popup, setPopup] = useState<Popup>(null);
    const searchTimer = useRef<number | null>(null);
    const menu = useEntityContextMenu<SiteCard>();
    const { form, load } = useDeviceFormOptions();

    const go = (changes: Record<string, string | undefined>) => {
        menu.close();
        const next: Record<string, string | undefined> = {
            tab: tab === 'all' ? undefined : tab,
            region: filters.region ?? undefined,
            q: filters.q ?? undefined,
            ...changes,
        };
        router.get(
            BASE,
            Object.fromEntries(Object.entries(next).filter(([, value]) => value !== undefined && value !== '' && value !== 'all')),
            { preserveState: true, preserveScroll: true },
        );
    };

    const updateSearch = (value: string) => {
        setSearch(value);
        if (searchTimer.current) window.clearTimeout(searchTimer.current);
        searchTimer.current = window.setTimeout(() => go({ q: value.trim() || undefined }), 350);
    };

    const actionsFor = (card: SiteCard): MenuItem[] => [
        { label: 'Open', icon: House, onClick: () => router.visit(card.href) },
        { label: 'Devices at this Site', icon: Cpu, onClick: () => router.visit(`/security-devices/devices?site=${card.id}`) },
        ...(can.scan
            ? [{
                label: 'Scan its network',
                icon: Radar,
                onClick: () => {
                    load();
                    setPopup({ kind: 'scan', siteId: card.id });
                },
            }]
            : []),
    ];

    const header = (
        <PageHeader
            icon={House}
            title="Sites"
            subline="Each house and office, how its devices are doing and which hub watches it"
            actions={<PageHeaderSearch value={search} onChange={updateSearch} placeholder="Search Sites" />}
            meters={
                <>
                    <PageHeaderMeterBlock label="Sites set up" onClick={() => go({ tab: 'not_set_up' })} ariaLabel="Show Sites without a hub">
                        <PageHeaderMeterBig>{`${meters.set_up.count} of ${meters.set_up.of}`}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{meters.set_up.caption}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Hubs answering"
                        tone={meters.hubs.of > 0 && meters.hubs.online === meters.hubs.of ? 'success' : meters.hubs.of > 0 ? 'critical' : 'brand'}
                        onClick={() => go({ tab: undefined })}
                        ariaLabel="Show all Sites"
                    >
                        <PageHeaderMeterBig>{`${meters.hubs.online} of ${meters.hubs.of}`}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{meters.hubs.caption}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Sites needing attention"
                        tone={meters.attention.count > 0 ? 'warning' : 'success'}
                        onClick={() => go({ tab: 'attention' })}
                        ariaLabel="Show Sites that need attention"
                    >
                        <PageHeaderMeterBig>{meters.attention.count}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{meters.attention.caption}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                regions.length > 1 ? (
                    <PageHeaderFilterSelect
                        label="All regions"
                        value={filters.region ?? 'all'}
                        options={regions.map((region) => ({ value: region, label: region }))}
                        onChange={(value) => go({ region: value })}
                    />
                ) : undefined
            }
            rail={
                <PageHeaderRail<SitesTab>
                    ariaLabel="Which Sites"
                    items={(Object.keys(TAB_LABEL) as SitesTab[]).map((key) => ({ key, label: TAB_LABEL[key], count: tabs[key] }))}
                    value={tab}
                    onSelect={(key) => go({ tab: key === 'all' ? undefined : key })}
                />
            }
        />
    );

    const empty =
        filters.q || filters.region
            ? 'No Sites match. Try another search or region.'
            : tab === 'attention'
              ? 'Nothing needs attention at any Site.'
              : tab === 'not_set_up'
                ? 'Every Site has a hub watching it.'
                : 'No Sites yet.';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & devices', href: '/security-devices' },
                { title: 'Sites', href: BASE },
            ]}
        >
            <Head title="Sites" />
            <PageLayout hero={header}>
                {cards.length === 0 ? (
                    <QuietLine text={empty} />
                ) : (
                    <PlaceCards
                        cols={3}
                        items={cards.map((card) => ({
                            id: card.id,
                            name: card.name,
                            sub: [card.address, `${card.device_count} ${card.device_count === 1 ? 'device' : 'devices'}`].filter(Boolean).join(' · '),
                            flag: <SiteFlagBadge flag={card.flag} />,
                            facts: card.facts.map((fact) => ({ tone: fact.tone, text: <FactText fact={fact} now={now} /> })),
                            href: card.href,
                            menu: actionsFor(card),
                            onContextMenu: (event) => menu.open(event, card),
                        }))}
                    />
                )}
            </PageLayout>

            {menu.ctx ? (
                <EntityContextMenu x={menu.ctx.x} y={menu.ctx.y} icon={House} title={menu.ctx.record.name} items={actionsFor(menu.ctx.record)} onClose={menu.close} />
            ) : null}

            <ScanNetwork
                open={popup?.kind === 'scan'}
                form={form}
                initialSiteId={popup?.kind === 'scan' ? popup.siteId : null}
                onClose={() => setPopup(null)}
                onStarted={(runId) => setPopup({ kind: 'scanning', runId })}
            />
            <ScanningViewer open={popup?.kind === 'scanning'} runId={popup?.kind === 'scanning' ? popup.runId : null} canStop={can.scan} onClose={() => setPopup(null)} />
        </AppLayout>
    );
}
