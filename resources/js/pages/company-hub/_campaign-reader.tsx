import { formatDate } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowUpRight,
    BookOpen,
    Circle,
    CircleCheck,
    CircleDot,
} from 'lucide-react';
import { HubBlocks, NewsCards } from './_blocks';
import type { HubCampaign, HubContent, HubEvent, HubProps } from './_types';

export function campaignPreview(
    content: HubContent,
    ownerId: number,
    data: HubProps,
): HubCampaign {
    const value = content.campaign;
    const milestones = value?.milestones ?? [];
    const pages = new Map(data.pages.map((page) => [page.id, page]));
    const events = new Map(
        data.events
            .filter((event) => Date.parse(event.end) > Date.now())
            .map((event) => [event.id, event]),
    );
    return {
        total: milestones.length,
        done: milestones.filter((item) => item.status === 'done').length,
        ends_on: value?.ends_on ?? null,
        milestones,
        stories: (value?.story_ids ?? []).flatMap((id) => {
            const page = pages.get(id);
            return page?.kind === 'news' ? [page] : [];
        }),
        resources: (value?.resource_ids ?? []).flatMap((id) => {
            const page = pages.get(id);
            return page && ['page', 'knowledge'].includes(page.kind)
                ? [page]
                : [];
        }),
        events: (value?.event_keys ?? []).flatMap((id) =>
            events.get(id) ? [events.get(id)!] : [],
        ),
        owner:
            data.people?.find((person) => person.user_id === ownerId) ?? null,
    };
}

export function CampaignReader({
    content,
    campaign,
    data,
    onEvent,
    preview = false,
}: {
    content: HubContent;
    campaign: HubCampaign;
    data: HubProps;
    onEvent: (event: HubEvent) => void;
    preview?: boolean;
}) {
    return (
        <section aria-label="Company campaign" className="space-y-5">
            <div className="flex items-center justify-between text-sm text-muted-foreground">
                <Link
                    href={`${data.demo ? '/company-hub/showcase' : '/company-hub'}?view=campaigns`}
                    className="inline-flex items-center gap-2 text-primary hover:underline"
                >
                    <ArrowLeft className="size-4" />
                    All campaigns
                </Link>
                {preview && <span>Draft preview</span>}
            </div>
            <div className="hub-campaign-layout">
                <div className="min-w-0 space-y-6">
                    <HubBlocks
                        preview={preview}
                        sections={content.sections}
                        data={data}
                        onEvent={onEvent}
                    />
                    {campaign.events.length > 0 && (
                        <HubBlocks
                            preview={preview}
                            sections={[
                                {
                                    id: 'campaign-events',
                                    columns: 1,
                                    blocks: [
                                        {
                                            id: 'campaign-events',
                                            type: 'events',
                                            title: 'Ways to take part',
                                            limit: 12,
                                        },
                                    ],
                                },
                            ]}
                            data={{ ...data, events: campaign.events }}
                            onEvent={onEvent}
                        />
                    )}
                    {campaign.stories.length > 0 && (
                        <section
                            className="hub-surface"
                            aria-label="Stories from the initiative"
                        >
                            <h2 className="text-section-title mb-4">
                                Stories from the initiative
                            </h2>
                            <NewsCards
                                pages={campaign.stories}
                                demo={data.demo}
                                bookmarks={preview ? undefined : data.bookmarks}
                            />
                        </section>
                    )}
                </div>
                <aside
                    className="min-w-0 space-y-5"
                    aria-label="Campaign details"
                >
                    <section className="hub-surface" aria-label="Our progress">
                        <h2 className="text-section-title">Our progress</h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {campaign.total
                                ? `${campaign.done} of ${campaign.total} milestones complete`
                                : 'Milestones will appear here as this initiative takes shape.'}
                        </p>
                        {!!campaign.total && (
                            <progress
                                className="mt-4 h-1.5 w-full accent-primary"
                                aria-label="Shared campaign progress"
                                max={campaign.total}
                                value={campaign.done}
                            />
                        )}
                        <ol className="hub-campaign-milestones">
                            {(campaign.milestones ?? []).map((item) => {
                                const Icon =
                                    item.status === 'done'
                                        ? CircleCheck
                                        : item.status === 'active'
                                          ? CircleDot
                                          : Circle;
                                return (
                                    <li key={item.id} data-status={item.status}>
                                        <Icon
                                            className="size-5 shrink-0"
                                            aria-hidden
                                        />
                                        <span>
                                            <strong>{item.title}</strong>
                                            <small>
                                                {item.status === 'done'
                                                    ? 'Complete'
                                                    : item.status === 'active'
                                                      ? 'In progress'
                                                      : 'Up next'}
                                            </small>
                                        </span>
                                    </li>
                                );
                            })}
                        </ol>
                        {campaign.ends_on && (
                            <p className="mt-4 border-t pt-3 text-xs text-muted-foreground">
                                Through {formatDate(campaign.ends_on)}
                            </p>
                        )}
                    </section>
                    <section
                        className="hub-surface"
                        aria-label="Useful campaign resources"
                    >
                        <h2 className="text-section-title mb-3">
                            Useful resources
                        </h2>
                        {campaign.resources.map((page) => (
                            <Link
                                key={page.id}
                                className="hub-campaign-resource"
                                href={
                                    data.demo
                                        ? `/company-hub/showcase?story=${page.slug}`
                                        : `/company-hub/read/${page.slug}`
                                }
                            >
                                <BookOpen
                                    className="size-4 shrink-0 text-primary"
                                    aria-hidden
                                />
                                <span>
                                    <strong>{page.title}</strong>
                                    <small>
                                        {page.resource_type ?? 'Company guide'}
                                    </small>
                                </span>
                                <ArrowUpRight
                                    className="size-4 shrink-0"
                                    aria-hidden
                                />
                            </Link>
                        ))}
                        {!campaign.resources.length && (
                            <p className="text-sm text-muted-foreground">
                                Resources will be added here.
                            </p>
                        )}
                    </section>
                    <section
                        className="hub-surface"
                        aria-label="Campaign owner"
                    >
                        <h2 className="text-section-title mb-3">
                            Looking after this
                        </h2>
                        {campaign.owner ? (
                            <>
                                {data.demo ? (
                                    <p className="font-medium">
                                        {campaign.owner.name}
                                    </p>
                                ) : (
                                    <Link
                                        className="font-medium text-primary hover:underline"
                                        href={`/company-hub/people/${campaign.owner.id}`}
                                    >
                                        {campaign.owner.name}
                                    </Link>
                                )}
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {[
                                        campaign.owner.position,
                                        campaign.owner.department,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                            </>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No current owner contact is available.
                            </p>
                        )}
                    </section>
                </aside>
            </div>
        </section>
    );
}
