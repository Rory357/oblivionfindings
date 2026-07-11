/* Lone Worker lifecycle action forms — check-in / extend / end / emergency (session)
 * and acknowledge / resolve (legacy alert). The INNER form has no Dialog of its own so
 * it can be reused in three chromes without modal-on-modal:
 *   1. standalone <LoneWorkerActionModal> (launched from a register row menu),
 *   2. a body-takeover pane inside the session detail WizardShell,
 *   3. a body-takeover pane inside the alert detail dialog.
 * Matches the Add-client modal family chrome (StepHead + wizard primitives + footer band). */
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { InfoCard, StepHead, Segmented, TilePicker } from '@/components/wizard/primitives';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    Check,
    CheckCircle2,
    Clock,
    Phone,
    Trash2,
    X,
    XCircle,
} from 'lucide-react';
import { type ReactNode } from 'react';
import { type ActionTarget, LONE_WORKER_ROUTE } from './lone-worker-types';
import { Button as GuardrailButton } from '@/components/ui/button';

const INTERVAL_OPTIONS = [
    { value: '15', label: '15m' },
    { value: '30', label: '30m' },
    { value: '60', label: '60m' },
    { value: '120', label: '2h' },
];

function toLocalInput(v: string | null | undefined): string {
    if (!v) return '';
    const d = new Date(v);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

type Meta = {
    title: string;
    blurb: string;
    icon: typeof CheckCircle2;
    cta: string;
    ctaIcon: typeof Check;
    tone: 'primary' | 'critical';
};

const META: Record<ActionTarget['kind'], Meta> = {
    checkin: { title: 'Record check-in', blurb: "Log the worker's status and time-stamp the check-in.", icon: CheckCircle2, cta: 'Submit check-in', ctaIcon: Check, tone: 'primary' },
    extend: { title: 'Extend / edit session', blurb: 'Push out the expected end or adjust the check-in interval.', icon: Clock, cta: 'Save changes', ctaIcon: Check, tone: 'primary' },
    end: { title: 'End session', blurb: 'Stop monitoring this session.', icon: XCircle, cta: 'End session', ctaIcon: XCircle, tone: 'primary' },
    delete: { title: 'Remove session', blurb: 'Remove a completed session from the register.', icon: Trash2, cta: 'Remove session', ctaIcon: Trash2, tone: 'critical' },
    emergency: { title: 'Trigger emergency', blurb: 'Raise an emergency and notify contacts now.', icon: AlertTriangle, cta: 'Confirm emergency', ctaIcon: Phone, tone: 'critical' },
    acknowledge: { title: 'Acknowledge alert', blurb: 'Mark that someone is responding — a convenience action.', icon: Bell, cta: 'Acknowledge', ctaIcon: Check, tone: 'primary' },
    resolve: { title: 'Resolve alert', blurb: 'Close out the alert — a convenience action.', icon: Check, cta: 'Resolve alert', ctaIcon: Check, tone: 'critical' },
};

const CHECKIN_TILES = [
    { key: 'ok', label: 'OK', description: 'All good', icon: CheckCircle2, accent: 'var(--status-success)' },
    { key: 'concern', label: 'Concern', description: 'Needs a follow-up', icon: AlertTriangle, accent: 'var(--status-warning)' },
    { key: 'emergency', label: 'Emergency', description: 'Notify contacts', icon: Phone, accent: 'var(--status-critical)' },
];

/**
 * The reusable inner action form (no Dialog wrapper). Posts to the existing endpoints
 * and refreshes in place; gates success on `!flash.error` (302 + flash, not a 422).
 */
export function LoneWorkerActionForm({
    target,
    onDone,
    onCancel,
}: {
    target: ActionTarget;
    onDone: () => void;
    onCancel: () => void;
}) {
    const meta = META[target.kind];

    const session = 'session' in target ? target.session : null;
    const alert = 'alert' in target ? target.alert : null;

    const form = useForm<Record<string, string>>(
        target.kind === 'checkin'
            ? { status: 'ok', notes: '' }
            : target.kind === 'extend'
              ? {
                    expected_end_at: toLocalInput(session?.expected_end_at),
                    check_in_interval_minutes: String(session?.check_in_interval_minutes ?? 30),
                }
              : target.kind === 'emergency'
                ? { emergency_notes: '' }
                : target.kind === 'acknowledge'
                  ? { notes: '' }
                  : target.kind === 'resolve'
                    ? { resolution_notes: '' }
                    : {},
    );

    const finish = (page: { props: Record<string, unknown> }) => {
        const flash = page.props.flash as { error?: string } | undefined;
        if (!flash?.error) onDone();
    };

    const submit = () => {
        if (session) {
            const base = `${LONE_WORKER_ROUTE}/sessions/${session.id}`;
            if (target.kind === 'checkin') {
                form.post(`${base}/check-in`, { preserveScroll: true, onSuccess: finish });
            } else if (target.kind === 'extend') {
                form.patch(base, { preserveScroll: true, onSuccess: finish });
            } else if (target.kind === 'end') {
                form.post(`${base}/end`, { preserveScroll: true, onSuccess: finish });
            } else if (target.kind === 'emergency') {
                form.post(`${base}/emergency`, { preserveScroll: true, onSuccess: finish });
            } else if (target.kind === 'delete') {
                form.delete(base, { preserveScroll: true, onSuccess: finish });
            }
            return;
        }
        if (alert) {
            const legacyId = Number(alert.id.replace('legacy_', ''));
            const base = `${LONE_WORKER_ROUTE}/alerts/${legacyId}`;
            if (target.kind === 'acknowledge') {
                form.post(`${base}/acknowledge`, { preserveScroll: true, onSuccess: finish });
            } else if (target.kind === 'resolve') {
                form.post(`${base}/resolve`, { preserveScroll: true, onSuccess: finish });
            }
        }
    };

    const ctaClass =
        meta.tone === 'critical'
            ? 'bg-status-critical text-white hover:bg-status-critical/90'
            : 'bg-primary text-primary-foreground hover:bg-primary/90';

    return (
        <div className="flex flex-col gap-5">
            <StepHead icon={meta.icon} title={meta.title} blurb={meta.blurb} />

            <div className="flex flex-col gap-4">
                {target.kind === 'checkin' && (
                    <>
                        <FieldLabel required>Worker status</FieldLabel>
                        <TilePicker
                            value={form.data.status}
                            onChange={(v) => form.setData('status', v)}
                            options={CHECKIN_TILES}
                            cols={3}
                        />
                        <NotesField
                            label="Notes (optional)"
                            placeholder="Anything to record about this check-in"
                            value={form.data.notes}
                            onChange={(v) => form.setData('notes', v)}
                        />
                    </>
                )}

                {target.kind === 'extend' && (
                    <>
                        <div>
                            <FieldLabel required>New expected end</FieldLabel>
                            <input
                                type="datetime-local"
                                value={form.data.expected_end_at}
                                onChange={(e) => form.setData('expected_end_at', e.target.value)}
                                className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/30 focus:outline-none"
                            />
                            <ErrText>{form.errors.expected_end_at}</ErrText>
                        </div>
                        <div>
                            <FieldLabel>Check-in interval</FieldLabel>
                            <Segmented
                                value={form.data.check_in_interval_minutes}
                                onChange={(v) => form.setData('check_in_interval_minutes', v)}
                                options={INTERVAL_OPTIONS}
                            />
                        </div>
                    </>
                )}

                {target.kind === 'end' && (
                    <InfoCard icon={XCircle} tone="warn">
                        End monitoring for this session? The worker will no longer be tracked and overdue alerts stop.
                    </InfoCard>
                )}

                {target.kind === 'delete' && (
                    <InfoCard icon={Trash2} tone="crit">
                        Remove this completed session from the register? It is soft-deleted — the record is retained for audit and an administrator can restore it. Use this for test, duplicate, or erroneous entries.
                    </InfoCard>
                )}

                {target.kind === 'emergency' && (
                    <InfoCard icon={AlertTriangle} tone="crit">
                        This immediately raises an emergency for this worker and notifies their emergency contacts and the Control Room. Continue?
                    </InfoCard>
                )}

                {target.kind === 'acknowledge' && (
                    <NotesField
                        label="Notes (optional)"
                        placeholder="e.g. Contacted worker, awaiting response"
                        value={form.data.notes}
                        onChange={(v) => form.setData('notes', v)}
                    />
                )}

                {target.kind === 'resolve' && (
                    <NotesField
                        label="Resolution notes"
                        placeholder="Describe how this was resolved"
                        value={form.data.resolution_notes}
                        onChange={(v) => form.setData('resolution_notes', v)}
                        error={form.errors.resolution_notes}
                    />
                )}
            </div>

            <div className="flex items-center justify-end gap-2 border-t border-border pt-4">
                <GuardrailButton unstyled
                    type="button"
                    onClick={onCancel}
                    className="rounded-lg border border-border px-3.5 py-2 text-sm font-medium text-foreground transition-colors hover:bg-muted"
                >
                    Cancel
                </GuardrailButton>
                <GuardrailButton unstyled
                    type="button"
                    onClick={submit}
                    disabled={form.processing}
                    className={cn('inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-semibold transition-colors disabled:opacity-60', ctaClass)}
                >
                    <meta.ctaIcon className="h-4 w-4" />
                    {meta.cta}
                </GuardrailButton>
            </div>
        </div>
    );
}

/**
 * Standalone action modal — wraps the form in a small Dialog. Used by the register
 * row menus (right-click / kebab) when no detail modal is open.
 */
export function LoneWorkerActionModal({
    target,
    open,
    onClose,
}: {
    target: ActionTarget;
    open: boolean;
    onClose: () => void;
}) {
    const subject =
        'session' in target
            ? `${target.session.user?.name ?? 'Worker'} · Session #${target.session.id}`
            : `Alert · ${target.alert.session?.user?.name ?? 'Lone worker'}`;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="overflow-hidden p-0 [&>button]:hidden"
                style={{ maxWidth: 'min(94vw, 460px)', width: 'min(94vw, 460px)' }}
            >
                <DialogTitle className="sr-only">{META[target.kind].title}</DialogTitle>
                <DialogDescription className="sr-only">{META[target.kind].blurb}</DialogDescription>
                <div className="flex items-center justify-between border-b border-border bg-muted/30 px-5 py-3">
                    <span className="text-xs font-medium tracking-wide text-muted-foreground">{subject}</span>
                    <GuardrailButton unstyled
                        type="button"
                        onClick={onClose}
                        className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        aria-label="Close"
                    >
                        <X className="h-4 w-4" />
                    </GuardrailButton>
                </div>
                <div className="px-5 py-5">
                    <LoneWorkerActionForm target={target} onDone={onClose} onCancel={onClose} />
                </div>
            </DialogContent>
        </Dialog>
    );
}

/* ── small local field helpers (token-only) ─────────────────────────── */

function FieldLabel({ children, required }: { children: ReactNode; required?: boolean }) {
    return (
        <label className="text-sm font-medium text-foreground">
            {children}
            {required ? <span className="ml-0.5 text-status-critical">*</span> : null}
        </label>
    );
}

function ErrText({ children }: { children?: ReactNode }) {
    if (!children) return null;
    return (
        <p className="mt-1 flex items-center gap-1 text-xs text-status-critical">
            <AlertTriangle className="h-3 w-3" />
            {children}
        </p>
    );
}

function NotesField({
    label,
    placeholder,
    value,
    onChange,
    error,
}: {
    label: string;
    placeholder: string;
    value: string;
    onChange: (v: string) => void;
    error?: string;
}) {
    return (
        <div>
            <FieldLabel>{label}</FieldLabel>
            <textarea
                rows={3}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className="mt-1 w-full rounded-lg border border-border bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/30 focus:outline-none"
            />
            <ErrText>{error}</ErrText>
        </div>
    );
}
