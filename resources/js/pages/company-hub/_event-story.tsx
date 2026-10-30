import { AppreciationIcon } from '@/components/recognition/appreciation-icon';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { BookOpen, Images } from 'lucide-react';
import { EventGallery } from './_event-gallery';
import type { HubEvent } from './_types';

export function EventStory({
    event,
    onRecognise,
}: {
    event: HubEvent;
    onRecognise?: () => void;
}) {
    const content = event.extendedProps.hubContent;
    if (!content) return null;
    const gallery = content.gallery;
    const agenda = content.agenda
        .split('\n')
        .map((item) => item.trim())
        .filter(Boolean);
    return (
        <>
            {content.host && (
                <div className="flex items-center gap-3 rounded-xl border bg-muted/20 px-4 py-3">
                    <span
                        className="flex size-10 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary"
                        aria-hidden="true"
                    >
                        {content.host.name
                            .split(' ')
                            .slice(0, 2)
                            .map((part) => part[0])
                            .join('')}
                    </span>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Your host
                        </p>
                        <p className="text-sm font-medium">
                            {content.host.profile_url ? (
                                <Link
                                    href={content.host.profile_url}
                                    className="text-primary underline-offset-4 hover:underline"
                                >
                                    {content.host.name}
                                </Link>
                            ) : (
                                content.host.name
                            )}
                        </p>
                    </div>
                </div>
            )}
            {agenda.length > 0 && (
                <section className="rounded-xl border p-5">
                    <h3 className="font-semibold">On the agenda</h3>
                    <ol className="mt-4 space-y-3">
                        {agenda.map((item, index) => (
                            <li
                                key={`${index}-${item}`}
                                className="flex items-start gap-3 text-sm"
                            >
                                <span
                                    className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                                    aria-hidden="true"
                                >
                                    {index + 1}
                                </span>
                                <span className="pt-0.5">{item}</span>
                            </li>
                        ))}
                    </ol>
                </section>
            )}
            {content.past && (
                <section className="space-y-4 rounded-xl border bg-card p-5">
                    <div className="flex items-center gap-2 text-xs font-medium text-primary">
                        <Images className="size-4" />
                        Photos & recaps
                    </div>
                    <h3 className="text-xl font-semibold">
                        {content.recap_title || 'A little look back'}
                    </h3>
                    <p className="text-sm leading-relaxed whitespace-pre-wrap">
                        {content.recap ||
                            'The organiser hasn’t shared a recap yet. Event details and shared files are still available here.'}
                    </p>
                    {!!event.extendedProps.registration?.attended && (
                        <p className="text-sm text-muted-foreground">
                            {event.extendedProps.registration.attended}{' '}
                            {event.extendedProps.registration.attended === 1
                                ? 'colleague attended'
                                : 'colleagues attended'}
                            .
                        </p>
                    )}
                    <EventGallery
                        key={event.id}
                        photos={gallery}
                        title={event.title}
                    />
                    {content.story && (
                        <Button variant="outline" asChild>
                            <Link href={content.story.url}>
                                <BookOpen className="size-4" />
                                Read story: {content.story.title}
                            </Link>
                        </Button>
                    )}
                    {onRecognise && (
                        <div className="flex items-center justify-between gap-4 border-t pt-4">
                            <div>
                                <h4 className="text-sm font-semibold">
                                    Good moments start with good people.
                                </h4>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    Choose the colleagues you’d like to
                                    recognise and share a thank-you.
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                className="shrink-0"
                                onClick={() => onRecognise()}
                            >
                                <AppreciationIcon className="size-4" />
                                Thank the team
                            </Button>
                        </div>
                    )}
                </section>
            )}
        </>
    );
}
