import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate, toDateInput } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    BookOpen,
    Download,
    FileText,
    History,
    Pencil,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import { HubBookmarkButton, HubBookmarksProvider } from './_bookmarks';
import { browseReturnLink } from './_browse-state';
import { DocumentReadReceipt } from './_document-read-receipt';
import { HubSiteHeader } from './_hero';
import { HubNavigationRail } from './_navigation';
import { HubPdfReader } from './_pdf-reader';
import { HubReadingProvider, useHubReading } from './_reading-navigation';
import type { HubProps } from './_types';
import { fileSize } from './_upload';
import './designer.css';
import './hub.css';

export type HubDocument = {
    actor_id: number;
    id: number;
    media_id: number;
    title: string;
    name: string;
    mime: string;
    size: number;
    version: number;
    document_version: number | null;
    revision_id?: number | null;
    description: string;
    owner: string | null;
    owner_url?: string | null;
    updated_at: string | null;
    review_on?: string | null;
    edition: 'draft' | 'published';
    page: { id: number; title: string; url: string };
    download_url: string;
    preview_url: string | null;
    can_edit: boolean;
    history_url: string | null;
    replacement_url: string | null;
};
export default function DocumentPage(
    props: HubProps & { document: HubDocument },
) {
    const actor =
        (usePage().props.auth as { user?: { id: number } } | undefined)?.user
            ?.id ?? 0;
    if (props.document.actor_id !== actor)
        return (
            <AppLayout>
                <main className="hub-page-canvas">
                    <h1 className="text-page-title">Document unavailable</h1>
                    <p>
                        Reopen Resources to check access for your current
                        account.
                    </p>
                    <Link href="/company-hub?view=resources">
                        Return to Resources
                    </Link>
                </main>
            </AppLayout>
        );
    return (
        <HubReadingProvider actorId={actor} label="document">
            <HubBookmarksProvider
                key={actor}
                actorId={actor}
                initial={props.bookmarks ?? []}
            >
                <DocumentBody
                    key={`${actor}:${props.document.id}:${props.document.media_id}:${props.document.edition}`}
                    {...props}
                />
            </HubBookmarksProvider>
        </HubReadingProvider>
    );
}
function DocumentBody(props: HubProps & { document: HubDocument }) {
    const file = props.document,
        reading = useHubReading();
    const guideUrl =
        file.edition === 'draft'
            ? `/company-hub/manage/${file.page.id}/edit`
            : file.page.url;
    const [search, setSearch] = useState('');
    const browseReturn = browseReturnLink(
        '/company-hub',
        window.location.search,
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Company Hub', href: '/company-hub' },
                { title: 'Resources', href: '/company-hub?view=resources' },
                {
                    title: file.title,
                    href: `/company-hub/documents/${file.id}`,
                },
            ]}
        >
            <Head title={`${file.title} · Company Hub`} />
            <DocumentReadReceipt file={file} />
            <div className="hub-portal" onClickCapture={reading?.capture}>
                <HubSiteHeader
                    content={props.home}
                    search={search}
                    onSearch={setSearch}
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get('/company-hub', { search });
                    }}
                >
                    <HubNavigationRail
                        updates={props.updates}
                        items={props.sharedNavigation ?? []}
                        current="view:resources"
                        onNavigate={(href) =>
                            reading ? reading.primary(href) : router.visit(href)
                        }
                    />
                </HubSiteHeader>
                <main className="hub-page-canvas hub-document-workspace">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="self-start"
                        onClick={() =>
                            reading?.returnHref
                                ? reading.back()
                                : router.visit(browseReturn?.href ?? guideUrl)
                        }
                    >
                        <ArrowLeft className="size-4" />
                        {reading?.returnLabel ??
                            browseReturn?.label ??
                            'Back to the guide'}
                    </Button>
                    <div className="hub-document-heading">
                        <div className="flex min-w-0 items-start gap-4">
                            <span className="hub-document-icon is-large">
                                <FileText className="size-7" />
                            </span>
                            <div className="min-w-0">
                                <p className="text-xs font-semibold tracking-wide text-primary uppercase">
                                    {file.edition === 'draft'
                                        ? 'Private draft'
                                        : 'Company document'}
                                </p>
                                <h1 className="mt-1 text-2xl font-semibold tracking-tight break-words">
                                    {file.title}
                                </h1>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {file.name} · {fileSize(file.size)} ·
                                    Version {file.version}
                                </p>
                            </div>
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
                            {file.edition === 'published' && (
                                <HubBookmarkButton
                                    subject={{
                                        subject_type: 'media',
                                        subject_id: file.id,
                                    }}
                                    title={file.title}
                                    saved={props.bookmarks.some(
                                        (item) =>
                                            item.subject_type === 'media' &&
                                            item.subject_id === file.id,
                                    )}
                                />
                            )}
                            <Button asChild variant="outline">
                                <a href={file.download_url}>
                                    <Download className="size-4" />
                                    Download
                                </a>
                            </Button>
                        </div>
                    </div>
                    {file.edition === 'draft' && (
                        <p className="flex items-center gap-2 rounded-xl border border-primary/20 bg-primary/5 px-5 py-3 text-sm">
                            <ShieldCheck className="size-4 shrink-0 text-primary" />
                            You are reading a private draft. Staff keep seeing
                            the published version until the replacement is
                            published.
                        </p>
                    )}
                    <div className="hub-document-layout">
                        <HubPdfReader
                            key={file.media_id}
                            url={file.preview_url}
                            download={file.download_url}
                            name={file.title}
                            mime={file.mime}
                        />
                        <aside className="hub-document-aside">
                            <h2>About this document</h2>
                            {file.description && (
                                <p className="whitespace-pre-wrap">
                                    {file.description}
                                </p>
                            )}
                            <dl className="space-y-4">
                                <div>
                                    <dt>Document owner</dt>
                                    <dd>
                                        {file.owner && file.owner_url ? (
                                            <Link
                                                href={file.owner_url}
                                                className="text-primary underline underline-offset-4"
                                            >
                                                {file.owner}
                                            </Link>
                                        ) : (
                                            file.owner ||
                                            'Contact your manager for help'
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt>Updated</dt>
                                    <dd>
                                        {file.updated_at
                                            ? formatDate(file.updated_at)
                                            : 'Not recorded'}
                                    </dd>
                                </div>
                                <div>
                                    <dt>Review date</dt>
                                    <dd>
                                        {file.review_on
                                            ? formatDate(file.review_on)
                                            : 'Not scheduled'}
                                        {file.review_on &&
                                            file.review_on.slice(0, 10) <
                                                toDateInput(new Date()) && (
                                                <p
                                                    role="status"
                                                    className="mt-2 text-xs text-status-warning-foreground"
                                                >
                                                    Review overdue. Check with{' '}
                                                    {file.owner ||
                                                        'the document owner'}{' '}
                                                    if you need to confirm a
                                                    step.
                                                </p>
                                            )}
                                    </dd>
                                </div>
                                <div>
                                    <dt>Published in</dt>
                                    <dd>
                                        <Link
                                            href={guideUrl}
                                            className="text-primary underline underline-offset-4"
                                        >
                                            {file.page.title}
                                        </Link>
                                    </dd>
                                </div>
                                <div>
                                    <dt>File</dt>
                                    <dd className="break-all">{file.name}</dd>
                                </div>
                            </dl>
                            <Button
                                className="w-full justify-start"
                                variant="outline"
                                asChild
                            >
                                <Link href={guideUrl}>
                                    <BookOpen className="size-4" /> Read the
                                    guide
                                </Link>
                            </Button>
                            {file.can_edit && (
                                <div className="space-y-3 border-t pt-4">
                                    <h3>Manage this document</h3>
                                    <p>
                                        Replace the file in its draft, then
                                        review and publish the update.
                                    </p>
                                    <Button
                                        variant="outline"
                                        className="w-full justify-start"
                                        asChild
                                    >
                                        <Link
                                            href={`/company-hub/manage/${file.page.id}/documents/${file.id}/edit`}
                                        >
                                            <History className="size-4" />{' '}
                                            Versions & replacement
                                        </Link>
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        className="w-full justify-start"
                                        asChild
                                    >
                                        <Link
                                            href={`/company-hub/manage/${file.page.id}/edit`}
                                        >
                                            <Pencil className="size-4" /> Edit
                                            guide
                                        </Link>
                                    </Button>
                                </div>
                            )}
                        </aside>
                    </div>
                </main>
            </div>
        </AppLayout>
    );
}
