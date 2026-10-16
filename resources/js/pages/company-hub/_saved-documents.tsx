import { Link } from '@inertiajs/react';
import { ArrowUpRight, FileText } from 'lucide-react';
import { HubBookmarkButton } from './_bookmarks';
import type { HubPageSummary, HubProps } from './_types';
import { fileSize } from './_upload';

/** Select from the authorised projection, never from a stored bookmark alone. */
export function savedDocuments(
    data: Pick<HubProps, 'resourceItems' | 'bookmarks'>,
) {
    const roots = new Set<number>();
    return (data.resourceItems ?? []).filter((item) => {
        const id = item.document_id;
        if (
            !id ||
            roots.has(id) ||
            !item.reader_url ||
            !data.bookmarks.some(
                (bookmark) =>
                    bookmark.subject_type === 'media' &&
                    bookmark.subject_id === id,
            )
        )
            return false;
        roots.add(id);
        return true;
    });
}

export function SavedDocuments({
    documents,
    demo,
}: {
    documents: HubPageSummary[];
    demo?: boolean;
}) {
    return (
        <section aria-label="Saved documents">
            <h2 className="text-section-title mb-4">Documents</h2>
            <div className="hub-resource-list">
                {documents.map((file) => (
                    <article
                        key={file.document_id}
                        className="hub-resource-card"
                    >
                        <span className="hub-resource-symbol">
                            <FileText className="size-5" />
                        </span>
                        <div className="hub-resource-copy">
                            <Link
                                href={file.reader_url!}
                                className="hub-resource-title"
                                data-hub-return-key={`document:${file.document_id}`}
                            >
                                {file.title}
                                <ArrowUpRight className="size-4" />
                            </Link>
                            <p className="line-clamp-2 text-sm text-muted-foreground">
                                {file.summary}
                            </p>
                            <p className="mt-2 text-xs text-muted-foreground">
                                {file.filename}
                                {typeof file.size === 'number'
                                    ? ` · ${fileSize(file.size)}`
                                    : ''}
                                {file.document_file_version
                                    ? ` · Version ${file.document_file_version}`
                                    : ''}
                            </p>
                        </div>
                        {!demo && (
                            <HubBookmarkButton
                                subject={{
                                    subject_type: 'media',
                                    subject_id: file.document_id!,
                                }}
                                title={file.title}
                                saved
                            />
                        )}
                    </article>
                ))}
            </div>
        </section>
    );
}
