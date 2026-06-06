/**
 * Booking Requests pane — review queue with List and Board views, status
 * filter, inline approve (creates the booking), and a read-only detail pop-up.
 */
import { Button } from '@/components/ui/button';
import {
    CalendarDays,
    CheckCircle2,
    ClipboardCheck,
    Eye,
    UserPlus,
    X,
} from 'lucide-react';
import { useState } from 'react';
import {
    Empty,
    FilterChip,
    Kanban,
    PaneHead,
    SearchBox,
    ViewToggle,
    type KanbanColumn,
} from '../pane-kit';
import { Avatar, fmtRange, Pill, StatusBadge, type Tone } from '../shared';
import type { RespiteCan, RespiteRequestRow } from '../types';

const COLUMNS: KanbanColumn[] = [
    { key: 'draft', label: 'Draft', tone: 'neutral' },
    { key: 'submitted', label: 'Submitted', tone: 'info' },
    { key: 'under_review', label: 'Under review', tone: 'warning' },
    { key: 'approved', label: 'Approved', tone: 'success' },
    { key: 'waitlisted', label: 'Waitlisted', tone: 'warning' },
    { key: 'rejected', label: 'Rejected', tone: 'critical' },
];

const needsReview = (s: string) => s === 'submitted' || s === 'under_review';

export function RequestsPane({
    requests,
    can,
    onView,
    onApprove,
    onPromote,
    onOnboard,
    onReject,
}: {
    requests: RespiteRequestRow[];
    can: RespiteCan;
    onView: (row: RespiteRequestRow) => void;
    onApprove: (row: RespiteRequestRow) => void;
    onPromote: (row: RespiteRequestRow) => void;
    onOnboard: (row: RespiteRequestRow) => void;
    onReject: (row: RespiteRequestRow) => void;
}) {
    const [q, setQ] = useState('');
    const [status, setStatus] = useState('all');
    const [view, setView] = useState<'list' | 'board'>('list');

    const rows = requests.filter(
        (r) =>
            (status === 'all' || r.status === status) &&
            (q === '' ||
                `${r.client} ${r.funding ?? ''} ${r.note ?? ''}`
                    .toLowerCase()
                    .includes(q.toLowerCase())),
    );

    return (
        <div>
            <PaneHead
                icon={ClipboardCheck}
                title="Booking Requests"
                count={`${rows.length} of ${requests.length}`}
            >
                <ViewToggle view={view} setView={setView} />
            </PaneHead>

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <SearchBox
                    value={q}
                    onChange={setQ}
                    placeholder="Search client, funding ref or note…"
                />
                {[
                    'all',
                    'submitted',
                    'under_review',
                    'approved',
                    'waitlisted',
                    'draft',
                ].map((s) => (
                    <FilterChip
                        key={s}
                        active={status === s}
                        onClick={() => setStatus(s)}
                    >
                        {s === 'all'
                            ? 'All'
                            : s === 'under_review'
                              ? 'Under review'
                              : s[0].toUpperCase() + s.slice(1)}
                    </FilterChip>
                ))}
            </div>

            {view === 'board' ? (
                <Kanban
                    columns={COLUMNS}
                    items={rows}
                    groupKey={(r) => r.status}
                    renderCard={(r) => (
                        <RequestMiniCard key={r.id} r={r} onView={onView} />
                    )}
                />
            ) : (
                <div className="grid gap-2.5">
                    {rows.map((r) => (
                        <RequestCard
                            key={r.id}
                            r={r}
                            can={can}
                            onView={onView}
                            onApprove={onApprove}
                            onPromote={onPromote}
                            onOnboard={onOnboard}
                            onReject={onReject}
                        />
                    ))}
                    {rows.length === 0 ? (
                        <Empty
                            icon={ClipboardCheck}
                            title="No requests match"
                            sub="Try a different status."
                        />
                    ) : null}
                </div>
            )}
        </div>
    );
}

function RequestCard({
    r,
    can,
    onView,
    onApprove,
    onPromote,
    onOnboard,
    onReject,
}: {
    r: RespiteRequestRow;
    can: RespiteCan;
    onView: (row: RespiteRequestRow) => void;
    onApprove: (row: RespiteRequestRow) => void;
    onPromote: (row: RespiteRequestRow) => void;
    onOnboard: (row: RespiteRequestRow) => void;
    onReject: (row: RespiteRequestRow) => void;
}) {
    const funding = fundingStatusMeta(r.fundingStatus);

    return (
        <div className="rounded-[14px] border border-border bg-card p-4 transition-shadow hover:shadow-sm">
            <div className="flex items-start gap-3.5">
                <Avatar name={r.client} className="h-11 w-11 text-sm" />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2.5">
                        <span className="text-[15px] font-bold">
                            {r.client}
                        </span>
                        <StatusBadge status={r.status} />
                        {r.referralRef ? (
                            <Pill tone="info">from {r.referralRef}</Pill>
                        ) : null}
                        <Pill tone={funding.tone}>{funding.label}</Pill>
                        {r.priority === 'crisis' || r.isEmergency ? (
                            <Pill tone="critical">
                                {r.fastTracked
                                    ? 'Fast-track'
                                    : 'Crisis priority'}
                            </Pill>
                        ) : null}
                        {r.carer?.['carer_breakdown_flag'] ? (
                            <Pill tone="critical">Carer breakdown</Pill>
                        ) : null}
                        {r.waitlistPosition ? (
                            <Pill tone="warning">
                                Waitlist #{r.waitlistPosition}
                            </Pill>
                        ) : null}
                        <span className="ml-auto text-[11.5px] text-muted-foreground">
                            {r.ref}
                        </span>
                    </div>
                    <div className="mt-2.5 flex flex-wrap items-center gap-2.5">
                        <span className="inline-flex items-center gap-1.5 text-[13px] font-semibold">
                            <CalendarDays className="h-4 w-4 text-primary" />
                            {fmtRange(r.start, r.end)}
                        </span>
                        {r.nights != null ? (
                            <Pill tone="info">{r.nights} nights</Pill>
                        ) : null}
                    </div>
                    {r.note ? (
                        <p className="mt-2 text-[12.5px] leading-snug text-muted-foreground">
                            {r.note}
                        </p>
                    ) : null}
                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        {r.funding ? <span>{r.funding}</span> : null}
                        {r.serviceAgreement ? (
                            <span>
                                {r.serviceAgreement.title ??
                                    `Agreement #${r.serviceAgreement.id}`}{' '}
                                ·{' '}
                                {r.serviceAgreement.carerSupportDaysRemaining != null
                                    ? `${r.serviceAgreement.carerSupportDaysRemaining}d left`
                                    : `${r.serviceAgreement.hoursRemaining}h left`}
                            </span>
                        ) : null}
                        {r.site ? <span>{r.site}</span> : null}
                        <span>
                            {r.reviewer
                                ? `Reviewer: ${r.reviewer}`
                                : 'Unassigned'}
                        </span>
                    </div>
                </div>
                <div className="flex shrink-0 flex-col justify-center gap-1.5">
                    {can.bookingsManage && needsReview(r.status) ? (
                        <Button
                            size="sm"
                            className="bg-status-success text-white hover:bg-status-success/90"
                            onClick={() => onApprove(r)}
                        >
                            <CheckCircle2 className="h-3.5 w-3.5" /> Approve
                        </Button>
                    ) : null}
                    {can.bookingsManage && r.status === 'waitlisted' ? (
                        <Button
                            size="sm"
                            className="bg-status-success text-white hover:bg-status-success/90"
                            onClick={() => onPromote(r)}
                        >
                            <CheckCircle2 className="h-3.5 w-3.5" /> Promote
                        </Button>
                    ) : null}
                    {can.bookingsManage &&
                    r.status === 'approved' &&
                    r.bookingId &&
                    !r.onboarded ? (
                        <Button size="sm" onClick={() => onOnboard(r)}>
                            <UserPlus className="h-3.5 w-3.5" /> Onboard
                        </Button>
                    ) : null}
                    {can.update && needsReview(r.status) ? (
                        <Button
                            size="sm"
                            variant="ghost"
                            className="text-status-critical hover:bg-status-critical-bg"
                            onClick={() => onReject(r)}
                        >
                            <X className="h-3.5 w-3.5" /> Decline
                        </Button>
                    ) : null}
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => onView(r)}
                    >
                        <Eye className="h-3.5 w-3.5" /> View
                    </Button>
                </div>
            </div>
        </div>
    );
}

function fundingStatusMeta(status: RespiteRequestRow['fundingStatus']): {
    label: string;
    tone: Tone;
} {
    switch (status) {
        case 'approved':
            return { label: 'Funding approved', tone: 'success' };
        case 'not_required':
            return { label: 'No funding gate', tone: 'neutral' };
        case 'declined':
            return { label: 'Funding declined', tone: 'critical' };
        case 'expired':
            return { label: 'Funding expired', tone: 'critical' };
        default:
            return { label: 'Funding pending', tone: 'warning' };
    }
}

function RequestMiniCard({
    r,
    onView,
}: {
    r: RespiteRequestRow;
    onView: (row: RespiteRequestRow) => void;
}) {
    return (
        <button
            type="button"
            onClick={() => onView(r)}
            className="w-full rounded-xl border border-border bg-card p-3 text-left transition-shadow hover:shadow-sm"
        >
            <div className="flex items-center gap-2">
                <Avatar name={r.client} className="h-7 w-7 text-[11px]" />
                <span className="min-w-0 flex-1 truncate text-[13.5px] font-bold">
                    {r.client}
                </span>
                <span className="text-[11px] text-muted-foreground">
                    {r.ref}
                </span>
            </div>
            <div className="mt-2 flex items-center gap-1.5 text-xs font-semibold">
                <CalendarDays className="h-3.5 w-3.5 text-primary" />
                {fmtRange(r.start, r.end)}
                {r.nights != null ? (
                    <span className="ml-auto text-muted-foreground">
                        {r.nights}n
                    </span>
                ) : null}
            </div>
            {r.funding ? (
                <div className="mt-1.5 text-[11px] text-muted-foreground">
                    {r.funding}
                </div>
            ) : null}
        </button>
    );
}
