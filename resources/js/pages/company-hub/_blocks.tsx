import { Avatar, AvatarFallback } from '@/components/ui/avatar';
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
import { formatDate, formatDateTime, formatRelative } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    Cake,
    CalendarDays,
    FileText,
    Heart,
    HeartHandshake,
    MapPin,
    Newspaper,
    PartyPopper,
    Sparkles,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { blockSpan } from './_layout';
import { HubPoll, ReplyForm } from './_participation';
import type {
    HubBlock,
    HubEvent,
    HubPageSummary,
    HubProps,
    HubSection,
} from './_types';

export function HubEmpty({ children }: { children: ReactNode }) {
    return <p className="hub-empty-state">{children}</p>;
}

export function NewsCards({
    pages,
    demo = false,
    emptyMessage = 'Company stories will appear here as they are published.',
}: {
    pages: HubPageSummary[];
    demo?: boolean;
    emptyMessage?: string;
}) {
    return pages.length ? (
        <div className="hub-news-grid">
            {pages.map((page) => (
                <Link
                    key={page.id}
                    href={
                        demo
                            ? `/company-hub/showcase?story=${page.slug}`
                            : `/company-hub/read/${page.slug}`
                    }
                    className="hub-story-card group focus-visible:ring-2 focus-visible:ring-ring"
                >
                    {page.cover_url ? (
                        <img
                            loading="lazy"
                            src={page.cover_url}
                            alt=""
                            className="aspect-[2/1] w-full object-cover"
                        />
                    ) : (
                        <div className="hub-news-image flex aspect-[3/1] items-center justify-between px-5">
                            <Newspaper className="size-8 text-primary" />
                            <span className="text-caption font-medium text-primary">
                                {page.category}
                            </span>
                        </div>
                    )}
                    <div className="hub-story-copy">
                        <div className="text-caption mb-2 flex flex-wrap items-center gap-2 text-muted-foreground">
                            <span>{page.category}</span>
                            <span aria-hidden="true">·</span>
                            <span>{formatDate(page.published_at)}</span>
                        </div>
                        <h3 className="text-section-title leading-snug group-hover:text-primary">
                            {page.title}
                        </h3>
                        <p className="mt-2 line-clamp-3 text-sm leading-relaxed text-muted-foreground">
                            {page.summary}
                        </p>
                        <span className="mt-4 flex items-center gap-2 text-sm font-medium text-primary">
                            {page.kind === 'news'
                                ? 'Read story'
                                : 'Explore resource'}
                            <ArrowRight className="size-4" />
                        </span>
                    </div>
                </Link>
            ))}
        </div>
    ) : (
        <HubEmpty>{emptyMessage}</HubEmpty>
    );
}

export function HubBlocks({
    sections,
    data,
    onEvent,
    onRecognise,
    hideViewHeading = false,
}: {
    sections: HubSection[];
    data: HubProps;
    onEvent: (event: HubEvent) => void;
    onRecognise?: (userId?: number) => void;
    hideViewHeading?: boolean;
}) {
    return (
        <div className="hub-sections">
            {sections.map((section) => (
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
                            }}
                            data-block-type={block.type}
                            aria-label={block.title}
                        >
                            {!hideViewHeading && (
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
}: {
    block: HubBlock;
    data: HubProps;
    onEvent: (event: HubEvent) => void;
    onRecognise?: (userId?: number) => void;
}) {
    const limit = block.limit ?? 6;
    const spotlight =
        data.pages.find((page) => page.kind === 'news' && page.featured) ??
        data.pages.find((page) => page.kind === 'news');
    if (block.type === 'file')
        return block.media_id ? (
            <Button variant="outline" asChild>
                <a href={`/company-hub/media/${block.media_id}`}>
                    Download {block.title}
                    <ArrowUpRight className="size-4" />
                </a>
            </Button>
        ) : (
            <HubEmpty>Add a file in the page editor.</HubEmpty>
        );
    if (block.type === 'poll') return <HubPoll block={block} data={data} />;
    if (block.type === 'people')
        return (
            <Card className="space-y-3 p-5">
                <h3 className="font-semibold">Find the right person</h3>
                <p className="text-sm text-muted-foreground">
                    Connect with colleagues and see their work contact details
                    in the staff directory.
                </p>
                {!!data.people?.length && (
                    <div className="grid grid-cols-2 gap-3">
                        {data.people
                            .filter(
                                (p) =>
                                    !block.category ||
                                    p.department === block.category,
                            )
                            .slice(0, limit)
                            .map((person) => (
                                <div
                                    key={person.id}
                                    className="rounded-lg border p-3"
                                >
                                    <strong className="text-sm">
                                        <Link
                                            href={`/company-hub/people/${person.id}`}
                                        >
                                            {person.name}
                                        </Link>
                                    </strong>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {[person.position, person.department]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                    {person.work_email && (
                                        <a
                                            className="mt-2 block truncate text-xs text-primary"
                                            href={`mailto:${person.work_email}`}
                                        >
                                            {person.work_email}
                                        </a>
                                    )}
                                    {person.work_phone && (
                                        <a
                                            className="mt-1 block text-xs text-primary"
                                            href={`tel:${person.work_phone}`}
                                        >
                                            {person.work_phone}
                                        </a>
                                    )}
                                </div>
                            ))}
                    </div>
                )}
                <Button variant="outline" asChild>
                    <Link href="/company-hub?view=people">
                        Open staff directory
                        <ArrowRight className="size-4" />
                    </Link>
                </Button>
            </Card>
        );
    if (block.type === 'text')
        return (
            <div className="space-y-4 text-base leading-relaxed">
                {(block.body ?? '').split('\n\n').map((paragraph, i) => (
                    <p key={i} className="whitespace-pre-wrap">
                        {paragraph}
                    </p>
                ))}
            </div>
        );
    if (block.type === 'resources') {
        const resources = data.pages
            .filter(
                (page) =>
                    ['page', 'knowledge'].includes(page.kind) &&
                    (!block.category || page.category === block.category),
            )
            .slice(0, limit);
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
                                            : `/company-hub/read/${page.slug}`
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
                demo={data.demo}
                emptyMessage={
                    data.search
                        ? 'No news matches your search. Try a different word.'
                        : undefined
                }
                pages={data.pages
                    .filter(
                        (p) =>
                            p.kind === 'news' &&
                            (!block.category || p.category === block.category),
                    )
                    .filter(
                        (p) =>
                            data.view !== 'home' ||
                            data.selectedPage ||
                            p.id !== spotlight?.id,
                    )
                    .slice(0, limit)}
            />
        );
    if (block.type === 'links') {
        const links = block.links ?? [];
        return links.length ? (
            <Card unstyled className="hub-quick-links">
                {links.map((link) => (
                    <a
                        key={link.id}
                        href={link.url}
                        rel={
                            link.url.startsWith('https:')
                                ? 'noopener noreferrer'
                                : undefined
                        }
                        target={
                            link.url.startsWith('https:') ? '_blank' : undefined
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
                    <article
                        id={`kudos-${kudos.id}`}
                        key={kudos.id}
                        className="hub-recognition-card scroll-mt-28"
                    >
                        <div className="flex items-center gap-3">
                            <Avatar className="size-10">
                                <AvatarFallback className="bg-primary/10 text-primary">
                                    {kudos.to
                                        ?.split(' ')
                                        .map((v) => v[0])
                                        .slice(0, 2)
                                        .join('')}
                                </AvatarFallback>
                            </Avatar>
                            <div className="min-w-0 flex-1">
                                <h3 className="font-semibold">{kudos.to}</h3>
                                <p className="text-caption text-muted-foreground">
                                    Recognised by {kudos.from}
                                </p>
                            </div>
                            <HeartHandshake className="size-6 shrink-0 text-primary" />
                        </div>
                        <p className="my-4 text-sm leading-relaxed whitespace-pre-wrap">
                            {kudos.message}
                        </p>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <span className="text-caption font-medium text-primary">
                                {kudos.category}
                            </span>
                            <span className="text-caption text-muted-foreground">
                                {formatRelative(kudos.created_at)}
                            </span>
                        </div>
                        <div className="mt-4 flex flex-wrap gap-2 border-t border-border pt-3">
                            {Object.entries(kudos.reactions).map(
                                ([emoji, reaction]) => (
                                    <Button
                                        key={emoji}
                                        variant={
                                            reaction.mine
                                                ? 'secondary'
                                                : 'ghost'
                                        }
                                        size="sm"
                                        className="frontline-tap"
                                        disabled={
                                            data.demo ||
                                            !data.capabilities.recognise
                                        }
                                        aria-label={`${emoji === 'heart' ? 'Appreciate' : emoji === 'party' ? 'Celebrate' : 'Applaud'} ${kudos.to}`}
                                        aria-pressed={reaction.mine}
                                        onClick={() =>
                                            router.post(
                                                `/hr/feed/kudos/${kudos.id}/react`,
                                                { emoji },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {emoji === 'heart' ? (
                                            <Heart className="size-4" />
                                        ) : emoji === 'party' ? (
                                            <PartyPopper className="size-4" />
                                        ) : (
                                            <Sparkles className="size-4" />
                                        )}
                                        {reaction.count || ''}
                                    </Button>
                                ),
                            )}
                        </div>
                        {kudos.replies.length > 0 && (
                            <details className="mt-3 text-sm">
                                <summary className="frontline-tap cursor-pointer text-muted-foreground">
                                    {kudos.replies.length} replies
                                </summary>
                                <div className="mt-3 space-y-3">
                                    {kudos.replies.map((reply) => (
                                        <p key={reply.id}>
                                            <strong>{reply.name}</strong>
                                            <br />
                                            {reply.body}
                                        </p>
                                    ))}
                                </div>
                            </details>
                        )}
                        {kudos.can_reply && data.capabilities.recognise && (
                            <ReplyForm
                                url={`/hr/feed/kudos/${kudos.id}/reply`}
                            />
                        )}
                    </article>
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
            <a
                href={block.url}
                target="_blank"
                rel="noopener noreferrer"
                className="frontline-tap inline-flex items-center gap-2 text-primary underline"
            >
                Watch {block.title}
                <ArrowUpRight className="size-4" />
            </a>
        );
    if (block.type === 'notices')
        return (
            <div className="space-y-3">
                {data.announcements.map((notice) => (
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
