/* Start-session wizard — the Add-client modal contract via WizardShell + primitives.
 * Step 1 "Choose the shift" (from a roster shift OR ad-hoc), Step 2 "Monitoring plan",
 * Step 3 "Review & start". Links the session to its shift and prefills from the roster. */
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import {
    Field,
    InfoCard,
    Ring,
    SelectInput,
    Segmented,
    StepHead,
} from '@/components/wizard/primitives';
import { AddressAutocomplete, type GeocodeResult } from '@/components/address-autocomplete';
import { initials } from '@/pages/health-safety/components/register-row-kit';
import { formatDateTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    Check,
    ChevronLeft,
    ChevronRight,
    Clock,
    MapPin,
    Radio,
    User,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { type Entity, LONE_WORKER_ROUTE, type Options, type ShiftOption } from './lone-worker-types';

const STEPS: WizardStep[] = [
    { key: 'shift', label: 'Choose the shift', blurb: 'Worker, site & location', icon: Calendar },
    { key: 'plan', label: 'Monitoring plan', blurb: 'Check-in cadence', icon: Clock },
    { key: 'review', label: 'Review & start', blurb: 'Confirm & begin', icon: Check },
];

const INTERVAL_OPTIONS = [
    { value: '15', label: '15m' },
    { value: '30', label: '30m' },
    { value: '60', label: '60m' },
    { value: '120', label: '2h' },
];

type WizMode = 'shift' | 'adhoc';

type Form = {
    shift_id: string;
    user_id: string;
    site_id: string;
    client_id: string;
    location: string;
    location_lat: string;
    location_lng: string;
    expected_end_at: string;
    check_in_interval_minutes: string;
    activity_description: string;
};

const EMPTY: Form = {
    shift_id: '',
    user_id: '',
    site_id: '',
    client_id: '',
    location: '',
    location_lat: '',
    location_lng: '',
    expected_end_at: '',
    check_in_interval_minutes: '30',
    activity_description: '',
};

function toLocalInput(v: string | null | undefined): string {
    if (!v) return '';
    const d = new Date(v);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function completionPct(d: Form): number {
    const fields = [d.user_id, d.site_id, d.client_id, d.location, d.expected_end_at, d.activity_description];
    const filled = fields.filter(Boolean).length + 1; // interval always set
    return Math.round((filled / 7) * 100);
}

function validateStep(key: string, d: Form): Record<string, string> {
    if (key === 'shift') {
        if (!d.user_id) return { user_id: 'Select the worker who is working alone.' };
        return {};
    }
    if (key === 'plan') {
        const e: Record<string, string> = {};
        if (!d.expected_end_at) e.expected_end_at = 'Set when the worker is expected back.';
        else if (new Date(d.expected_end_at).getTime() <= Date.now())
            e.expected_end_at = 'Expected end must be in the future.';
        return e;
    }
    return {};
}

const nameOf = (items: Entity[], id: string) => items.find((i) => String(i.id) === id)?.name ?? '—';

export function LoneWorkerWizard({
    open,
    onClose,
    options,
}: {
    open: boolean;
    onClose: () => void;
    options: Options;
}) {
    const form = useForm<Form>({ ...EMPTY });
    const [stepIndex, setStepIndex] = useState(0);
    const [mode, setMode] = useState<WizMode>('shift');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);

    const d = form.data;
    const cur = STEPS[stepIndex];
    const isReview = cur.key === 'review';
    const pct = useMemo(() => completionPct(d), [d]);
    const fieldError = (n: keyof Form) => errors[n] ?? (form.errors as Record<string, string>)[n];

    const reset = () => {
        form.reset();
        form.clearErrors();
        setErrors({});
        setMode('shift');
        setStepIndex(0);
    };

    const close = () => {
        reset();
        setDone(false);
        onClose();
    };

    const selectShift = (s: ShiftOption) => {
        form.setData((prev) => ({
            ...prev,
            shift_id: String(s.id),
            user_id: s.worker ? String(s.worker.id) : '',
            site_id: s.site ? String(s.site.id) : '',
            client_id: s.client ? String(s.client.id) : '',
            expected_end_at: toLocalInput(s.ends_at),
            location: s.location ?? prev.location,
            location_lat: s.location_lat != null ? String(s.location_lat) : '',
            location_lng: s.location_lng != null ? String(s.location_lng) : '',
        }));
        setErrors((e) => ({ ...e, user_id: '' }));
    };

    // OpenStreetMap (Nominatim) suggestion picked — fill address + coordinates.
    const onGeocode = (r: GeocodeResult) => {
        form.setData((prev) => ({
            ...prev,
            location: r.address_line_1 || r.display_name || prev.location,
            location_lat: r.lat != null ? String(r.lat) : prev.location_lat,
            location_lng: r.lng != null ? String(r.lng) : prev.location_lng,
        }));
    };

    // Ad-hoc: picking a site prefills the location + coordinates from the Site record
    // ("selectable from site"). Typing / OSM autocomplete still overrides afterwards.
    const selectSite = (siteId: string) => {
        const site = options.sites.find((s) => String(s.id) === siteId);
        form.setData((prev) => ({
            ...prev,
            site_id: siteId,
            location: site?.address ? site.address : prev.location,
            location_lat: site?.latitude != null ? String(site.latitude) : prev.location_lat,
            location_lng: site?.longitude != null ? String(site.longitude) : prev.location_lng,
        }));
    };

    const switchMode = (m: WizMode) => {
        setMode(m);
        if (m === 'adhoc') {
            // Drop the shift link but keep any captured worker/site/client.
            form.setData('shift_id', '');
        }
    };

    const next = () => {
        const e = validateStep(cur.key, d);
        setErrors(e);
        if (Object.keys(e).length === 0) setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };
    const back = () => setStepIndex((i) => Math.max(i - 1, 0));

    const submit = (stay: boolean) => {
        const all = { ...validateStep('shift', d), ...validateStep('plan', d) };
        if (Object.keys(all).length) {
            setErrors(all);
            setStepIndex(all.user_id ? 0 : 1);
            return;
        }
        form.transform((data) => ({ ...data, stay: stay ? 1 : 0 }));
        form.post(`${LONE_WORKER_ROUTE}/sessions`, {
            preserveScroll: true,
            onSuccess: () => {
                if (stay) reset();
                else setDone(true);
            },
            onError: (errs) => setStepIndex(errs.user_id ? 0 : 1),
        });
    };

    const footerEnd = isReview ? (
        <div className="flex items-center gap-2">
            <button type="button" onClick={close} className="rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground hover:text-foreground">
                Cancel
            </button>
            <button
                type="button"
                onClick={() => submit(true)}
                disabled={form.processing}
                className="rounded-lg border border-primary/40 px-3.5 py-2 text-sm font-semibold text-primary transition-colors hover:bg-primary/10 disabled:opacity-60"
            >
                Save & add another
            </button>
            <button
                type="button"
                onClick={() => submit(false)}
                disabled={form.processing}
                className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-60"
            >
                <Radio className="h-4 w-4" /> Start session
            </button>
        </div>
    ) : (
        <div className="flex items-center gap-2">
            <button type="button" onClick={close} className="rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground hover:text-foreground">
                Cancel
            </button>
            <button
                type="button"
                onClick={next}
                className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
            >
                Continue <ChevronRight className="h-4 w-4" />
            </button>
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Start lone-worker session"
            description="Begin monitoring a worker who is working alone or remotely."
            railIcon={Radio}
            railTitle="Start session"
            railSub="Lone worker monitoring"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={(i) => setStepIndex(i)}
            pct={pct}
            footerStart={stepIndex > 0 && !done ? (
                <button type="button" onClick={back} className="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground hover:text-foreground">
                    <ChevronLeft className="h-4 w-4" /> Back
                </button>
            ) : null}
            footerEnd={done ? null : footerEnd}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Session started"
                        blurb={`${nameOf(options.staff, d.user_id)} is now being monitored. Overdue check-ins will surface here and in the Control Room automatically.`}
                        actions={
                            <>
                                <button type="button" onClick={() => { setDone(false); reset(); }} className="rounded-lg border border-border px-4 py-2 text-sm font-medium text-foreground hover:bg-muted">
                                    Start another
                                </button>
                                <button type="button" onClick={close} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90">
                                    Done
                                </button>
                            </>
                        }
                    />
                ) : undefined
            }
        >
            <WizardStepPane>
                {cur.key === 'shift' && (
                    <div className="flex flex-col gap-5">
                        <StepHead
                            icon={Calendar}
                            title="Choose the shift"
                            blurb="Lone work maps to a rostered shift — pick it and the worker, site, client & end time prefill from the roster."
                        />
                        <Segmented<WizMode>
                            value={mode}
                            onChange={switchMode}
                            options={[
                                { value: 'shift', label: 'From a roster shift' },
                                { value: 'adhoc', label: 'Ad-hoc · no shift' },
                            ]}
                        />

                        {mode === 'shift' ? (
                            <div className="flex flex-col gap-2">
                                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    In-progress lone / remote shifts · from the roster
                                </p>
                                {options.shifts.length === 0 ? (
                                    <InfoCard icon={Calendar} tone="info">
                                        No in-progress shifts available to monitor right now. Switch to <strong>Ad-hoc</strong> to capture a worker manually.
                                    </InfoCard>
                                ) : (
                                    <div className="flex max-h-[300px] flex-col gap-2 overflow-y-auto pr-1">
                                        {options.shifts.map((s) => {
                                            const selected = d.shift_id === String(s.id);
                                            return (
                                                <button
                                                    key={s.id}
                                                    type="button"
                                                    onClick={() => selectShift(s)}
                                                    className={cn(
                                                        'flex items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition-colors',
                                                        selected
                                                            ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                                            : 'border-border hover:bg-muted/50',
                                                    )}
                                                >
                                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                                                        {initials(s.worker?.name)}
                                                    </span>
                                                    <span className="min-w-0 flex-1">
                                                        <span className="flex items-center gap-2">
                                                            <span className="truncate text-sm font-semibold text-foreground">{s.worker?.name ?? 'Unassigned'}</span>
                                                            {s.is_lone ? (
                                                                <span className="rounded-full bg-status-warning-bg px-1.5 py-0.5 text-[10px] font-semibold text-status-warning">
                                                                    {s.is_on_call ? 'On-call' : 'Solo cover'}
                                                                </span>
                                                            ) : null}
                                                        </span>
                                                        <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                                                            SH-{s.id} · {formatDateTime(s.starts_at)} → {formatDateTime(s.ends_at)} · {s.site?.name ?? 'No site'}
                                                            {s.client ? ` · ${s.client.name}` : ''}
                                                        </span>
                                                    </span>
                                                    {selected ? <Check className="h-4 w-4 shrink-0 text-primary" /> : null}
                                                </button>
                                            );
                                        })}
                                    </div>
                                )}
                                {fieldError('user_id') ? (
                                    <p className="flex items-center gap-1 text-xs text-status-critical">
                                        <AlertTriangle className="h-3 w-3" /> {fieldError('user_id')}
                                    </p>
                                ) : null}
                            </div>
                        ) : (
                            <div className="flex flex-col gap-4">
                                <InfoCard icon={AlertTriangle} tone="warn">
                                    No rostered shift — capture the worker manually. Ad-hoc sessions aren't linked to a timesheet or the roster.
                                </InfoCard>
                                <Field label="Worker" required error={fieldError('user_id')}>
                                    <SelectInput
                                        value={d.user_id}
                                        onChange={(v) => form.setData('user_id', v)}
                                        placeholder="Select staff member…"
                                        options={options.staff.map((s) => ({ value: String(s.id), label: s.name }))}
                                    />
                                </Field>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field label="Site" hint="prefills the address">
                                        <SelectInput
                                            value={d.site_id}
                                            onChange={selectSite}
                                            placeholder="No site"
                                            options={options.sites.map((s) => ({ value: String(s.id), label: s.name }))}
                                        />
                                    </Field>
                                    <Field label="Client">
                                        <SelectInput
                                            value={d.client_id}
                                            onChange={(v) => form.setData('client_id', v)}
                                            placeholder="No client"
                                            options={options.clients.map((c) => ({ value: String(c.id), label: c.name }))}
                                        />
                                    </Field>
                                </div>
                            </div>
                        )}

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="Location" hint="search OpenStreetMap or type an address" span>
                                <AddressAutocomplete
                                    value={d.location}
                                    onChange={(v) => form.setData('location', v)}
                                    onSelect={onGeocode}
                                    endpoint="/health-safety/lone-workers/geocode/search"
                                    placeholder="e.g. 14 Cameron Rd, Tauranga"
                                />
                            </Field>
                            <Field label="Latitude">
                                <input
                                    value={d.location_lat}
                                    onChange={(e) => form.setData('location_lat', e.target.value)}
                                    placeholder="-37.6878"
                                    className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm tabular-nums shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/30 focus:outline-none"
                                />
                            </Field>
                            <Field label="Longitude">
                                <input
                                    value={d.location_lng}
                                    onChange={(e) => form.setData('location_lng', e.target.value)}
                                    placeholder="176.1651"
                                    className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm tabular-nums shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/30 focus:outline-none"
                                />
                            </Field>
                        </div>
                        <InfoCard icon={MapPin} tone="info">
                            Coordinates auto-fill from the chosen site or an OpenStreetMap address — on a shift they default to the worker's last GPS ping (ShiftGpsLog). All optional.
                        </InfoCard>
                    </div>
                )}

                {cur.key === 'plan' && (
                    <div className="flex flex-col gap-5">
                        <StepHead icon={Clock} title="Monitoring plan" blurb="When to expect them back and how often to check in." />
                        <Field label="Expected end" required error={fieldError('expected_end_at')}>
                            <input
                                type="datetime-local"
                                value={d.expected_end_at}
                                onChange={(e) => form.setData('expected_end_at', e.target.value)}
                                className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/30 focus:outline-none"
                            />
                        </Field>
                        <Field label="Check-in interval">
                            <Segmented
                                value={d.check_in_interval_minutes}
                                onChange={(v) => form.setData('check_in_interval_minutes', v)}
                                options={INTERVAL_OPTIONS}
                            />
                        </Field>
                        <Field label="Activity description">
                            <textarea
                                rows={3}
                                value={d.activity_description}
                                onChange={(e) => form.setData('activity_description', e.target.value)}
                                placeholder="Describe the lone-work activity — e.g. home visit, medication support, site lock-up."
                                className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/30 focus:outline-none"
                            />
                        </Field>
                    </div>
                )}

                {cur.key === 'review' && (
                    <div className="flex flex-col gap-5">
                        <div className="flex items-center gap-4">
                            <Ring pct={pct} />
                            <div>
                                <h3 className="text-base font-semibold text-foreground">Review & start</h3>
                                <p className="text-sm text-muted-foreground">Confirm the details, then start monitoring.</p>
                            </div>
                        </div>
                        <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                            <ReviewCard icon={User} title="Worker & location" onEdit={() => setStepIndex(0)}>
                                <ReviewRow label="Linked shift" value={d.shift_id ? `SH-${d.shift_id}` : 'Ad-hoc (no shift)'} />
                                <ReviewRow label="Worker" value={nameOf(options.staff, d.user_id)} />
                                <ReviewRow label="Site" value={d.site_id ? nameOf(options.sites, d.site_id) : '—'} />
                                <ReviewRow label="Client" value={d.client_id ? nameOf(options.clients, d.client_id) : '—'} />
                                <ReviewRow label="Location" value={d.location || '—'} />
                            </ReviewCard>
                            <ReviewCard icon={Clock} title="Monitoring plan" onEdit={() => setStepIndex(1)}>
                                <ReviewRow label="Expected end" value={d.expected_end_at ? formatDateTime(d.expected_end_at) : '—'} />
                                <ReviewRow label="Check-in interval" value={`Every ${d.check_in_interval_minutes} min`} />
                                <ReviewRow label="Activity" value={d.activity_description || '—'} />
                            </ReviewCard>
                        </div>
                    </div>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}
