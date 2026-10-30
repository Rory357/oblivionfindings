import { formatDate } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, Newspaper } from 'lucide-react';
import type { HubPageSummary } from './_types';

export function FeaturedTiles({
    feature,
    layout = 'mosaic',
    demo = false,
    minHeight,
}: {
    feature?: { lead: HubPageSummary | null; supporting: HubPageSummary[] };
    layout?: 'mosaic' | 'equal' | 'single';
    demo?: boolean;
    minHeight?: number;
}) {
    const lead = feature?.lead;
    if (!lead)
        return (
            <div className="hub-featured-empty">
                <Newspaper className="size-7 text-primary" />
                <p>Publish a news story to feature it here.</p>
            </div>
        );
    const destination = (page: HubPageSummary) =>
        demo
            ? `/company-hub/showcase?story=${page.slug}`
            : `/company-hub/read/${page.slug}`;
    const supporting =
        layout === 'single' ? [] : feature.supporting.slice(0, 2);
    return (
        <div
            className="hub-authored-feature"
            style={{ minHeight: minHeight || 300 }}
            data-layout={layout}
            data-has-supporting={!!supporting.length}
        >
            <Link
                className="hub-feature-tile hub-authored-lead"
                href={destination(lead)}
            >
                {lead.cover_url && (
                    <img
                        className="hub-tile-image"
                        src={lead.cover_url}
                        alt=""
                    />
                )}
                <span className="hub-tile-shade" />
                <div className="hub-tile-content">
                    <span className="hub-tile-label">
                        {lead.category} · Featured story
                    </span>
                    <h2>{lead.title}</h2>
                    <p>{lead.summary}</p>
                    <span className="hub-tile-footer">
                        <span>
                            {lead.owner ? `${lead.owner} · ` : ''}
                            {formatDate(lead.published_at)}
                        </span>
                        <span className="inline-flex items-center gap-2">
                            Read story <ArrowUpRight className="size-4" />
                        </span>
                    </span>
                </div>
            </Link>
            {!!supporting.length && (
                <div
                    className="hub-feature-secondary"
                    style={{
                        gridTemplateRows: `repeat(${supporting.length}, minmax(0, 1fr))`,
                    }}
                >
                    {supporting.map((page) => (
                        <Link
                            key={page.id}
                            className="hub-feature-tile hub-authored-support"
                            href={destination(page)}
                        >
                            {page.cover_url && (
                                <img
                                    className="hub-tile-image"
                                    src={page.cover_url}
                                    alt=""
                                    loading="lazy"
                                />
                            )}
                            <span className="hub-tile-shade" />
                            <div className="hub-tile-content">
                                <span className="hub-tile-label">
                                    {page.category}
                                </span>
                                <h3>{page.title}</h3>
                                <span className="hub-tile-footer">
                                    {formatDate(page.published_at)}
                                    <ArrowUpRight className="size-4" />
                                </span>
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}
