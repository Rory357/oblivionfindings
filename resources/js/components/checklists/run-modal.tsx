import { router, usePage } from '@inertiajs/react';
import {
    Building2,
    Camera,
    Check,
    CheckCircle2,
    Loader2,
    Minus,
    TriangleAlert,
    Wrench,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import { freqLabel, useChecklistConfig } from './context';
import { CategoryIcon, Progress, StatusBadge } from './primitives';
import type { RunDetail, RunItemDef } from './types';

type RespState = Record<number, { value: string; notes: string }>;

function isFail(item: RunItemDef, value: string | undefined): boolean {
    if (value == null || value === '') return false;
    if (item.response_type === 'yes_no' || item.response_type === 'yes_no_na') return value === 'no';
    if (item.response_type === 'pass_fail') return value === 'fail';
    if (item.response_type === 'numeric') {
        const cfg = item.response_config ?? {};
        const n = Number(value);
        return (cfg.min != null && n < cfg.min) || (cfg.max != null && n > cfg.max);
    }
    return false;
}

function isAnswered(value: string | undefined): boolean {
    return value != null && value !== '';
}

export function RunModal({ runId, onClose }: { runId: number; onClose: () => void }) {
    const cfg = useChecklistConfig();
    const page = usePage();
    const runDetail = (page.props as { runDetail?: RunDetail | null }).runDetail ?? null;
    const userName = (page.props as { auth?: { user?: { name?: string } } })?.auth?.user?.name ?? '';

    const ready = runDetail && runDetail.id === runId;
    const [resp, setResp] = useState<RespState>({});
    const [signature, setSignature] = useState(userName);
    const [show, setShow] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    // Pull the run's full detail without leaving the page.
    useEffect(() => {
        router.reload({ only: ['runDetail'], data: { run: runId }, preserveState: true, preserveScroll: true });
    }, [runId]);

    // Seed local state once the matching detail arrives.
    useEffect(() => {
        if (!ready || !runDetail) return;
        const seed: RespState = {};
        runDetail.responses.forEach((r) => {
            seed[r.template_item_id] = { value: r.response_value ?? '', notes: r.notes ?? '' };
        });
        setResp(seed);
    }, [ready, runDetail?.id]);

    useEffect(() => {
        const id = requestAnimationFrame(() => setShow(true));
        return () => cancelAnimationFrame(id);
    }, []);

    const items = runDetail?.items ?? [];
    const readOnly = runDetail?.status === 'completed';

    const answered = items.filter((it) => isAnswered(resp[it.id]?.value)).length;
    const pct = items.length ? Math.round((answered / items.length) * 100) : 0;
    const failed = items.filter((it) => isFail(it, resp[it.id]?.value));
    const hazardItems = failed.filter((it) => it.failure_creates_hazard);
    const damageItems = failed.filter((it) => it.failure_creates_damage);
    const requiredItems = items.filter((it) => it.is_required);
    const requiredDone = requiredItems.every((it) => isAnswered(resp[it.id]?.value));

    const set = (id: number, patch: Partial<{ value: string; notes: string }>) =>
        setResp((p) => ({ ...p, [id]: { value: p[id]?.value ?? '', notes: p[id]?.notes ?? '', ...patch } }));

    const close = () => {
        setShow(false);
        setTimeout(onClose, 180);
    };

    const payload = useMemo(
        () =>
            items
                .filter((it) => isAnswered(resp[it.id]?.value) || resp[it.id]?.notes)
                .map((it) => ({
                    template_item_id: it.id,
                    response_value: resp[it.id]?.value ?? null,
                    notes: resp[it.id]?.notes || null,
                    is_failed: isFail(it, resp[it.id]?.value),
                    create_hazard: isFail(it, resp[it.id]?.value) && it.failure_creates_hazard,
                    create_damage: isFail(it, resp[it.id]?.value) && it.failure_creates_damage,
                })),
        [items, resp],
    );

    const save = () => {
        if (!runDetail) return;
        setSubmitting(true);
        router.post(
            `/checklists/runs/${runDetail.id}/responses`,
            { responses: payload },
            { preserveScroll: true, onSuccess: close, onFinish: () => setSubmitting(false) },
        );
    };

    const complete = () => {
        if (!runDetail) return;
        setSubmitting(true);
        router.post(
            `/checklists/runs/${runDetail.id}/complete`,
            { responses: payload, signature_name: signature, overall_notes: null },
            { preserveScroll: true, onSuccess: close, onFinish: () => setSubmitting(false) },
        );
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
            <div
                className={cn('absolute inset-0 transition-opacity duration-200', show ? 'opacity-100' : 'opacity-0')}
                style={{ background: 'rgba(0,0,0,0.5)', backdropFilter: 'blur(2px)' }}
                onClick={close}
            />
            <div
                className={cn(
                    'relative flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-border bg-background shadow-2xl transition-all duration-200',
                    show ? 'scale-100 opacity-100' : 'scale-95 opacity-0',
                )}
            >
                {!ready ? (
                    <div className="flex h-64 flex-col items-center justify-center gap-3 text-muted-foreground">
                        <Loader2 className="h-6 w-6 animate-spin" />
                        <span className="text-sm">Loading checklist…</span>
                    </div>
                ) : (
                    <>
                        {/* header */}
                        <div className="shrink-0 border-b border-border bg-card">
                            <div className="flex items-start justify-between gap-3 p-5 pb-3">
                                <div className="flex min-w-0 items-start gap-3">
                                    <CategoryIcon category={runDetail!.template.category} box={42} size={21} />
                                    <div className="min-w-0">
                                        <h2 className="text-base font-semibold leading-snug">
                                            {runDetail!.template.name}
                                        </h2>
                                        <div className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                            <span className="inline-flex items-center gap-1">
                                                <Building2 className="h-3 w-3" />
                                                {runDetail!.site.name}
                                            </span>
                                            <span>·</span>
                                            {freqLabel(cfg, runDetail!.template.frequency)}
                                        </div>
                                    </div>
                                </div>
                                <Button variant="ghost" size="icon" onClick={close}>
                                    <X className="h-4 w-4" />
                                </Button>
                            </div>
                            <div className="flex items-center gap-3 px-5 pb-4">
                                <Progress value={pct} className="flex-1" />
                                <span className="text-xs font-medium tabular-nums text-muted-foreground">
                                    {answered}/{items.length} · {pct}%
                                </span>
                            </div>
                            {hazardItems.length > 0 || damageItems.length > 0 ? (
                                <div className="space-y-1 border-t border-border bg-status-critical-bg px-5 py-2 text-xs font-medium text-status-critical">
                                    {hazardItems.length > 0 ? (
                                        <div className="flex items-center gap-2">
                                            <TriangleAlert className="h-3.5 w-3.5 shrink-0" />
                                            {hazardItems.length} failed check{hazardItems.length === 1 ? '' : 's'} will raise a hazard on{' '}
                                            {runDetail!.site.name}
                                        </div>
                                    ) : null}
                                    {damageItems.length > 0 ? (
                                        <div className="flex items-center gap-2">
                                            <Wrench className="h-3.5 w-3.5 shrink-0" />
                                            {damageItems.length} failed check{damageItems.length === 1 ? '' : 's'} will log a damage report on{' '}
                                            {runDetail!.site.name}
                                        </div>
                                    ) : null}
                                </div>
                            ) : null}
                        </div>

                        {/* items */}
                        <div className="min-h-0 flex-1 space-y-2.5 overflow-y-auto bg-background p-5">
                            {items.map((it, i) => (
                                <RunItem
                                    key={it.id}
                                    item={it}
                                    idx={i + 1}
                                    value={resp[it.id]?.value ?? ''}
                                    notes={resp[it.id]?.notes ?? ''}
                                    readOnly={readOnly}
                                    onValue={(v) => set(it.id, { value: v })}
                                    onNotes={(n) => set(it.id, { notes: n })}
                                />
                            ))}
                        </div>

                        {/* footer */}
                        <div className="shrink-0 border-t border-border bg-card p-4">
                            {readOnly ? (
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-xs text-muted-foreground">This run is completed.</span>
                                    <Button variant="outline" onClick={close}>
                                        Close
                                    </Button>
                                </div>
                            ) : (
                                <div className="flex flex-col gap-3">
                                    {runDetail!.template.flags?.sign ? (
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs font-medium text-muted-foreground">Sign-off</span>
                                            <input
                                                value={signature}
                                                onChange={(e) => setSignature(e.target.value)}
                                                placeholder="Your name"
                                                className="h-8 flex-1 rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-ring/30"
                                            />
                                        </div>
                                    ) : null}
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="text-xs text-muted-foreground">
                                            {failed.length > 0 ? (
                                                <span className="font-medium text-status-warning">
                                                    {failed.length} flagged ·{' '}
                                                </span>
                                            ) : null}
                                            {answered} of {items.length} answered
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Button
                                                variant="outline"
                                                onClick={save}
                                                disabled={submitting || payload.length === 0}
                                            >
                                                Save &amp; close
                                            </Button>
                                            <Button
                                                onClick={complete}
                                                disabled={submitting || !requiredDone || !signature.trim()}
                                            >
                                                <CheckCircle2 className="h-4 w-4" />
                                                Complete run
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}

function RunItem({
    item,
    idx,
    value,
    notes,
    readOnly,
    onValue,
    onNotes,
}: {
    item: RunItemDef;
    idx: number;
    value: string;
    notes: string;
    readOnly: boolean;
    onValue: (v: string) => void;
    onNotes: (n: string) => void;
}) {
    const fail = isFail(item, value);
    return (
        <div
            className={cn(
                'rounded-lg border bg-card p-3.5 transition',
                fail ? 'border-status-critical/40 bg-status-critical-bg/40' : 'border-border',
            )}
        >
            <div className="flex items-start gap-2.5">
                <span className="mt-px flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-muted text-[10px] font-semibold text-muted-foreground">
                    {idx}
                </span>
                <div className="min-w-0 flex-1">
                    <div className="flex items-start gap-1.5">
                        <p className="text-sm font-medium leading-snug">{item.question}</p>
                        {item.failure_creates_hazard ? (
                            <span title="Failure raises a hazard" className="mt-0.5 shrink-0 text-status-critical">
                                <TriangleAlert className="h-3 w-3" />
                            </span>
                        ) : null}
                        {item.failure_creates_damage ? (
                            <span title="Failure logs a damage report" className="mt-0.5 shrink-0 text-status-warning">
                                <Wrench className="h-3 w-3" />
                            </span>
                        ) : null}
                    </div>
                    {item.guidance ? (
                        <p className="mt-0.5 text-[11px] text-muted-foreground">{item.guidance}</p>
                    ) : null}
                    {!item.is_required ? (
                        <span className="text-[11px] text-muted-foreground">Optional</span>
                    ) : null}
                    <div className="mt-2.5">
                        <RunInput item={item} value={value} readOnly={readOnly} onChange={onValue} />
                    </div>
                    {(item.response_type === 'yes_no' ||
                        item.response_type === 'yes_no_na' ||
                        item.response_type === 'pass_fail' ||
                        item.response_type === 'numeric') &&
                    !readOnly ? (
                        <input
                            value={notes}
                            onChange={(e) => onNotes(e.target.value)}
                            placeholder="Add a note (optional)"
                            className="mt-2 h-8 w-full rounded-md border border-input bg-background px-3 text-xs outline-none focus:ring-2 focus:ring-ring/30"
                        />
                    ) : null}
                </div>
            </div>
        </div>
    );
}

function RunInput({
    item,
    value,
    readOnly,
    onChange,
}: {
    item: RunItemDef;
    value: string;
    readOnly: boolean;
    onChange: (v: string) => void;
}) {
    if (item.response_type === 'numeric') {
        const cfg = item.response_config ?? {};
        const has = value !== '' && value != null;
        const out = has && ((cfg.min != null && Number(value) < cfg.min) || (cfg.max != null && Number(value) > cfg.max));
        return (
            <div className="flex flex-wrap items-center gap-2">
                <input
                    type="number"
                    value={value}
                    disabled={readOnly}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder="0"
                    className={cn(
                        'h-9 w-28 rounded-md border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-ring/30 disabled:opacity-60',
                        out ? 'border-status-critical text-status-critical' : 'border-input',
                    )}
                />
                {cfg.unit ? <span className="text-sm text-muted-foreground">{cfg.unit}</span> : null}
                {cfg.min != null ? (
                    <span className="text-xs text-muted-foreground">
                        safe range {cfg.min}–{cfg.max}
                        {cfg.unit ?? ''}
                    </span>
                ) : null}
                {out ? (
                    <StatusBadge tone="critical" Icon={TriangleAlert}>
                        Out of range
                    </StatusBadge>
                ) : has ? (
                    <StatusBadge tone="success" Icon={Check}>
                        OK
                    </StatusBadge>
                ) : null}
            </div>
        );
    }
    if (item.response_type === 'text') {
        return (
            <textarea
                value={value}
                disabled={readOnly}
                onChange={(e) => onChange(e.target.value)}
                rows={2}
                placeholder="Add notes…"
                className="w-full resize-none rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ring/30 disabled:opacity-60"
            />
        );
    }
    if (item.response_type === 'photo') {
        const has = value === 'photo';
        return (
            <button
                type="button"
                disabled={readOnly}
                onClick={() => onChange(has ? '' : 'photo')}
                className={cn(
                    'flex items-center gap-2 rounded-md border border-dashed px-3 py-2 text-sm transition disabled:opacity-60',
                    has ? 'border-status-success bg-status-success-bg text-status-success' : 'border-input text-muted-foreground hover:bg-accent',
                )}
            >
                {has ? <CheckCircle2 className="h-4 w-4" /> : <Camera className="h-4 w-4" />}
                {has ? 'Photo attached' : 'Add photo'}
            </button>
        );
    }
    // yes_no / yes_no_na / pass_fail
    const opts =
        item.response_type === 'yes_no_na'
            ? (['yes', 'no', 'na'] as const)
            : item.response_type === 'pass_fail'
              ? (['pass', 'fail'] as const)
              : (['yes', 'no'] as const);
    const labels: Record<string, string> = { yes: 'Pass', no: 'Fail', na: 'N/A', pass: 'Pass', fail: 'Fail' };
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {opts.map((o) => {
                const active = value === o;
                const positive = o === 'yes' || o === 'pass';
                const negative = o === 'no' || o === 'fail';
                return (
                    <button
                        key={o}
                        type="button"
                        disabled={readOnly}
                        onClick={() => onChange(o)}
                        className={cn(
                            'inline-flex items-center gap-1 rounded-md border px-3 py-1.5 text-sm font-medium transition disabled:opacity-60',
                            active
                                ? positive
                                    ? 'border-status-success bg-status-success-bg text-status-success'
                                    : negative
                                      ? 'border-status-critical bg-status-critical-bg text-status-critical'
                                      : 'border-border bg-muted text-foreground'
                                : 'border-input bg-background text-muted-foreground hover:bg-accent',
                        )}
                    >
                        {active ? (
                            positive ? (
                                <Check className="h-3.5 w-3.5" />
                            ) : negative ? (
                                <X className="h-3.5 w-3.5" />
                            ) : (
                                <Minus className="h-3.5 w-3.5" />
                            )
                        ) : null}
                        {labels[o]}
                    </button>
                );
            })}
        </div>
    );
}
