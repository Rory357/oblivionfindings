import { formatDate } from '@/lib/datetime';
import { ArrowRight, Clock3, MessageCircle, Newspaper } from 'lucide-react';
import { HubBrowseLink, type ReturnState } from './_browse-state';
import { PageBookmark } from './_page-bookmark';
import type { HubPageSummary, HubProps } from './_types';

export function NewsCards({
    pages,
    demo = false,
    emptyMessage = 'Company stories will appear here as they are published.',
    layout = 'cards',
    bookmarks,
    browse,
}: {
    pages: HubPageSummary[];
    demo?: boolean;
    emptyMessage?: string;
    layout?: 'cards' | 'list';
    bookmarks?: HubProps['bookmarks'];
    browse?: ReturnState;
}) {
    if (!pages.length) return <p className="hub-empty-state">{emptyMessage}</p>;
    return (
        <div className="hub-news-grid" data-layout={layout}>
            {pages.map((page) => {
                const href = demo
                    ? `/company-hub/showcase?story=${page.slug}`
                    : `/company-hub/read/${page.slug}`;
                const author = page.author || page.owner;
                const saved =
                    bookmarks?.some(
                        (item) =>
                            item.subject_type === 'page' &&
                            item.subject_id === page.id,
                    ) ??
                    page.bookmarked ??
                    false;
                return (
                    <article
                        key={page.id}
                        className="hub-story-card"
                        aria-label={page.title}
                    >
                        <HubBrowseLink
                            href={href}
                            browse={browse}
                            returnKey={`story-${page.id}`}
                            className="hub-story-main group rounded-lg focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            {page.cover_url ? (
                                <img
                                    loading="lazy"
                                    src={page.cover_url}
                                    alt=""
                                    className="hub-story-image"
                                />
                            ) : (
                                <div className="hub-news-image flex items-center justify-between px-5">
                                    <Newspaper className="size-8 text-primary" />
                                    <span className="text-caption font-medium text-primary">
                                        {page.category}
                                    </span>
                                </div>
                            )}
                            <div className="hub-story-copy">
                                <div className="hub-story-meta">
                                    <span className="font-medium text-primary">
                                        {page.category}
                                    </span>
                                    <span aria-hidden="true">·</span>
                                    <span>{formatDate(page.published_at)}</span>
                                    {!!page.read_minutes && (
                                        <span className="inline-flex items-center gap-1">
                                            <Clock3 className="size-3" />
                                            {page.read_minutes} min read
                                        </span>
                                    )}
                                </div>
                                <h3 className="text-section-title leading-snug group-hover:text-primary">
                                    {page.title}
                                </h3>
                                <p className="mt-2 line-clamp-3 text-sm leading-relaxed text-muted-foreground">
                                    {page.summary}
                                </p>
                                <span className="mt-4 inline-flex items-center gap-2 text-sm font-medium text-primary">
                                    {page.kind === 'news'
                                        ? 'Read story'
                                        : 'Explore resource'}
                                    <ArrowRight className="size-4" />
                                </span>
                            </div>
                        </HubBrowseLink>
                        {(author ||
                            (page.comments_enabled &&
                                typeof page.comment_count === 'number') ||
                            (!demo && bookmarks !== undefined)) && (
                            <footer className="hub-story-footer">
                                {author && (
                                    <span className="hub-story-author">
                                        <span
                                            className="hub-author-initials"
                                            aria-hidden="true"
                                        >
                                            {author
                                                .trim()
                                                .split(/\s+/)
                                                .slice(0, 2)
                                                .map((word) => word[0])
                                                .join('')}
                                        </span>
                                        <span>{author}</span>
                                    </span>
                                )}
                                <div className="ml-auto flex shrink-0 items-center gap-1">
                                    {page.comments_enabled &&
                                        typeof page.comment_count ===
                                            'number' && (
                                            <HubBrowseLink
                                                href={`${href}#hub-conversation`}
                                                browse={browse}
                                                returnKey={`discussion-${page.id}`}
                                                className="hub-story-conversation"
                                                aria-label={`${page.comment_count} ${page.comment_count === 1 ? 'comment' : 'comments'} on ${page.title}`}
                                            >
                                                <MessageCircle className="size-4" />
                                                <span>
                                                    {page.comment_count}
                                                </span>
                                            </HubBrowseLink>
                                        )}
                                    {!demo && bookmarks !== undefined && (
                                        <PageBookmark
                                            pageId={page.id}
                                            title={page.title}
                                            saved={saved}
                                        />
                                    )}
                                </div>
                            </footer>
                        )}
                    </article>
                );
            })}
        </div>
    );
}
