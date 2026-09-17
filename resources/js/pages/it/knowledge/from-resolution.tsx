import {
    KbArticleDialog,
    type KbDraft,
    type KbOptions,
} from '@/components/it/it-wizards';
import { PageHeader } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { BookOpen } from 'lucide-react';
import { useState } from 'react';

export default function KnowledgeFromResolution({
    draft,
    options,
    sourceActorId,
}: {
    draft: KbDraft;
    options: KbOptions;
    sourceActorId: number;
}) {
    const [open, setOpen] = useState(true);
    const actorId = usePage<SharedData>().props.auth.user.id;
    if (actorId !== sourceActorId)
        return (
            <AppLayout>
                <p className="p-5">
                    Your account changed. Reopen Knowledge to continue with your
                    current access.
                </p>
            </AppLayout>
        );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Knowledge', href: '/it/knowledge' },
                {
                    title: 'Draft from resolution',
                    href: `/it/knowledge/from-resolution/${draft.source_ticket_id}`,
                },
            ]}
        >
            <Head title="Draft from resolution" />
            <div className="space-y-5">
                <PageHeader
                    variant="profile"
                    icon={BookOpen}
                    title="Draft from resolution"
                    backHref="/it/knowledge"
                    subline={`Source: ${draft.source_reference}`}
                />
                <section className="space-y-4 rounded-xl border border-border bg-card p-5">
                    <p className="text-sm">
                        Write reusable instructions from the recorded
                        resolution. The document will retain its source ticket
                        and the first approved revision.
                    </p>
                    <p className="text-subtle">
                        Ticket conversations, personal details and internal
                        verification notes are not copied into the guide. Use
                        the source while writing guidance suitable for the
                        document’s audience.
                    </p>
                    <div className="flex flex-wrap gap-3">
                        <Button variant="outline" asChild>
                            <a
                                href={`/it/tickets/${draft.source_ticket_id}`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Open source {draft.source_reference}
                            </a>
                        </Button>
                        <Button onClick={() => setOpen(true)}>
                            Write guide
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/it/knowledge">Open Knowledge</Link>
                        </Button>
                    </div>
                </section>
            </div>
            {open && (
                <KbArticleDialog
                    key={`${actorId}:${draft.source_ticket_id}`}
                    draft={draft}
                    options={options}
                    onClose={() => setOpen(false)}
                />
            )}
        </AppLayout>
    );
}
