import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { heroIcons } from './_hero-icons';
import type { HubBlock, HubProps } from './_types';

export function CampaignBlock({
    block,
    data,
}: {
    block: HubBlock;
    data: HubProps;
}) {
    // The server (or authorised editor preview) supplies the complete current projection.
    // An empty/missing projection never falls back to a broader page pool.
    const campaigns = data.blockData?.[block.id]?.campaigns ?? [];
    const Flag = heroIcons.flag;
    return (
        <div className="space-y-4">
            <div className="hub-campaign-blocks">
                {campaigns.map((page) => {
                    const progress = page.campaign_progress;
                    const total = progress?.total ?? 0;
                    const done = progress?.done ?? 0;
                    return (
                        <article key={page.id} className="hub-campaign-block">
                            {page.cover_url && (
                                <img
                                    src={page.cover_url}
                                    alt=""
                                    loading="lazy"
                                    className="hub-campaign-block-cover"
                                />
                            )}
                            <div className="space-y-4 p-5">
                                <div className="flex items-center gap-2 text-xs font-medium text-primary">
                                    <Flag className="size-5" />
                                    {page.category || 'Company campaign'}
                                </div>
                                <div>
                                    <Link
                                        className="text-lg font-semibold hover:underline"
                                        href={
                                            data.demo
                                                ? `/company-hub/showcase?story=${page.slug}`
                                                : `/company-hub/read/${page.slug}`
                                        }
                                    >
                                        {page.title}
                                        <ArrowUpRight
                                            aria-hidden="true"
                                            className="ml-2 inline size-4"
                                        />
                                    </Link>
                                    {page.summary && (
                                        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                            {page.summary}
                                        </p>
                                    )}
                                </div>
                                {total > 0 ? (
                                    <div className="space-y-2">
                                        <div className="flex flex-wrap justify-between gap-2 text-xs">
                                            <span className="font-medium">
                                                {done} of {total} milestones
                                                complete
                                            </span>
                                            <span className="text-muted-foreground">
                                                Shared progress
                                            </span>
                                        </div>
                                        <div
                                            role="progressbar"
                                            aria-label={`${page.title}: shared milestones`}
                                            aria-valuenow={done}
                                            aria-valuemin={0}
                                            aria-valuemax={total}
                                            className="h-2 overflow-hidden rounded-full bg-primary/10"
                                        >
                                            <div
                                                className="h-full rounded-full bg-primary"
                                                style={{
                                                    width: `${Math.min(100, Math.max(0, (done / total) * 100))}%`,
                                                }}
                                            />
                                        </div>
                                    </div>
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        Open this campaign to see how you can
                                        take part.
                                    </p>
                                )}
                                {(page.owner || progress?.ends_on) && (
                                    <div className="flex flex-wrap justify-between gap-2 border-t pt-3 text-xs text-muted-foreground">
                                        <span>{page.owner}</span>
                                        {progress?.ends_on && (
                                            <span>
                                                Target{' '}
                                                {formatDate(progress.ends_on)}
                                            </span>
                                        )}
                                    </div>
                                )}
                            </div>
                        </article>
                    );
                })}
            </div>
            {!campaigns.length && (
                <p className="hub-empty-state">
                    No published campaigns match this selection yet.
                </p>
            )}
            <Button asChild variant="link" className="h-auto p-0">
                <Link
                    href={`${data.demo ? '/company-hub/showcase' : '/company-hub'}?view=campaigns`}
                >
                    Explore campaigns
                    <ArrowUpRight className="size-4" />
                </Link>
            </Button>
        </div>
    );
}
