// Careers inside the public website (mockup v2, approved by Stephan 23 Oct
// 2026): open roles, why work with us, and how applying works.
import {
    applyUrl,
    employmentType,
    hiringSteps,
    JobFacts,
    jobUrl,
    type JobListing,
} from '@/components/careers/careers-parts';
import { demoPhotos } from '@/components/public-site/content';
import {
    Lens,
    PageIntro,
    Pathway,
    SectionHeading,
    SitePhoto,
} from '@/components/public-site/primitives';
import { siteLinks, usePublicSite } from '@/components/public-site/site';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import PublicSiteLayout from '@/layouts/marketing-layout';
import { Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    Briefcase,
    CalendarClock,
    GraduationCap,
    PhoneCall,
    Search,
    Users,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';

type Filters = {
    search: string | null;
    position_role: string | null;
    employment_type: string | null;
    site: number | null;
};

type Props = {
    jobs: JobListing[];
    options: {
        position_roles: string[];
        employment_types: string[];
        sites: { id: number; name: string }[];
    };
    filters: Filters;
};

/** Filters only show once there are enough roles to need them. */
const FILTER_FROM = 7;
const ALL = 'all';

const reasons = [
    [
        GraduationCap,
        'Paid training',
        'A paid induction, shadow shifts and support to gain your Health and Wellbeing certificates.',
    ],
    [
        CalendarClock,
        'Rosters you can plan around',
        'Rosters published ahead, with shifts that work around study and whānau.',
    ],
    [
        PhoneCall,
        'Never on your own',
        'An on-call team leader, day and night, whenever you need a hand.',
    ],
    [
        Users,
        'A team that stays',
        'Small, steady teams, so the people we support see familiar faces.',
    ],
] as const;

const roleLabel = (role: string) => {
    const label = role.replace(/_/g, ' ');
    return label.charAt(0).toUpperCase() + label.slice(1);
};

function RoleFilters({
    options,
    filters,
}: {
    options: Props['options'];
    filters: Filters;
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const apply = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            siteLinks.careers,
            {
                ...(merged.search ? { search: merged.search } : {}),
                ...(merged.position_role
                    ? { position_role: merged.position_role }
                    : {}),
                ...(merged.employment_type
                    ? { employment_type: merged.employment_type }
                    : {}),
                ...(merged.site ? { site: merged.site } : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };
    const onSearch = (event: FormEvent) => {
        event.preventDefault();
        apply({ search: search.trim() || null });
    };

    return (
        <form
            role="search"
            aria-label="Filter open roles"
            onSubmit={onSearch}
            className="grid gap-4 rounded-3xl border border-border bg-card p-5 sm:grid-cols-2 lg:grid-cols-4"
        >
            <div className="space-y-1.5">
                <Label htmlFor="careers-search">Search roles</Label>
                <div className="relative">
                    <Search
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden
                    />
                    <Input
                        id="careers-search"
                        type="search"
                        className="frontline-tap pl-9"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Role title"
                    />
                </div>
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="careers-role">Role</Label>
                <Select
                    value={filters.position_role ?? ALL}
                    onValueChange={(value) =>
                        apply({ position_role: value === ALL ? null : value })
                    }
                >
                    <SelectTrigger
                        id="careers-role"
                        className="frontline-tap w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All roles</SelectItem>
                        {options.position_roles.map((role) => (
                            <SelectItem key={role} value={role}>
                                {roleLabel(role)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="careers-site">Where</Label>
                <Select
                    value={filters.site ? String(filters.site) : ALL}
                    onValueChange={(value) =>
                        apply({ site: value === ALL ? null : Number(value) })
                    }
                >
                    <SelectTrigger
                        id="careers-site"
                        className="frontline-tap w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>Everywhere</SelectItem>
                        {options.sites.map((site) => (
                            <SelectItem key={site.id} value={String(site.id)}>
                                {site.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="careers-type">Type</Label>
                <Select
                    value={filters.employment_type ?? ALL}
                    onValueChange={(value) =>
                        apply({
                            employment_type: value === ALL ? null : value,
                        })
                    }
                >
                    <SelectTrigger
                        id="careers-type"
                        className="frontline-tap w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All types</SelectItem>
                        {options.employment_types.map((type) => (
                            <SelectItem key={type} value={type}>
                                {employmentType(type)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
        </form>
    );
}

export default function CareersIndex({ jobs, options, filters }: Props) {
    const { name, demo, provider } = usePublicSite();
    const phone = provider.phone?.trim();
    const filtered = Boolean(
        filters.search ||
        filters.position_role ||
        filters.employment_type ||
        filters.site,
    );

    return (
        <PublicSiteLayout
            title="Careers"
            description="Join a local team that supports people to live the lives they choose. See our open roles and apply online."
            current="careers"
            intro={
                <PageIntro
                    eyebrow="Careers"
                    title="Work that makes a real difference"
                    lede={`Join a local team that supports people to live the lives they choose. ${
                        name
                            ? `${name} trains you, backs you and gives you`
                            : 'We train you, back you and give you'
                    } a team that’s always a call away.`}
                    media={
                        <SitePhoto
                            photo={demo ? demoPhotos.careers : null}
                            icon={Users}
                            className="aspect-[4/3] shadow-2xl"
                            eager
                        />
                    }
                >
                    <Button asChild size="lg" className="frontline-tap">
                        <a href="#open-roles">
                            See open roles
                            <ArrowRight className="size-4" aria-hidden />
                        </a>
                    </Button>
                </PageIntro>
            }
        >
            <section
                id="open-roles"
                aria-labelledby="roles-heading"
                className="journey-site-container journey-site-anchor space-y-8 py-16"
            >
                <SectionHeading
                    id="roles-heading"
                    kicker="Join the team"
                    title="Open roles"
                >
                    No experience? Many of our best support workers started with
                    us. We’ll train you.
                </SectionHeading>
                {(filtered || jobs.length >= FILTER_FROM) && (
                    <RoleFilters options={options} filters={filters} />
                )}
                {jobs.length === 0 ? (
                    <div className="rounded-3xl border border-border bg-card">
                        {filtered ? (
                            <EmptyState
                                icon={Search}
                                title="No roles match those filters"
                                description="Try a different search, or see every open role."
                                action={
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="frontline-tap"
                                    >
                                        <Link href={siteLinks.careers}>
                                            See every open role
                                        </Link>
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={Briefcase}
                                title="No open roles right now"
                                description="New roles appear here first. You can also send us a careers question."
                                action={
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="frontline-tap"
                                    >
                                        <Link href={siteLinks.contact}>
                                            Send us a careers question
                                        </Link>
                                    </Button>
                                }
                            />
                        )}
                    </div>
                ) : (
                    <ul className="grid gap-4">
                        {jobs.map((job) => (
                            <li key={job.id}>
                                <article
                                    aria-labelledby={`job-${job.slug}`}
                                    className="grid gap-5 rounded-3xl border border-border bg-card p-6 md:grid-cols-[1fr_auto] md:items-center md:p-8"
                                >
                                    <div className="space-y-3">
                                        <h3
                                            id={`job-${job.slug}`}
                                            className="text-section-title"
                                        >
                                            <Link
                                                href={jobUrl(job.slug)}
                                                className="hover:underline"
                                            >
                                                {job.title}
                                                {job.site && (
                                                    <span className="font-normal text-muted-foreground">
                                                        {' '}
                                                        · {job.site.name}
                                                    </span>
                                                )}
                                            </Link>
                                        </h3>
                                        {job.summary && (
                                            <p className="text-muted-foreground">
                                                {job.summary}
                                            </p>
                                        )}
                                        <div className="text-muted-foreground">
                                            <JobFacts job={job} />
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap gap-2 md:flex-col">
                                        <Button
                                            asChild
                                            className="frontline-tap"
                                        >
                                            <Link href={applyUrl(job.slug)}>
                                                Apply
                                                <span className="sr-only">
                                                    {' '}
                                                    for {job.title}
                                                </span>
                                            </Link>
                                        </Button>
                                        <Button
                                            asChild
                                            variant="outline"
                                            className="frontline-tap"
                                        >
                                            <Link href={jobUrl(job.slug)}>
                                                Read more
                                                <span className="sr-only">
                                                    {' '}
                                                    about {job.title}
                                                </span>
                                            </Link>
                                        </Button>
                                    </div>
                                </article>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section
                aria-labelledby="why-heading"
                className="journey-site-lilac"
            >
                <div className="journey-site-container space-y-8 py-16">
                    <SectionHeading
                        id="why-heading"
                        kicker="Why work with us"
                        title="We look after the people who look after people"
                    />
                    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {reasons.map(([icon, title, body]) => (
                            <li
                                key={title}
                                className="flex flex-col gap-3 rounded-3xl border border-border bg-card p-6"
                            >
                                <Lens icon={icon} />
                                <p className="font-semibold">{title}</p>
                                <p className="text-sm text-muted-foreground">
                                    {body}
                                </p>
                            </li>
                        ))}
                    </ul>
                </div>
            </section>

            <section
                aria-labelledby="hiring-heading"
                className="journey-site-container space-y-10 py-16"
            >
                <SectionHeading
                    id="hiring-heading"
                    kicker="How applying works"
                    title="From application to your first shift"
                />
                <Pathway steps={hiringSteps} />
                <p className="text-sm text-muted-foreground">
                    Questions about a role?{' '}
                    <Link
                        href={siteLinks.contact}
                        className="font-semibold text-primary hover:underline"
                    >
                        Send us a careers question
                    </Link>
                    {phone ? ` or call ${phone}.` : '.'}
                </p>
            </section>
        </PublicSiteLayout>
    );
}
