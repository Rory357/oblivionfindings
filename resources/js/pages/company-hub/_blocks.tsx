import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    Cake,
    CalendarDays,
    FileText,
    MapPin,
    PartyPopper,
    Sparkles,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { CampaignBlock } from './_campaign-block';
import { DynamicBlock } from './_dynamic-blocks';
import { FeaturedTiles } from './_featured';
import { blockSpan } from './_layout';
import { NewsCards } from './_news-cards';
import { HubPoll } from './_participation';
import { RecognitionCard } from './_recognition-card';
import { HubFormattedText } from './_rich-text';
import type { HubBlock, HubEvent, HubProps, HubSection } from './_types';
import { fileSize } from './_upload';

export function HubEmpty({ children }: { children: ReactNode }) {
    return <p className="hub-empty-state">{children}</p>;
}

export { NewsCards } from './_news-cards';

export function HubBlocks({
    sections,
    data,
    onEvent,
    onRecognise,
    hideViewHeading = false,
    preview = false,
}: {
    sections: HubSection[];
    data: HubProps;
    onEvent: (event: HubEvent) => void;
    onRecognise?: (userId?: number) => void;
    hideViewHeading?: boolean;
    preview?: boolean;
}) {
    return (
        <div className="hub-sections">
            {sections
                .filter((section) => !section.hidden)
                .map((section) => ({
                    ...section,
                    blocks: section.blocks.filter((block) => !block.hidden),
                }))
                .filter((section) => section.blocks.length)
                .map((section) => (
                    <div
                        key={section.id}
                        className="hub-section-layout"
                        data-columns={section.columns}
                        data-tone={section.tone}
                        data-spacing={section.spacing}
                    >
                        {section.blocks.map((block, index) => (
                            <section
                                key={block.id}
                                className="hub-surface"
                                style={{
                                    gridColumn: `span ${blockSpan(section, block, index)}`,
                                    minHeight: block.min_height || undefined,
                                }}
                                data-block-type={block.type}
                                data-tone={block.tone ?? 'plain'}
                                aria-label={
                                    block.type === 'featured'
                                        ? 'Featured stories'
                                        : block.title
                                }
                                id={
                                    block.type === 'featured'
                                        ? `block-${block.id}`
                                        : undefined
                                }
                                tabIndex={
                                    block.type === 'featured' ? -1 : undefined
                                }
                            >
                                {!hideViewHeading &&
                                    block.type !== 'featured' && (
                                        <div className="hub-section-heading">
                                            <h2
                                                id={`block-${block.id}`}
                                                tabIndex={-1}
                                                className="text-section-title"
                                            >
                                                {block.title}
                                            </h2>
                                            {[
                                                'news',
                                                'shoutouts',
                                                'events',
                                                'resources',
                                            ].includes(block.type) && (
                                                <Link
                                                    href={`${data.demo ? '/company-hub/showcase' : '/company-hub'}?view=${block.type}`}
                                                    className="frontline-tap inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary hover:underline"
                                                >
                                                    All{' '}
                                                    {block.type === 'shoutouts'
                                                        ? 'shout-outs'
                                                        : block.type}
                                                    <ArrowRight className="size-3.5" />
                                                </Link>
                                            )}
                                        </div>
                                    )}
                                <BlockContent
                                    preview={preview}
                                    block={block}
                                    data={data}
                                    onEvent={onEvent}
                                    onRecognise={onRecognise}
                                />
                            </section>
                        ))}
                    </div>
                ))}
        </div>
    );
}

export function BlockContent({
    block,
    data,
    onEvent,
    onRecognise,
    preview = false,
}: {
    block: HubBlock;
    data: HubProps;
    onEvent: (event: HubEvent) => void;
    onRecognise?: (userId?: number) => void;
    preview?: boolean;
}) {
    const limit = block.limit ?? 6;
    if (block.type === 'campaign')
        return <CampaignBlock block={block} data={data} />;
    if (block.type === 'featured')
        return (
            <FeaturedTiles
                feature={data.blockData?.[block.id]?.featured}
                layout={
                    block.layout === 'equal' || block.layout === 'single'
                        ? block.layout
                        : 'mosaic'
                }
                demo={data.demo}
                minHeight={block.min_height}
            />
        );
    if (block.type === 'notice')
        return (
            <div className="hub-custom-callout">
                <Sparkles className="size-5 shrink-0 text-primary" />
                <p className="text-sm leading-relaxed whitespace-pre-wrap">
                    {block.body || 'Add your message in the page editor.'}
                </p>
            </div>
        );
    if (block.type === 'button')
        return (
            <div className="space-y-4">
                {block.body && (
                    <p className="text-sm leading-relaxed whitespace-pre-wrap text-muted-foreground">
                        {block.body}
                    </p>
                )}
                {block.url ? (
                    <Button asChild>
                        <a
                            href={block.url}
                            rel="noopener noreferrer"
                            target={
                                block.url.startsWith('https:')
                                    ? '_blank'
                                    : undefined
                            }
                        >
                            {block.action_label || 'Explore'}
                            <ArrowUpRight className="size-4" />
                        </a>
                    </Button>
                ) : (
                    <HubEmpty>
                        Add a button label and destination in the page editor.
                    </HubEmpty>
                )}
            </div>
        );
    if (block.type === 'file') {
        const document = data.resourceItems?.find(
            (item) => item.media_id === block.media_id,
        );
        return block.media_id ? (
            <div className="space-y-4">
                {block.body && (
                    <p className="text-sm leading-relaxed whitespace-pre-wrap text-muted-foreground">
                        {block.body}
                    </p>
                )}
                <div className="flex flex-wrap items-center gap-3">
                    {!preview && document?.reader_url && (
                        <Button asChild>
                            <Link href={document.reader_url}>
                                <FileText className="size-4" /> Read document
                            </Link>
                        </Button>
                    )}
                    <Button variant="outline" asChild>
                        <a
                            href={`/company-hub/media/${block.media_id}?download=1`}
                        >
                            Download {block.title}
                            <ArrowUpRight className="size-4" />
                        </a>
                    </Button>
                </div>
                {document?.filename && (
                    <p className="text-xs text-muted-foreground">
                        {document.filename}
                        {typeof document.size === 'number'
                            ? ` · ${fileSize(document.size)}`
                            : ''}
                        {document.document_file_version
                            ? ` · Version ${document.document_file_version}`
                            : ''}
                    </p>
                )}
            </div>
        ) : (
            <HubEmpty>Add a file in the page editor.</HubEmpty>
        );
    }
    if (block.type === 'poll')
        return <HubPoll block={block} data={data} preview={preview} />;
    if (['services', 'teams', 'people'].includes(block.type))
        return <DynamicBlock block={block} data={data} />;
    if (block.type === 'text')
        return <HubFormattedText value={block.rich_text} body={block.body} />;
    if (block.type === 'resources') {
        const resources =
            data.blockData?.[block.id]?.resources ??
            data.pages
                .filter(
                    (page) =>
                        ['page', 'knowledge'].includes(page.kind) &&
                        (!block.category || page.category === block.category),
                )
                .slice(0, limit);
        if (resources.length && block.layout === 'cards')
            return (
                <div
                    className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,15rem),1fr))] gap-3"
                    aria-label="Company resources"
                >
                    {resources.map((page) => (
                        <Link
                            key={page.id}
                            href={
                                data.demo
                                    ? `/company-hub/showcase?story=${page.slug}`
                                    : (page.resource_url ??
                                      `/company-hub/read/${page.slug}`)
                            }
                            className="group flex min-w-0 flex-col rounded-xl border border-border bg-card p-5 transition-colors hover:border-primary/40 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <span className="mb-5 flex items-center justify-between gap-3">
                                <span className="grid size-10 place-items-center rounded-xl bg-primary/10 text-primary">
                                    <FileText className="size-5" />
                                </span>
                                <ArrowUpRight className="size-4 text-muted-foreground group-hover:text-primary" />
                            </span>
                            <span className="font-semibold">{page.title}</span>
                            {page.summary && (
                                <span className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                    {page.summary}
                                </span>
                            )}
                            <span className="mt-auto flex flex-wrap items-center justify-between gap-2 pt-5 text-xs text-muted-foreground">
                                <span>{page.category}</span>
                                <span>{formatDate(page.published_at)}</span>
                            </span>
                        </Link>
                    ))}
                </div>
            );
        return resources.length ? (
            <Table aria-label="Company resources">
                <TableHeader>
                    <TableRow>
                        <TableHead>Resource</TableHead>
                        <TableHead>Category</TableHead>
                        <TableHead>Published</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {resources.map((page) => (
                        <TableRow key={page.id}>
                            <TableCell>
                                <Link
                                    className="flex items-start gap-3 py-2 hover:text-primary"
                                    href={
                                        data.demo
                                            ? `/company-hub/showcase?story=${page.slug}`
                                            : (page.resource_url ??
                                              `/company-hub/read/${page.slug}`)
                                    }
                                >
                                    <FileText className="mt-0.5 size-5 shrink-0 text-primary" />
                                    <span>
                                        <span className="block font-medium">
                                            {page.title}
                                        </span>
                                        <span className="mt-1 block max-w-lg text-xs leading-relaxed text-muted-foreground">
                                            {page.summary}
                                        </span>
                                    </span>
                                </Link>
                            </TableCell>
                            <TableCell className="text-muted-foreground">
                                {page.category}
                            </TableCell>
                            <TableCell className="whitespace-nowrap text-muted-foreground">
                                {formatDate(page.published_at)}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        ) : (
            <HubEmpty>
                {data.search
                    ? 'No resources match your search. Try a different word.'
                    : 'Published company guides and resources will appear here.'}
            </HubEmpty>
        );
    }
    if (block.type === 'news')
        return (
            <NewsCards
                bookmarks={preview ? undefined : data.bookmarks}
                demo={data.demo}
                layout={block.layout === 'list' ? 'list' : 'cards'}
                emptyMessage={
                    data.search
                        ? 'No news matches your search. Try a different word.'
                        : undefined
                }
                pages={
                    data.blockData?.[block.id]?.news ??
                    data.pages
                        .filter(
                            (p) =>
                                p.kind === 'news' &&
                                p.show_in_news !== false &&
                                (!block.category ||
                                    p.category === block.category),
                        )
                        .slice(0, limit)
                }
            />
        );
    if (block.type === 'links') {
        const links = block.links ?? [];
        return links.length ? (
            <Card
                unstyled
                className="hub-quick-links"
                data-layout={block.layout ?? 'cards'}
            >
                {links.map((link) => (
                    <a
                        key={link.id}
                        href={link.url || undefined}
                        aria-disabled={!link.url}
                        rel={
                            link.url?.startsWith('https:')
                                ? 'noopener noreferrer'
                                : undefined
                        }
                        target={
                            link.url?.startsWith('https:')
                                ? '_blank'
                                : undefined
                        }
                        className="hub-quick-link focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <span className="hub-link-icon">
                            <ArrowUpRight className="size-5" />
                        </span>
                        <span>
                            <span className="block font-medium">
                                {link.title}
                            </span>
                            {link.description && (
                                <span className="text-caption mt-1 block text-muted-foreground">
                                    {link.description}
                                </span>
                            )}
                        </span>
                    </a>
                ))}
            </Card>
        ) : (
            <HubEmpty>
                Keep the tools and resources your team uses most in one easy
                place.
            </HubEmpty>
        );
    }
    if (block.type === 'shoutouts')
        return (
            <div className="space-y-4">
                {data.kudos.slice(0, limit).map((kudos) => (
                    <RecognitionCard
                        key={kudos.id}
                        kudos={kudos}
                        data={data}
                        compact
                        readOnly={preview}
                    />
                ))}
                {data.kudos.length === 0 && (
                    <HubEmpty>
                        A small thank-you can make someone’s day. Be the first
                        to recognise a colleague.
                    </HubEmpty>
                )}
            </div>
        );
    if (block.type === 'celebrations') {
        const people = [
            ...data.milestones.birthdays,
            ...data.milestones.anniversaries,
            ...data.milestones.new_hires,
        ]
            .sort((a, b) => a.days_away - b.days_away)
            .slice(0, limit);
        return (
            <Card unstyled className="hub-celebration-list">
                {people.length ? (
                    <div className="space-y-5">
                        {people.map((person) => (
                            <div
                                key={`${person.type}-${person.user_id}`}
                                className="flex items-center gap-3"
                            >
                                <div className="grid size-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                                    {person.type === 'birthday' ? (
                                        <Cake className="size-5" />
                                    ) : (
                                        <Sparkles className="size-5" />
                                    )}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium">
                                        {person.user_name}
                                    </p>
                                    <p className="text-caption text-muted-foreground">
                                        {person.type === 'birthday'
                                            ? 'Birthday'
                                            : person.type === 'anniversary'
                                              ? `${person.years}-year anniversary`
                                              : 'Welcome to the team'}
                                        {person.type !== 'new_hire' &&
                                            ` · ${person.days_away === 0 ? 'Today' : person.date}`}
                                    </p>
                                </div>
                                {onRecognise && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="frontline-tap"
                                        aria-label={`Celebrate ${person.user_name}`}
                                        onClick={() =>
                                            onRecognise(person.user_id)
                                        }
                                    >
                                        <PartyPopper className="size-4 text-primary" />
                                    </Button>
                                )}
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        New starters, anniversaries and approved birthdays will
                        appear here.
                    </p>
                )}
            </Card>
        );
    }
    if (block.type === 'events')
        return data.events.length ? (
            <div className="hub-event-list">
                {data.events.slice(0, limit).map((event) => (
                    <Button
                        key={event.id}
                        data-hub-event-key={event.id}
                        unstyled
                        onClick={() => onEvent(event)}
                        className="hub-event-card group"
                    >
                        <div className="mb-4 flex items-center gap-3">
                            <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary">
                                <CalendarDays className="size-5" />
                            </span>
                            <span className="text-sm font-medium text-primary">
                                {formatDate(event.start)}
                            </span>
                        </div>
                        <h3 className="text-section-title whitespace-normal">
                            {event.title}
                        </h3>
                        {event.extendedProps.cancellation?.cancelled && (
                            <span className="mt-2 inline-flex rounded-full bg-status-warning-bg px-2.5 py-1 text-xs font-medium text-status-warning">
                                Cancelled
                            </span>
                        )}
                        <p className="mt-2 text-sm text-muted-foreground">
                            {event.allDay
                                ? 'All day'
                                : formatDateTime(event.start)}
                        </p>
                        {event.extendedProps.location && (
                            <p className="text-caption mt-3 flex items-center gap-1.5 text-muted-foreground">
                                <MapPin className="size-3.5 shrink-0" />
                                <span className="truncate">
                                    {event.extendedProps.location}
                                </span>
                            </p>
                        )}
                        <span className="mt-4 flex items-center gap-2 text-sm font-medium text-primary">
                            Event details
                            <ArrowRight className="size-4" />
                        </span>
                    </Button>
                ))}
            </div>
        ) : (
            <HubEmpty>
                There are no upcoming events to show. Check back for the next
                team get-together.
            </HubEmpty>
        );
    if (block.type === 'image' && block.media_id)
        return (
            <figure>
                <img
                    src={`/company-hub/media/${block.media_id}`}
                    alt={block.alt ?? ''}
                    className="w-full rounded-xl object-cover"
                />
                {block.body && (
                    <figcaption className="text-caption mt-2 text-muted-foreground">
                        {block.body}
                    </figcaption>
                )}
            </figure>
        );
    if (block.type === 'video' && block.url)
        return (
            <div className="space-y-4">
                {block.body && (
                    <p className="text-sm leading-relaxed whitespace-pre-wrap text-muted-foreground">
                        {block.body}
                    </p>
                )}
                <a
                    href={block.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="frontline-tap inline-flex items-center gap-2 text-primary underline"
                >
                    Watch {block.title}
                    <ArrowUpRight className="size-4" />
                </a>
            </div>
        );
    if (block.type === 'notices')
        return (
            <div className="space-y-3">
                {data.announcements.slice(0, limit).map((notice) => (
                    <Link
                        key={notice.id}
                        href={`/company-hub?view=updates&announcement=${notice.id}`}
                        className="block rounded-xl border border-border bg-card p-4 hover:bg-accent"
                    >
                        <h3 className="font-medium">{notice.title}</h3>
                        <p className="mt-2 line-clamp-2 text-sm text-muted-foreground">
                            {notice.content}
                        </p>
                        {notice.requires_acknowledgement && (
                            <span className="mt-3 block text-sm font-medium text-primary">
                                {notice.viewer_acknowledged
                                    ? 'Acknowledged'
                                    : 'Read and acknowledge'}
                            </span>
                        )}
                    </Link>
                ))}
            </div>
        );
    return null;
}
