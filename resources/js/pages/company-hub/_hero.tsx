import { PageHeader } from '@/components/page/page-header';
import { AppreciationIcon } from '@/components/recognition/appreciation-icon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUp,
    ArrowUpRight,
    BookOpen,
    Search,
    UsersRound,
} from 'lucide-react';
import {
    useEffect,
    useRef,
    useState,
    type CSSProperties,
    type FormEvent,
    type ReactNode,
    type Ref,
} from 'react';
import { heroIcons } from './_hero-icons';
import { defaultHero } from './_hero-settings';
import type { HubContent, HubHeroSummary, HubPageSummary } from './_types';

export function HubSiteHeader({
    content,
    actions,
    search,
    onSearch,
    onSubmit,
    children,
    onEditHero,
    editHeroRef,
    compactNavigation = true,
    summary = [],
}: {
    content?: HubContent;
    actions?: ReactNode;
    search: string;
    onSearch: (value: string) => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    children: ReactNode;
    onEditHero?: () => void;
    editHeroRef?: Ref<HTMLButtonElement>;
    compactNavigation?: boolean;
    summary?: HubHeroSummary;
}) {
    const hero = { ...defaultHero, ...content?.hero };
    const image = hero.style === 'image' && content?.cover_id;
    const banner = useRef<HTMLDivElement>(null);
    const [compact, setCompact] = useState(false);
    useEffect(() => {
        if (
            !compactNavigation ||
            !banner.current ||
            typeof IntersectionObserver === 'undefined'
        )
            return;
        const observer = new IntersectionObserver(
            ([entry]) => {
                setCompact(
                    !entry.isIntersecting &&
                        entry.boundingClientRect.bottom < 59,
                );
            },
            { rootMargin: '-58px 0px 0px 0px', threshold: 0 },
        );
        observer.observe(banner.current);
        return () => observer.disconnect();
    }, [compactNavigation]);
    return (
        <>
            <div
                ref={banner}
                className="hub-banner"
                data-height={hero.height}
                data-photo={!!image}
                data-alignment={hero.alignment}
                data-summary={!!hero.show_metrics && summary.length > 0}
                style={
                    {
                        '--hub-shade': hero.overlay / 100,
                        '--hub-text-shade': Math.max(
                            0,
                            (0.72 - hero.overlay / 100) /
                                (1 - hero.overlay / 100),
                        ),
                        '--hub-heading-gutter': hero.show_search
                            ? '260px'
                            : onEditHero
                              ? '110px'
                              : '0px',
                    } as CSSProperties
                }
            >
                {image && (
                    <img
                        className="hub-banner-photo"
                        src={`/company-hub/media/${image}`}
                        alt=""
                        style={{
                            objectPosition: `${hero.focal_x}% ${hero.focal_y}%`,
                        }}
                    />
                )}
                <PageHeader
                    className="hub-compact-header"
                    icon={heroIcons[hero.icon ?? 'heart-handshake']}
                    title={content?.title || 'Company Hub'}
                    wrapTitle
                    subline={
                        hero.show_summary
                            ? content?.summary ||
                              'Our people. Our stories. Our shared space.'
                            : undefined
                    }
                    actions={
                        <>
                            {hero.show_search && (
                                <form
                                    role="search"
                                    className="hub-banner-search"
                                    onSubmit={onSubmit}
                                >
                                    <Search
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    <Input
                                        aria-label="Search Company Hub"
                                        placeholder="Search the hub…"
                                        value={search}
                                        onChange={(e) =>
                                            onSearch(e.target.value)
                                        }
                                    />
                                    <Button
                                        type="submit"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Search"
                                    >
                                        <ArrowRight className="size-4" />
                                    </Button>
                                </form>
                            )}
                            {onEditHero && (
                                <Button
                                    className="hub-glass-button"
                                    variant="ghost"
                                    onClick={onEditHero}
                                    ref={editHeroRef}
                                >
                                    Edit hero
                                </Button>
                            )}
                        </>
                    }
                    expandedContent={
                        <div className="hub-banner-action">
                            {hero.show_metrics && summary.length > 0 && (
                                <nav
                                    className="hub-banner-summary"
                                    aria-label="Hub at a glance"
                                >
                                    {summary.map((item) => (
                                        <Link
                                            key={item.url}
                                            href={item.url}
                                            aria-label={`${item.value} ${item.label}`}
                                        >
                                            <strong>{item.value}</strong>
                                            <span>{item.label}</span>
                                        </Link>
                                    ))}
                                </nav>
                            )}
                            {hero.action_label && hero.action_url && (
                                <a
                                    className="hub-glass-button"
                                    href={hero.action_url}
                                    rel="noopener noreferrer"
                                    target={
                                        hero.action_url.startsWith('https:')
                                            ? '_blank'
                                            : undefined
                                    }
                                >
                                    <ArrowRight className="size-4" />
                                    {hero.action_label}
                                </a>
                            )}
                        </div>
                    }
                    rail={children}
                />
            </div>
            <div className="hub-scroll-navigation">
                {compact && (
                    <div className="eh-header hub-compact-navigation text-band-foreground">
                        <Button
                            size="icon"
                            variant="ghost"
                            aria-label="Back to Company Hub header"
                            onClick={() =>
                                banner.current?.scrollIntoView({
                                    behavior: window.matchMedia(
                                        '(prefers-reduced-motion: reduce)',
                                    ).matches
                                        ? 'instant'
                                        : 'smooth',
                                    block: 'start',
                                })
                            }
                        >
                            <ArrowUp className="size-4" />
                        </Button>
                        {children}
                    </div>
                )}
            </div>
            {actions && <div className="hub-command-bar">{actions}</div>}
        </>
    );
}

export function HubHero({
    content,
    featured,
    pages = [],
    actions,
    recognise,
    baseUrl = '/company-hub',
    demo = false,
}: {
    content: HubContent;
    featured?: HubPageSummary;
    pages?: HubPageSummary[];
    actions?: ReactNode;
    recognise?: () => void;
    baseUrl?: string;
    demo?: boolean;
}) {
    const storyUrl = (page: HubPageSummary) =>
        demo
            ? `${baseUrl}?story=${page.slug}`
            : `/company-hub/read/${page.slug}`;
    const others = pages
        .filter(
            (page) =>
                page.id !== featured?.id &&
                page.kind === 'news' &&
                page.show_in_news !== false,
        )
        .slice(0, 2);
    const links = content.sections
        .flatMap((section) => section.blocks)
        .filter((block) => block.type === 'links')
        .flatMap((block) => block.links ?? [])
        .slice(0, 2);
    const fallbackLinks = [
        {
            id: 'people',
            title: 'Find your people',
            description: 'Connect with colleagues across the company',
            url: '/company-hub?view=people',
        },
        {
            id: 'resources',
            title: 'Your everyday essentials',
            description: 'Guides, information and tools in one place',
            url: `${baseUrl}?view=resources`,
        },
    ];
    const imageUrl =
        featured?.cover_url ??
        (content.cover_id
            ? `/company-hub/media/${content.cover_id}`
            : featured
              ? null
              : '/images/company-hub/workplace-welcome.png');
    const lead = (
        <>
            {imageUrl && (
                <img src={imageUrl} alt="" className="hub-tile-image" />
            )}
            {imageUrl && <span className="hub-tile-shade" />}
            <div className="hub-tile-content">
                <span className="hub-tile-label">
                    {featured?.category ?? content.hero_label}
                </span>
                <h1>{featured?.title ?? content.title}</h1>
                <p>{featured?.summary ?? content.summary}</p>
                <span className="hub-tile-footer">
                    {featured ? (
                        <>
                            {formatDate(featured.published_at)}
                            <span className="flex items-center gap-2">
                                Read story <ArrowUpRight className="size-4" />
                            </span>
                        </>
                    ) : (
                        <span>Stay informed. Stay connected.</span>
                    )}
                </span>
            </div>
        </>
    );
    return (
        <section
            aria-label="Featured on the hub"
            className="hub-featured-section"
        >
            {(actions || recognise) && (
                <div className="mb-4 flex items-center justify-end gap-2">
                    {actions}
                    {recognise && (
                        <Button variant="outline" onClick={recognise}>
                            <AppreciationIcon className="size-4" />
                            Give a shout-out
                        </Button>
                    )}
                </div>
            )}
            <div className="hub-featured-grid">
                {featured ? (
                    <Link
                        className="hub-feature-tile hub-feature-lead"
                        href={storyUrl(featured)}
                    >
                        {lead}
                    </Link>
                ) : (
                    <div className="hub-feature-tile hub-feature-lead hub-feature-welcome">
                        {lead}
                    </div>
                )}
                <div className="hub-feature-secondary">
                    {[0, 1].map((index) => {
                        const story = others[index];
                        const link = links[index] ?? fallbackLinks[index];
                        const Icon = index === 0 ? UsersRound : BookOpen;
                        return story ? (
                            <Link
                                key={story.id}
                                href={storyUrl(story)}
                                className="hub-feature-tile hub-feature-small"
                            >
                                {story.cover_url && (
                                    <img
                                        src={story.cover_url}
                                        alt=""
                                        className="hub-tile-image"
                                    />
                                )}
                                <span className="hub-tile-shade" />
                                <div className="hub-tile-content">
                                    <span className="hub-tile-label">
                                        {story.category}
                                    </span>
                                    <h2>{story.title}</h2>
                                    <ArrowUpRight className="hub-tile-arrow size-5" />
                                </div>
                            </Link>
                        ) : (
                            <a
                                key={link.id}
                                href={link.url}
                                className="hub-feature-tile hub-feature-link"
                                target={
                                    link.url.startsWith('https:')
                                        ? '_blank'
                                        : undefined
                                }
                                rel="noopener noreferrer"
                            >
                                <Icon className="size-6" />
                                <div>
                                    <h2>{link.title}</h2>
                                    <p>{link.description}</p>
                                </div>
                                <ArrowUpRight className="size-5 shrink-0" />
                            </a>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

export function HubArticleHeader({
    content,
    publishedAt,
}: {
    content: HubContent;
    publishedAt?: string;
}) {
    return (
        <header className="hub-article-header">
            <p className="text-xs font-semibold tracking-wider text-primary uppercase">
                {content.category}
                {publishedAt && ` · ${formatDate(publishedAt)}`}
            </p>
            {content.cover_id && content.hero?.style !== 'image' && (
                <img
                    className="mt-5 max-h-80 w-full rounded-lg object-cover"
                    src={`/company-hub/media/${content.cover_id}`}
                    alt=""
                    style={{
                        objectPosition: `${content.hero?.focal_x ?? 50}% ${content.hero?.focal_y ?? 50}%`,
                    }}
                />
            )}
        </header>
    );
}
