import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { Box, Eye, Truck, X } from 'lucide-react';

export type ReportMode = 'vehicle' | 'asset' | 'near_miss';

type FormOptions = {
    assets: Array<{ id: number; name: string; registration_number: string | null; category: string | null }>;
    users: Array<{ id: number; name: string }>;
    types: string[];
    severities: string[];
};

const MODE_META: Record<ReportMode, { title: string; icon: typeof Truck; blurb: string; types: string[] }> = {
    vehicle: { title: 'Report a vehicle incident', icon: Truck, blurb: 'Collision, damage, theft, vandalism or breakdown.', types: ['collision', 'damage', 'theft', 'vandalism', 'breakdown', 'other'] },
    asset: { title: 'Report an asset / equipment incident', icon: Box, blurb: 'Damage, theft or fault on a non-vehicle asset.', types: ['damage', 'theft', 'vandalism', 'breakdown', 'other'] },
    near_miss: { title: 'Report a near miss', icon: Eye, blurb: 'No harm done — blame-free. What could have happened?', types: ['near_miss'] },
};

function titleCase(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function localNow(): string {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

/**
 * Core-capture report form. Step 5 replaces this with the full branched
 * WizardShell wizard (vehicle 6-step / asset 4-step / near-miss 4-step, photos,
 * scene, people, s22 Police step). This compact form already files a complete-
 * enough incident so the spine + cascade work end-to-end.
 */
export function FleetIncidentReportDialog({
    open,
    mode,
    formOptions,
    onClose,
}: {
    open: boolean;
    mode: ReportMode;
    formOptions: FormOptions;
    onClose: () => void;
    onOpenIncident: (id: number) => void;
}) {
    const meta = MODE_META[mode];
    const Icon = meta.icon;

    const assets = mode === 'asset' ? formOptions.assets.filter((a) => a.category && a.category !== 'vehicle') : formOptions.assets;

    const { data, setData, post, processing, errors, reset } = useForm<{
        asset_id: number | '';
        driver_user_id: number | '';
        incident_type: string;
        severity: string;
        occurred_at: string;
        location: string;
        description: string;
        injury_involved: boolean;
        potential_severity: string;
    }>({
        asset_id: '',
        driver_user_id: '',
        incident_type: meta.types[0],
        severity: mode === 'near_miss' ? 'minor' : 'moderate',
        occurred_at: localNow(),
        location: '',
        description: '',
        injury_involved: false,
        potential_severity: 'moderate',
    });

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    if (!open) return null;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/fleet-assets/incidents', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-label={meta.title} onClick={onClose}>
            <form className="my-4 w-full max-w-xl rounded-2xl border border-border bg-card shadow-xl" onClick={(e) => e.stopPropagation()} onSubmit={submit}>
                <div className="flex items-start justify-between gap-4 border-b border-border p-5">
                    <div className="flex items-center gap-3">
                        <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <Icon className="h-5 w-5" />
                        </span>
                        <div>
                            <h2 className="text-lg font-bold text-foreground">{meta.title}</h2>
                            <p className="text-xs text-muted-foreground">{meta.blurb}</p>
                        </div>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Close" className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="space-y-4 p-5">
                    <Labeled label={mode === 'asset' ? 'Asset' : 'Vehicle / asset'} error={errors.asset_id}>
                        <select value={data.asset_id} onChange={(e) => setData('asset_id', e.target.value ? Number(e.target.value) : '')} className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                            <option value="">Select…</option>
                            {assets.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                    {a.registration_number ? ` · ${a.registration_number}` : ''}
                                </option>
                            ))}
                        </select>
                    </Labeled>

                    <div className="grid grid-cols-2 gap-3">
                        <Labeled label="Type" error={errors.incident_type}>
                            <select value={data.incident_type} onChange={(e) => setData('incident_type', e.target.value)} className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                {meta.types.map((t) => (
                                    <option key={t} value={t}>
                                        {titleCase(t)}
                                    </option>
                                ))}
                            </select>
                        </Labeled>
                        {mode === 'near_miss' ? (
                            <Labeled label="Could have been" error={errors.potential_severity}>
                                <select value={data.potential_severity} onChange={(e) => setData('potential_severity', e.target.value)} className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                    {formOptions.severities.map((s) => (
                                        <option key={s} value={s}>
                                            {titleCase(s)}
                                        </option>
                                    ))}
                                </select>
                            </Labeled>
                        ) : (
                            <Labeled label="Severity" error={errors.severity}>
                                <select value={data.severity} onChange={(e) => setData('severity', e.target.value)} className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                    {formOptions.severities.map((s) => (
                                        <option key={s} value={s}>
                                            {titleCase(s)}
                                        </option>
                                    ))}
                                </select>
                            </Labeled>
                        )}
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <Labeled label="When" error={errors.occurred_at}>
                            <input type="datetime-local" value={data.occurred_at} onChange={(e) => setData('occurred_at', e.target.value)} className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm" />
                        </Labeled>
                        <Labeled label="Driver" error={errors.driver_user_id}>
                            <select value={data.driver_user_id} onChange={(e) => setData('driver_user_id', e.target.value ? Number(e.target.value) : '')} className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                <option value="">—</option>
                                {formOptions.users.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name}
                                    </option>
                                ))}
                            </select>
                        </Labeled>
                    </div>

                    {mode !== 'asset' ? (
                        <Labeled label="Location" error={errors.location}>
                            <input value={data.location} onChange={(e) => setData('location', e.target.value)} placeholder="Where did it happen?" className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm" />
                        </Labeled>
                    ) : null}

                    <Labeled label="What happened" error={errors.description}>
                        <textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={4} className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm" />
                    </Labeled>

                    {mode === 'vehicle' ? (
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={data.injury_involved} onChange={(e) => setData('injury_involved', e.target.checked)} className="h-4 w-4 rounded border-border" />
                            Someone was injured (triggers the 24-hour Police-report duty)
                        </label>
                    ) : null}
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-border p-4">
                    <button type="button" onClick={onClose} className="rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground hover:bg-muted">
                        Cancel
                    </button>
                    <button type="submit" disabled={processing || !data.asset_id || !data.description} className="rounded-md bg-primary px-4 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                        {processing ? 'Reporting…' : 'Report incident'}
                    </button>
                </div>
            </form>
        </div>
    );
}

function Labeled({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-foreground">{label}</span>
            {children}
            {error ? <span className="mt-1 block text-xs text-status-critical">{error}</span> : null}
        </label>
    );
}
