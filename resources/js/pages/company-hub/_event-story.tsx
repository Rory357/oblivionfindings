import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, BookOpen, Images } from 'lucide-react';
import { useState } from 'react';
import type { HubEvent } from './_types';

export function EventStory({ event }: { event: HubEvent }) {
    const content = event.extendedProps.hubContent;
    const [photo, setPhoto] = useState<number | null>(null);
    if (!content) return null;
    const gallery = content.gallery;
    const previous = () =>
        setPhoto((index) =>
            index === null
                ? null
                : (index + gallery.length - 1) % gallery.length,
        );
    const next = () =>
        setPhoto((index) =>
            index === null ? null : (index + 1) % gallery.length,
        );
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
                            {content.host.name}
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
                        A little look back
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
                    {gallery.length > 0 && (
                        <div className="grid grid-cols-3 gap-3">
                            {gallery.map((image, index) => (
                                <Button
                                    key={image.id}
                                    type="button"
                                    variant="ghost"
                                    aria-label={`View event photo ${index + 1} of ${gallery.length}`}
                                    onClick={() => setPhoto(index)}
                                    className="h-auto overflow-hidden rounded-xl border p-0 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                                >
                                    <img
                                        src={image.url}
                                        alt=""
                                        className="h-36 w-full object-cover transition-transform hover:scale-105"
                                    />
                                </Button>
                            ))}
                        </div>
                    )}
                    {content.story && (
                        <Button variant="outline" asChild>
                            <Link href={content.story.url}>
                                <BookOpen className="size-4" />
                                Read story: {content.story.title}
                            </Link>
                        </Button>
                    )}
                </section>
            )}
            <Dialog
                open={photo !== null}
                onOpenChange={(open) => !open && setPhoto(null)}
            >
                <DialogContent
                    style={{
                        width: 'min(92vw, 980px)',
                        maxWidth: 'min(92vw, 980px)',
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'ArrowLeft') {
                            e.preventDefault();
                            previous();
                        }
                        if (e.key === 'ArrowRight') {
                            e.preventDefault();
                            next();
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>{event.title} · Photos</DialogTitle>
                        <DialogDescription>
                            Photo {(photo ?? 0) + 1} of {gallery.length}
                        </DialogDescription>
                    </DialogHeader>
                    {photo !== null && gallery[photo] && (
                        <img
                            className="max-h-[65vh] w-full rounded-lg object-contain"
                            src={gallery[photo].url}
                            alt={`Event photo ${photo + 1}`}
                        />
                    )}
                    {gallery.length > 1 && (
                        <div className="flex justify-between">
                            <Button variant="outline" onClick={previous}>
                                <ArrowLeft className="size-4" />
                                Previous photo
                            </Button>
                            <Button variant="outline" onClick={next}>
                                Next photo
                                <ArrowRight className="size-4" />
                            </Button>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
