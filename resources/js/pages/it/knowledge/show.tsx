import {
    KbPreview,
    type KbOptions,
    type KbRow,
} from '@/components/it/it-wizards';
import { KnowledgeDiagrams } from '@/components/it/knowledge-diagrams';
import {
    KNOWLEDGE_DOCUMENT_TYPES,
    KNOWLEDGE_SECTIONS,
    knowledgeSectionsFor,
} from '@/components/it/knowledge-document';
import {
    dropKnowledgeBuffer,
    readKnowledgeBuffer,
    saveKnowledgeBuffer,
} from '@/components/it/knowledge-editor-buffer';
import { useKnowledgeEditorContext } from '@/components/it/knowledge-editor-context';
import { knowledgeFileHref } from '@/components/it/knowledge-navigation';
import { useKnowledgeRaster } from '@/components/it/knowledge-raster';
import {
    KnowledgeRecordSearch,
    KnowledgeRelatedRecords,
} from '@/components/it/knowledge-related-records';
import { KnowledgeRevisionDialog } from '@/components/it/knowledge-revision-dialog';
import {
    KnowledgeUnfinishedUploads,
    type UnfinishedUploads,
} from '@/components/it/knowledge-unfinished-uploads';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import type { SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    BookOpen,
    FileClock,
    Files,
    GitBranch,
    Link2,
    Users,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type FileVersion = {
    id: number;
    series_id: string;
    version: number;
    name: string;
    size: number;
    created_at: string;
    href: string;
};
type FileHistory = { files: FileVersion[]; next_before_id: number | null };
const MAX_DOCUMENT_FILE_BYTES = 20 * 1024 * 1024;
const tabs = [
    { key: 'content', label: 'Document', icon: BookOpen },
    { key: 'diagrams', label: 'Diagrams', icon: GitBranch },
    { key: 'files', label: 'Files & versions', icon: Files },
    { key: 'relationships', label: 'Related records', icon: Link2 },
    { key: 'ownership', label: 'Ownership & review', icon: Users },
];

export default function KnowledgeDocument({
    article,
    options,
    files,
    returnHref,
    fileHistory = { files: [], next_before_id: null },
    unfinishedUploads = { files: [], next_before_id: null },
    viewingRevision = null,
    resolutionSources = [],
}: {
    article: KbRow;
    options: KbOptions;
    files: FileVersion[];
    returnHref: string;
    fileHistory?: FileHistory;
    unfinishedUploads?: UnfinishedUploads;
    viewingRevision?: {
        id: number;
        number: number;
        published_at: string;
    } | null;
    resolutionSources?: Array<{
        reference: string;
        href: string;
        ticket_version: number | null;
    }>;
}) {
    const page = usePage<SharedData>();
    const actorId = page.props.auth.user.id;
    const raster = useKnowledgeRaster({
        actorId,
        articleId: article.id,
        revisionId: viewingRevision?.id,
    });
    const [editing, setEditing] = useState(
        new URL(page.url, 'https://local.invalid').searchParams.get('edit') ===
            '1' && article.can.edit === true,
    );
    const [history, setHistory] = useState(false);
    const [editorGeneration, setEditorGeneration] = useState(0);
    const initialSection = new URL(
        page.url,
        'https://local.invalid',
    ).searchParams.get('section');
    const [tab, setTab] = useState(
        tabs.some((item) => item.key === initialSection)
            ? initialSection!
            : 'content',
    );
    const sectionHref = (key: string) => {
        const url = new URL(page.url, 'https://local.invalid');
        url.searchParams.delete('edit');
        url.searchParams.set('section', key);
        return `${url.pathname}${url.search}`;
    };
    const [proposal, setProposal] = useState(false);
    const copy = article.working_copy;
    const content =
        proposal && copy ? { ...article, ...copy.content } : article;
    const state = proposal && copy ? copy.status : article.status;
    const [busy, setBusy] = useState(false);
    const [accessDenied, setAccessDenied] = useState(false);
    const lifecycle = (action: string) => {
        if (busy) return;
        setBusy(true);
        router.post(
            `/it/kb/${article.id}/${action}`,
            { actor_user_id: actorId, lock_version: article.lock_version },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };
    const flash = page.props.flash as { error?: string } | undefined;
    const currentFileIds = content.file_ids ?? [];
    const activeFiles = files.filter((file) =>
        currentFileIds.includes(file.id),
    );
    const meters = [
        {
            key: 'content',
            label: 'Content',
            value:
                KNOWLEDGE_DOCUMENT_TYPES.find(
                    (item) => item.value === content.document_type,
                )?.label ?? 'Guide',
            detail: 'Read this document',
        },
        {
            key: 'diagrams',
            label: 'Diagrams',
            value: content.diagrams?.length ?? 0,
            detail: 'Shapes and connections',
        },
        {
            key: 'files',
            label: 'Files',
            value: activeFiles.length,
            detail: 'Word, PDF and images',
        },
        {
            key: 'relationships',
            label: 'Related records',
            value: content.related_records?.length ?? 0,
            detail: 'Systems, sites and assets',
        },
        {
            key: 'ownership',
            label: 'Review due',
            value: formatDateOnly(content.review_due_at) || 'Not set',
            detail: content.review_overdue
                ? 'Review overdue'
                : 'Ownership and publication',
        },
    ];
    if (accessDenied)
        return (
            <AppLayout>
                <div className="space-y-3 p-5">
                    <h1 className="text-section-title">
                        Document access changed
                    </h1>
                    <p className="text-subtle">
                        Reopen Knowledge with your current account to see
                        available documents.
                    </p>
                    <Button asChild variant="outline">
                        <Link href="/it/knowledge">Open Knowledge</Link>
                    </Button>
                </div>
            </AppLayout>
        );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Knowledge', href: returnHref },
                { title: article.title, href: `/it/knowledge/${article.id}` },
            ]}
        >
            <Head title={article.title} />
            <div className="space-y-5">
                <PageHeader
                    variant="profile"
                    className="overflow-clip!"
                    wrapTitle
                    icon={BookOpen}
                    title={article.title}
                    backHref={returnHref}
                    titleChip={
                        <PageHeaderStatusChip
                            variant={
                                article.status === 'published'
                                    ? 'success'
                                    : 'neutral'
                            }
                        >
                            {article.status.replaceAll('_', ' ')}
                        </PageHeaderStatusChip>
                    }
                    subline={`Document ${article.id} · ${article.owner ?? 'Owner not recorded'} · ${article.updated ?? 'Saved document'}`}
                    actions={
                        <>
                            {article.can.manage &&
                                article.revision_ready &&
                                !editing && (
                                    <PageHeaderGlassButton
                                        icon={FileClock}
                                        onClick={() => setHistory(true)}
                                    >
                                        Revisions & review
                                    </PageHeaderGlassButton>
                                )}
                            {article.can.edit && !editing && (
                                <PageHeaderPrimaryButton
                                    onClick={() => {
                                        setProposal(true);
                                        setEditing(true);
                                    }}
                                >
                                    Edit document
                                </PageHeaderPrimaryButton>
                            )}
                        </>
                    }
                    meters={
                        <>
                            {meters.map((meter) => (
                                <PageHeaderMeterBlock
                                    key={meter.key}
                                    label={meter.label}
                                    href={sectionHref(meter.key)}
                                    preserveState
                                    preserveScroll
                                    onClick={() => setTab(meter.key)}
                                >
                                    <PageHeaderMeterBig>
                                        {meter.value}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {meter.detail}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ))}
                        </>
                    }
                />
                {flash?.error && (
                    <p
                        role="alert"
                        className="rounded-lg border border-status-critical bg-status-critical-bg p-4 text-status-critical"
                    >
                        {flash.error}
                    </p>
                )}
                {viewingRevision && (
                    <section
                        aria-label="Historical publication"
                        className="space-y-2 rounded-lg border border-border p-4"
                    >
                        <p className="text-sm font-semibold">
                            Published revision {viewingRevision.number} ·{' '}
                            {formatDateTime(viewingRevision.published_at)}
                        </p>
                        <p className="text-subtle">
                            This is the saved publication referenced by the
                            source resolution.
                        </p>
                        <Link
                            href={`/it/knowledge/${article.id}`}
                            className="frontline-focus inline-flex min-h-11 items-center rounded-md text-sm text-primary"
                        >
                            Open the current document
                        </Link>
                    </section>
                )}
                {editing ? (
                    <DocumentEditor
                        key={`${article.id}:${actorId}:${editorGeneration}`}
                        article={article}
                        options={options}
                        actorId={actorId}
                        files={files}
                        fileHistory={fileHistory}
                        unfinishedUploads={unfinishedUploads}
                        initialTab={tab}
                        onContinue={(section) => {
                            setTab(section);
                            setEditorGeneration((value) => value + 1);
                        }}
                        onDone={() => {
                            setEditing(false);
                            setProposal(true);
                        }}
                        onCancel={() => setEditing(false)}
                        onDenied={() => setAccessDenied(true)}
                    />
                ) : (
                    <>
                        {copy && (
                            <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-4">
                                <div>
                                    <h2 className="text-section-title">
                                        {copy.status === 'in_review'
                                            ? 'Proposed revision awaiting review'
                                            : 'A proposed revision is being prepared'}
                                    </h2>
                                    <p className="text-subtle">
                                        {proposal
                                            ? 'You are viewing the saved proposal.'
                                            : 'You are viewing the approved publication.'}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    onClick={() => setProposal(!proposal)}
                                >
                                    {proposal
                                        ? 'Read publication'
                                        : 'Read proposed revision'}
                                </Button>
                            </section>
                        )}
                        <TierTwoTabs
                            tabs={tabs}
                            activeTab={tab}
                            onTab={setTab}
                            renderLink={(
                                item,
                                className,
                                inner,
                                accessibility,
                            ) => (
                                <Button
                                    variant="ghost"
                                    className={className}
                                    {...accessibility}
                                    onClick={() => setTab(item.key)}
                                >
                                    {inner}
                                </Button>
                            )}
                            ariaLabel="Document sections"
                            panelId="knowledge-document-panel"
                        />
                        <div
                            id="knowledge-document-panel"
                            role="tabpanel"
                            className="space-y-5"
                        >
                            {tab === 'content' && (
                                <section
                                    id="document-content"
                                    className="space-y-5 rounded-xl border border-border bg-card p-5"
                                >
                                    <h2 className="text-section-title">
                                        {content.title}
                                    </h2>
                                    {!!content.tags?.length && (
                                        <nav
                                            aria-label="Document tags"
                                            className="flex flex-wrap gap-2"
                                        >
                                            {content.tags.map((tag) => (
                                                <Link
                                                    key={tag}
                                                    href={`/it/knowledge?tag=${encodeURIComponent(tag)}`}
                                                    className="frontline-focus rounded-full border border-border px-3 py-2 text-sm"
                                                >
                                                    {tag}
                                                </Link>
                                            ))}
                                        </nav>
                                    )}
                                    <KbPreview body={content.body ?? ''} />
                                    {knowledgeSectionsFor(
                                        content.document_type ?? 'guide',
                                    ).map((field) =>
                                        content.structured_content?.[
                                            field.key
                                        ] ? (
                                            <section
                                                key={field.key}
                                                className="space-y-2 border-t border-border pt-5"
                                            >
                                                <h3 className="text-section-title">
                                                    {field.label}
                                                </h3>
                                                <KbPreview
                                                    body={
                                                        content
                                                            .structured_content[
                                                            field.key
                                                        ] ?? ''
                                                    }
                                                />
                                            </section>
                                        ) : null,
                                    )}
                                    {article.status === 'published' &&
                                        !viewingRevision &&
                                        !proposal && (
                                            <div className="flex flex-wrap items-center gap-3 border-t border-border pt-5">
                                                <span className="text-sm">
                                                    Was this document helpful?
                                                </span>
                                                {article.user_vote != null ? (
                                                    <p className="text-subtle">
                                                        Your feedback has been
                                                        recorded.
                                                    </p>
                                                ) : (
                                                    <>
                                                        {[true, false].map(
                                                            (helpful) => (
                                                                <Button
                                                                    key={String(
                                                                        helpful,
                                                                    )}
                                                                    variant="outline"
                                                                    disabled={
                                                                        busy
                                                                    }
                                                                    onClick={() => {
                                                                        setBusy(
                                                                            true,
                                                                        );
                                                                        router.post(
                                                                            `/it/kb/${article.id}/helpful`,
                                                                            {
                                                                                helpful,
                                                                                lock_version:
                                                                                    article.lock_version,
                                                                                actor_user_id:
                                                                                    actorId,
                                                                            },
                                                                            {
                                                                                preserveScroll: true,
                                                                                onFinish:
                                                                                    () =>
                                                                                        setBusy(
                                                                                            false,
                                                                                        ),
                                                                            },
                                                                        );
                                                                    }}
                                                                >
                                                                    {helpful
                                                                        ? 'Yes'
                                                                        : 'No'}
                                                                </Button>
                                                            ),
                                                        )}
                                                    </>
                                                )}
                                                {article.user_solved ? (
                                                    <p
                                                        role="status"
                                                        className="text-subtle"
                                                    >
                                                        You confirmed this
                                                        solved your issue.
                                                    </p>
                                                ) : (
                                                    <Button
                                                        variant="outline"
                                                        disabled={busy}
                                                        onClick={() => {
                                                            setBusy(true);
                                                            router.post(
                                                                `/it/kb/${article.id}/helpful`,
                                                                {
                                                                    helpful: true,
                                                                    solved: true,
                                                                    lock_version:
                                                                        article.lock_version,
                                                                    actor_user_id:
                                                                        actorId,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                    onFinish:
                                                                        () =>
                                                                            setBusy(
                                                                                false,
                                                                            ),
                                                                },
                                                            );
                                                        }}
                                                    >
                                                        This solved my issue
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                </section>
                            )}
                            {tab === 'diagrams' && (
                                <section
                                    id="document-diagrams"
                                    className="space-y-4"
                                >
                                    {article.can.edit && (
                                        <Button
                                            onClick={() => setEditing(true)}
                                        >
                                            Build diagram
                                        </Button>
                                    )}
                                    <KnowledgeDiagrams
                                        diagrams={content.diagrams ?? []}
                                        recordKey={`${actorId}:${article.id}:${viewingRevision?.id ?? 'current'}:${proposal ? 'proposal' : 'publication'}`}
                                        raster={raster}
                                    />
                                </section>
                            )}
                            {tab === 'files' && (
                                <section id="document-files">
                                    <DocumentFiles
                                        key={`${article.id}:${actorId}:${article.lock_version}:${files.map((file) => file.id).join(',')}`}
                                        article={article}
                                        files={files}
                                        history={fileHistory}
                                        unfinished={unfinishedUploads}
                                        activeIds={currentFileIds}
                                        actorId={actorId}
                                    />
                                </section>
                            )}
                            {tab === 'relationships' && (
                                <section
                                    id="document-relationships"
                                    className="rounded-xl border border-border bg-card p-5"
                                >
                                    {content.related_records?.length ? (
                                        <KnowledgeRelatedRecords
                                            records={content.related_records}
                                        />
                                    ) : (
                                        <p className="text-subtle">
                                            No related records have been linked.
                                        </p>
                                    )}
                                    {resolutionSources.length > 0 && (
                                        <section
                                            aria-label="Source resolutions"
                                            className="mt-4 space-y-2"
                                        >
                                            <h3 className="text-section-title">
                                                Source resolutions
                                            </h3>
                                            {resolutionSources.map((source) => (
                                                <Link
                                                    key={source.href}
                                                    href={source.href}
                                                    className="frontline-focus flex min-h-11 items-center rounded-md text-sm text-primary"
                                                >
                                                    {source.reference} ·
                                                    recorded ticket version{' '}
                                                    {source.ticket_version ??
                                                        'not available'}
                                                </Link>
                                            ))}
                                        </section>
                                    )}
                                </section>
                            )}
                            {tab === 'ownership' && (
                                <section
                                    id="document-ownership"
                                    className="space-y-5 rounded-xl border border-border bg-card p-5"
                                >
                                    <h2 className="text-section-title">
                                        Ownership & review
                                    </h2>
                                    {!!article.document_issues?.length && (
                                        <section
                                            aria-label="Document checks"
                                            className="space-y-3 rounded-lg border border-border p-4"
                                        >
                                            <h3 className="text-sm font-semibold">
                                                Needs attention
                                            </h3>
                                            <ul className="list-disc space-y-1 pl-5 text-sm">
                                                {article.document_issues.map(
                                                    (issue) => (
                                                        <li
                                                            key={`${issue.code}:${issue.field ?? ''}`}
                                                        >
                                                            {issue.message}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                            {article.can.edit && (
                                                <div className="flex flex-wrap gap-2">
                                                    {[
                                                        ...new Set(
                                                            article.document_issues.map(
                                                                (issue) =>
                                                                    issue.section,
                                                            ),
                                                        ),
                                                    ].map((section) => (
                                                        <Button
                                                            key={section}
                                                            variant="outline"
                                                            onClick={() => {
                                                                setTab(section);
                                                                setProposal(
                                                                    true,
                                                                );
                                                                setEditing(
                                                                    true,
                                                                );
                                                            }}
                                                        >
                                                            {section ===
                                                            'relationships'
                                                                ? 'Correct related records'
                                                                : section ===
                                                                    'content'
                                                                  ? 'Complete document sections'
                                                                  : 'Update owner and review date'}
                                                        </Button>
                                                    ))}
                                                </div>
                                            )}
                                            <p className="text-subtle">
                                                A review stays due until the
                                                updated document and its next
                                                review date are approved.
                                            </p>
                                        </section>
                                    )}
                                    <dl className="grid gap-4 sm:grid-cols-2">
                                        {[
                                            [
                                                'Owner',
                                                options.owners.find(
                                                    (owner) =>
                                                        owner.id ===
                                                        content.owner_user_id,
                                                )?.name ??
                                                    content.owner ??
                                                    'Not recorded',
                                            ],
                                            [
                                                'Audience',
                                                (
                                                    {
                                                        all_staff: 'All staff',
                                                        specific_sites:
                                                            'Selected approved sites',
                                                        it_agents: 'IT staff',
                                                    } as Record<string, string>
                                                )[content.audience],
                                            ],
                                            [
                                                'Review due',
                                                formatDateOnly(
                                                    content.review_due_at,
                                                ) || 'Not set',
                                            ],
                                            [
                                                'Published',
                                                formatDateTime(
                                                    article.published_at,
                                                ) || 'Not published',
                                            ],
                                            [
                                                'State',
                                                state.replaceAll('_', ' '),
                                            ],
                                            [
                                                'Service',
                                                options.services.find(
                                                    (service) =>
                                                        service.id ===
                                                        content.related_service_id,
                                                )?.name ??
                                                    article.related_service ??
                                                    'Not linked',
                                            ],
                                        ].map(([label, value]) => (
                                            <div key={label}>
                                                <dt className="text-caption">
                                                    {label}
                                                </dt>
                                                <dd className="text-sm font-medium">
                                                    {value}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                    <div className="flex flex-wrap gap-3">
                                        {article.can.author &&
                                            (article.status === 'draft' ||
                                                copy?.status === 'draft') && (
                                                <Button
                                                    disabled={busy}
                                                    onClick={() =>
                                                        lifecycle(
                                                            'submit-review',
                                                        )
                                                    }
                                                >
                                                    {busy
                                                        ? 'Saving…'
                                                        : 'Send for review'}
                                                </Button>
                                            )}
                                        {article.can.author &&
                                            (article.status === 'in_review' ||
                                                copy?.status === 'in_review' ||
                                                article.status ===
                                                    'retired') && (
                                                <Button
                                                    variant="outline"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        lifecycle('restore')
                                                    }
                                                >
                                                    Return to draft
                                                </Button>
                                            )}
                                        {article.can.manage &&
                                            article.revision_ready && (
                                                <Button
                                                    variant="outline"
                                                    onClick={() =>
                                                        setHistory(true)
                                                    }
                                                >
                                                    Compare revisions and review
                                                </Button>
                                            )}
                                    </div>
                                </section>
                            )}
                        </div>
                    </>
                )}
                {!editing && (
                    <Button variant="outline" asChild>
                        <Link href={returnHref}>Back to Knowledge</Link>
                    </Button>
                )}
            </div>
            {history && (
                <KnowledgeRevisionDialog
                    article={article}
                    actorId={actorId}
                    onClose={() => setHistory(false)}
                />
            )}
        </AppLayout>
    );
}

function DocumentEditor({
    article,
    options,
    actorId,
    onDone,
    onCancel,
    onDenied,
    files,
    fileHistory,
    unfinishedUploads,
    initialTab,
    onContinue,
}: {
    article: KbRow;
    options: KbOptions;
    actorId: number;
    onDone: () => void;
    onCancel: () => void;
    onDenied: () => void;
    files: FileVersion[];
    fileHistory: FileHistory;
    unfinishedUploads: UnfinishedUploads;
    initialTab: string;
    onContinue: (section: string) => void;
}) {
    const context = useKnowledgeEditorContext(
        article.id,
        actorId,
        article.lock_version,
    );
    const current = context.status === 'ready' ? context.data.article : article;
    const currentOptions =
        context.status === 'ready' ? context.data.options : options;
    const content = current.working_copy?.content ?? current;
    const [tab, setTab] = useState(initialTab);
    const [fileBusy, setFileBusy] = useState(false);
    const [linksChanged, setLinksChanged] = useState(false);
    const [reviewedCurrent, setReviewedCurrent] = useState(false);
    const form = useForm({
        actor_user_id: actorId,
        lock_version: article.lock_version ?? 1,
        title: content.title ?? '',
        category: content.category ?? 'hardware',
        body: content.body ?? '',
        document_type: content.document_type ?? 'guide',
        tags_text: (content.tags ?? []).join(', '),
        audience: content.audience ?? 'all_staff',
        site_scope: content.site_scope ?? [],
        owner_user_id: String(content.owner_user_id ?? ''),
        related_service_id: String(content.related_service_id ?? ''),
        review_due_at: content.review_due_at ?? '',
        structured_content: Object.fromEntries(
            KNOWLEDGE_SECTIONS.map((field) => [
                field.key,
                content.structured_content?.[field.key] ?? '',
            ]),
        ),
        related_records: content.related_records ?? [],
        diagrams: content.diagrams ?? [],
    });
    const [ownUploadVersion, setOwnUploadVersion] = useState<number | null>(
        null,
    );
    const diagramRaster = useKnowledgeRaster({
        actorId,
        articleId: article.id,
        version: form.data.lock_version,
        onBusy: setFileBusy,
        onUploaded: (result) => {
            setOwnUploadVersion(result.lock_version);
            form.setData((data) => ({
                ...data,
                lock_version: result.lock_version,
            }));
        },
    });
    const [savedBuffer] = useState(() =>
        readKnowledgeBuffer<typeof form.data>(actorId, article.id),
    );
    const template = currentOptions.document_templates?.find(
        (item) => item.type === form.data.document_type,
    );
    const missingSections = (template?.required ?? []).filter(
        (key) => !form.data.structured_content[key]?.trim(),
    );
    const [resumePending, setResumePending] = useState(savedBuffer !== null);
    const finished = useRef(false);
    const [originalScopes] = useState(
        () =>
            savedBuffer?.scopes ??
            [article, article.working_copy?.content]
                .filter(Boolean)
                .map((snapshot) => ({
                    audience: snapshot!.audience,
                    site_scope: snapshot!.site_scope,
                })),
    );
    const scopeAllowed = [
        ...originalScopes,
        form.data,
        ...(savedBuffer ? [savedBuffer.data] : []),
    ].every(
        (scope) =>
            scope.audience !== 'specific_sites' ||
            (Boolean(scope.site_scope?.length) &&
                (currentOptions.organisation_wide === true ||
                    scope.site_scope!.every((id) =>
                        currentOptions.sites.some(
                            (site) => Number(site.id) === Number(id),
                        ),
                    ))),
    );
    const discardBuffer = () => {
        finished.current = true;
        dropKnowledgeBuffer(actorId, article.id);
    };
    const cancel = () => {
        discardBuffer();
        onCancel();
    };
    useEffect(() => {
        if (
            context.status === 'denied' ||
            (context.status === 'ready' && !scopeAllowed)
        ) {
            dropKnowledgeBuffer(actorId, article.id);
            onDenied();
        }
    }, [context.status, scopeAllowed, actorId, article.id, onDenied]);
    useEffect(() => {
        if (
            !finished.current &&
            !resumePending &&
            context.status === 'ready' &&
            scopeAllowed &&
            form.isDirty
        ) {
            saveKnowledgeBuffer(
                actorId,
                article.id,
                form.data,
                [
                    ...originalScopes,
                    {
                        audience: form.data.audience,
                        site_scope: form.data.site_scope,
                    },
                ],
                linksChanged,
                tab,
            );
        }
    }, [
        actorId,
        article.id,
        context.status,
        form.data,
        form.isDirty,
        linksChanged,
        originalScopes,
        resumePending,
        scopeAllowed,
        tab,
    ]);
    const refreshContext = context.refresh;
    useEffect(() => {
        const refresh = () => refreshContext();
        const visible = () => {
            if (document.visibilityState === 'visible') refresh();
        };
        window.addEventListener('focus', refresh);
        window.addEventListener('pageshow', refresh);
        document.addEventListener('visibilitychange', visible);
        return () => {
            window.removeEventListener('focus', refresh);
            window.removeEventListener('pageshow', refresh);
            document.removeEventListener('visibilitychange', visible);
        };
    }, [refreshContext]);
    const leave = useSettingsLeaveConfirmation(
        form.isDirty,
        'Discard unsaved document changes?',
        'Your unsaved document changes will be discarded. A save already sent may still finish; reopen the saved document before retrying it. Cancel to keep editing.',
        discardBuffer,
    );
    const [error, setError] = useState('');
    const stale =
        current.lock_version !== form.data.lock_version &&
        !(
            ownUploadVersion === form.data.lock_version &&
            (current.lock_version ?? 0) < ownUploadVersion
        );
    const submit = (event: React.FormEvent, keepEditing = false) => {
        event.preventDefault();
        if (
            form.processing ||
            fileBusy ||
            stale ||
            context.status !== 'ready' ||
            !context.data.editable ||
            !scopeAllowed ||
            resumePending
        )
            return;
        setError('');
        form.transform(({ related_records, tags_text, ...fields }) => {
            const data = {
                ...fields,
                ...(currentOptions.tags_ready
                    ? {
                          tags: tags_text
                              .split(',')
                              .map((tag) => tag.trim())
                              .filter(Boolean),
                      }
                    : {}),
            };
            return linksChanged
                ? {
                      ...data,
                      related_records: related_records.map(
                          ({ id, type, relation }) => ({
                              id,
                              type,
                              relation: relation ?? 'documents',
                          }),
                      ),
                  }
                : data;
        });
        form.patch(`/it/kb/${article.id}`, {
            preserveScroll: true,
            onError: () => {
                setError(
                    'The draft was not saved. Review the field messages below; your changes are retained.',
                );
                context.refresh();
            },
            onSuccess: (page) => {
                const failure = (
                    page.props.flash as { error?: string } | undefined
                )?.error;
                if (failure) setError(failure);
                else {
                    discardBuffer();
                    if (keepEditing) onContinue('files');
                    else onDone();
                }
            },
        });
    };
    const errors = form.errors as Record<string, string>;
    if (context.status !== 'ready' || !scopeAllowed)
        return (
            <section className="space-y-3 rounded-xl border border-border bg-card p-5">
                <h2 className="text-section-title">Checking document access</h2>
                <p role="status" className="text-subtle">
                    {context.status === 'loading'
                        ? 'Loading the current document before showing your editor.'
                        : 'message' in context
                          ? context.message
                          : 'This document is unavailable.'}
                </p>
                {context.status === 'failed' && (
                    <Button variant="outline" onClick={context.refresh}>
                        Try loading again
                    </Button>
                )}
                <Button variant="outline" onClick={onCancel}>
                    Back to document
                </Button>
            </section>
        );
    if (resumePending && savedBuffer)
        return (
            <section className="space-y-3 rounded-xl border border-border bg-card p-5">
                <h2 className="text-section-title">Resume unsaved changes?</h2>
                <p className="text-subtle">
                    Changes from this open session are available. Resume them to
                    compare with the current document, or discard them. Save a
                    draft to keep work after a reload or sign-out.
                </p>
                <div className="flex flex-wrap gap-2">
                    <Button
                        onClick={() => {
                            form.setData(savedBuffer.data);
                            setLinksChanged(savedBuffer.linksChanged);
                            setTab(savedBuffer.tab);
                            setResumePending(false);
                        }}
                    >
                        Resume changes
                    </Button>
                    <Button
                        variant="outline"
                        onClick={() => {
                            dropKnowledgeBuffer(actorId, article.id);
                            setResumePending(false);
                        }}
                    >
                        Discard unsaved changes
                    </Button>
                </div>
            </section>
        );
    return (
        <div className="space-y-5">
            <form onSubmit={submit} className="space-y-5">
                {/* This toolbar keeps the full-page draft actions together. */}
                {/* eslint-disable-next-line no-restricted-syntax -- Editor action toolbar, not a content card. */}
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-4">
                    <div>
                        <h2 className="text-section-title">Edit document</h2>
                        <p className="text-subtle">
                            {article.status === 'published'
                                ? 'Save a proposed revision for review. Readers continue to see the approved publication.'
                                : 'Save your work as a draft, then send it for review.'}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing || fileBusy}
                            onClick={() => leave.request(cancel)}
                        >
                            Cancel editing
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                fileBusy ||
                                stale ||
                                !context.data.editable
                            }
                        >
                            {form.processing
                                ? 'Saving…'
                                : 'Save document draft'}
                        </Button>
                    </div>
                </div>
                {!context.data.editable && (
                    <p role="status" className="text-sm text-status-warning">
                        This document is not currently editable. Your unsaved
                        changes are retained. Reopen the document and return it
                        to draft before saving.
                    </p>
                )}
                {(error || stale || Object.keys(errors).length > 0) && (
                    <div
                        role="alert"
                        className="space-y-2 rounded-lg border border-status-critical bg-status-critical-bg p-4 text-status-critical"
                    >
                        <p>
                            {stale
                                ? 'This document changed while you were editing. Your changes are retained. Review the latest document in a separate tab before continuing.'
                                : error}
                        </p>
                        {Object.entries(errors).map(([key, message]) => (
                            <p key={key}>
                                {key.replaceAll('_', ' ')}: {message}
                            </p>
                        ))}
                        {stale && (
                            <div className="space-y-3 text-foreground">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setReviewedCurrent(true)}
                                >
                                    Review current saved content
                                </Button>
                                {reviewedCurrent && (
                                    <Card>
                                        <CardContent className="space-y-4 p-4">
                                            <h3 className="text-section-title">
                                                {content.title}
                                            </h3>
                                            <p className="text-caption">
                                                Current version{' '}
                                                {current.lock_version} ·{' '}
                                                {content.audience?.replaceAll(
                                                    '_',
                                                    ' ',
                                                )}{' '}
                                                · Review due{' '}
                                                {formatDateOnly(
                                                    content.review_due_at,
                                                )}
                                            </p>
                                            <KbPreview
                                                body={content.body ?? ''}
                                            />
                                            {knowledgeSectionsFor(
                                                content.document_type ??
                                                    'guide',
                                            ).map((field) =>
                                                content.structured_content?.[
                                                    field.key
                                                ] ? (
                                                    <section key={field.key}>
                                                        <h4 className="text-sm font-medium">
                                                            {field.label}
                                                        </h4>
                                                        <KbPreview
                                                            body={
                                                                content
                                                                    .structured_content[
                                                                    field.key
                                                                ] ?? ''
                                                            }
                                                        />
                                                    </section>
                                                ) : null,
                                            )}
                                            <KnowledgeRelatedRecords
                                                records={
                                                    content.related_records
                                                }
                                            />
                                            <KnowledgeDiagrams
                                                diagrams={
                                                    content.diagrams ?? []
                                                }
                                                recordKey={`${actorId}:${article.id}:current-comparison`}
                                                raster={diagramRaster}
                                            />
                                            <p className="text-subtle">
                                                Keep your proposal after
                                                reviewing the current version.
                                                Saving again will apply your
                                                displayed fields; files are
                                                preserved.
                                            </p>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled={
                                                    !context.data.editable
                                                }
                                                onClick={() => {
                                                    form.setData(
                                                        'lock_version',
                                                        current.lock_version ??
                                                            1,
                                                    );
                                                    form.clearErrors(
                                                        'lock_version',
                                                    );
                                                    setError('');
                                                    setReviewedCurrent(false);
                                                }}
                                            >
                                                Keep my proposal against this
                                                version
                                            </Button>
                                        </CardContent>
                                    </Card>
                                )}
                            </div>
                        )}
                    </div>
                )}
                <TierTwoTabs
                    tabs={tabs.map((item) =>
                        item.key === 'diagrams'
                            ? { ...item, label: 'Diagram builder' }
                            : item,
                    )}
                    activeTab={tab}
                    onTab={setTab}
                    renderLink={(item, className, inner, accessibility) => (
                        <Button
                            type="button"
                            variant="ghost"
                            className={className}
                            {...accessibility}
                            onClick={() => setTab(item.key)}
                        >
                            {inner}
                        </Button>
                    )}
                    ariaLabel="Document editor sections"
                />
                {tab !== 'files' && (
                    <fieldset
                        disabled={form.processing || fileBusy}
                        className="space-y-5 rounded-xl border border-border bg-card p-5"
                    >
                        {tab === 'content' && (
                            <>
                                <Field label="Title" id="document-title">
                                    <Input
                                        id="document-title"
                                        maxLength={255}
                                        value={form.data.title}
                                        onChange={(event) =>
                                            form.setData(
                                                'title',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                {currentOptions.tags_ready && (
                                    <Field label="Tags" id="document-tags">
                                        <Input
                                            id="document-tags"
                                            value={form.data.tags_text}
                                            maxLength={510}
                                            aria-describedby="document-tags-help"
                                            onChange={(event) =>
                                                form.setData(
                                                    'tags_text',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <p
                                            id="document-tags-help"
                                            className="text-subtle"
                                        >
                                            Separate tags with commas. Use up to
                                            12 tags, with 40 characters each.
                                        </p>
                                    </Field>
                                )}
                                <div className="grid gap-5 sm:grid-cols-2">
                                    <Field
                                        label="Document type"
                                        id="document-type"
                                    >
                                        <select
                                            id="document-type"
                                            className="select w-full"
                                            value={form.data.document_type}
                                            onChange={(event) =>
                                                form.setData(
                                                    'document_type',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            {KNOWLEDGE_DOCUMENT_TYPES.map(
                                                (item) => (
                                                    <option
                                                        key={item.value}
                                                        value={item.value}
                                                    >
                                                        {item.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </Field>
                                    <Field
                                        label="Category"
                                        id="document-category"
                                    >
                                        <select
                                            id="document-category"
                                            className="select w-full"
                                            value={form.data.category}
                                            onChange={(event) =>
                                                form.setData(
                                                    'category',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            {[
                                                'hardware',
                                                'account',
                                                'network',
                                                'other',
                                            ].map((value) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {value}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                </div>
                                <Field
                                    label="Document content"
                                    id="document-body"
                                >
                                    <Textarea
                                        id="document-body"
                                        rows={18}
                                        maxLength={20000}
                                        value={form.data.body}
                                        onChange={(event) =>
                                            form.setData(
                                                'body',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                {template && (
                                    <section
                                        className="space-y-2 rounded-lg border border-border p-4"
                                        aria-label="Document template guidance"
                                    >
                                        <h3 className="text-sm font-medium">
                                            {template.label} template
                                        </h3>
                                        <p className="text-subtle">
                                            {template.description}
                                        </p>
                                        {missingSections.length > 0 ? (
                                            <>
                                                <p className="text-sm">
                                                    You can save this draft now.
                                                    Complete these sections
                                                    before sending it for
                                                    review:
                                                </p>
                                                <ul className="list-inside list-disc space-y-1 text-sm">
                                                    {missingSections.map(
                                                        (key) => (
                                                            <li key={key}>
                                                                <a
                                                                    href={`#document-${key}`}
                                                                    className="frontline-focus underline"
                                                                >
                                                                    {KNOWLEDGE_SECTIONS.find(
                                                                        (
                                                                            field,
                                                                        ) =>
                                                                            field.key ===
                                                                            key,
                                                                    )?.label ??
                                                                        key}
                                                                </a>
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            </>
                                        ) : (
                                            <p className="text-subtle">
                                                The required template sections
                                                are filled in. A reviewer still
                                                needs to check the content.
                                            </p>
                                        )}
                                    </section>
                                )}
                                <details>
                                    <summary className="cursor-pointer text-sm font-medium">
                                        Preview content
                                    </summary>
                                    <KbPreview body={form.data.body} />
                                </details>
                                {knowledgeSectionsFor(
                                    form.data.document_type,
                                ).map((field) => (
                                    <Field
                                        key={field.key}
                                        label={field.label}
                                        id={`document-${field.key}`}
                                    >
                                        <Textarea
                                            id={`document-${field.key}`}
                                            rows={5}
                                            maxLength={5000}
                                            value={
                                                form.data.structured_content[
                                                    field.key
                                                ]
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'structured_content',
                                                    {
                                                        ...form.data
                                                            .structured_content,
                                                        [field.key]:
                                                            event.target.value,
                                                    },
                                                )
                                            }
                                        />
                                    </Field>
                                ))}
                            </>
                        )}
                        {tab === 'diagrams' &&
                            (current.media_ready ? (
                                <KnowledgeDiagrams
                                    diagrams={form.data.diagrams}
                                    recordKey={`${actorId}:${article.id}:editor`}
                                    raster={diagramRaster}
                                    onChange={(diagrams) =>
                                        form.setData('diagrams', diagrams)
                                    }
                                />
                            ) : (
                                <p role="status">
                                    The diagram builder needs the document
                                    storage update before diagrams can be saved.
                                    Setup is pending for this environment.
                                </p>
                            ))}
                        {tab === 'relationships' && (
                            <>
                                <KnowledgeRelatedRecords
                                    records={form.data.related_records}
                                />
                                {form.data.related_records.map((record) => (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        key={`${record.type}:${record.id}`}
                                        onClick={() => {
                                            setLinksChanged(true);
                                            form.setData(
                                                'related_records',
                                                form.data.related_records.filter(
                                                    (item) => item !== record,
                                                ),
                                            );
                                        }}
                                    >
                                        Remove {record.label}
                                    </Button>
                                ))}
                                <KnowledgeRecordSearch
                                    articleId={article.id}
                                    selected={form.data.related_records}
                                    onSelect={(record) => {
                                        setLinksChanged(true);
                                        form.setData('related_records', [
                                            ...form.data.related_records,
                                            {
                                                ...record,
                                                relation: 'documents',
                                            },
                                        ]);
                                    }}
                                />
                                <p className="text-caption">
                                    Unchanged relationships are preserved,
                                    including records currently hidden from your
                                    account.
                                </p>
                            </>
                        )}
                        {tab === 'ownership' && (
                            <>
                                <Field
                                    label="Document owner"
                                    id="document-owner"
                                >
                                    <select
                                        id="document-owner"
                                        className="select w-full"
                                        value={form.data.owner_user_id}
                                        onChange={(event) =>
                                            form.setData(
                                                'owner_user_id',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <option value="">Choose owner</option>
                                        {currentOptions.owners.map((owner) => (
                                            <option
                                                key={owner.id}
                                                value={owner.id}
                                            >
                                                {owner.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field label="Audience" id="document-audience">
                                    <select
                                        id="document-audience"
                                        className="select w-full"
                                        value={form.data.audience}
                                        onChange={(event) =>
                                            form.setData(
                                                'audience',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <option value="all_staff">
                                            All staff
                                        </option>
                                        <option value="it_agents">
                                            IT staff
                                        </option>
                                        <option value="specific_sites">
                                            Selected approved sites
                                        </option>
                                    </select>
                                </Field>
                                {form.data.audience === 'specific_sites' && (
                                    <fieldset className="space-y-2">
                                        <legend className="text-sm font-medium">
                                            Approved sites
                                        </legend>
                                        {currentOptions.sites.map((site) => (
                                            <label
                                                key={site.id}
                                                className="flex items-center gap-3 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={form.data.site_scope.includes(
                                                        site.id,
                                                    )}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'site_scope',
                                                            event.target.checked
                                                                ? [
                                                                      ...form
                                                                          .data
                                                                          .site_scope,
                                                                      site.id,
                                                                  ]
                                                                : form.data.site_scope.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          site.id,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                {site.name}
                                            </label>
                                        ))}
                                    </fieldset>
                                )}
                                <Field label="Review due" id="document-review">
                                    <Input
                                        id="document-review"
                                        type="date"
                                        value={form.data.review_due_at}
                                        onChange={(event) =>
                                            form.setData(
                                                'review_due_at',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Related service"
                                    id="document-service"
                                >
                                    <select
                                        id="document-service"
                                        className="select w-full"
                                        value={form.data.related_service_id}
                                        onChange={(event) =>
                                            form.setData(
                                                'related_service_id',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <option value="">No service</option>
                                        {currentOptions.services.map(
                                            (service) => (
                                                <option
                                                    key={service.id}
                                                    value={service.id}
                                                >
                                                    {service.name}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </Field>
                            </>
                        )}
                    </fieldset>
                )}
                {tab !== 'files' && (
                    <div className="flex justify-end gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing || fileBusy}
                            onClick={() => leave.request(cancel)}
                        >
                            Cancel editing
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                fileBusy ||
                                stale ||
                                !context.data.editable
                            }
                        >
                            {form.processing
                                ? 'Saving…'
                                : 'Save document draft'}
                        </Button>
                    </div>
                )}
                {leave.confirmation}
            </form>
            <div hidden={tab !== 'files'} className="space-y-4">
                {form.isDirty && (
                    <section className="space-y-3 rounded-xl border border-border bg-card p-5">
                        <p role="status" className="text-subtle">
                            Save your text and diagram changes before adding a
                            file. You will stay here in the editor.
                        </p>
                        <Button
                            disabled={
                                form.processing ||
                                fileBusy ||
                                stale ||
                                !context.data.editable
                            }
                            onClick={(event) => submit(event, true)}
                        >
                            {form.processing
                                ? 'Saving…'
                                : 'Save changes and continue to files'}
                        </Button>
                    </section>
                )}
                <DocumentFiles
                    key={`${article.id}:${article.lock_version}`}
                    article={current}
                    files={files}
                    history={fileHistory}
                    unfinished={unfinishedUploads}
                    activeIds={content.file_ids ?? []}
                    actorId={actorId}
                    uploadsDisabled={
                        form.isDirty ||
                        form.processing ||
                        stale ||
                        !context.data.editable
                    }
                    onBusy={setFileBusy}
                    onSaved={() => onContinue('files')}
                />
            </div>
        </div>
    );
}

function Field({
    label,
    id,
    children,
}: {
    label: string;
    id: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
        </div>
    );
}

function DocumentFiles({
    article,
    files,
    activeIds,
    actorId,
    history,
    unfinished,
    uploadsDisabled = false,
    onBusy,
    onSaved,
}: {
    article: KbRow;
    files: FileVersion[];
    activeIds: number[];
    actorId: number;
    history: FileHistory;
    unfinished: UnfinishedUploads;
    uploadsDisabled?: boolean;
    onBusy?: (busy: boolean) => void;
    onSaved?: () => void;
}) {
    const [older, setOlder] = useState(history.files);
    const [nextBefore, setNextBefore] = useState(history.next_before_id);
    const [loadingHistory, setLoadingHistory] = useState(false);
    const [historyError, setHistoryError] = useState('');
    const visibleFiles = [
        ...files,
        ...older.filter(
            (file) => !files.some((current) => current.id === file.id),
        ),
    ];
    const loadOlder = async () => {
        if (!nextBefore || loadingHistory) return;
        setLoadingHistory(true);
        setHistoryError('');
        try {
            const { data } = await axios.get<
                FileHistory & { actor_user_id: number; article_id: number }
            >(`/it/knowledge/${article.id}/files/history`, {
                params: { before_id: nextBefore },
            });
            if (
                data.actor_user_id !== actorId ||
                data.article_id !== article.id ||
                !Array.isArray(data.files)
            )
                throw new Error('File access changed.');
            setOlder((current) => [
                ...current,
                ...data.files.filter(
                    (file) =>
                        !current.some((existing) => existing.id === file.id),
                ),
            ]);
            setNextBefore(data.next_before_id);
        } catch {
            setHistoryError(
                'Older files could not be loaded. Try again, or reopen the document to check your current access.',
            );
        } finally {
            setLoadingHistory(false);
        }
    };
    const [uploadUuid] = useState(() => crypto.randomUUID());
    const form = useForm({
        actor_user_id: actorId,
        request_uuid: uploadUuid,
        lock_version: article.lock_version ?? 1,
        file: null as File | null,
        replace_file_id: '',
    });
    const [generation, setGeneration] = useState(0);
    const proposedIds =
        article.working_copy?.content.file_ids ?? article.file_ids ?? [];
    const upload = (event: React.FormEvent) => {
        event.preventDefault();
        if (uploadsDisabled || form.processing || !form.data.file) return;
        form.transform((data) => ({
            ...data,
            lock_version: article.lock_version ?? 1,
        }));
        form.post(`/it/knowledge/${article.id}/files`, {
            preserveScroll: true,
            forceFormData: true,
            onStart: () => onBusy?.(true),
            onFinish: () => onBusy?.(false),
            onSuccess: (page) => {
                if ((page.props.flash as { error?: string } | undefined)?.error)
                    return;
                form.reset();
                setGeneration(generation + 1);
                onSaved?.();
            },
        });
    };
    return (
        <div className="space-y-5">
            <section className="space-y-3 rounded-xl border border-border bg-card p-5">
                <h2 className="text-section-title">Document files</h2>
                {files.filter((file) => activeIds.includes(file.id)).length ===
                    0 && (
                    <p className="text-subtle">
                        No files in this version of the document.
                    </p>
                )}
                {files
                    .filter((file) => activeIds.includes(file.id))
                    .map((file) => (
                        <FileRow key={file.id} file={file} />
                    ))}
            </section>
            {article.can.edit && !article.media_ready && (
                <p
                    role="status"
                    className="text-subtle rounded-xl border border-border bg-card p-5"
                >
                    Document and image uploads need the document storage update.
                    Setup is pending for this environment.
                </p>
            )}
            {article.can.edit && article.media_ready && (
                <form
                    onSubmit={upload}
                    className="space-y-4 rounded-xl border border-border bg-card p-5"
                >
                    <fieldset
                        disabled={uploadsDisabled || form.processing}
                        className="space-y-4"
                    >
                        <h2 className="text-section-title">
                            Add or replace a file in the draft
                        </h2>
                        <p className="text-subtle">
                            Word (.doc, .docx), PDF, PNG and JPEG, up to 20 MB.
                            Replacements retain earlier published versions.
                            Files must pass malware checking before they can be
                            opened.
                        </p>
                        <Field
                            label="Replace existing file (optional)"
                            id="replace-file"
                        >
                            <select
                                id="replace-file"
                                className="select w-full"
                                value={form.data.replace_file_id}
                                disabled={form.processing}
                                onChange={(event) => {
                                    form.setData(
                                        'replace_file_id',
                                        event.target.value,
                                    );
                                    form.setData(
                                        'request_uuid',
                                        crypto.randomUUID(),
                                    );
                                }}
                            >
                                <option value="">Add a new file</option>
                                {files
                                    .filter((file) =>
                                        proposedIds.includes(file.id),
                                    )
                                    .map((file) => (
                                        <option key={file.id} value={file.id}>
                                            {file.name} · version {file.version}
                                        </option>
                                    ))}
                            </select>
                        </Field>
                        <Field
                            label="Word, PDF or image file"
                            id="document-file"
                        >
                            <Input
                                key={generation}
                                id="document-file"
                                type="file"
                                accept=".doc,.docx,.pdf,.png,.jpg,.jpeg"
                                disabled={form.processing}
                                aria-invalid={Boolean(form.errors.file)}
                                aria-describedby={
                                    form.errors.file
                                        ? 'document-file-error'
                                        : undefined
                                }
                                onChange={(event) => {
                                    const file =
                                        event.target.files?.[0] ?? null;
                                    const oversized =
                                        file !== null &&
                                        file.size > MAX_DOCUMENT_FILE_BYTES;
                                    form.clearErrors('file');
                                    form.setData(
                                        'file',
                                        oversized ? null : file,
                                    );
                                    if (oversized) {
                                        form.setError(
                                            'file',
                                            'This file is larger than 20 MB. Choose a smaller document or image.',
                                        );
                                    }
                                    form.setData(
                                        'request_uuid',
                                        crypto.randomUUID(),
                                    );
                                }}
                            />
                        </Field>
                        {Object.entries(form.errors).map(([key, error]) => (
                            <p
                                key={key}
                                id={
                                    key === 'file'
                                        ? 'document-file-error'
                                        : undefined
                                }
                                role="alert"
                                className="text-sm text-status-critical"
                            >
                                {error}
                            </p>
                        ))}
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.file}
                        >
                            {form.processing
                                ? 'Checking and saving file…'
                                : 'Save file to draft'}
                        </Button>
                    </fieldset>
                </form>
            )}
            {article.can.author && article.media_ready && (
                <KnowledgeUnfinishedUploads
                    key={JSON.stringify(unfinished)}
                    initial={unfinished}
                    articleId={article.id}
                    actorId={actorId}
                    editable={article.can.edit === true && !uploadsDisabled}
                    onBusy={onBusy}
                    onSaved={onSaved}
                />
            )}
            {(visibleFiles.some((file) => !activeIds.includes(file.id)) ||
                nextBefore) && (
                <details className="space-y-3 rounded-xl border border-border bg-card p-5">
                    <summary className="cursor-pointer text-sm font-medium">
                        Other saved file versions
                    </summary>
                    <p className="text-caption">
                        Files from accessible published revisions and the
                        current proposal.
                    </p>
                    {visibleFiles
                        .filter((file) => !activeIds.includes(file.id))
                        .map((file) => (
                            <FileRow key={file.id} file={file} />
                        ))}
                    {historyError && (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            {historyError}
                        </p>
                    )}
                    {nextBefore && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={loadingHistory}
                            onClick={loadOlder}
                        >
                            {loadingHistory
                                ? 'Loading older files…'
                                : 'Load older file versions'}
                        </Button>
                    )}
                </details>
            )}
        </div>
    );
}

function FileRow({ file }: { file: FileVersion }) {
    const href = knowledgeFileHref(file.href, usePage().url);
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3">
            <div className="min-w-0">
                <p className="text-sm font-medium break-words">{file.name}</p>
                <p className="text-caption">
                    Version {file.version} · {Math.ceil(file.size / 1024)} KB ·{' '}
                    {formatDateTime(file.created_at)}
                </p>
            </div>
            <div className="flex gap-2">
                <Button variant="outline" asChild>
                    <Link href={href}>Open</Link>
                </Button>
                <Button variant="ghost" asChild>
                    <a href={`${file.href}?original=1`}>Download original</a>
                </Button>
            </div>
        </div>
    );
}
