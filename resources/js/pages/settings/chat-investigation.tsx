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
import { Head, usePage } from '@inertiajs/react';
import { Eye, Paperclip } from 'lucide-react';
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
        reason: string;
        expires_at: string;
    };
    conversation: InvestigableConversation;
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
    const { grant, conversation } = investigation;
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
                        <div className="flex gap-2">
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
            </SettingsLayout>
        </AppLayout>
    );
}
