import MarketingLayout from '@/layouts/marketing-layout';
import { Link } from '@inertiajs/react';
import { ArrowRight, Heart, Shield, Target, Users } from 'lucide-react';
import React from 'react';

const About: React.FC = () => {
    const values = [
        {
            icon: Heart,
            title: 'People First',
            description:
                'We believe technology should enhance human care, not replace it. Every feature we build starts with understanding the needs of residents, families, and care teams.',
        },
        {
            icon: Shield,
            title: 'Trust & Security',
            description:
                'We handle sensitive data, so we take security seriously. Bank-grade encryption, strict access controls, and full GDPR compliance come standard.',
        },
        {
            icon: Target,
            title: 'Simplicity',
            description:
                'Care is complex enough. Our software shouldnt be. We obsess over making every interaction intuitive, so your team can focus on what matters.',
        },
        {
            icon: Users,
            title: 'Partnership',
            description:
                "We're looking for early partners to help shape our platform. Work directly with the founders and influence the future of the product.",
        },
    ];

    const milestones = [
        {
            year: '2025',
            title: 'The Spark',
            description:
                'The idea for Oblivion Findings was born—recognising the need for modern, intuitive software in the supported living sector.',
        },
        {
            year: '2026',
            title: 'Building Begins',
            description:
                'Started developing the platform from the ground up, working closely with industry experts to ensure it meets real-world needs.',
        },
        {
            year: '2027',
            title: 'The Future',
            description:
                'Launching soon—bringing innovative tools to empower supported living providers across New Zealand and beyond.',
        },
    ];

    return (
        <MarketingLayout
            title="About"
            description="Learn about Oblivion Findings - our mission, values, and the team behind the supported living platform."
        >
            {/* Hero */}
            <section className="text-center">
                <h1 className="text-4xl font-bold tracking-tight text-foreground sm:text-5xl">
                    Building the future of{' '}
                    <span className="text-primary">supported living</span>
                </h1>
                <p className="mx-auto mt-6 max-w-3xl text-lg text-muted-foreground">
                    We're two IT professionals with a shared vision—to build
                    modern, intuitive software that helps supported living teams
                    spend less time on admin and more time delivering
                    exceptional care.
                </p>
            </section>

            {/* Mission */}
            <section className="mt-16">
                <div className="rounded-3xl border border-border bg-gradient-to-br from-primary/5 to-transparent p-8 sm:p-12">
                    <div className="mx-auto max-w-3xl text-center">
                        <h2 className="text-2xl font-bold text-foreground">
                            Our Mission
                        </h2>
                        <p className="mt-4 text-lg text-muted-foreground">
                            To empower supported living providers with
                            technology that simplifies operations, ensures
                            compliance, and ultimately enables better outcomes
                            for the people they support.
                        </p>
                        <p className="mt-4 text-lg text-muted-foreground">
                            We believe that when care teams have the right
                            tools, they can focus on what they do best:
                            supporting people to live fulfilling, independent
                            lives.
                        </p>
                        <p className="mt-6 text-lg font-medium text-foreground italic">
                            "Every person has a story worth telling. We're here
                            to help you capture those moments, preserve precious
                            memories, and create a legacy that lasts forever."
                        </p>
                    </div>
                </div>
            </section>

            {/* Values */}
            <section className="mt-24">
                <h2 className="text-center text-2xl font-bold text-foreground">
                    Our Values
                </h2>
                <p className="mx-auto mt-4 max-w-2xl text-center text-muted-foreground">
                    The principles that guide everything we do
                </p>

                <div className="mt-10 grid gap-6 md:grid-cols-2">
                    {values.map((value, index) => {
                        const Icon = value.icon;
                        return (
                            <div
                                key={index}
                                className="group relative overflow-hidden rounded-2xl border border-border bg-card p-8 transition-all hover:border-primary/20 hover:shadow-lg hover:shadow-primary/5"
                            >
                                {/* Gloss overlay */}
                                <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/40 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                                <div className="pointer-events-none absolute -inset-full top-0 block h-full w-1/2 -skew-x-12 bg-gradient-to-r from-transparent to-white/20 opacity-0 transition-all duration-700 group-hover:animate-shine" />
                                <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-primary/20 to-primary/5 text-primary shadow-inner shadow-primary/10">
                                    <Icon size={28} />
                                </div>
                                <h3 className="mt-6 text-xl font-semibold text-foreground">
                                    {value.title}
                                </h3>
                                <p className="mt-3 leading-relaxed text-muted-foreground">
                                    {value.description}
                                </p>
                            </div>
                        );
                    })}
                </div>
            </section>

            {/* Story / Timeline */}
            <section className="mt-24">
                <h2 className="text-center text-2xl font-bold text-foreground">
                    Our Journey
                </h2>
                <p className="mx-auto mt-4 max-w-2xl text-center text-muted-foreground">
                    From a spark of an idea to building the future of supported
                    living software
                </p>

                <div className="relative mt-10">
                    <div className="absolute top-0 bottom-0 left-4 w-px bg-border md:left-1/2" />

                    <div className="space-y-12">
                        {milestones.map((milestone, index) => {
                            const isLeft = index % 2 === 0;
                            return (
                                <div
                                    key={index}
                                    className="relative md:flex md:items-center"
                                >
                                    <div
                                        className={`md:w-1/2 ${isLeft ? 'md:pr-12 md:text-right' : 'md:order-2 md:pl-12'}`}
                                    >
                                        <div
                                            className={`group relative overflow-hidden rounded-2xl border border-border bg-card p-6 transition-all hover:border-primary/20 hover:shadow-lg hover:shadow-primary/5 ${isLeft ? '' : ''}`}
                                        >
                                            {/* Gloss overlay */}
                                            <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/40 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                                            <div className="pointer-events-none absolute -inset-full top-0 block h-full w-1/2 -skew-x-12 bg-gradient-to-r from-transparent to-white/20 opacity-0 transition-all duration-700 group-hover:animate-shine" />
                                            <span className="relative text-sm font-bold text-primary">
                                                {milestone.year}
                                            </span>
                                            <h3 className="relative mt-1 text-lg font-semibold text-foreground">
                                                {milestone.title}
                                            </h3>
                                            <p className="relative mt-2 text-sm text-muted-foreground">
                                                {milestone.description}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="absolute top-6 left-4 flex h-4 w-4 items-center justify-center rounded-full bg-primary ring-4 ring-background md:left-1/2 md:-translate-x-1/2" />
                                    <div
                                        className={`hidden md:block md:w-1/2 ${isLeft ? 'md:order-2' : ''}`}
                                    />
                                </div>
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* Team Section */}
            <section className="mt-24">
                <div className="rounded-3xl border border-border bg-card p-8 sm:p-12">
                    <div className="grid items-center gap-10 lg:grid-cols-2">
                        <div>
                            <h2 className="text-2xl font-bold text-foreground">
                                Built by two IT professionals
                            </h2>
                            <p className="mt-4 text-muted-foreground">
                                We're a small team with big ambitions. With
                                backgrounds in software engineering and IT,
                                we're building something we believe the industry
                                desperately needs—modern, intuitive software
                                that actually works for the people using it.
                            </p>
                            <p className="mt-4 text-muted-foreground">
                                We're looking for early partners to help us
                                shape the future of supported living software.
                                Work directly with us, have a real voice in the
                                product, and let's build something great
                                together.
                            </p>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-muted/80 to-muted/40 p-6 text-center transition-all hover:from-muted/90 hover:to-muted/50">
                                <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/40 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                                <div className="pointer-events-none absolute -inset-full top-0 block h-full w-1/2 -skew-x-12 bg-gradient-to-r from-transparent to-white/30 opacity-0 transition-all duration-700 group-hover:animate-shine" />
                                <div className="relative text-3xl font-bold text-foreground">
                                    2
                                </div>
                                <div className="relative text-sm text-muted-foreground">
                                    Founders
                                </div>
                            </div>
                            <div className="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-muted/80 to-muted/40 p-6 text-center transition-all hover:from-muted/90 hover:to-muted/50">
                                <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/40 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                                <div className="pointer-events-none absolute -inset-full top-0 block h-full w-1/2 -skew-x-12 bg-gradient-to-r from-transparent to-white/30 opacity-0 transition-all duration-700 group-hover:animate-shine" />
                                <div className="relative text-3xl font-bold text-foreground">
                                    15+
                                </div>
                                <div className="relative text-sm text-muted-foreground">
                                    Years IT experience
                                </div>
                            </div>
                            <div className="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-muted/80 to-muted/40 p-6 text-center transition-all hover:from-muted/90 hover:to-muted/50">
                                <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/40 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                                <div className="pointer-events-none absolute -inset-full top-0 block h-full w-1/2 -skew-x-12 bg-gradient-to-r from-transparent to-white/30 opacity-0 transition-all duration-700 group-hover:animate-shine" />
                                <div className="relative text-3xl font-bold text-foreground">
                                    0
                                </div>
                                <div className="relative text-sm text-muted-foreground">
                                    Clients so far
                                </div>
                            </div>
                            <div className="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-muted/80 to-muted/40 p-6 text-center transition-all hover:from-muted/90 hover:to-muted/50">
                                <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/40 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                                <div className="pointer-events-none absolute -inset-full top-0 block h-full w-1/2 -skew-x-12 bg-gradient-to-r from-transparent to-white/30 opacity-0 transition-all duration-700 group-hover:animate-shine" />
                                <div className="relative text-3xl font-bold text-foreground">
                                    100%
                                </div>
                                <div className="relative text-sm text-muted-foreground">
                                    NZ based
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* CTA */}
            <section className="mt-24">
                <div className="rounded-3xl bg-gradient-to-r from-primary to-primary/90 px-6 py-12 sm:px-12 sm:py-16">
                    <div className="mx-auto max-w-3xl text-center">
                        <h2 className="text-2xl font-bold text-primary-foreground sm:text-3xl">
                            Join us on our mission
                        </h2>
                        <p className="mt-4 text-primary-foreground/80">
                            Whether you're looking for a new platform or just
                            want to learn more, we'd love to hear from you.
                        </p>
                        <div className="mt-8 flex flex-col items-center justify-center gap-4 sm:flex-row">
                            <Link
                                href="/contact"
                                className="inline-flex items-center justify-center gap-2 rounded-full bg-background px-8 py-4 text-base font-medium text-foreground shadow-lg transition-all hover:bg-background/90"
                            >
                                Get in touch
                                <ArrowRight size={18} />
                            </Link>
                            <Link
                                href="/features"
                                className="inline-flex items-center justify-center gap-2 rounded-full border border-primary-foreground/30 bg-primary-foreground/10 px-8 py-4 text-base font-medium text-primary-foreground transition-all hover:bg-primary-foreground/20"
                            >
                                Explore features
                            </Link>
                        </div>
                    </div>
                </div>
            </section>
        </MarketingLayout>
    );
};

export default About;
