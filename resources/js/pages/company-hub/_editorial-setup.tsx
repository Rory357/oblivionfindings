import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, BookOpen, CalendarDays, Newspaper } from 'lucide-react';
import { useHubPageCreation } from './_page-creation-context';
import type { HubProps } from './_types';

export function EditorialSetup({ data }: { data: HubProps }) {
    const create = useHubPageCreation();
    if (data.demo || !data.capabilities.write) return null;
    const needsNews = !data.pages.some((page) => page.kind === 'news');
    const needsGuide = !data.pages.some((page) => page.kind === 'knowledge');
    const needsEvent = !data.events.length;
    const count = Number(needsNews) + Number(needsGuide) + Number(needsEvent);
    if (!count) return null;
    return (
        <details className="hub-editorial-setup">
            <summary>
                <span className="font-medium">
                    Make this space your company’s own
                </span>
                <span className="text-xs text-muted-foreground">
                    {count} starting {count === 1 ? 'point' : 'points'} ·
                    Editors only
                </span>
            </summary>
            <p className="mt-2 text-sm text-muted-foreground">
                Start with a useful story, a clear guide and something to look
                forward to. Your drafts stay private until published.
            </p>
            <div className="mt-3 flex flex-wrap gap-2">
                {needsNews && create && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            create({
                                initial: {
                                    title: '',
                                    kind: 'news',
                                    template: 'story',
                                },
                            })
                        }
                    >
                        <Newspaper className="size-4" /> Write the first story
                    </Button>
                )}
                {needsGuide && (
                    <Button asChild variant="outline" size="sm">
                        <Link href="/company-hub?view=resources">
                            <BookOpen className="size-4" /> Add a useful guide
                        </Link>
                    </Button>
                )}
                {needsEvent && data.capabilities.createEvent && (
                    <Button asChild variant="outline" size="sm">
                        <Link href="/company-hub?view=events&event_workspace=new">
                            <CalendarDays className="size-4" /> Plan an event
                        </Link>
                    </Button>
                )}
                <Button asChild variant="ghost" size="sm">
                    <Link href="/company-hub?view=publishing">
                        Review publishing <ArrowUpRight className="size-3.5" />
                    </Link>
                </Button>
            </div>
        </details>
    );
}
