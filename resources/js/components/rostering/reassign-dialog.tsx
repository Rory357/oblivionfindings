import { useEffect, useMemo, useState } from 'react';
import { AlertTriangle, Loader2, Search, UserCheck, Users } from 'lucide-react';

import {
    EligibilityStatusBadge,
    deriveEligibilityStatus,
} from '@/components/eligibility/eligibility-status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export type ReassignShift = {
    id: number;
    starts_at?: string | null;
    ends_at?: string | null;
    client?: string | null;
    /** Current assignee name, when reassigning rather than filling an open shift. */
    staff?: string | null;
    isOpen?: boolean;
};

type Candidate = {
    id: number;
    name: string;
    email?: string | null;
    weekly_hours: number;
    is_eligible: boolean;
    blocked_reasons: string[];
    warning_reasons: string[];
    has_staff_conflict?: boolean;
    has_time_off?: boolean;
    has_compliance_block?: boolean;
    has_tight_turnaround?: boolean;
    recommended_score?: number;
};

export type ReassignDialogProps = {
    open: boolean;
    shift: ReassignShift | null;
    /** Whether the viewer may override soft eligibility warnings. */
    canOverride?: boolean;
    onOpenChange: (open: boolean) => void;
    onAssign: (
        shiftId: number,
        userId: number,
        override?: { reason: string },
    ) => void;
};

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .map((w) => w[0]!)
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

function fmtRange(shift: ReassignShift): string {
    if (!shift.starts_at) return '';
    const start = new Date(shift.starts_at);
    const timeOpts: Intl.DateTimeFormatOptions = {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    };
    const day = start.toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
    const startT = start.toLocaleTimeString(undefined, timeOpts);
    const endT = shift.ends_at
        ? new Date(shift.ends_at).toLocaleTimeString(undefined, timeOpts)
        : '';
    return endT ? `${day} · ${startT}–${endT}` : `${day} · ${startT}`;
}

export function ReassignDialog({
    open,
    shift,
    canOverride = true,
    onOpenChange,
    onAssign,
}: ReassignDialogProps) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [candidates, setCandidates] = useState<Candidate[]>([]);
    const [currentUserId, setCurrentUserId] = useState<number | null>(null);
    const [query, setQuery] = useState('');
    const [pendingWarn, setPendingWarn] = useState<Candidate | null>(null);
    const [reason, setReason] = useState('');
    const [locked, setLocked] = useState(false);

    const shiftId = shift?.id ?? null;

    useEffect(() => {
        if (!open || shiftId == null) return;
        let cancelled = false;
        setLoading(true);
        setError(null);
        setCandidates([]);
        setQuery('');
        setPendingWarn(null);
        setReason('');
        setLocked(false);
        fetch(`/operations/shifts/${shiftId}/candidates`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then((res) => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            })
            .then((data) => {
                if (cancelled) return;
                setCandidates(
                    Array.isArray(data.candidates) ? data.candidates : [],
                );
                setCurrentUserId(data.current_user_id ?? null);
                setLocked(Boolean(data.locked));
            })
            .catch(() => {
                if (!cancelled) {
                    setError('Could not load staff. Please try again.');
                }
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => {
            cancelled = true;
        };
    }, [open, shiftId]);

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return candidates;
        return candidates.filter((c) => c.name.toLowerCase().includes(q));
    }, [candidates, query]);

    if (!shift) return null;

    const isOpenShift = shift.isOpen || currentUserId == null;
    const title = isOpenShift ? 'Assign staff' : 'Reassign shift';

    const pick = (c: Candidate) => {
        const { status } = deriveEligibilityStatus(c);
        if (status === 'blocked' || c.id === currentUserId) return;
        if (status === 'warnings') {
            setPendingWarn(c);
            setReason('');
            return;
        }
        onAssign(shift.id, c.id);
    };

    const confirmOverride = () => {
        if (!pendingWarn || !reason.trim()) return;
        onAssign(shift.id, pendingWarn.id, { reason: reason.trim() });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Users className="h-5 w-5 text-primary" />
                        {title}
                    </DialogTitle>
                    <DialogDescription>
                        {shift.client ? `${shift.client} · ` : ''}
                        {fmtRange(shift)}
                        {shift.staff && !isOpenShift
                            ? ` · currently ${shift.staff}`
                            : ''}
                    </DialogDescription>
                </DialogHeader>

                {pendingWarn ? (
                    <div className="min-w-0 space-y-3 py-1">
                        <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg/40 p-3">
                            <div className="flex items-center gap-2 text-sm font-semibold text-status-warning">
                                <AlertTriangle className="h-4 w-4" />
                                {pendingWarn.name} has eligibility warnings
                            </div>
                            <ul className="mt-2 list-disc space-y-0.5 pl-5 text-xs text-muted-foreground">
                                {pendingWarn.warning_reasons.map((r, i) => (
                                    <li key={i}>{r}</li>
                                ))}
                            </ul>
                        </div>
                        <div className="space-y-1.5">
                            <label
                                htmlFor="reassign-reason"
                                className="text-sm font-medium"
                            >
                                Reason for overriding{' '}
                                <span className="text-status-critical">*</span>
                            </label>
                            <textarea
                                id="reassign-reason"
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                rows={3}
                                placeholder="Why is this assignment OK despite the warning?"
                                className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none"
                            />
                            {!canOverride ? (
                                <p className="text-xs text-status-critical">
                                    You may not have permission to override
                                    warnings — this is re-checked on submit.
                                </p>
                            ) : null}
                        </div>
                    </div>
                ) : locked ? (
                    <div className="py-8 text-center text-sm text-muted-foreground">
                        This shift is completed or cancelled, so it can no longer
                        be reassigned.
                    </div>
                ) : (
                    <div className="min-w-0 space-y-2 py-1">
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <input
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder="Search staff…"
                                className="w-full rounded-md border border-input bg-background py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none"
                            />
                        </div>
                        <div className="max-h-[340px] min-w-0 space-y-1 overflow-y-auto">
                            {loading ? (
                                <div className="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                    Loading staff…
                                </div>
                            ) : error ? (
                                <div className="py-10 text-center text-sm text-status-critical">
                                    {error}
                                </div>
                            ) : filtered.length === 0 ? (
                                <div className="py-10 text-center text-sm text-muted-foreground">
                                    No staff match.
                                </div>
                            ) : (
                                filtered.map((c) => (
                                    <CandidateRow
                                        key={c.id}
                                        candidate={c}
                                        isCurrent={c.id === currentUserId}
                                        onPick={() => pick(c)}
                                    />
                                ))
                            )}
                        </div>
                    </div>
                )}

                <DialogFooter>
                    {pendingWarn ? (
                        <>
                            <Button
                                variant="ghost"
                                onClick={() => setPendingWarn(null)}
                            >
                                Back
                            </Button>
                            <Button
                                disabled={!reason.trim()}
                                onClick={confirmOverride}
                            >
                                Assign anyway
                            </Button>
                        </>
                    ) : (
                        <Button
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function CandidateRow({
    candidate,
    isCurrent,
    onPick,
}: {
    candidate: Candidate;
    isCurrent: boolean;
    onPick: () => void;
}) {
    const { status, warningCount } = deriveEligibilityStatus(candidate);
    const blocked = status === 'blocked';
    const disabled = blocked || isCurrent;
    const detail =
        candidate.blocked_reasons[0] ?? candidate.warning_reasons[0] ?? null;

    return (
        // eslint-disable-next-line no-restricted-syntax -- candidate list row with avatar + badge layout; not a shadcn Button.
        <button
            type="button"
            disabled={disabled}
            onClick={onPick}
            className={cn(
                'flex w-full items-center gap-3 overflow-hidden rounded-md border border-transparent px-2.5 py-2 text-left transition-colors',
                disabled
                    ? 'cursor-not-allowed opacity-60'
                    : 'hover:border-border hover:bg-muted/60',
            )}
        >
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent text-xs font-bold text-primary">
                {initials(candidate.name)}
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex min-w-0 items-center gap-1.5">
                    <span className="truncate text-sm font-medium">
                        {candidate.name}
                    </span>
                    {isCurrent ? (
                        <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-semibold text-primary">
                            <UserCheck className="h-3 w-3" /> Current
                        </span>
                    ) : null}
                </div>
                <div className="truncate text-[11px] text-muted-foreground">
                    {Math.round(candidate.weekly_hours)}h this week
                    {detail ? ` · ${detail}` : ''}
                </div>
            </div>
            <EligibilityStatusBadge
                status={status}
                warningCount={warningCount}
                className="shrink-0"
            />
        </button>
    );
}

export default ReassignDialog;
