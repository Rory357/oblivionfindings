import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Bookmark,
    CalendarDays,
    FileText,
    Newspaper,
    type LucideIcon,
} from 'lucide-react';
import { savedDocuments } from './_saved-documents';
import type { HubEvent, HubProps } from './_types';

type SavedItem = {
    key: string;
    title: string;
    label: string;
    href: string;
    icon: LucideIcon;
    event: HubEvent | null;
};

/** Personal shortcuts use only the current authorised feed and live saved state. */
export function HomeSaved({
    data,
    onEvent,
}: {
    data: HubProps;
    onEvent: (event: HubEvent) => void;
}) {
    // Showcase content has no personal saves or supported Saved destination.
    if (data.demo) return null;
    const base = '/company-hub';
    const pages = new Map(data.pages.map((page) => [page.id, page]));
    const documents = new Map(
        savedDocuments(data).map((file) => [file.document_id, file]),
    );
    const seen = new Set<string>();
    const items = data.bookmarks.flatMap<SavedItem>((bookmark) => {
        const key = `${bookmark.subject_type}:${bookmark.subject_id}`;
        if (seen.has(key)) return [];
        seen.add(key);
        if (bookmark.subject_type === 'media') {
            const file = documents.get(bookmark.subject_id);
            return file
                ? [
                      {
                          key,
                          title: file.title,
                          label: 'Document',
                          href: file.reader_url!,
                          icon: FileText,
                          event: null,
                      },
                  ]
                : [];
        }
        if (bookmark.subject_type === 'page') {
            const page = pages.get(bookmark.subject_id);
            return page
                ? [
                      {
                          key,
                          title: page.title,
                          label:
                              page.kind === 'news'
                                  ? 'Company news'
                                  : 'Company page',
                          href: `${base}/read/${page.slug}`,
                          icon: page.kind === 'news' ? Newspaper : FileText,
                          event: null,
                      },
                  ]
                : [];
        }
        if (bookmark.subject_type === 'event') {
            const event = (data.savedEvents ?? data.events).find(
                (event) =>
                    (event.extendedProps.savedSubjectId ??
                        event.extendedProps.recurrenceParentId ??
                        event.extendedProps.eventId) === bookmark.subject_id,
            );
            return event
                ? [
                      {
                          key,
                          title: event.title,
                          label: 'Event',
                          href: '',
                          icon: CalendarDays,
                          event,
                      },
                  ]
                : [];
        }
        return [];
    });
    return (
        <section className="hub-home-saved" aria-label="Saved for later">
            <div className="hub-home-saved-heading">
                <h2>
                    <Bookmark className="size-4" /> Saved for later
                </h2>
                <Link href={`${base}?view=saved`}>
                    View all <ArrowRight className="size-3.5" />
                </Link>
            </div>
            {items.length ? (
                <ul className="hub-home-saved-items">
                    {items.slice(0, 3).map((item) => {
                        const content = (
                            <>
                                <item.icon className="size-4 shrink-0 text-primary" />
                                <span className="min-w-0">
                                    <strong className="line-clamp-1">
                                        {item.title}
                                    </strong>{' '}
                                    <small>{item.label}</small>
                                </span>
                            </>
                        );
                        return (
                            <li key={item.key}>
                                {item.event ? (
                                    <Button
                                        variant="ghost"
                                        className="hub-home-saved-item"
                                        title={item.title}
                                        data-hub-return-key={`saved:${item.key}`}
                                        onClick={() => onEvent(item.event!)}
                                    >
                                        {content}
                                    </Button>
                                ) : (
                                    <Link
                                        href={item.href}
                                        className="hub-home-saved-item"
                                        title={item.title}
                                        data-hub-return-key={`saved:${item.key}`}
                                    >
                                        {content}
                                    </Link>
                                )}
                            </li>
                        );
                    })}
                </ul>
            ) : (
                <p>
                    Save a story, document or event to find it here.{' '}
                    <Link href={`${base}?view=resources`}>
                        Browse resources <ArrowRight className="size-3.5" />
                    </Link>
                </p>
            )}
        </section>
    );
}
