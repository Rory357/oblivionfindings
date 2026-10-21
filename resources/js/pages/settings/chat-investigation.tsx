import { InvestigationExportDialog } from '@/components/chat-governance/investigation-export-dialog';
import {
    conversationLabel,
    governanceRequest,
    INVESTIGATION_API,
    type InvestigableConversation,
} from '@/components/chat-governance/types';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { formatDateTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Head, usePage } from '@inertiajs/react';
import { Eye, FileDown, Paperclip } from 'lucide-react';
import { useState } from 'react';

export type InvestigationMessage = {
    id: number;
    sender: string;
    content: string;
    created_at: string | null;
    edited_at: string | null;
    removed_at: string | null;
    attachments: { name: string; mime_type: string; size_bytes: number }[];
    previous_versions: {
        version: number;
        content: string | null;
        replaced_at: string;
    }[];
};

export type InvestigationPage = {
    grant: {
        id: number;
        case_reference: string;
        case_batch: string | null;
        reason: string;
        expires_at: string;
    };
    conversation: InvestigableConversation;
    /** Every conversation granted under this case, this one included (metadata only). */
    case_conversations: {
        grant_id: number;
        conversation: InvestigableConversation | null;
        active: boolean;
    }[];
    holds: {
        id: number;
        case_reference: string;
        active: boolean;
        placed_at: string;
    }[];
    messages: InvestigationMessage[];
    next_before: number | null;
};

/**
 * Read-only investigation view of one private staff conversation. It never
 * marks messages read, adds the investigator as a participant or notifies
 * anyone; every page load is written to the investigation audit.
 */
export default function ChatInvestigationView({
    investigation,
}: {
    investigation: InvestigationPage;
}) {
    const { auth } = usePage<{ auth: { user: { id: number } } }>().props;
    const [messages, setMessages] = useState(investigation.messages);
    const [before, setBefore] = useState(investigation.next_before);
    const [loading, setLoading] = useState(false);
    const [ended, setEnded] = useState(false);
    const [error, setError] = useState('');
    const [exporting, setExporting] = useState(false);
    const { grant, conversation } = investigation;
    const caseConversations = investigation.case_conversations ?? [];
    // The export is offered only while this grant is active; the server checks again.
    const [loadedAt] = useState(() => Date.now());
    const canExport = !ended && new Date(grant.expires_at).getTime() > loadedAt;
    const activeInCase = Math.max(
        1,
        caseConversations.filter((item) => item.active).length,
    );
    const activeHold = investigation.holds.find((hold) => hold.active);

    async function loadEarlier() {
        if (!before || loading) return;
        setLoading(true);
        setError('');
        try {
            const page = await governanceRequest<InvestigationPage>(
                auth.user.id,
                `${INVESTIGATION_API}/grants/${grant.id}/messages?before=${before}`,
            );
            setMessages((current) => [...page.messages, ...current]);
            setBefore(page.next_before);
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setLoading(false);
        }
    }

    async function endAccess() {
        setError('');
        try {
            await governanceRequest(
                auth.user.id,
                `${INVESTIGATION_API}/grants/${grant.id}/end`,
                { method: 'POST' },
            );
            setEnded(true);
            setMessages([]);
            setBefore(null);
        } catch (failure) {
            setError((failure as Error).message);
        }
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Settings', href: '/settings' },
                {
                    title: 'Chat investigations',
                    href: '/settings/chat/investigations',
                },
                {
                    title: 'Case view',
                    href: `/settings/chat/investigations/${grant.id}`,
                },
            ]}
        >
            <Head title="Investigation view" />
            <SettingsLayout>
                <div className="grid min-w-0 gap-5">
                    <header className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0 space-y-1">
                            <p className="text-xs font-semibold tracking-wide text-primary uppercase">
                                Investigation view
                            </p>
                            <h1 className="text-page-title break-words">
                                {conversationLabel(conversation)}
                            </h1>
                            <div className="flex flex-wrap gap-2">
                                <StatusBadge variant="info">
                                    Read-only
                                </StatusBadge>
                                {activeHold && (
                                    <StatusBadge variant="warning">
                                        Preservation hold ·{' '}
                                        {activeHold.case_reference}
                                    </StatusBadge>
                                )}
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {canExport && (
                                <Button onClick={() => setExporting(true)}>
                                    <FileDown className="size-4" />
                                    Export PDF
                                </Button>
                            )}
                            <Button asChild variant="outline">
                                <a href="/settings/chat/investigations">
                                    Back to investigations
                                </a>
                            </Button>
                            {!ended && (
                                <Button
                                    variant="outline"
                                    onClick={() => void endAccess()}
                                >
                                    End access now
                                </Button>
                            )}
                        </div>
                    </header>
                    <Alert variant="info" role="note">
                        <Eye />
                        <AlertDescription>
                            You are viewing this conversation for case{' '}
                            {grant.case_reference}. You are not a participant:
                            nothing is marked read, nobody is notified, and you
                            cannot reply. Access ends{' '}
                            {formatDateTime(grant.expires_at)}. This view is
                            logged.
                        </AlertDescription>
                    </Alert>
                    <section className="grid gap-1 rounded-2xl border bg-card p-5 text-sm shadow-xs">
                        <p>
                            <strong>Reason for access:</strong> {grant.reason}
                        </p>
                        <p>
                            <strong>Members:</strong>{' '}
                            {conversation.members.join(', ') ||
                                'None remaining'}
                        </p>
                    </section>
                    {caseConversations.length > 1 && (
                        <nav
                            aria-label="Conversations in this case"
                            className="grid gap-2 rounded-2xl border bg-card p-5 text-sm shadow-xs"
                        >
                            <h2 className="text-section-title">
                                Conversations in this case
                            </h2>
                            <ul className="grid gap-1">
                                {caseConversations.map((item) => {
                                    const current = item.grant_id === grant.id;
                                    const label = item.conversation
                                        ? conversationLabel(item.conversation)
                                        : 'Conversation unavailable';
                                    return (
                                        <li
                                            key={item.grant_id}
                                            className={cn(
                                                'flex flex-wrap items-center justify-between gap-2 rounded-md px-3 py-2',
                                                current && 'bg-muted',
                                            )}
                                        >
                                            {item.active && !current ? (
                                                <a
                                                    href={`/settings/chat/investigations/${item.grant_id}`}
                                                    className="min-w-0 break-words underline underline-offset-4"
                                                >
                                                    {label}
                                                </a>
                                            ) : (
                                                <span
                                                    className="min-w-0 break-words"
                                                    aria-current={
                                                        current
                                                            ? 'page'
                                                            : undefined
                                                    }
                                                >
                                                    {label}
                                                </span>
                                            )}
                                            {current ? (
                                                <StatusBadge variant="info">
                                                    Viewing
                                                </StatusBadge>
                                            ) : (
                                                !item.active && (
                                                    <StatusBadge variant="neutral">
                                                        Access ended
                                                    </StatusBadge>
                                                )
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        </nav>
                    )}
                    {error && (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            {error}
                        </p>
                    )}
                    {ended ? (
                        <p role="status" className="text-sm">
                            Case access ended. Record a new case reason to view
                            this conversation again.
                        </p>
                    ) : (
                        <section
                            aria-label="Conversation record"
                            className="grid gap-3 rounded-2xl border bg-card p-5 shadow-xs"
                        >
                            {before && (
                                <div>
                                    <Button
                                        variant="outline"
                                        disabled={loading}
                                        onClick={() => void loadEarlier()}
                                    >
                                        {loading
                                            ? 'Loading…'
                                            : 'Load earlier messages'}
                                    </Button>
                                </div>
                            )}
                            {messages.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    This conversation has no messages.
                                </p>
                            )}
                            <ol className="grid gap-3">
                                {messages.map((message) => (
                                    <li
                                        key={message.id}
                                        className="rounded-lg bg-muted/40 p-3 text-sm"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <strong>{message.sender}</strong>
                                            <span className="text-caption">
                                                {formatDateTime(
                                                    message.created_at,
                                                )}
                                            </span>
                                            {message.edited_at && (
                                                <StatusBadge variant="neutral">
                                                    Edited
                                                </StatusBadge>
                                            )}
                                            {message.removed_at && (
                                                <StatusBadge variant="warning">
                                                    Removed by sender ·{' '}
                                                    {formatDateTime(
                                                        message.removed_at,
                                                    )}
                                                </StatusBadge>
                                            )}
                                        </div>
                                        <p className="mt-1 break-words whitespace-pre-wrap">
                                            {message.content}
                                        </p>
                                        {message.attachments.map((file) => (
                                            <p
                                                key={file.name}
                                                className="mt-1 flex items-center gap-1 text-muted-foreground"
                                            >
                                                <Paperclip className="size-3.5" />
                                                {file.name}
                                            </p>
                                        ))}
                                        {message.previous_versions.length >
                                            0 && (
                                            <details className="mt-2">
                                                <summary className="cursor-pointer text-muted-foreground">
                                                    Earlier versions (
                                                    {
                                                        message
                                                            .previous_versions
                                                            .length
                                                    }
                                                    )
                                                </summary>
                                                <ul className="mt-1 grid gap-1">
                                                    {message.previous_versions.map(
                                                        (version) => (
                                                            <li
                                                                key={
                                                                    version.version
                                                                }
                                                                className="rounded-md border p-2"
                                                            >
                                                                <span className="text-caption">
                                                                    Replaced{' '}
                                                                    {formatDateTime(
                                                                        version.replaced_at,
                                                                    )}
                                                                </span>
                                                                <p className="break-words whitespace-pre-wrap">
                                                                    {version.content ??
                                                                        'Earlier text unavailable'}
                                                                </p>
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            </details>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        </section>
                    )}
                </div>
                {canExport && (
                    <InvestigationExportDialog
                        actorId={auth.user.id}
                        grantId={grant.id}
                        caseReference={grant.case_reference}
                        activeInCase={activeInCase}
                        open={exporting}
                        onOpenChange={setExporting}
                    />
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
