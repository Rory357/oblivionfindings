// The page an applicant's emailed status link opens, inside the public
// website (mockup v2, approved by Stephan 23 Oct 2026). The token in the URL
// is the only key; this page shows no personal details.
import {
    formatDate,
    ProgressPathway,
} from '@/components/careers/careers-parts';
import { PageIntro } from '@/components/public-site/primitives';
import { siteLinks, usePublicSite } from '@/components/public-site/site';
import { Button } from '@/components/ui/button';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import PublicSiteLayout from '@/layouts/marketing-layout';
import type { ApplicationStatus } from '@/types/job-postings';
import { Link } from '@inertiajs/react';
import { HeartHandshake } from 'lucide-react';

type Props = {
    application: ApplicationStatus;
};

/** Where each Recruitment stage sits on the applicant's pathway. */
export function stageIndex(status: string): number {
    const map: Record<string, number> = {
        new: 0,
        active: 0,
        screening: 1,
        interview_scheduled: 2,
        interview_completed: 2,
        reference_check: 3,
        offer_pending: 4,
        offer_sent: 4,
        offered: 4,
        // Past the last step: every step is done.
        offer_accepted: 5,
        onboarding: 5,
        hired: 5,
    };
    return map[status] ?? 0;
}

const CLOSED = ['rejected', 'withdrawn'];

function badgeVariant(status: string): StatusVariant {
    if (CLOSED.includes(status)) return 'neutral';
    if (stageIndex(status) >= 5) return 'success';
    return 'info';
}

export default function ApplicationStatusPage({ application }: Props) {
    const { provider } = usePublicSite();
    const phone = provider.phone?.trim();
    const closed = CLOSED.includes(application.status);
    const location = application.posting?.location;
    const applied = formatDate(application.applied_at);
    const steps = [
        {
            title: 'Application received',
            body: applied
                ? `${applied}. Thanks for applying.`
                : 'Thanks for applying.',
        },
        {
            title: 'Under review',
            body: 'Our team reads every application.',
        },
        {
            title: 'Interview',
            body: 'If you’re shortlisted, we’ll call you to arrange a time.',
        },
        {
            title: 'Checks',
            body: 'References and a police vet, with your consent.',
        },
        {
            title: 'Decision',
            body: 'We’ll let you know either way.',
        },
    ];

    return (
        <PublicSiteLayout
            title="Your application"
            current="careers"
            intro={
                <PageIntro
                    eyebrow="Your application"
                    title={
                        location
                            ? `${application.position_title} · ${location}`
                            : application.position_title
                    }
                    lede="This page shows where your application is up to. It updates as things move along."
                />
            }
        >
            <div className="journey-site-container grid items-start gap-6 py-12 lg:grid-cols-[1.6fr_1fr]">
                <section
                    aria-labelledby="status-heading"
                    className="space-y-6 rounded-3xl border border-border bg-card p-6 md:p-8"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="status-heading" className="text-section-title">
                            Where it’s up to
                        </h2>
                        <StatusBadge variant={badgeVariant(application.status)}>
                            {application.status_label}
                        </StatusBadge>
                    </div>
                    {closed ? (
                        <p className="text-muted-foreground">
                            {application.status === 'rejected'
                                ? 'Thank you for your interest. Your application wasn’t successful this time. You’re welcome to apply for other roles.'
                                : 'This application has been withdrawn. You’re welcome to apply again for this or other roles.'}
                        </p>
                    ) : (
                        <ProgressPathway
                            steps={steps}
                            current={stageIndex(application.status)}
                        />
                    )}
                </section>
                <aside className="space-y-4 rounded-3xl border border-border bg-card p-6 text-sm">
                    <p className="flex items-start gap-2.5">
                        <HeartHandshake
                            className="mt-0.5 size-4 shrink-0 text-primary"
                            aria-hidden
                        />
                        This link is just for you. Please don’t share it.
                    </p>
                    <p className="text-muted-foreground">
                        Questions about your application?{' '}
                        {phone ? `Call ${phone} or ` : ''}
                        <Link
                            href={siteLinks.contact}
                            className="font-semibold text-primary hover:underline"
                        >
                            {phone ? 'send' : 'Send'} us a careers question
                        </Link>
                        .
                    </p>
                    <Button
                        asChild
                        variant="outline"
                        className="frontline-tap w-full"
                    >
                        <Link href={siteLinks.careers}>
                            See other open roles
                        </Link>
                    </Button>
                </aside>
            </div>
        </PublicSiteLayout>
    );
}
