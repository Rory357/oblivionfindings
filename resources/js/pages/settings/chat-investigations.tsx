import { InvestigationAccess } from '@/components/chat-governance/investigation-access';
import { PreservationHolds } from '@/components/chat-governance/preservation-holds';
import {
    investigationActionLabels,
    type InvestigationEvent,
    type InvestigationGrant,
    type PreservationHold,
} from '@/components/chat-governance/types';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, usePage } from '@inertiajs/react';

type Props = {
    grants: InvestigationGrant[];
    holds: PreservationHold[];
    events: InvestigationEvent[];
    durations: number[];
};

/**
 * The restricted investigator workspace (M22 investigator, holds). Reached
 * only with `chat.investigate`; its audit is separate from the settings audit.
 */
export default function ChatInvestigationsPage({
    grants,
    holds,
    events,
    durations,
}: Props) {
    const { auth } = usePage<{ auth: { user: { id: number } } }>().props;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Settings', href: '/settings' },
                {
                    title: 'Chat investigations',
                    href: '/settings/chat/investigations',
                },
            ]}
        >
            <Head title="Chat investigations" />
            <SettingsLayout>
                <div className="grid min-w-0 gap-5">
                    <header className="space-y-1">
                        <p className="text-xs font-semibold tracking-wide text-primary uppercase">
                            Global Settings / Chat
                        </p>
                        <h1 className="text-page-title">Chat investigations</h1>
                        <p className="text-subtle">
                            Case-based, time-limited and read-only. Every
                            search, view and hold is recorded in the
                            investigation audit below.
                        </p>
                    </header>
                    <div className="grid min-w-0 gap-5 xl:grid-cols-2">
                        <section
                            aria-labelledby="investigation-access-heading"
                            className="min-w-0 rounded-2xl border bg-card p-5 shadow-xs"
                        >
                            <h2
                                id="investigation-access-heading"
                                className="text-section-title mb-4"
                            >
                                Authorised investigation access
                            </h2>
                            <InvestigationAccess
                                actorId={auth.user.id}
                                investigator
                                durations={durations}
                                grants={grants}
                            />
                        </section>
                        <section
                            aria-labelledby="preservation-heading"
                            className="min-w-0 rounded-2xl border bg-card p-5 shadow-xs"
                        >
                            <h2
                                id="preservation-heading"
                                className="text-section-title mb-4"
                            >
                                Exports and preservation
                            </h2>
                            <PreservationHolds
                                actorId={auth.user.id}
                                investigator
                                holds={holds}
                            />
                        </section>
                    </div>
                    <section
                        aria-labelledby="investigation-audit-heading"
                        className="min-w-0 rounded-2xl border bg-card p-5 shadow-xs"
                    >
                        <h2
                            id="investigation-audit-heading"
                            className="text-section-title"
                        >
                            Investigation audit
                        </h2>
                        <p className="text-subtle mb-3">
                            Kept apart from the settings audit. Visible to
                            authorised investigators only.
                        </p>
                        {events.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No investigation activity has been recorded.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {events.map((event) => (
                                    <li
                                        key={event.id}
                                        className="flex flex-wrap justify-between gap-2 py-2"
                                    >
                                        <span>
                                            {investigationActionLabels[
                                                event.action
                                            ] ?? event.action}
                                            {event.case_reference &&
                                                ` · ${event.case_reference}`}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {event.actor} ·{' '}
                                            {formatDateTime(event.created_at)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
