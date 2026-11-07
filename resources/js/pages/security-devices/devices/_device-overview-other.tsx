/**
 * The overview for printers (pg-sdr-10), UPSs (pg-sdr-16), video recorders (pg-sdr-21) and other
 * devices. Sensor gateways (pg-sdr-22) have their own overview (_gateway-overview.tsx). Readings
 * the device doesn't send say "Not available"; nothing is filled in for it.
 */
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { TrendChart, type TrendRangeKey } from '@/components/security-devices/device-blocks';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import type { PopupDevice } from '@/components/security-devices/device-popups';
import { FactRows, QuietLine, SectionHead } from '@/components/signature/blocks';
import { router } from '@inertiajs/react';
import { DeviceCard } from './_device-card';
import type { DevicePageProps } from './_device-page-types';
import { MainSide } from './_device-page-parts';
import { aboutTitle, KIND_NOUN, OnlineDonut, RecentLog, ReplyTimeTrend } from './_device-overview-network';

export function OtherOverviewTab({
    props,
    now,
    onRange,
    menuFor,
}: {
    props: DevicePageProps;
    now: Date;
    onRange: (range: TrendRangeKey) => void;
    menuFor: (device: PopupDevice) => MenuItem[];
}) {
    const menu = useEntityContextMenu<PopupDevice>();
    const overview = props.overview;
    if (!overview) return null;
    const kind = props.device.kind;
    const noun = KIND_NOUN[kind] ?? 'device';
    const reading = overview.reading_trend ?? null;
    const readingBlock = reading ? (
        <TrendChart
            title={reading.title ?? 'Reading'}
            sub="From its own reports"
            start={reading.start}
            step={reading.step_seconds}
            points={reading.points}
            unit={reading.unit}
            peakWords="Highest"
        />
    ) : kind === 'printer' ? (
        <QuietLine title="Toner left" text="Not available: this printer doesn’t send its toner level or page counts to Oblivion." />
    ) : kind === 'ups' ? (
        <QuietLine title="Battery and load" text="Not available: this UPS doesn’t send its battery, load or runtime to Oblivion." />
    ) : null;

    const cameras = overview.cameras ?? [];
    const factsTitle = kind === 'recorder' ? 'For IT' : aboutTitle(kind);

    return (
        <MainSide
            main={
                <>
                    {kind === 'recorder' ? (
                        cameras.length > 0 ? (
                            <section>
                                <SectionHead title="Cameras on this recorder" sub="Each camera’s own checks over the last 24 hours" />
                                <div className="grid gap-5 [grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr))]">
                                    {cameras.map((camera) => (
                                        <DeviceCard
                                            key={camera.id}
                                            device={camera}
                                            actions={menuFor(camera)}
                                            onOpen={() => router.visit(camera.href)}
                                            onContextMenu={(event) => menu.open(event, camera)}
                                            selectable={false}
                                            selected={false}
                                            onToggle={() => undefined}
                                            now={now}
                                        />
                                    ))}
                                </div>
                            </section>
                        ) : (
                            <QuietLine title="Cameras on this recorder" text="No cameras are linked to this recorder yet." />
                        )
                    ) : null}
                    {readingBlock}
                    <ReplyTimeTrend overview={overview} range={props.range} onRange={onRange} noun={noun} />
                    {kind === 'ups' ? (
                        <RecentLog title="Power history" entries={overview.events} canSee={props.can.see_events} now={now} />
                    ) : null}
                </>
            }
            side={
                <>
                    <OnlineDonut overview={overview} />
                    {kind !== 'ups' ? <RecentLog title="Recent" entries={overview.events} canSee={props.can.see_events} now={now} /> : null}
                    <FactRows title={factsTitle} rows={overview.facts} />
                    {menu.ctx ? (
                        <EntityContextMenu
                            x={menu.ctx.x}
                            y={menu.ctx.y}
                            icon={iconForKind(menu.ctx.record.kind ?? 'other')}
                            title={menu.ctx.record.name}
                            items={menuFor(menu.ctx.record)}
                            onClose={menu.close}
                        />
                    ) : null}
                </>
            }
        />
    );
}
