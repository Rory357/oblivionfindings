import { formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    Download,
    FileText,
    ListChecks,
    MessageSquareText,
    RadioTower,
    StickyNote,
} from 'lucide-react';
import type { ReactNode } from 'react';

export type LinkedOperationalEvidenceData = {
    label: string;
    read_only: boolean;
    source: {
        id: number;
        reference: string | null;
        alert_type: string;
        severity: string;
        status: string;
        href: string | null;
        site: { id: number; name: string } | null;
        client: { id: number; name: string } | null;
        triggered_at: string | null;
        acknowledged_at?: string | null;
        resolved_at?: string | null;
        closed_at?: string | null;
        created_at: string | null;
        updated_at: string | null;
    };
    notes: Array<{
        id: number;
        type: string;
        purpose: string;
        purpose_label: string;
        content: string;
        author: { id: number; name: string } | null;
        created_at: string | null;
    }>;
    tasks: Array<{
        id: number;
        title: string;
        description: string | null;
        status: string;
        priority: string;
        owner: { id: number; name: string } | null;
        due_at: string | null;
        overdue: boolean;
        transfer: {
            state: 'open' | 'retained' | 'transferred';
            corrective_action_reference: string | null;
            transferred_at: string | null;
        };
    }>;
    evidence_packs: Array<{
        id: number;
        title: string;
        status: string;
        item_count: number;
        items: Array<{
            id: number;
            type: string;
            title: string;
            description: string | null;
            mime_type: string | null;
            file_size: number | null;
            captured_at: string | null;
            captured_by: { id: number; name: string } | null;
            download_url: string | null;
            created_at?: string | null;
        }>;
    }>;
    communications: Array<{
        id: number;
        channel: string;
        direction: string;
        purpose: string | null;
        subject: string | null;
        content: string | null;
        status: string;
        sent_at: string | null;
        delivered_at: string | null;
        created_at: string | null;
    }>;
};

export function LinkedOperationalEvidence({
    evidence,
}: {
    evidence: LinkedOperationalEvidenceData | null;
}) {
    if (!evidence) return null;

    const empty =
        evidence.notes.length === 0 &&
        evidence.tasks.length === 0 &&
        evidence.evidence_packs.length === 0 &&
        evidence.communications.length === 0;
    const headingId = `linked-control-room-evidence-${evidence.source.id}`;

    return (
        <section
            aria-labelledby={headingId}
            className="rounded-xl border border-border bg-card/70 p-4"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-2.5">
                    <RadioTower className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                    <div className="min-w-0">
                        <h2
                            id={headingId}
                            className="text-sm font-bold text-foreground"
                        >
                            {evidence.label}
                        </h2>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            Canonical Control Room records remain linked here;
                            they are not copied into the incident.
                        </p>
                    </div>
                </div>
                <span className="rounded-full bg-muted px-2 py-1 text-[11px] font-semibold text-muted-foreground">
                    Read-only operational record
                </span>
            </div>

            <dl className="mt-4 grid gap-2 rounded-lg border border-border bg-background/70 p-3 text-xs sm:grid-cols-2 lg:grid-cols-3">
                <SourceValue label="Control Room alert">
                    {evidence.source.href ? (
                        <Link
                            href={evidence.source.href}
                            className="font-semibold text-primary hover:underline"
                        >
                            {evidence.source.reference ??
                                'Reference unavailable'}
                        </Link>
                    ) : (
                        (evidence.source.reference ?? 'Reference unavailable')
                    )}
                </SourceValue>
                <SourceValue label="Site">
                    {evidence.source.site?.name ?? 'No site recorded'}
                </SourceValue>
                <SourceValue label="Client">
                    {evidence.source.client?.name ?? 'No client recorded'}
                </SourceValue>
                <SourceValue label="Alert type">
                    {titleCase(evidence.source.alert_type)}
                </SourceValue>
                <SourceValue label="Alert state">
                    {titleCase(evidence.source.status)} ·{' '}
                    {titleCase(evidence.source.severity)}
                </SourceValue>
                <SourceValue label="Alert raised">
                    {formatOptionalDate(evidence.source.triggered_at)}
                </SourceValue>
                <SourceValue label="Source created">
                    {formatOptionalDate(evidence.source.created_at)}
                </SourceValue>
                <SourceValue label="Source updated">
                    {formatOptionalDate(evidence.source.updated_at)}
                </SourceValue>
            </dl>

            {empty ? (
                <p className="mt-4 rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground">
                    No operator notes, operational tasks, evidence packs, or
                    communications were recorded for this alert.
                </p>
            ) : (
                <div className="mt-4 grid gap-4 xl:grid-cols-2">
                    {evidence.notes.length ? (
                        <EvidenceGroup
                            icon={StickyNote}
                            title="Operational notes"
                        >
                            {evidence.notes.map((note) => (
                                <article
                                    key={note.id}
                                    className="border-b border-border py-2.5 last:border-0"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
                                            {note.purpose_label}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {note.author?.name ??
                                                'Unknown operator'}
                                            {note.created_at
                                                ? ` · ${formatDateTime(note.created_at)}`
                                                : ''}
                                        </span>
                                    </div>
                                    <p className="mt-2 text-sm whitespace-pre-wrap text-foreground">
                                        {note.content}
                                    </p>
                                </article>
                            ))}
                        </EvidenceGroup>
                    ) : null}

                    {evidence.tasks.length ? (
                        <EvidenceGroup icon={ListChecks} title="Source tasks">
                            {evidence.tasks.map((task) => (
                                <article
                                    key={task.id}
                                    className="border-b border-border py-2.5 last:border-0"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <p className="text-sm font-semibold text-foreground">
                                                {task.title}
                                            </p>
                                            {task.description ? (
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {task.description}
                                                </p>
                                            ) : null}
                                        </div>
                                        <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
                                            {titleCase(task.status)} ·{' '}
                                            {titleCase(task.priority)}
                                        </span>
                                    </div>
                                    {task.transfer.state === 'transferred' ? (
                                        <p className="mt-2 text-xs font-semibold text-primary">
                                            Transferred to{' '}
                                            {task.transfer
                                                .corrective_action_reference ??
                                                'an H&S corrective action'}
                                        </p>
                                    ) : (
                                        <p className="mt-2 flex flex-wrap items-center gap-x-1 text-xs text-muted-foreground">
                                            <span>
                                                {task.owner?.name ??
                                                    'Unassigned'}
                                                {task.due_at ? ' · due' : ''}
                                            </span>
                                            {task.due_at ? (
                                                <time
                                                    dateTime={task.due_at}
                                                    className={
                                                        task.overdue
                                                            ? 'font-semibold text-status-critical'
                                                            : undefined
                                                    }
                                                >
                                                    {formatDateTime(
                                                        task.due_at,
                                                    )}
                                                    {task.overdue
                                                        ? ' · overdue'
                                                        : ''}
                                                </time>
                                            ) : null}
                                        </p>
                                    )}
                                </article>
                            ))}
                        </EvidenceGroup>
                    ) : null}

                    {evidence.evidence_packs.length ? (
                        <EvidenceGroup icon={FileText} title="Evidence packs">
                            {evidence.evidence_packs.map((pack) => (
                                <article
                                    key={pack.id}
                                    className="border-b border-border py-2.5 last:border-0"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="text-sm font-semibold text-foreground">
                                            {pack.title}
                                        </p>
                                        <span className="text-xs text-muted-foreground">
                                            {titleCase(pack.status)} ·{' '}
                                            {pack.item_count}{' '}
                                            {pack.item_count === 1
                                                ? 'item'
                                                : 'items'}
                                        </span>
                                    </div>
                                    <div className="mt-2 flex flex-col gap-2">
                                        {pack.items.map((item) => (
                                            <div
                                                key={item.id}
                                                className="rounded-md bg-muted/40 p-2.5"
                                            >
                                                <div className="flex flex-wrap items-start justify-between gap-3">
                                                    <div className="min-w-0">
                                                        <p className="text-sm font-medium text-foreground">
                                                            {item.title}
                                                        </p>
                                                        {item.description ? (
                                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                                {
                                                                    item.description
                                                                }
                                                            </p>
                                                        ) : null}
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            {item.captured_by
                                                                ?.name ??
                                                                'Capture owner unavailable'}
                                                            {item.captured_at
                                                                ? ` · ${formatDateTime(item.captured_at)}`
                                                                : ''}
                                                            {item.file_size
                                                                ? ` · ${formatSize(item.file_size)}`
                                                                : ''}
                                                        </p>
                                                    </div>
                                                    {item.download_url ? (
                                                        <a
                                                            href={
                                                                item.download_url
                                                            }
                                                            aria-label={`Download ${item.title}`}
                                                            className="inline-flex min-h-9 shrink-0 items-center gap-1 rounded-md px-2 text-xs font-semibold text-primary hover:bg-muted"
                                                        >
                                                            <Download className="h-3.5 w-3.5" />
                                                            Download
                                                        </a>
                                                    ) : null}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </article>
                            ))}
                        </EvidenceGroup>
                    ) : null}

                    {evidence.communications.length ? (
                        <EvidenceGroup
                            icon={MessageSquareText}
                            title="Communication summaries"
                        >
                            {evidence.communications.map((communication) => (
                                <article
                                    key={communication.id}
                                    className="border-b border-border py-2.5 last:border-0"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="text-sm font-semibold text-foreground">
                                            {communication.subject ??
                                                communication.purpose ??
                                                'Operational update'}
                                        </p>
                                        <span className="text-xs text-muted-foreground">
                                            {titleCase(communication.direction)}{' '}
                                            · {titleCase(communication.channel)}{' '}
                                            · {titleCase(communication.status)}
                                        </span>
                                    </div>
                                    {communication.content ? (
                                        <p className="mt-1 text-sm whitespace-pre-wrap text-foreground">
                                            {communication.content}
                                        </p>
                                    ) : null}
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {formatOptionalDate(
                                            communication.sent_at ??
                                                communication.created_at,
                                        )}
                                    </p>
                                </article>
                            ))}
                        </EvidenceGroup>
                    ) : null}
                </div>
            )}
        </section>
    );
}

function SourceValue({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div>
            <dt className="font-semibold text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 text-foreground">{children}</dd>
        </div>
    );
}

function EvidenceGroup({
    icon: Icon,
    title,
    children,
}: {
    icon: typeof StickyNote;
    title: string;
    children: ReactNode;
}) {
    return (
        <section className="rounded-lg border border-border bg-background/60 p-3">
            <h3 className="flex items-center gap-2 text-sm font-bold text-foreground">
                <Icon className="h-4 w-4 text-primary" />
                {title}
            </h3>
            <div className="mt-1">{children}</div>
        </section>
    );
}

function titleCase(value: string): string {
    return value
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatOptionalDate(value: string | null): string {
    return value ? formatDateTime(value) : 'Not recorded';
}

function formatSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
