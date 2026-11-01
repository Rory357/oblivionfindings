/**
 * A sensor's page (S&D rebuild Phase E, drawing pg-sdr-7): Readings · Alerts, on the same URL as
 * every device page. The readings now with their state, each reading over 24 hours, 7 days or 30
 * days against the lines where Oblivion opens an alert, the battery and its forecast, who is told,
 * where the sensor is and the gateway it reports through.
 *
 * Not offered here, on purpose: the History and Settings tabs (not drawn), Add alert rule (alert
 * rules cover IT devices only; a sensor's limits are built in), and everything Phase F: a person or
 * care plan linked to the sensor and automations (drawings 7b and 7c).
 */
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { TrendChart, type TrendRangeKey } from '@/components/security-devices/device-blocks';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { DeviceFormPopup, useDeviceFormOptions } from '@/components/security-devices/device-popups';
import { QuietLine, SideBySide, Surface } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { MainSide } from './_device-page-parts';
import { AlertLog, FactLines, fill, popupDevice, useNow } from './_sensor-tracker-parts';
import type { SensorPageProps, SensorSeries, SensorTab } from './_sensor-tracker-types';

export type { SensorPageProps } from './_sensor-tracker-types';

const RANGES: TrendRangeKey[] = ['24h', '7d', '30d'];

/** Readings where 0 means nothing on its own get an axis fitted to the values. */
const FITTED = new Set(['temperature', 'pressure']);

function ReadingChart({ series, range, onRange, half = false }: { series: SensorSeries; range: TrendRangeKey; onRange?: (range: TrendRangeKey) => void; half?: boolean }) {
    return (
        <TrendChart
            title={series.title}
            sub={series.key === 'co2' ? 'How fresh the air is · parts per million (ppm)' : undefined}
            start={series.start}
            step={series.step_seconds}
            points={series.points}
            unit={series.unit}
            range={range}
            ranges={onRange ? RANGES : undefined}
            onRange={onRange}
            limits={series.limits}
            fit={FITTED.has(series.key)}
            legend={series.title}
            ticks={half ? 5 : 8}
        />
    );
}

/** Graphs two to a row; an odd last one takes the full width and carries the range switch. */
function Readings({ series, range, onRange }: { series: SensorSeries[]; range: TrendRangeKey; onRange: (range: TrendRangeKey) => void }) {
    if (series.length === 0) {
        return <QuietLine title="Readings" text="This sensor hasn’t sent any readings yet." />;
    }
    const rows: SensorSeries[][] = [];
    for (let index = 0; index < series.length; index += 2) rows.push(series.slice(index, index + 2));
    const last = series[series.length - 1];
    return (
        <>
            {rows.map((row) =>
                row.length === 2 ? (
                    <SideBySide key={row[0].key}>
                        {row.map((item) => (
                            <ReadingChart key={item.key} series={item} range={range} onRange={item === last ? onRange : undefined} half />
                        ))}
                    </SideBySide>
                ) : (
                    <ReadingChart key={row[0].key} series={row[0]} range={range} onRange={row[0] === last ? onRange : undefined} />
                ),
            )}
        </>
    );
}

function AlertRules({ rules }: { rules: { label: string; hint: string }[] }) {
    if (rules.length === 0) return null;
    return (
        <Surface title="Alert rules" sub="Built into Oblivion · who’s told and how">
            <ul className="grid gap-2 px-[18px] pt-2 pb-4">
                {rules.map((rule) => (
                    <li key={rule.label} className="flex items-center gap-3 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                        <span className="grid min-w-0 flex-1">
                            <b className="text-sm font-semibold">{rule.label}</b>
                            <small className="text-caption">{rule.hint}</small>
                        </span>
                        <StatusBadge variant="success">On</StatusBadge>
                    </li>
                ))}
            </ul>
        </Surface>
    );
}

export default function SensorPage(props: SensorPageProps) {
    const { device, tab, can } = props;
    const now = useNow();
    const [editing, setEditing] = useState(false);
    const { form, load } = useDeviceFormOptions();
    const base = `/security-devices/devices/${device.id}`;

    const go = (changes: { tab?: SensorTab; range?: TrendRangeKey }) => {
        const next = { tab: changes.tab ?? tab, range: changes.range ?? props.range };
        router.get(
            base,
            Object.fromEntries(
                Object.entries({ tab: next.tab === 'readings' ? undefined : next.tab, range: next.range === '24h' ? undefined : next.range }).filter(([, value]) => value !== undefined),
            ),
            { preserveState: true, preserveScroll: true },
        );
    };

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
                can.edit ? (
                    <PageHeaderGlassButton
                        icon={Pencil}
                        onClick={() => {
                            load();
                            setEditing(true);
                        }}
                    >
                        Edit
                    </PageHeaderGlassButton>
                ) : undefined
            }
            meters={
                <>
                    {props.meters.map((meter) => (
                        <PageHeaderMeterBlock
                            key={meter.key}
                            label={meter.label}
                            tone={meter.tone}
                            onClick={() => go({ tab: meter.tab })}
                            ariaLabel={`${meter.label}: open ${props.tabs.find((item) => item.key === meter.tab)?.label ?? 'Readings'}`}
                        >
                            <PageHeaderMeterBig>{meter.value ?? 'Not available'}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>{fill(meter.caption, now)}</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ))}
                </>
            }
            rail={
                <PageHeaderRail<SensorTab>
                    ariaLabel={`${device.name}: views`}
                    items={props.tabs.map((item) => ({ key: item.key, label: item.label }))}
                    value={tab}
                    onSelect={(key) => go({ tab: key })}
                />
            }
        />
    );

    let body: ReactNode = null;
    if (tab === 'readings' && props.readings) {
        const readings = props.readings;
        body = (
            <MainSide
                main={<Readings series={readings.series} range={props.range} onRange={(range) => go({ range })} />}
                side={
                    <>
                        <AlertRules rules={readings.rules} />
                        <FactLines
                            title="Where it is"
                            rows={readings.where.rows}
                            now={now}
                            action={readings.where.gateway_href ? { label: 'Open gateway', href: readings.where.gateway_href } : null}
                        />
                    </>
                }
            />
        );
    } else if (tab === 'alerts' && props.alerts) {
        body = <AlertLog title="Alerts" sub="The last 90 days · each opens in Alerts" entries={props.alerts} now={now} empty="No alerts for this sensor in the last 90 days." />;
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
