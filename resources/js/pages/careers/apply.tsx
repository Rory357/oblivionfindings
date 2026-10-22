// The public job page inside the website shell (mockup v2, approved by
// Stephan 23 Oct 2026). /careers/jobs/{slug} shows the role;
// /careers/jobs/{slug}/apply shows it with the apply wizard already open.
import { ApplyWizard } from '@/components/careers/apply-wizard';
import {
    employmentType,
    formatDate,
    JobFacts,
    jobHeading,
    paragraphs,
    textLines,
    type PublicJob,
} from '@/components/careers/careers-parts';
import { PageIntro } from '@/components/public-site/primitives';
import { siteLinks, usePublicSite } from '@/components/public-site/site';
import { Button } from '@/components/ui/button';
import PublicSiteLayout from '@/layouts/marketing-layout';
import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Briefcase,
    CalendarClock,
    MapPin,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';

type Props = {
    job: PublicJob;
    /** The apply route opens the wizard straight away. */
    openApply?: boolean;
    trackingDefaults?: { source_channel: string };
};

function BulletList({ items }: { items: string[] }) {
    return (
        <ul className="list-disc space-y-2 pl-5 text-muted-foreground marker:text-primary">
            {items.map((item, index) => (
                <li key={`${index}-${item}`}>{item}</li>
            ))}
        </ul>
    );
}

export default function CareersJob({
    job,
    openApply = false,
    trackingDefaults,
}: Props) {
    const { name } = usePublicSite();
    const [open, setOpen] = useState(openApply);
    const about = paragraphs(job.description);
    const duties = textLines(job.responsibilities);
    const needs = textLines(job.requirements);
    const glance: [LucideIcon, string, string][] = [
        ...(job.site
            ? [[MapPin, 'Where', job.site.name] as [LucideIcon, string, string]]
            : []),
        [Briefcase, 'Type', employmentType(job.employment_type)],
        ...(job.pay
            ? [[Wallet, 'Pay', job.pay] as [LucideIcon, string, string]]
            : []),
        [
            CalendarClock,
            'Applications close',
            formatDate(job.closing_at) ?? 'When the role is filled',
        ],
    ];

    return (
        <PublicSiteLayout
            title={job.title}
            description={job.summary ?? `Apply for the ${job.title} role.`}
            current="careers"
            intro={
                <PageIntro
                    eyebrow="Careers"
                    title={jobHeading(job)}
                    lede={job.summary ?? undefined}
                    back={{ href: siteLinks.careers, label: 'All open roles' }}
                >
                    <div className="text-journey-ink-muted">
                        <JobFacts job={job} />
                    </div>
                    <Button
                        size="lg"
                        className="frontline-tap"
                        onClick={() => setOpen(true)}
                    >
                        Apply for this role
                        <ArrowRight className="size-4" aria-hidden />
                    </Button>
                </PageIntro>
            }
        >
            <div className="journey-site-container grid items-start gap-6 py-12 lg:grid-cols-[1.6fr_1fr]">
                <article className="space-y-8 rounded-3xl border border-border bg-card p-6 md:p-10">
                    {about.length > 0 && (
                        <section className="space-y-3">
                            <h2 className="text-section-title">
                                About the role
                            </h2>
                            {about.map((paragraph, index) => (
                                <p
                                    key={index}
                                    className="leading-relaxed whitespace-pre-line text-muted-foreground"
                                >
                                    {paragraph}
                                </p>
                            ))}
                        </section>
                    )}
                    {duties.length > 0 && (
                        <section className="space-y-3">
                            <h2 className="text-section-title">
                                What you’ll do
                            </h2>
                            <BulletList items={duties} />
                        </section>
                    )}
                    {needs.length > 0 && (
                        <section className="space-y-3">
                            <h2 className="text-section-title">
                                What you’ll bring
                            </h2>
                            <BulletList items={needs} />
                        </section>
                    )}
                    <section className="space-y-3 rounded-2xl bg-muted p-5">
                        <h2 className="font-semibold">
                            Checks before you start
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            If we offer you the role, we’ll check two references
                            and ask for your consent to a New Zealand Police vet
                            before your first shift. Having a conviction doesn’t
                            automatically rule you out: we look at each one
                            fairly and in confidence.
                        </p>
                    </section>
                </article>
                <aside className="space-y-4 rounded-3xl border border-border bg-card p-6">
                    <p className="font-semibold">At a glance</p>
                    <dl className="space-y-3 text-sm">
                        {glance.map(([Icon, term, value]) => (
                            <div key={term} className="flex gap-3">
                                <Icon
                                    className="mt-0.5 size-4 shrink-0 text-primary"
                                    aria-hidden
                                />
                                <div>
                                    <dt className="text-muted-foreground">
                                        {term}
                                    </dt>
                                    <dd className="font-medium">{value}</dd>
                                </div>
                            </div>
                        ))}
                    </dl>
                    <Button
                        className="frontline-tap w-full"
                        onClick={() => setOpen(true)}
                    >
                        Apply for this role
                    </Button>
                    <p className="text-sm text-muted-foreground">
                        Questions?{' '}
                        <Link
                            href={siteLinks.contact}
                            className="font-semibold text-primary hover:underline"
                        >
                            Send us a careers question
                        </Link>
                        .
                    </p>
                </aside>
            </div>

            <ApplyWizard
                job={job}
                open={open}
                onClose={() => setOpen(false)}
                siteName={name}
                sourceChannel={trackingDefaults?.source_channel}
            />
        </PublicSiteLayout>
    );
}
