/**
 * The Incidents list as a support worker sees it (board G4-4, approved by Stephan 1 Nov 2026).
 *
 * No management boxes: her four boxes use the same four words as My Day — Your drafts to finish · Sent · Being
 * followed up · Closed. They are frontline boxes (a plain caption, no trend lines or bars); only drafts take the
 * alert tone. The status views are tier-2 tabs without counts (the boxes already carry them: a number appears once).
 * Rows are only incidents for the people she supports — all her access opens; her own reports about no particular
 * person show on My Day instead (D-5). "Where it's up to" is her word with the who-line underneath, never the
 * register's own stages. Severity uses the one colour map: Low grey, Medium amber, High red. The only row button is
 * "Finish" on her own draft (44 px); she still sees other people's drafts for her clients (Q7).
 */
import { Link } from '@inertiajs/react';
import {
    CheckCircle2,
    CircleDot,
    FileEdit,
    LayoutList,
    Send,
} from 'lucide-react';
import type { ReactNode } from 'react';

import type { IncidentOwnership } from '@/components/incidents/incident-ownership-panel';
import {
    incidentTypeLabel,
    SEVERITY_LABELS,
} from '@/components/incidents/incident-stages';
import {
    RecordIdentity,
    RecordTable,
    type RecordTableColumn,
} from '@/components/incidents/record-table';
import type { GroupedProfileNavTab } from '@/components/page/grouped-profile-nav';
import {
    PageHeaderNumber,
    PageHeaderNumbers,
} from '@/components/page/page-header-strip';
import { Ring } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDate, formatTime, toDateInput } from '@/lib/datetime';
import { incidentReportWord, REPORT_WORDS } from '@/lib/incidents/report-words';

export type FrontlineCounts = {
    own_drafts: number;
    drafts: number;
    sent: number;
    followed_up: number;
    closed_recent: number;
};

export type FrontlineIncidentRow = {
    id: number;
    ref: string | null;
    occurred_at: string | null;
    type: string;
    severity: string;
    status: string;
    potential_severity: string | null;
    ownership?: IncidentOwnership;
    client: {
        id: number;
        first_name: string;
        last_name: string;
        site: string | null;
    } | null;
    reporter: { name: string } | null;
    reported_by_me?: boolean;
};

/** Her status views: tier-2 tabs, no counts. */
export const FRONTLINE_VIEWS: GroupedProfileNavTab[] = [
    { key: 'all', label: 'All', icon: LayoutList },
    { key: 'drafts', label: 'Drafts', icon: FileEdit },
    { key: 'sent', label: 'Sent', icon: Send },
    { key: 'followed_up', label: 'Being followed up', icon: CircleDot },
    { key: 'closed', label: 'Closed', icon: CheckCircle2 },
];

/** Low grey, Medium amber, High red — the one severity colour map (G4-4). */
const SEVERITY_TONE: Record<string, StatusVariant> = {
    low: 'neutral',
    medium: 'warning',
    high: 'critical',
    critical: 'critical',
};

export function FrontlineNumbers({
    counts,
    tab,
    onTab,
}: {
    counts: FrontlineCounts;
    tab: string;
    onTab: (tab: string) => void;
}) {
    return (
        <PageHeaderNumbers>
            <PageHeaderNumber
                label="Your drafts to finish"
                value={counts.own_drafts}
                caption="Only you can send them"
                tone={counts.own_drafts > 0 ? 'critical' : undefined}
                onClick={() => onTab('drafts')}
                pressed={tab === 'drafts'}
            />
            <PageHeaderNumber
                label="Sent"
                value={counts.sent}
                caption="Waiting for a manager to take it on"
                onClick={() => onTab('sent')}
                pressed={tab === 'sent'}
            />
            <PageHeaderNumber
                label="Being followed up"
                value={counts.followed_up}
                caption="A manager has it"
                onClick={() => onTab('followed_up')}
                pressed={tab === 'followed_up'}
            />
            <PageHeaderNumber
                label="Closed · last 30 days"
                value={counts.closed_recent}
                caption={`Since ${formatDate(new Date(Date.now() - 30 * 86_400_000)).replace(/^\S+\s/, '')}`}
                onClick={() => onTab('closed_recent')}
                pressed={tab === 'closed_recent'}
            />
        </PageHeaderNumbers>
    );
}

function personName(row: FrontlineIncidentRow): string | null {
    return row.client
        ? `${row.client.first_name} ${row.client.last_name}`.trim()
        : null;
}

function hasOwner(row: FrontlineIncidentRow): boolean {
    return (
        row.ownership?.state === 'assigned' ||
        row.ownership?.state === 'historical'
    );
}

/** Her word, with the who-line underneath (exported for tests). */
export function whereItsUpTo(row: FrontlineIncidentRow): {
    word: keyof typeof REPORT_WORDS;
    who: string | null;
} {
    const word = incidentReportWord(row.status, hasOwner(row));
    const site = row.client?.site;
    const who =
        word === 'draft'
            ? row.reported_by_me
                ? 'Waiting for you to send it'
                : `Waiting for ${row.reporter?.name ?? 'the person who started it'} to send it`
            : word === 'sent'
              ? `A manager${site ? ` at ${site}` : ''} takes it on`
              : word === 'followed_up'
                ? row.ownership?.owner?.name
                    ? `${row.ownership.owner.name} has it`
                    : 'A manager has it'
                : null;
    return { word, who };
}

export function FrontlineIncidentTable({
    rows,
    onOpen,
    finishHref,
    footer,
}: {
    rows: FrontlineIncidentRow[];
    onOpen: (id: number) => void;
    finishHref: (id: number) => string;
    footer?: ReactNode;
}) {
    const today = toDateInput(new Date());
    const columns: RecordTableColumn<FrontlineIncidentRow>[] = [
        {
            key: 'incident',
            label: 'Incident',
            className: 'min-w-[16rem] max-w-[22rem]',
            cell: (row) => {
                const person = personName(row);
                const draft = row.status === 'draft';
                return (
                    <RecordIdentity
                        title={
                            <Link
                                href={`/incidents/${row.id}`}
                                onClick={(event) => {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    onOpen(row.id);
                                }}
                                className="rounded-sm text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {incidentTypeLabel(row.type)}
                                {person ? ` — ${person}` : ''}
                            </Link>
                        }
                        sub={
                            draft && row.reported_by_me
                                ? 'Your draft'
                                : [
                                      row.type === 'near_miss'
                                          ? 'Near miss · no one hurt'
                                          : incidentTypeLabel(row.type),
                                      row.ref,
                                  ]
                                      .filter(Boolean)
                                      .join(' · ')
                        }
                    />
                );
            },
        },
        {
            key: 'when',
            label: 'When',
            nowrap: true,
            cell: (row) =>
                row.occurred_at ? (
                    <RecordIdentity
                        title={
                            toDateInput(row.occurred_at) === today
                                ? 'Today'
                                : formatDate(row.occurred_at).replace(
                                      /^\S+\s/,
                                      '',
                                  )
                        }
                        sub={formatTime(row.occurred_at)}
                    />
                ) : (
                    <span className="text-muted-foreground">
                        Time not recorded
                    </span>
                ),
        },
        {
            key: 'client',
            label: 'Client',
            nowrap: true,
            cell: (row) =>
                personName(row) ?? (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'severity',
            label: 'Severity',
            nowrap: true,
            cell: (row) => {
                const potential =
                    row.type === 'near_miss' && row.potential_severity;
                const key = potential ? row.potential_severity! : row.severity;
                const label = SEVERITY_LABELS[key] ?? key;
                return (
                    <StatusBadge variant={SEVERITY_TONE[key] ?? 'neutral'}>
                        {potential ? `Potential: ${label}` : label}
                    </StatusBadge>
                );
            },
        },
        {
            key: 'up_to',
            label: 'Where it’s up to',
            nowrap: true,
            cell: (row) => {
                const { word, who } = whereItsUpTo(row);
                const status = REPORT_WORDS[word];
                return (
                    <div className="flex items-center gap-3">
                        <div className="grid gap-0.5">
                            <span>
                                <StatusBadge variant={status.variant}>
                                    {status.label}
                                </StatusBadge>
                            </span>
                            {who ? (
                                <span className="text-caption">{who}</span>
                            ) : null}
                        </div>
                        {word === 'draft' && row.reported_by_me ? (
                            <Button
                                asChild
                                variant="outline"
                                className="frontline-tap ml-auto"
                                onClick={(event) => event.stopPropagation()}
                            >
                                <Link href={finishHref(row.id)}>Finish</Link>
                            </Button>
                        ) : null}
                    </div>
                );
            },
        },
    ];
    return (
        <RecordTable
            title="Incidents"
            sub="Incidents for the people you support · click a row to open it"
            ariaLabel="Incidents table"
            rows={rows}
            rowKey={(row) => row.id}
            onOpen={(row) => onOpen(row.id)}
            columns={columns}
            empty={
                <p className="sg-quiet">
                    <Ring tone="none" />
                    <span>No incidents match this view and its filters.</span>
                </p>
            }
            footer={rows.length ? footer : undefined}
        />
    );
}
