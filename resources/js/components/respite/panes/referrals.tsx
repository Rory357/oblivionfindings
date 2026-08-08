/**
 * Referrals pane — intake queue with List and Board (kanban) views, status +
 * urgency filters, inline triage/accept, and a read-only detail pop-up.
 */
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    CalendarPlus,
    Check,
    ClipboardCheck,
    Eye,
    Flag,
    Inbox,
    Plus,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { respiteActions } from '../actions';
import {
    Empty,
    FilterChip,
    Kanban,
    PaneHead,
    SearchBox,
    ViewToggle,
    type KanbanColumn,
} from '../pane-kit';
import {
    Avatar,
    relTime,
    StatusBadge,
    urgencyAccent,
    UrgencyBadge,
} from '../shared';
import type { RespiteCan, RespiteReferralRow } from '../types';

const COLUMNS: KanbanColumn[] = [
    { key: 'received', label: 'New', tone: 'info' },
    { key: 'triaged', label: 'Triaged', tone: 'warning' },
    { key: 'accepted', label: 'Accepted', tone: 'success' },
    { key: 'declined', label: 'Declined', tone: 'neutral' },
];

export function ReferralsPane({
    referrals,
    can,
    onView,
    onNew,
    onCreateRequest,
    onCompleteProfile,
    onDecline,
}: {
    referrals: RespiteReferralRow[];
    can: RespiteCan;
    onView: (row: RespiteReferralRow) => void;
    onNew: () => void;
    onCreateRequest: (row: RespiteReferralRow) => void;
    onCompleteProfile: (row: RespiteReferralRow) => void;
    onDecline: (row: RespiteReferralRow) => void;
}) {
    const [q, setQ] = useState('');
    const [status, setStatus] = useState('all');
    const [urgency, setUrgency] = useState('all');
    const [view, setView] = useState<'list' | 'board'>('list');

    const rows = referrals.filter(
        (r) =>
            (status === 'all' || r.status === status) &&
            (urgency === 'all' || r.urgency === urgency) &&
            (q === '' ||
                `${r.client} ${r.referrer ?? ''} ${r.reason ?? ''}`
                    .toLowerCase()
                    .includes(q.toLowerCase())),
    );

    return (
        <div>
            <PaneHead
                icon={Inbox}
                title="Referrals"
                count={`${rows.length} of ${referrals.length}`}
            >
                <ViewToggle view={view} setView={setView} />
                {can.create ? (
                    <Button size="sm" onClick={onNew}>
                        <Plus className="h-3.5 w-3.5" /> New referral
                    </Button>
                ) : null}
            </PaneHead>

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <SearchBox
                    value={q}
                    onChange={setQ}
                    placeholder="Search client, referrer or reason…"
                />
                {['all', 'received', 'triaged', 'accepted'].map((s) => (
                    <FilterChip
                        key={s}
                        active={status === s}
                        onClick={() => setStatus(s)}
                    >
                        {s === 'all'
                            ? 'All status'
                            : s === 'received'
                              ? 'New'
                              : s[0].toUpperCase() + s.slice(1)}
                    </FilterChip>
                ))}
                <span className="mx-0.5 h-5 w-px bg-border" />
                {(['all', 'crisis', 'urgent', 'planned'] as const).map((u) => (
                    <FilterChip
                        key={u}
                        active={urgency === u}
                        onClick={() => setUrgency(u)}
                        tone={
                            u === 'crisis'
                                ? 'critical'
                                : u === 'urgent'
                                  ? 'warning'
                                  : undefined
                        }
                    >
                        {u === 'all'
                            ? 'All urgency'
                            : u[0].toUpperCase() + u.slice(1)}
                    </FilterChip>
                ))}
            </div>

            {view === 'board' ? (
                <Kanban
                    columns={COLUMNS}
                    items={rows}
                    groupKey={(r) => r.status}
                    renderCard={(r) => (
                        <ReferralMiniCard key={r.id} r={r} onView={onView} />
                    )}
                />
            ) : (
                <div className="grid gap-2.5">
                    {rows.map((r) => (
                        <ReferralCard
                            key={r.id}
                            r={r}
                            can={can}
                            onView={onView}
                            onCreateRequest={onCreateRequest}
                            onCompleteProfile={onCompleteProfile}
                            onDecline={onDecline}
                        />
                    ))}
                    {rows.length === 0 ? (
                        <Empty
                            icon={Inbox}
                            title="No referrals match"
                            sub="Try clearing a filter."
                        />
                    ) : null}
                </div>
            )}
        </div>
    );
}

function ReferralCard({
    r,
    can,
    onView,
    onCreateRequest,
    onCompleteProfile,
    onDecline,
}: {
    r: RespiteReferralRow;
    can: RespiteCan;
    onView: (row: RespiteReferralRow) => void;
    onCreateRequest: (row: RespiteReferralRow) => void;
    onCompleteProfile: (row: RespiteReferralRow) => void;
    onDecline: (row: RespiteReferralRow) => void;
}) {
    return (
        <div
            className={cn(
                'overflow-hidden rounded-[14px] border border-l-[3px] border-border bg-card transition-shadow hover:shadow-sm',
                urgencyAccent(r.urgency),
            )}
        >
            <div className="flex gap-3.5 p-4">
                <Avatar name={r.client} className="h-11 w-11 text-sm" />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2.5">
                        <span className="text-[15px] font-bold">
                            {r.client}
                            {r.age != null ? (
                                <span className="ml-1.5 text-xs font-medium text-muted-foreground">
                                    age {r.age}
                                </span>
                            ) : null}
                        </span>
                        <StatusBadge status={r.status} />
                        <UrgencyBadge urgency={r.urgency} />
                        <span className="ml-auto text-[11.5px] text-muted-foreground">
                            {r.ref}
                            {r.received ? ` · ${relTime(r.received)}` : ''}
                        </span>
                    </div>
                    {r.reason ? (
                        <p className="mt-2 text-[13px] leading-snug">
                            {r.reason}
                        </p>
                    ) : null}
                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        {r.referrer ? (
                            <span>
                                {r.referrer}
                                {r.referrerType ? ` · ${r.referrerType}` : ''}
                            </span>
                        ) : null}
                        {r.funding ? <span>{r.funding}</span> : null}
                        {r.site ? <span>{r.site}</span> : null}
                    </div>
                </div>
                <div className="flex shrink-0 flex-col justify-center gap-1.5">
                    {can.update && r.status === 'received' ? (
                        <Button
                            size="sm"
                            onClick={() => respiteActions.triageReferral(r.id)}
                        >
                            <Flag className="h-3.5 w-3.5" /> Triage
                        </Button>
                    ) : null}
                    {can.update && r.status === 'triaged' ? (
                        <Button
                            size="sm"
                            className="bg-status-success text-white hover:bg-status-success/90"
                            onClick={() => respiteActions.acceptReferral(r.id)}
                        >
                            <Check className="h-3.5 w-3.5" /> Accept
                        </Button>
                    ) : null}
                    {can.create && r.status === 'accepted' && !r.hasRequest ? (
                        <Button size="sm" onClick={() => onCreateRequest(r)}>
                            <CalendarPlus className="h-3.5 w-3.5" /> Create
                            booking request
                        </Button>
                    ) : null}
                    {can.update && r.clientId && !r.clientProfileComplete ? (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onCompleteProfile(r)}
                        >
                            <ClipboardCheck className="h-3.5 w-3.5" /> Complete
                            profile
                        </Button>
                    ) : null}
                    {can.update &&
                    (r.status === 'received' || r.status === 'triaged') ? (
                        <Button
                            size="sm"
                            variant="ghost"
                            className="text-status-critical hover:bg-status-critical-bg"
                            onClick={() => onDecline(r)}
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

function ReferralMiniCard({
    r,
    onView,
}: {
    r: RespiteReferralRow;
    onView: (row: RespiteReferralRow) => void;
}) {
    return (
        <Button
            unstyled
            type="button"
            onClick={() => onView(r)}
            className={cn(
                'w-full overflow-hidden rounded-xl border border-l-[3px] border-border bg-card p-3 text-left transition-shadow hover:shadow-sm',
                urgencyAccent(r.urgency),
            )}
        >
            <div className="flex items-center gap-2">
                <Avatar name={r.client} className="h-7 w-7 text-[11px]" />
                <span className="min-w-0 flex-1 truncate text-[13.5px] font-bold">
                    {r.client}
                </span>
                <UrgencyBadge urgency={r.urgency} />
            </div>
            {r.reason ? (
                <p className="mt-2 line-clamp-2 text-xs text-muted-foreground">
                    {r.reason}
                </p>
            ) : null}
            <div className="mt-2 flex items-center justify-between text-[11px] text-muted-foreground">
                <span className="truncate">{r.referrer}</span>
                <span className="shrink-0">{r.ref}</span>
            </div>
        </Button>
    );
}
