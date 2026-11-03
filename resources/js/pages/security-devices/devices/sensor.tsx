/**
 * A sensor's page (S&D rebuild Phase E + E6, drawings pg-sdr-7, 7b, 7c): Nights · Readings · Alerts.
 *
 * - Standard sensors (7): latest readings, 24h/7d/30d trend charts against configurable alert limits,
 *   battery forecast, who is told, and where the sensor is.
 * - Bed sensors linked to a falls plan (7b): Nights view with last night's log, minutes out of bed
 *   over 7 nights, the 4-check readiness ring, and privacy-gated resident details.
 * - Hot-water temperature probes (7c): At the tap now, Hottest today, H&S hazard and Battery meters,
 *   plus the active scald-risk hazard banner.
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
import { PopupCallout, PopupField, PopupForm } from '@/components/popup';
import { TrendChart, type TrendRangeKey } from '@/components/security-devices/device-blocks';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { DeviceFormPopup, useDeviceFormOptions } from '@/components/security-devices/device-popups';
import { PersonLinkPopup } from '@/components/security-devices/person-link-popup';
import { Log, Note, PeopleCards, PointBars, QuietLine, Ring, RingCard, SideBySide, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Pencil, SlidersHorizontal } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { MainSide } from './_device-page-parts';
import { AlertLog, FactLines, fill, popupDevice, useNow } from './_sensor-tracker-parts';
import type {
    SensorEditableRules,
    SensorNightsData,
    SensorPageProps,
    SensorRuleRow,
    SensorScaldAlert,
    SensorSeries,
    SensorTab,
} from './_sensor-tracker-types';

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

type DraftMetric = {
    key: string;
    label: string;
    unit: string;
    mode: 'range' | 'threshold';
    enabled: boolean;
    below: string;
    above: string;
    warning_at: string;
    critical_at: string;
};

function SensorLimitsPopup({
    open,
    deviceId,
    deviceName,
    editable,
    onClose,
}: {
    open: boolean;
    deviceId: number;
    deviceName: string;
    editable: SensorEditableRules;
    onClose: () => void;
}) {
    const [metrics, setMetrics] = useState<DraftMetric[]>([]);
    const [batteryEnabled, setBatteryEnabled] = useState(true);
    const [batteryBelow, setBatteryBelow] = useState('20');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>(undefined);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!open) return;
        setMetrics(
            editable.metrics.map((item) => ({
                key: item.key,
                label: item.label,
                unit: item.unit,
                mode: item.mode,
                enabled: item.enabled,
                below: item.below === null ? '' : String(item.below),
                above: item.above === null ? '' : String(item.above),
                warning_at: item.warning_at === null ? '' : String(item.warning_at),
                critical_at: item.critical_at === null ? '' : String(item.critical_at),
            })),
        );
        if (editable.battery) {
            setBatteryEnabled(editable.battery.enabled);
            setBatteryBelow(String(editable.battery.below));
        }
        setError(undefined);
        setFieldErrors({});
    }, [open, editable]);

    const updateMetric = (key: string, patch: Partial<DraftMetric>) => {
        setMetrics((prev) => prev.map((item) => (item.key === key ? { ...item, ...patch } : item)));
    };

    const parseNullable = (raw: string): number | null | string => {
        const trimmed = raw.trim();
        if (trimmed === '') return null;
        const parsed = Number(trimmed);
        return Number.isFinite(parsed) ? parsed : trimmed;
    };

    const submit = async () => {
        setBusy(true);
        setError(undefined);
        setFieldErrors({});
        try {
            const metricsPayload = Object.fromEntries(
                metrics.map((item) => [
                    item.key,
                    item.mode === 'threshold'
                        ? {
                              enabled: item.enabled,
                              warning_at: parseNullable(item.warning_at),
                              critical_at: parseNullable(item.critical_at),
                          }
                        : {
                              enabled: item.enabled,
                              below: parseNullable(item.below),
                              above: parseNullable(item.above),
                          },
                ]),
            );
            const batteryPayload = editable.battery
                ? {
                      enabled: batteryEnabled,
                      below: batteryBelow.trim() === '' ? 20 : Number(batteryBelow),
                  }
                : null;

            await axios.put(`/security-devices/devices/${deviceId}/alert-limits`, {
                metrics: metricsPayload,
                ...(batteryPayload ? { battery: batteryPayload } : {}),
            });
            onClose();
            router.reload();
        } catch (failure) {
            const response = axios.isAxiosError(failure) ? failure.response : undefined;
            if (response?.status === 422 && response.data?.errors) {
                const errors = Object.fromEntries(
                    Object.entries(response.data.errors as Record<string, string[]>).map(([k, list]) => [k, list[0] ?? '']),
                );
                setFieldErrors(errors);
                setError(Object.values(errors)[0] ?? 'Check the highlighted limits.');
            } else {
                setError(
                    response?.status === 403
                        ? 'You don’t have permission to change sensor alert limits.'
                        : 'Alert limits couldn’t be saved. Try again.',
                );
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={SlidersHorizontal}
            title="Edit alert limits"
            description={`Alert thresholds for ${deviceName}`}
            error={error}
            processing={busy}
            onSubmit={() => {
                void submit();
            }}
            submitLabel="Save limits"
        >
            <PopupCallout tone="info">
                Reading alerts go to staff on shift at this Site in the app. Leave either end blank when only a low or high limit is needed (for example, below 16 °C only).
            </PopupCallout>
            {metrics.map((item) => (
                <div key={item.key} className="col-span-full grid gap-3 rounded-lg border border-border bg-muted/30 p-3.5">
                    <div className="flex items-center justify-between gap-3">
                        <span className="grid min-w-0">
                            <b className="text-sm font-semibold">{item.label}</b>
                            <small className="text-caption">Unit: {item.unit}</small>
                        </span>
                        <Switch
                            checked={item.enabled}
                            disabled={busy}
                            aria-label={`${item.label} alert`}
                            onCheckedChange={(enabled) => updateMetric(item.key, { enabled })}
                        />
                    </div>
                    {item.mode === 'threshold' ? (
                        <div className="grid grid-cols-2 gap-3">
                            <PopupField label={`Alert at (${item.unit})`} htmlFor={`limit-${item.key}-warn`} error={fieldErrors[`metrics.${item.key}`]}>
                                <Input
                                    id={`limit-${item.key}-warn`}
                                    type="number"
                                    step="1"
                                    disabled={busy || !item.enabled}
                                    value={item.warning_at}
                                    onChange={(event) => updateMetric(item.key, { warning_at: event.target.value })}
                                    placeholder="e.g. 1200"
                                />
                            </PopupField>
                            <PopupField label={`Urgent at (${item.unit}, optional)`} htmlFor={`limit-${item.key}-crit`}>
                                <Input
                                    id={`limit-${item.key}-crit`}
                                    type="number"
                                    step="1"
                                    disabled={busy || !item.enabled}
                                    value={item.critical_at}
                                    onChange={(event) => updateMetric(item.key, { critical_at: event.target.value })}
                                    placeholder="Leave blank if none"
                                />
                            </PopupField>
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 gap-3">
                            <PopupField label={`Low limit (${item.unit})`} htmlFor={`limit-${item.key}-below`} error={fieldErrors[`metrics.${item.key}`]}>
                                <Input
                                    id={`limit-${item.key}-below`}
                                    type="number"
                                    step="0.5"
                                    disabled={busy || !item.enabled}
                                    value={item.below}
                                    onChange={(event) => updateMetric(item.key, { below: event.target.value })}
                                    placeholder="e.g. 16"
                                />
                            </PopupField>
                            <PopupField label={`High limit (${item.unit})`} htmlFor={`limit-${item.key}-above`}>
                                <Input
                                    id={`limit-${item.key}-above`}
                                    type="number"
                                    step="0.5"
                                    disabled={busy || !item.enabled}
                                    value={item.above}
                                    onChange={(event) => updateMetric(item.key, { above: event.target.value })}
                                    placeholder="Leave blank if none"
                                />
                            </PopupField>
                        </div>
                    )}
                </div>
            ))}
            {editable.battery ? (
                <div className="col-span-full grid gap-3 rounded-lg border border-border bg-muted/30 p-3.5">
                    <div className="flex items-center justify-between gap-3">
                        <span className="grid min-w-0">
                            <b className="text-sm font-semibold">Low battery</b>
                            <small className="text-caption">Included in the 6:00 am morning summary</small>
                        </span>
                        <Switch
                            checked={batteryEnabled}
                            disabled={busy}
                            aria-label="Low battery alert"
                            onCheckedChange={setBatteryEnabled}
                        />
                    </div>
                    <PopupField label="Alert under (%)" htmlFor="limit-battery-below" error={fieldErrors['battery.below']}>
                        <Input
                            id="limit-battery-below"
                            type="number"
                            min={5}
                            max={90}
                            step={1}
                            disabled={busy || !batteryEnabled}
                            value={batteryBelow}
                            onChange={(event) => setBatteryBelow(event.target.value)}
                        />
                    </PopupField>
                </div>
            ) : null}
        </PopupForm>
    );
}

function AlertRules({
    rules,
    deviceId,
    canManage,
    hasEditable,
    onEdit,
}: {
    rules: SensorRuleRow[];
    deviceId: number;
    canManage: boolean;
    hasEditable: boolean;
    onEdit: () => void;
}) {
    const [busyKey, setBusyKey] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    if (rules.length === 0 && !canManage) return null;

    const toggleRule = async (rule: SensorRuleRow, enabled: boolean) => {
        if (!rule.key) return;
        setBusyKey(rule.key);
        setError(null);
        try {
            const payload =
                rule.key === 'battery'
                    ? { battery: { enabled } }
                    : { metrics: { [rule.key]: { enabled } } };
            await axios.put(`/security-devices/devices/${deviceId}/alert-limits`, payload);
            router.reload();
        } catch {
            setError('That alert rule couldn’t be changed. Try again.');
        } finally {
            setBusyKey(null);
        }
    };

    return (
        <>
            <Surface
                title="Alert rules"
                sub="Who’s told and how"
                action={canManage && hasEditable ? { label: 'Edit limits', onClick: onEdit } : null}
            >
                {rules.length > 0 ? (
                    <ul className="grid gap-2 px-[18px] pt-2 pb-4">
                        {rules.map((rule) => {
                            const on = rule.enabled ?? true;
                            return (
                                <li key={rule.key ?? rule.label} className="flex items-center gap-3 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                                    <span className="grid min-w-0 flex-1">
                                        <b className="text-sm font-semibold">{rule.label}</b>
                                        <small className="text-caption">{rule.hint}</small>
                                    </span>
                                    {canManage && rule.key ? (
                                        <Switch
                                            checked={on}
                                            disabled={busyKey !== null}
                                            aria-label={rule.label}
                                            onCheckedChange={(checked) => void toggleRule(rule, checked)}
                                        />
                                    ) : (
                                        <StatusBadge variant={on ? 'success' : 'neutral'}>{on ? 'On' : 'Off'}</StatusBadge>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                ) : (
                    <p className="px-[18px] pt-2 pb-4 text-sm text-muted-foreground">No alert limits set for this sensor yet.</p>
                )}
            </Surface>
            {error ? (
                <Note tone="critical">
                    <span role="alert">{error}</span>
                </Note>
            ) : null}
        </>
    );
}

/**
 * S&D E6 (pg-sdr-7c): active scald-risk alert and H&S hazard callout at the top of a hot-water probe's Readings view.
 */
function ScaldHazardBanner({ alert, now }: { alert: SensorScaldAlert; now: Date }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const raiseHazard = async () => {
        setBusy(true);
        setError(null);
        try {
            const response = await axios.post<{ url?: string }>(`/security-devices/alerts/${alert.id}/hazard`);
            if (response.data?.url) {
                router.visit(response.data.url);
            } else {
                router.reload();
            }
        } catch (failure) {
            const status = axios.isAxiosError(failure) ? failure.response?.status : undefined;
            setError(status === 403 ? 'You don’t have permission to raise an H&S hazard.' : 'The H&S hazard couldn’t be raised. Try again.');
        } finally {
            setBusy(false);
        }
    };

    const openedWords = alert.opened_at ? fill({ text: '{time}', at: alert.opened_at }, now) : null;
    const kicker = alert.hazard_raised
        ? `H&S hazard raised${openedWords ? ` · ${openedWords}` : ''}`
        : `Scald risk alert open${openedWords ? ` · ${openedWords}` : ''}`;

    return (
        <>
            <Surface tone="critical">
                <div className="flex flex-wrap items-start justify-between gap-4 px-[18px] py-4">
                    <div className="grid min-w-0 flex-1 gap-1">
                        <span className="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                            <Ring tone="critical" />
                            {kicker}
                        </span>
                        <h3 className="text-base font-semibold text-foreground">{alert.title}</h3>
                        <p className="text-sm text-muted-foreground">
                            {alert.hazard_raised
                                ? 'Raised in Health & safety · get the tempering valve checked so the tap stays at or under 45 °C.'
                                : 'Hot water above 45 °C can scald quickly. Raise the H&S hazard and get the tempering valve checked.'}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button size="sm" variant="outline" onClick={() => router.visit(alert.alert_href)}>
                            Open alert
                        </Button>
                        {alert.hazard_url ? (
                            <Button size="sm" onClick={() => router.visit(alert.hazard_url ?? '')}>
                                Open hazard
                            </Button>
                        ) : alert.can_raise_hazard ? (
                            <Button size="sm" disabled={busy} onClick={() => void raiseHazard()}>
                                Raise H&S hazard
                            </Button>
                        ) : null}
                    </div>
                </div>
            </Surface>
            {error ? (
                <Note tone="critical">
                    <span role="alert">{error}</span>
                </Note>
            ) : null}
        </>
    );
}

/**
 * S&D Phase F (7b, 7c): who this sensor is linked to and their plan's rule (or Link to a person),
 * and for a temperature probe the hot-water scald watch.
 */
function CareCard({ care, deviceId, onLink }: { care: NonNullable<SensorPageProps['care']>; deviceId: number; onLink: () => void }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const call = async (request: () => Promise<unknown>, failed: string) => {
        setBusy(true);
        setError(null);
        try {
            await request();
            router.reload();
        } catch (failure) {
            const status = axios.isAxiosError(failure) ? failure.response?.status : undefined;
            setError(status === 403 ? 'You can’t change this.' : status ? failed : 'Result not confirmed. Reload to check.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            {care.link ? (
                <Surface title={care.link.title} sub={care.link.plan ? `From ${care.link.plan} · alerts go to whoever is on shift` : 'Alerts go to whoever is on shift'}>
                    <div className="grid gap-3 px-[18px] pt-2 pb-4">
                        {care.link.rule ? <p className="text-sm">{care.link.rule}</p> : null}
                        <div className="flex flex-wrap gap-2">
                            {care.link.person_href ? (
                                <Button size="sm" variant="outline" onClick={() => router.visit(care.link?.person_href ?? '')}>
                                    Open their record
                                </Button>
                            ) : null}
                            {care.can_link ? (
                                <Button size="sm" variant="ghost" disabled={busy} onClick={() => void call(() => axios.delete(`/security-devices/devices/${deviceId}/care-link`), 'It wasn’t unlinked. Try again.')}>
                                    Unlink
                                </Button>
                            ) : null}
                        </div>
                    </div>
                </Surface>
            ) : care.can_link ? (
                <QuietLine title="Care" text="Not linked to anyone’s care." action={{ label: 'Link to a person', onClick: onLink }} />
            ) : null}
            {care.scald ? (
                <Surface title="Scald watch" sub="Hot water over 45 °C, three readings in a row">
                    <div className="flex items-center gap-3 px-[18px] pt-2 pb-4">
                        <span className="grid min-w-0 flex-1">
                            <b className="text-sm font-semibold">Watch this tap for scald risk</b>
                            <small className="text-caption">Opens a critical alert for the staff on shift, with the H&S hazard ready to raise</small>
                        </span>
                        {care.scald.can_change ? (
                            <Switch
                                checked={care.scald.on}
                                disabled={busy}
                                aria-label="Watch this tap for scald risk"
                                onCheckedChange={(on) => void call(() => axios.put(`/security-devices/devices/${deviceId}/scald-watch`, { on }), 'It wasn’t changed. Try again.')}
                            />
                        ) : (
                            <StatusBadge variant={care.scald.on ? 'success' : 'neutral'}>{care.scald.on ? 'On' : 'Off'}</StatusBadge>
                        )}
                    </div>
                </Surface>
            ) : null}
            {error ? (
                <Note tone="critical">
                    <span role="alert">{error}</span>
                </Note>
            ) : null}
        </>
    );
}

/**
 * S&D E6 (pg-sdr-7b): bed sensor Nights view — care rule banner, last night's log, 7-night out-of-bed
 * bar chart, 4-check readiness ring, linked resident card, and what it senses.
 */
function NightsView({
    nights,
    deviceId,
    now,
    onLink,
}: {
    nights: SensorNightsData;
    deviceId: number;
    now: Date;
    onLink: () => void;
}) {
    const [unlinking, setUnlinking] = useState(false);

    const unlink = async () => {
        setUnlinking(true);
        try {
            await axios.delete(`/security-devices/devices/${deviceId}/care-link`);
            router.reload();
        } finally {
            setUnlinking(false);
        }
    };

    return (
        <MainSide
            main={
                <>
                    {nights.care_banner ? (
                        <Surface>
                            <div className="grid gap-3 px-[18px] py-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="grid min-w-0 flex-1 gap-1">
                                        <span className="text-xs font-medium text-muted-foreground">{nights.care_banner.kicker}</span>
                                        <h3 className="text-base font-semibold text-foreground">{nights.care_banner.title}</h3>
                                        <p className="text-sm text-muted-foreground">{nights.care_banner.sub}</p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {nights.care_banner.person_href ? (
                                            <Button size="sm" variant="outline" onClick={() => router.visit(nights.care_banner?.person_href ?? '')}>
                                                Open falls plan
                                            </Button>
                                        ) : null}
                                        {nights.can_link ? (
                                            <Button size="sm" variant="ghost" disabled={unlinking} onClick={() => void unlink()}>
                                                Unlink
                                            </Button>
                                        ) : null}
                                    </div>
                                </div>
                                <p className="flex items-center gap-2 border-t border-border pt-3 text-sm text-muted-foreground">
                                    <Ring tone="none" />
                                    <span>
                                        Night hours: <b className="font-semibold text-foreground">{nights.care_banner.night_hours}</b>. Outside them, getting up doesn’t alert anyone.
                                    </span>
                                </p>
                            </div>
                        </Surface>
                    ) : (
                        <QuietLine
                            title="Care rule"
                            text="Not linked to anyone’s falls plan."
                            action={nights.can_link ? { label: 'Link to a person', onClick: onLink } : null}
                        />
                    )}
                    {nights.last_night.items.length > 0 ? (
                        <Log
                            title="Last night"
                            sub={nights.last_night.sub}
                            limit={nights.last_night.items.length}
                            items={nights.last_night.items.map((item) => ({
                                key: item.key,
                                time: item.time,
                                tone: item.tone,
                                title: item.title,
                                sub: item.sub ?? undefined,
                            }))}
                        />
                    ) : (
                        <QuietLine title="Last night" sub={nights.last_night.sub} text={nights.last_night.empty ?? 'No bed occupancy readings were recorded last night.'} />
                    )}
                    <PointBars
                        title="Minutes out of bed at night"
                        sub="Last 7 nights"
                        values={nights.seven_nights.values}
                        labels={nights.seven_nights.labels}
                        note={nights.seven_nights.note}
                    />
                </>
            }
            side={
                <>
                    <RingCard
                        value={nights.readiness.passed}
                        of={nights.readiness.of}
                        label={nights.readiness.label}
                        sub={fill(nights.readiness.sub, now)}
                        checks={nights.readiness.checks.map((item) => ({
                            state: item.state,
                            label: item.label,
                            note: item.note ?? undefined,
                        }))}
                    />
                    {nights.person_card ? (
                        <PeopleCards
                            title="Linked to"
                            cols={1}
                            items={[
                                {
                                    name: nights.person_card.name,
                                    sub: nights.person_card.sub ?? undefined,
                                    flag: <StatusBadge variant="warning">{nights.person_card.flag}</StatusBadge>,
                                    href: nights.person_card.href ?? undefined,
                                    foot: nights.person_card.key_worker ? (
                                        <>
                                            <b>{nights.person_card.key_worker}</b> key worker
                                        </>
                                    ) : undefined,
                                },
                            ]}
                        />
                    ) : null}
                    <FactLines title="What it senses" rows={nights.what_it_senses} now={now} />
                </>
            }
        />
    );
}

export default function SensorPage(props: SensorPageProps) {
    const { device, tab, can } = props;
    const now = useNow();
    const [editing, setEditing] = useState(false);
    const [linking, setLinking] = useState(false);
    const [editingLimits, setEditingLimits] = useState(false);
    const { form, load } = useDeviceFormOptions();
    const base = `/security-devices/devices/${device.id}`;
    const defaultTab = props.tabs[0]?.key ?? 'readings';

    const go = (changes: { tab?: SensorTab; range?: TrendRangeKey }) => {
        const next = { tab: changes.tab ?? tab, range: changes.range ?? props.range };
        router.get(
            base,
            Object.fromEntries(
                Object.entries({ tab: next.tab === defaultTab ? undefined : next.tab, range: next.range === '24h' ? undefined : next.range }).filter(([, value]) => value !== undefined),
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
    if (tab === 'nights' && props.nights) {
        body = <NightsView nights={props.nights} deviceId={device.id} now={now} onLink={() => setLinking(true)} />;
    } else if (tab === 'readings' && props.readings) {
        const readings = props.readings;
        const scaldAlert = props.care?.scald?.alert ?? null;
        body = (
            <MainSide
                main={
                    <>
                        {scaldAlert ? <ScaldHazardBanner alert={scaldAlert} now={now} /> : null}
                        <Readings series={readings.series} range={props.range} onRange={(range) => go({ range })} />
                        {scaldAlert ? (
                            <QuietLine
                                title="When it’s fixed"
                                text="Oblivion closes this alert once the tap stays at or under 45 °C for 24 hours, and tells whoever has the hazard so it can be closed."
                            />
                        ) : null}
                    </>
                }
                side={
                    <>
                        {props.care ? <CareCard care={props.care} deviceId={device.id} onLink={() => setLinking(true)} /> : null}
                        <AlertRules
                            rules={readings.rules}
                            deviceId={device.id}
                            canManage={Boolean(can.manage_rules)}
                            hasEditable={Boolean(readings.editable_rules)}
                            onEdit={() => setEditingLimits(true)}
                        />
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
            {props.readings?.editable_rules && can.manage_rules ? (
                <SensorLimitsPopup
                    open={editingLimits}
                    deviceId={device.id}
                    deviceName={device.name}
                    editable={props.readings.editable_rules}
                    onClose={() => setEditingLimits(false)}
                />
            ) : null}
            {props.care?.can_link || props.nights?.can_link ? (
                <PersonLinkPopup
                    open={linking}
                    device={{ id: device.id, name: device.name, site: device.site }}
                    onClose={() => setLinking(false)}
                    onLinked={() => {
                        setLinking(false);
                        router.reload();
                    }}
                />
            ) : null}
        </AppLayout>
    );
}
