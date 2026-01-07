import Footer from '@/Components/Footer';
import Header from '@/Components/Header';
import { Head } from '@inertiajs/react';
import React from 'react';

const Home: React.FC = () => {
    const year = new Date().getFullYear(); // still here if you need it, but Footer handles its own year

    return (
        <>
            <Head>
                <title>Oblivion Findings · Supported Living Platform</title>
                <meta
                    name="description"
                    content="Oblivion Findings helps supported living providers manage residents, visits, notes, and compliance in one simple, web-based dashboard."
                />
            </Head>

            <div className="min-h-screen bg-slate-950 text-white">
                {/* Gradient background */}
                <div className="pointer-events-none fixed inset-0 -z-10 bg-gradient-to-b from-slate-950 via-slate-900 to-slate-950" />

                {/* Page container */}
                <div className="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-10 lg:px-8">
                    {/* Top nav */}
                    <Header />

                    {/* Main content */}
                    <main className="mt-10 space-y-16 sm:mt-16 sm:space-y-20">
                        {/* Hero section */}
                        <section className="grid gap-10 lg:grid-cols-2 lg:items-center">
                            <div>
                                <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-slate-800 bg-slate-900/40 px-3 py-1 text-[11px] text-slate-300">
                                    <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />
                                    <span>
                                        Built for supported living teams
                                    </span>
                                </div>

                                <h1 className="text-3xl font-semibold tracking-tight text-white sm:text-4xl lg:text-5xl">
                                    All your supported living{' '}
                                    <span className="text-indigo-300">
                                        organised in one place.
                                    </span>
                                </h1>

                                <p className="mt-4 text-sm leading-relaxed text-slate-300 sm:text-base">
                                    Oblivion Findings gives providers a clear,
                                    up-to-date view of residents, visits, notes,
                                    and compliance. Think WebCare-style
                                    dashboards – but faster, simpler, and built
                                    for real-world supported living workflows.
                                </p>

                                <div className="mt-6 flex flex-wrap items-center gap-3">
                                    <button
                                        type="button"
                                        className="inline-flex items-center justify-center rounded-full bg-indigo-500 px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-indigo-400"
                                    >
                                        Book a live demo
                                    </button>

                                    <button
                                        type="button"
                                        className="inline-flex items-center justify-center rounded-full border border-slate-700 px-4 py-2 text-xs text-slate-200 hover:bg-slate-900 sm:text-sm"
                                    >
                                        Watch 3-minute overview
                                    </button>
                                </div>

                                <div className="mt-6 flex flex-wrap items-center gap-4 text-[11px] text-slate-400">
                                    <div className="flex items-center gap-2">
                                        <span className="flex h-5 w-5 items-center justify-center rounded-full bg-slate-800 text-[10px]">
                                            ✓
                                        </span>
                                        <span>
                                            NDIS / supported living ready
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="flex h-5 w-5 items-center justify-center rounded-full bg-slate-800 text-[10px]">
                                            ✓
                                        </span>
                                        <span>
                                            No long IT rollout – web based
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {/* Mock dashboard illustration */}
                            <div className="relative">
                                <div className="absolute -top-10 -left-10 h-40 w-40 rounded-full bg-indigo-500/20 blur-3xl" />
                                <div className="absolute -right-4 -bottom-8 h-40 w-40 rounded-full bg-emerald-500/10 blur-3xl" />

                                <div className="relative rounded-3xl border border-slate-700/60 bg-slate-900/70 p-4 shadow-[0_0_40px_rgba(15,23,42,0.8)] sm:p-6">
                                    <div className="mb-4 flex items-center justify-between">
                                        <div>
                                            <p className="text-xs text-slate-400">
                                                Today
                                            </p>
                                            <p className="text-sm font-medium text-white">
                                                Team dashboard
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-1">
                                            <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />
                                            <span className="h-1.5 w-1.5 rounded-full bg-amber-400" />
                                            <span className="h-1.5 w-1.5 rounded-full bg-rose-400" />
                                        </div>
                                    </div>

                                    {/* Mini stat grid */}
                                    <div className="mb-4 grid grid-cols-2 gap-3 text-xs">
                                        <div className="rounded-2xl border border-slate-700/70 bg-slate-900/80 p-3">
                                            <p className="text-slate-400">
                                                Active residents
                                            </p>
                                            <p className="mt-1 text-xl font-semibold text-white">
                                                32
                                            </p>
                                            <p className="mt-1 text-[10px] text-emerald-400">
                                                +3 this month
                                            </p>
                                        </div>
                                        <div className="rounded-2xl border border-slate-700/70 bg-slate-900/80 p-3">
                                            <p className="text-slate-400">
                                                Support visits today
                                            </p>
                                            <p className="mt-1 text-xl font-semibold text-white">
                                                18
                                            </p>
                                            <p className="mt-1 text-[10px] text-slate-400">
                                                Across 4 locations
                                            </p>
                                        </div>
                                        <div className="rounded-2xl border border-slate-700/70 bg-slate-900/80 p-3">
                                            <p className="text-slate-400">
                                                Staff on shift
                                            </p>
                                            <p className="mt-1 text-xl font-semibold text-white">
                                                9
                                            </p>
                                            <p className="mt-1 text-[10px] text-emerald-400">
                                                Coverage OK
                                            </p>
                                        </div>
                                        <div className="rounded-2xl border border-slate-700/70 bg-slate-900/80 p-3">
                                            <p className="text-slate-400">
                                                Open incidents
                                            </p>
                                            <p className="mt-1 text-xl font-semibold text-white">
                                                3
                                            </p>
                                            <p className="mt-1 text-[10px] text-amber-400">
                                                2 overdue reviews
                                            </p>
                                        </div>
                                    </div>

                                    {/* Mini list */}
                                    <div className="space-y-3 rounded-2xl border border-slate-700/70 bg-slate-900/70 p-3 text-[11px]">
                                        <p className="mb-1 text-slate-400">
                                            Next support visits
                                        </p>

                                        <div className="flex items-center justify-between">
                                            <div>
                                                <p className="text-slate-200">
                                                    Alex Johnson
                                                </p>
                                                <p className="text-slate-400">
                                                    Medication &amp; morning
                                                    check
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-slate-300">
                                                    09:30
                                                </p>
                                                <p className="text-slate-500">
                                                    Sarah L
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <div>
                                                <p className="text-slate-200">
                                                    Priya Patel
                                                </p>
                                                <p className="text-slate-400">
                                                    Community access
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-slate-300">
                                                    11:00
                                                </p>
                                                <p className="text-slate-500">
                                                    James M
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <div>
                                                <p className="text-slate-200">
                                                    Chris Taylor
                                                </p>
                                                <p className="text-slate-400">
                                                    Daily living skills
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-slate-300">
                                                    14:15
                                                </p>
                                                <p className="text-slate-500">
                                                    Amelia B
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        {/* Features */}
                        <section id="features" className="space-y-6">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <h2 className="text-xl font-semibold text-white sm:text-2xl">
                                        Everything you need to run supported
                                        living
                                    </h2>
                                    <p className="mt-1 text-sm text-slate-300">
                                        Forget spreadsheets and scattered notes.
                                        Oblivion Findings pulls the pieces
                                        together.
                                    </p>
                                </div>
                            </div>

                            <div className="grid gap-5 md:grid-cols-3">
                                <div className="rounded-2xl border border-slate-800 bg-slate-900/70 p-4 sm:p-5">
                                    <p className="mb-1 text-xs font-medium text-indigo-300">
                                        Residents &amp; care plans
                                    </p>
                                    <h3 className="text-sm font-semibold text-white">
                                        A single view of each person you support
                                    </h3>
                                    <p className="mt-2 text-xs leading-relaxed text-slate-300">
                                        See key information, outcomes, and risk
                                        flags at a glance. Keep assessments and
                                        care plans up-to-date without digging
                                        through files.
                                    </p>
                                </div>

                                <div className="rounded-2xl border border-slate-800 bg-slate-900/70 p-4 sm:p-5">
                                    <p className="mb-1 text-xs font-medium text-indigo-300">
                                        Visits, notes &amp; tasks
                                    </p>
                                    <h3 className="text-sm font-semibold text-white">
                                        Capture the day as it happens
                                    </h3>
                                    <p className="mt-2 text-xs leading-relaxed text-slate-300">
                                        Log visits, notes and outcomes in real
                                        time. Give your team clear handovers and
                                        reduce the risk of missed actions.
                                    </p>
                                </div>

                                <div className="rounded-2xl border border-slate-800 bg-slate-900/70 p-4 sm:p-5">
                                    <p className="mb-1 text-xs font-medium text-indigo-300">
                                        Compliance &amp; reporting
                                    </p>
                                    <h3 className="text-sm font-semibold text-white">
                                        Be inspection-ready without the scramble
                                    </h3>
                                    <p className="mt-2 text-xs leading-relaxed text-slate-300">
                                        Quickly evidence what good support looks
                                        like. Export clean summaries for
                                        managers, funders and regulators in a
                                        few clicks.
                                    </p>
                                </div>
                            </div>
                        </section>

                        {/* Providers / Workers */}
                        <section
                            id="providers"
                            className="grid items-start gap-6 lg:grid-cols-[1.1fr_1fr]"
                        >
                            <div className="space-y-4 rounded-3xl border border-slate-800 bg-slate-900/70 p-5 sm:p-6">
                                <p className="text-xs font-medium text-indigo-300">
                                    For providers &amp; service managers
                                </p>
                                <h2 className="text-lg font-semibold text-white sm:text-xl">
                                    Clarity for managers, calm for teams
                                </h2>
                                <p className="text-sm text-slate-300">
                                    See what’s happening across your services in
                                    real time: where staff are, who’s been
                                    visited, what’s outstanding, and where risks
                                    are emerging.
                                </p>

                                <ul className="mt-3 space-y-2 text-xs text-slate-300">
                                    <li className="flex gap-2">
                                        <span className="mt-1 flex h-4 w-4 items-center justify-center rounded-full bg-slate-800 text-[9px]">
                                            ✓
                                        </span>
                                        <span>
                                            Live view of visits completed, in
                                            progress and missed
                                        </span>
                                    </li>
                                    <li className="flex gap-2">
                                        <span className="mt-1 flex h-4 w-4 items-center justify-center rounded-full bg-slate-800 text-[9px]">
                                            ✓
                                        </span>
                                        <span>
                                            Incident and escalation tracking
                                            with clear follow-ups
                                        </span>
                                    </li>
                                    <li className="flex gap-2">
                                        <span className="mt-1 flex h-4 w-4 items-center justify-center rounded-full bg-slate-800 text-[9px]">
                                            ✓
                                        </span>
                                        <span>
                                            Simple exports for internal quality
                                            reporting and funders
                                        </span>
                                    </li>
                                </ul>
                            </div>

                            <div
                                id="workers"
                                className="space-y-4 rounded-3xl border border-slate-800 bg-slate-900/40 p-5 sm:p-6"
                            >
                                <p className="text-xs font-medium text-indigo-300">
                                    For support workers
                                </p>
                                <h3 className="text-sm font-semibold text-white">
                                    “I know exactly what my shift looks like”
                                </h3>
                                <p className="text-xs text-slate-300">
                                    Workers see today’s visits, tasks and key
                                    notes in one page – no more juggling paper
                                    diaries and group chats.
                                </p>

                                <div className="space-y-2 rounded-2xl border border-slate-800 bg-slate-950/40 p-3 text-[11px]">
                                    <div className="flex justify-between text-slate-300">
                                        <span>Today’s caseload</span>
                                        <span>Sarah L</span>
                                    </div>
                                    <div className="grid gap-2">
                                        <div className="flex justify-between">
                                            <span className="text-slate-200">
                                                09:30 • Alex J
                                            </span>
                                            <span className="text-emerald-400">
                                                Planned
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-slate-200">
                                                11:00 • Priya P
                                            </span>
                                            <span className="text-slate-300">
                                                In progress
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-slate-200">
                                                14:15 • Chris T
                                            </span>
                                            <span className="text-slate-400">
                                                Later
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        {/* Pricing / CTA */}
                        <section id="pricing" className="space-y-6">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <h2 className="text-xl font-semibold text-white">
                                        Simple pricing for growing providers
                                    </h2>
                                    <p className="mt-1 text-sm text-slate-300">
                                        Start small and scale as you bring more
                                        services onto Oblivion Findings.
                                    </p>
                                </div>
                            </div>

                            <div className="grid gap-5 md:grid-cols-[1.1fr_1fr]">
                                <div className="rounded-3xl border border-slate-800 bg-slate-900/70 p-5 sm:p-6">
                                    <p className="text-xs font-medium text-indigo-300">
                                        Core platform
                                    </p>
                                    <h3 className="mt-1 text-lg font-semibold text-white">
                                        Per-resident pricing, no lock-in
                                        contracts
                                    </h3>
                                    <p className="mt-2 text-sm text-slate-300">
                                        Only pay for the residents you actively
                                        manage in Oblivion Findings. Unlimited
                                        staff accounts.
                                    </p>

                                    <ul className="mt-4 space-y-2 text-xs text-slate-300">
                                        <li>
                                            • Unlimited support worker logins
                                        </li>
                                        <li>
                                            • All core modules (residents,
                                            visits, notes, incidents)
                                        </li>
                                        <li>
                                            • Email support and onboarding help
                                        </li>
                                    </ul>
                                </div>

                                <div className="space-y-3 rounded-3xl border border-slate-800 bg-slate-900/40 p-5 sm:p-6">
                                    <p className="text-xs font-medium text-indigo-300">
                                        Next step
                                    </p>
                                    <h3 className="text-sm font-semibold text-white">
                                        Let’s look at your current setup
                                    </h3>
                                    <p className="text-xs text-slate-300">
                                        Share how you’re currently tracking
                                        visits, notes and incidents, and we’ll
                                        show you how Oblivion Findings can
                                        replace the patchwork.
                                    </p>

                                    <a
                                        href="/contact"
                                        className="mt-2 inline-flex items-center justify-center rounded-full bg-indigo-500 px-4 py-2 text-xs font-medium text-white shadow-sm hover:bg-indigo-400"
                                    >
                                        Talk to the team
                                    </a>

                                    <p className="text-[11px] text-slate-500">
                                        No pressure sales call – just a
                                        walkthrough with someone who understands
                                        supported living services.
                                    </p>
                                </div>
                            </div>
                        </section>

                        {/* Footer */}
                        <Footer />
                    </main>
                </div>
            </div>
        </>
    );
};

export default Home;
