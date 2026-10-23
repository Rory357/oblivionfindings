import { Log, Ring, Surface } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDate, formatDateTime, formatTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import type { ReactNode } from 'react';

export type LinkedOperationalEvidenceData = {
    label: string;
    read_only: boolean;
    access?: 'readable' | 'restricted';
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
    } | null;
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

/**
 * The linked Control Room record, read-only (drawing pg-hsn-2c: "Control room
 * notes" as a log, "Control room tasks" as rows), with its evidence packs and
 * communication summaries. `showSource={false}` drops the source facts surface
 * on pages that already list the alert among their linked records.
 */
export function LinkedOperationalEvidence({
    evidence,
    showSource = true,
}: {
    evidence: LinkedOperationalEvidenceData | null;
    showSource?: boolean;
}) {
    if (!evidence) return null;

    if (evidence.access === 'restricted' || !evidence.source) {
        return (
            <Surface
                title="From the Control room"
                aria-label="Linked Control Room evidence"
            >
                <p className="sg-quiet">
                    <Ring tone="none" />
                    <span>
                        This incident has a linked Control Room record. Your
                        access does not include its notes or files.
                    </span>
                </p>
            </Surface>
        );
    }

    const source = evidence.source;
    const empty =
        evidence.notes.length === 0 &&
        evidence.tasks.length === 0 &&
        evidence.evidence_packs.length === 0 &&
        evidence.communications.length === 0;

    return (
        <div className="grid min-w-0 gap-5">
            {showSource ? (
                <Surface
                    title={evidence.label}
                    sub="Notes, response tasks and files from the linked Control Room record."
                >
                    <div className="px-[18px] pt-2.5">
                        <StatusBadge variant="neutral">
                            Read-only operational record
                        </StatusBadge>
                    </div>
                    <dl className="sg-kv">
                        <dt>Control Room alert</dt>
                        <dd>
                            {source.href ? (
                                <Link
                                    href={source.href}
                                    className="frontline-focus rounded-sm font-semibold text-primary underline-offset-4 hover:underline"
                                >
                                    {source.reference ?? 'Reference unavailable'}
                                </Link>
                            ) : (
                                (source.reference ?? 'Reference unavailable')
                            )}
                        </dd>
                        <dt>Site</dt>
                        <dd>{source.site?.name ?? 'No site recorded'}</dd>
                        <dt>Client</dt>
                        <dd>{source.client?.name ?? 'No client recorded'}</dd>
                        <dt>Alert type</dt>
                        <dd>{titleCase(source.alert_type)}</dd>
                        <dt>Alert state</dt>
                        <dd>
                            {titleCase(source.status)} ·{' '}
                            {titleCase(source.severity)}
                        </dd>
                        <dt>Alert raised</dt>
                        <dd>{formatOptionalDate(source.triggered_at)}</dd>
                        <dt>Source created</dt>
                        <dd>{formatOptionalDate(source.created_at)}</dd>
                        <dt>Source updated</dt>
                        <dd>{formatOptionalDate(source.updated_at)}</dd>
                    </dl>
                </Surface>
            ) : null}

            {empty ? (
                <Surface title="From the Control room">
                    <p className="sg-quiet">
                        <Ring tone="none" />
                        <span>
                            No operator notes, operational tasks, evidence
                            packs, or communications were recorded for this
                            alert.
                        </span>
                    </p>
                </Surface>
            ) : null}

            {evidence.notes.length ? (
                <Log
                    title="Control room notes"
                    limit={evidence.notes.length}
                    items={evidence.notes.map((note) => ({
                        time: note.created_at ? shortStamp(note.created_at) : '',
                        title: note.purpose_label,
                        sub: (
                            <>
                                <span>
                                    {note.author?.name ?? 'Unknown operator'}
                                </span>
                                {' · '}
                                <span className="whitespace-pre-wrap">
                                    {note.content}
                                </span>
                            </>
                        ),
                    }))}
                />
            ) : null}

            {evidence.tasks.length ? (
                <Surface title="Control room tasks">
                    <ul className="sg-list mt-2">
                        {evidence.tasks.map((task) => (
                            <li key={task.id} className="sg-li">
                                <span className="sg-li-t">
                                    <b>
                                        {task.title}
                                    </b>
                                    {task.description ? (
                                        <span className="text-caption block">
                                            {task.description}
                                        </span>
                                    ) : null}
                                    {task.transfer.state === 'transferred' ? (
                                        <span className="text-caption block font-semibold text-primary">
                                            Transferred to{' '}
                                            {task.transfer
                                                .corrective_action_reference ??
                                                'an H&S corrective action'}
                                        </span>
                                    ) : (
                                        <span className="text-caption flex flex-wrap items-center gap-x-1">
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
                                                            ? 'font-semibold text-status-critical-foreground'
                                                            : undefined
                                                    }
                                                >
                                                    {formatDateTime(task.due_at)}
                                                    {task.overdue
                                                        ? ' · overdue'
                                                        : ''}
                                                </time>
                                            ) : null}
                                        </span>
                                    )}
                                </span>
                                <StatusBadge variant="neutral">
                                    {titleCase(task.status)} ·{' '}
                                    {titleCase(task.priority)}
                                </StatusBadge>
                            </li>
                        ))}
                    </ul>
                </Surface>
            ) : null}

            {evidence.evidence_packs.map((pack) => (
                <Surface
                    key={pack.id}
                    title={pack.title}
                    sub={
                        <>
                            Evidence pack · {titleCase(pack.status)} ·{' '}
                            {pack.item_count}{' '}
                            {pack.item_count === 1 ? 'item' : 'items'}
                        </>
                    }
                >
                    <ul className="sg-list mt-2">
                        {pack.items.map((item) => (
                            <li key={item.id} className="sg-li">
                                <span className="sg-li-t">
                                    <b>
                                        {item.title}
                                    </b>
                                    {item.description ? (
                                        <span className="text-caption block">
                                            {item.description}
                                        </span>
                                    ) : null}
                                    <span className="text-caption block">
                                        {item.captured_by?.name ??
                                            'Capture owner unavailable'}
                                        {item.captured_at
                                            ? ` · ${formatDateTime(item.captured_at)}`
                                            : ''}
                                        {item.file_size
                                            ? ` · ${formatSize(item.file_size)}`
                                            : ''}
                                    </span>
                                </span>
                                {item.download_url ? (
                                    <a
                                        href={item.download_url}
                                        aria-label={`Download ${item.title}`}
                                        className="frontline-hit frontline-focus inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-primary hover:bg-muted"
                                    >
                                        <Download className="size-3.5" />
                                        Download
                                    </a>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </Surface>
            ))}

            {evidence.communications.length ? (
                <Surface title="Communication summaries">
                    <ul className="sg-list mt-2">
                        {evidence.communications.map((communication) => (
                            <li key={communication.id} className="sg-li">
                                <span className="sg-li-t">
                                    <b>
                                        {communication.subject ??
                                            communication.purpose ??
                                            'Operational update'}
                                    </b>
                                    {communication.content ? (
                                        <span className="block text-sm whitespace-pre-wrap text-foreground">
                                            {communication.content}
                                        </span>
                                    ) : null}
                                    <span className="text-caption block">
                                        {formatOptionalDate(
                                            communication.sent_at ??
                                                communication.created_at,
                                        )}
                                    </span>
                                </span>
                                <span className="text-caption shrink-0">
                                    {titleCase(communication.direction)} ·{' '}
                                    {titleCase(communication.channel)} ·{' '}
                                    {titleCase(communication.status)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Surface>
            ) : null}
        </div>
    );
}

/** "Mon 7:55 pm" — short enough for the log's time column. */
function shortStamp(value: string): ReactNode {
    const weekday = formatDate(value).split(' ')[0];
    return `${weekday} ${formatTime(value)}`;
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
