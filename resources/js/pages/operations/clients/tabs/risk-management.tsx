import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { ApplicableProceduresPanel, type ApplicableProcedure } from '@/components/health-safety/applicable-procedures-panel';
import { HazardDetailDialog } from '@/components/health-safety/hazard-detail-dialog';
import { RISK, RiskChip, StatusChip, fmtDueShort, type HazardDetail } from '@/components/health-safety/hazard-kit';
import { ShiftContextMenu, type ShiftCtxState } from '@/components/rostering';
import { router } from '@inertiajs/react';
import {
    AlertOctagon,
    CalendarClock,
    ChevronRight,
    Copy,
    ExternalLink,
    Eye,
    Pencil,
    Plus,
    ShieldAlert,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useMemo, useState, type MouseEvent as ReactMouseEvent } from 'react';

export type ClientRiskItem = {
    id: number;
    label?: string | null;
    severity?: string | null;
    controls?: string | null;
    review_date?: string | null;
    active?: boolean;
};

export type HomeHazardRow = {
    id: number;
    reference_number: string;
    hazard_label: string;
    description: string;
    risk_rating: string;
    severity: string;
    status: string;
    due_date: string | null;
    overdue: boolean;
    site_id: number;
};

type RiskManagementTabProps = {
    clientId: number;
    risks?: ClientRiskItem[];
    canCreate?: boolean;
    canUpdate?: boolean;
    canDelete?: boolean;
    /** Open the Add-Client-style risk wizard (add new risk). */
    onAddRisk?: () => void;
    /** Open the Add-Client-style risk wizard pre-filled to edit a risk. */
    onEditRisk?: (risk: ClientRiskItem) => void;
    /** Read-only site/environmental hazards at the client's current home. */
    homeHazards?: HomeHazardRow[];
    homeHazardDetail?: HazardDetail | null;
    homeName?: string | null;
    homeSiteId?: number | null;
    /** Read-only safe work procedures governing care at the client's home. */
    homeProcedures?: ApplicableProcedure[];
};

const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low'] as const;

const SEVERITY_STYLES: Record<string, { badge: string; ring: string }> = {
    critical: {
        badge: 'bg-status-critical-bg text-status-critical',
        ring: 'border-status-critical/30',
    },
    high: {
        badge: 'bg-status-warning-bg text-status-warning',
        ring: 'border-status-warning/30',
    },
    medium: {
        badge: 'bg-status-info-bg text-status-info',
        ring: 'border-status-info/30',
    },
    low: {
        badge: 'bg-status-success-bg text-status-success',
        ring: 'border-status-success/30',
    },
};

function severityStyle(severity?: string | null) {
    const key = String(severity ?? '').toLowerCase();
    return (
        SEVERITY_STYLES[key] ?? {
            badge: 'bg-muted text-muted-foreground',
            ring: '',
        }
    );
}

function reviewState(value?: string | null): 'overdue' | 'soon' | null {
    if (!value) return null;
    const ts = new Date(value).getTime();
    if (Number.isNaN(ts)) return null;
    const delta = ts - Date.now();
    if (delta < 0) return 'overdue';
    if (delta < 30 * 86400000) return 'soon';
    return null;
}

function reviewLabel(value?: string | null): string {
    if (!value) return '—';
    try {
        return new Intl.DateTimeFormat('en-NZ', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }).format(new Date(value));
    } catch {
        return value;
    }
}

export function RiskManagementTab({
    clientId,
    risks,
    canCreate = false,
    canUpdate = false,
    canDelete = false,
    onAddRisk,
    onEditRisk,
    homeHazards = [],
    homeHazardDetail = null,
    homeName = null,
    homeSiteId = null,
    homeProcedures = [],
}: RiskManagementTabProps) {
    const list = useMemo(() => risks ?? [], [risks]);

    const registerSiteId = homeSiteId ?? homeHazards[0]?.site_id ?? null;
    const [hazardCtx, setHazardCtx] = useState<ShiftCtxState | null>(null);
    const openHazard = (id: number) => router.reload({ only: ['homeHazardDetail'], data: { hazard: id }, preserveScroll: true, preserveState: true });
    const closeHazard = () => router.reload({ only: ['homeHazardDetail'], data: { hazard: '' }, preserveScroll: true, preserveState: true });
    const openHazardInRegister = (h: HomeHazardRow) => router.visit(`/compliance/hazards?site_id=${h.site_id}&hazard=${h.id}`);
    const openHazardCtx = (e: ReactMouseEvent, h: HomeHazardRow) => {
        e.preventDefault();
        setHazardCtx({
            x: e.clientX,
            y: e.clientY,
            tag: (h.risk_rating ?? 'low').toUpperCase(),
            meta: `${h.reference_number} · read-only`,
            items: [
                { icon: <Eye className="h-3.5 w-3.5" />, label: 'View hazard', sub: 'read-only', tone: 'primary', onClick: () => openHazard(h.id) },
                { sep: true },
                { icon: <ExternalLink className="h-3.5 w-3.5" />, label: 'Open in register', sub: '/compliance/hazards', onClick: () => openHazardInRegister(h) },
                { icon: <Copy className="h-3.5 w-3.5" />, label: 'Copy link', onClick: () => navigator.clipboard?.writeText(`${window.location.origin}/compliance/hazards?site_id=${h.site_id}&hazard=${h.id}`) },
            ],
        });
    };

    const [statusFilter, setStatusFilter] = useState<
        'all' | 'active' | 'inactive'
    >('active');
    const [severityFilter, setSeverityFilter] = useState<string>('all');
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [riskToDelete, setRiskToDelete] = useState<ClientRiskItem | null>(null);

    const filtered = useMemo(() => {
        return list
            .filter((r) => {
                if (statusFilter === 'active' && !r.active) return false;
                if (statusFilter === 'inactive' && r.active) return false;
                if (
                    severityFilter !== 'all' &&
                    String(r.severity ?? '').toLowerCase() !== severityFilter
                ) {
                    return false;
                }
                return true;
            })
            .sort((a, b) => {
                if ((a.active ? 1 : 0) !== (b.active ? 1 : 0)) {
                    return a.active ? -1 : 1;
                }
                const ai = SEVERITY_ORDER.indexOf(
                    String(a.severity ?? '').toLowerCase() as any,
                );
                const bi = SEVERITY_ORDER.indexOf(
                    String(b.severity ?? '').toLowerCase() as any,
                );
                const aiVal = ai === -1 ? SEVERITY_ORDER.length : ai;
                const biVal = bi === -1 ? SEVERITY_ORDER.length : bi;
                if (aiVal !== biVal) return aiVal - biVal;
                return String(a.label ?? '').localeCompare(
                    String(b.label ?? ''),
                );
            });
    }, [list, statusFilter, severityFilter]);

    const activeRisks = list.filter((r) => r.active);
    const criticalActive = activeRisks.filter(
        (r) => String(r.severity ?? '').toLowerCase() === 'critical',
    );
    const reviewOverdue = activeRisks.filter(
        (r) => reviewState(r.review_date) === 'overdue',
    );
    const reviewSoon = activeRisks.filter(
        (r) => reviewState(r.review_date) === 'soon',
    );

    const remove = (risk: ClientRiskItem) => {
        if (deletingId) return;
        setDeletingId(risk.id);
        router.delete(`/operations/clients/${clientId}/risks/${risk.id}`, {
            preserveScroll: true,
            onFinish: () => setDeletingId(null),
        });
    };

    return (
        <div className="space-y-4">
            {/* Stat strip */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div className="rounded-xl border bg-primary/10 p-3 text-center">
                    <div className="text-xl font-bold text-primary">
                        {activeRisks.length}
                    </div>
                    <div className="text-[10px] tracking-wider text-primary uppercase">
                        Active risks
                    </div>
                </div>
                <div className="rounded-xl border bg-status-critical-bg p-3 text-center">
                    <div className="text-xl font-bold text-status-critical">
                        {criticalActive.length}
                    </div>
                    <div className="text-[10px] tracking-wider text-status-critical uppercase">
                        Critical
                    </div>
                </div>
                <div className="rounded-xl border bg-status-warning-bg p-3 text-center">
                    <div className="text-xl font-bold text-status-warning">
                        {reviewOverdue.length}
                    </div>
                    <div className="text-[10px] tracking-wider text-status-warning uppercase">
                        Reviews overdue
                    </div>
                </div>
                <div className="rounded-xl border bg-status-info-bg p-3 text-center">
                    <div className="text-xl font-bold text-status-info">
                        {reviewSoon.length}
                    </div>
                    <div className="text-[10px] tracking-wider text-status-info uppercase">
                        Review in 30 d
                    </div>
                </div>
            </div>

            {/* Safe work procedures governing care at this home (read-only) */}
            {homeProcedures.length > 0 ? (
                <ApplicableProceduresPanel
                    procedures={homeProcedures}
                    subtitle={homeName ? `Procedures governing care at ${homeName} (and organisation-wide)` : 'Procedures governing care at this home'}
                />
            ) : null}

            {/* Site / environmental hazards (read-only — managed in the register) */}
            {homeName ? (
                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-3 space-y-0">
                        <div>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <ShieldAlert className="h-4 w-4 text-primary" /> Site / environmental hazards
                                <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">Read-only</span>
                            </CardTitle>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Hazards logged at {homeName} — managed by the H&amp;S team, shown here for context. Actions deep-link to the register.
                            </p>
                        </div>
                        <Button variant="outline" size="sm" onClick={() => router.visit(`/compliance/hazards${registerSiteId ? `?site_id=${registerSiteId}` : ''}`)}>
                            <ExternalLink className="mr-1.5 h-4 w-4" /> Open register
                        </Button>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2">
                        {homeHazards.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">No open hazards at this home.</div>
                        ) : (
                            homeHazards.map((h) => {
                                const tone = RISK[h.risk_rating]?.tone ?? 'neutral';
                                const dot = tone === 'critical' ? 'bg-status-critical' : tone === 'warning' ? 'bg-status-warning' : tone === 'success' ? 'bg-status-success' : 'bg-muted-foreground';
                                return (
                                    <div
                                        key={h.id}
                                        onClick={() => openHazard(h.id)}
                                        onContextMenu={(e) => openHazardCtx(e, h)}
                                        tabIndex={0}
                                        aria-label={`View hazard ${h.reference_number}`}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter' || e.key === ' ') {
                                                e.preventDefault();
                                                openHazard(h.id);
                                            }
                                        }}
                                        className="flex cursor-pointer items-center gap-3 rounded-xl border border-border p-3 transition-colors hover:bg-muted/45 focus-visible:bg-muted/45 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                    >
                                        <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${dot}`} />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-semibold text-foreground">{h.hazard_label}</p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {h.reference_number} · {h.description}
                                            </p>
                                        </div>
                                        <RiskChip rating={h.risk_rating} />
                                        <StatusChip status={h.status} />
                                        <span className={cn('hidden text-xs whitespace-nowrap sm:inline', h.overdue ? 'font-bold text-status-critical' : 'text-muted-foreground')}>Due {fmtDueShort(h.due_date)}</span>
                                        <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground/50" />
                                    </div>
                                );
                            })
                        )}
                    </CardContent>
                </Card>
            ) : null}

            {/* Filter + add bar */}
            <Card className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center">
                <Select
                    value={statusFilter}
                    onValueChange={(v) =>
                        setStatusFilter(v as 'all' | 'active' | 'inactive')
                    }
                >
                    <SelectTrigger className="h-9 w-full text-xs sm:w-[140px]">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="active">Active only</SelectItem>
                        <SelectItem value="inactive">Inactive only</SelectItem>
                        <SelectItem value="all">All risks</SelectItem>
                    </SelectContent>
                </Select>
                <Select
                    value={severityFilter}
                    onValueChange={setSeverityFilter}
                >
                    <SelectTrigger className="h-9 w-full text-xs sm:w-[160px]">
                        <SelectValue placeholder="Any severity" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Any severity</SelectItem>
                        {SEVERITY_ORDER.map((s) => (
                            <SelectItem key={s} value={s}>
                                {s.charAt(0).toUpperCase() + s.slice(1)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <div className="flex-1" />
                {canCreate ? (
                    <Button
                        size="sm"
                        className="gap-1.5"
                        onClick={() => onAddRisk?.()}
                        data-test="risk-add"
                    >
                        <Plus className="h-4 w-4" />
                        Add risk
                    </Button>
                ) : null}
            </Card>

            {/* Risk cards */}
            {filtered.length === 0 ? (
                <EmptyState
                    icon={list.length === 0 ? ShieldCheck : AlertOctagon}
                    title={
                        list.length === 0
                            ? 'No risks recorded'
                            : 'No risks match your filters'
                    }
                    description={
                        list.length === 0
                            ? 'Add a risk to start building this client’s safety profile.'
                            : 'Try widening the status or severity filters.'
                    }
                    action={
                        list.length === 0 && canCreate ? (
                            <Button size="sm" onClick={() => onAddRisk?.()}>
                                <Plus className="mr-1 h-4 w-4" />
                                Add risk
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <div className="space-y-2">
                    {filtered.map((risk) => {
                        const sty = severityStyle(risk.severity);
                        const review = reviewState(risk.review_date);
                        return (
                            <Card
                                key={risk.id}
                                className={cn(
                                    sty.ring,
                                    !risk.active && 'opacity-60',
                                )}
                            >
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <ShieldAlert className="h-4 w-4 text-muted-foreground" />
                                                    <span className="font-semibold">
                                                        {risk.label ??
                                                            'Unlabelled risk'}
                                                    </span>
                                                    <Badge
                                                        className={cn(
                                                            'border-0 text-[10px]',
                                                            sty.badge,
                                                        )}
                                                    >
                                                        {String(
                                                            risk.severity ??
                                                                'unknown',
                                                        )}
                                                    </Badge>
                                                    {!risk.active ? (
                                                        <Badge
                                                            variant="secondary"
                                                            className="text-[10px]"
                                                        >
                                                            Inactive
                                                        </Badge>
                                                    ) : null}
                                                    {review === 'overdue' ? (
                                                        <Badge className="border-0 bg-status-warning-bg text-[10px] text-status-warning">
                                                            Review overdue
                                                        </Badge>
                                                    ) : null}
                                                    {review === 'soon' ? (
                                                        <Badge className="border-0 bg-status-info-bg text-[10px] text-status-info">
                                                            Review soon
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                                <div className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                                                    <CalendarClock className="h-3 w-3" />
                                                    Next review:{' '}
                                                    {reviewLabel(
                                                        risk.review_date,
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-1">
                                                {canUpdate ? (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            onEditRisk?.(risk)
                                                        }
                                                    >
                                                        <Pencil className="mr-1 h-3 w-3" />
                                                        Edit
                                                    </Button>
                                                ) : null}
                                                {canDelete ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={
                                                            deletingId ===
                                                            risk.id
                                                        }
                                                        onClick={() => setRiskToDelete(risk)}
                                                        aria-label={`Remove ${risk.label ?? 'unlabelled risk'}`}
                                                    >
                                                        <Trash2 className="h-3 w-3" />
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </div>
                                    </CardTitle>
                                </CardHeader>
                                {risk.controls ? (
                                    <CardContent className="pt-0">
                                        <div className="text-xs text-muted-foreground">
                                            Controls
                                        </div>
                                        <div className="mt-1 text-sm whitespace-pre-wrap">
                                            {risk.controls}
                                        </div>
                                    </CardContent>
                                ) : null}
                            </Card>
                        );
                    })}
                </div>
            )}

            {hazardCtx ? <ShiftContextMenu ctx={hazardCtx} onClose={() => setHazardCtx(null)} /> : null}

            {homeHazardDetail ? (
                <HazardDetailDialog key={homeHazardDetail.id} detail={homeHazardDetail} open onClose={closeHazard} readOnly registerHref="/compliance/hazards" />
            ) : null}
            <ConfirmDialog
                open={riskToDelete !== null}
                onClose={() => setRiskToDelete(null)}
                onConfirm={() => riskToDelete && remove(riskToDelete)}
                title="Remove risk?"
                description={`Remove “${riskToDelete?.label ?? 'Unlabelled risk'}” from this client? This action cannot be undone.`}
                confirmText="Remove risk"
            />
        </div>
    );
}

export default RiskManagementTab;
