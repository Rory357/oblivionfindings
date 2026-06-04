import { Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CalendarClock,
    ClipboardCheck,
    House,
    Layers,
    Plus,
    PlayCircle,
    TriangleAlert,
} from 'lucide-react';
import type { ReactNode } from 'react';

import { PageHero, type PageHeroBadge, type PageHeroStat } from '@/components/page';
import { Button } from '@/components/ui/button';

import { useChecklistConfig } from './context';
import type { ChecklistStats } from './types';

export type HeroStats = ChecklistStats;

export function ChecklistHero({
    stats,
    siteCount,
    templateCount,
    categoryCount,
    onStart,
    onNewTemplate,
    footer,
}: {
    stats: HeroStats;
    siteCount: number;
    templateCount: number;
    categoryCount: number;
    onStart: () => void;
    onNewTemplate: () => void;
    footer: ReactNode;
}) {
    const { scope, can, today } = useChecklistConfig();
    const page = usePage();
    const userName = (page.props as { auth?: { user?: { name?: string } } })?.auth?.user?.name;
    const firstName = userName?.split(' ')[0] ?? 'there';

    const site = scope.mode === 'site' ? scope.site : null;
    const dateLabel = new Date(`${today}T00:00:00`).toLocaleDateString('en-NZ', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });

    const eyebrow = (
        <span className="mb-2 flex items-center justify-center gap-2 text-[10.5px] font-semibold uppercase tracking-wider text-primary-foreground/80 md:justify-start">
            <span className="relative inline-flex h-2 w-2" aria-hidden>
                <span className="absolute inset-0 inline-flex h-full w-full animate-ping rounded-full bg-status-success/70" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success ring-2 ring-status-success/30" />
            </span>
            {site ? `${site.name} · live checklist status` : 'Live across your network · refreshed just now'}
        </span>
    );

    const title: ReactNode = (
        <>
            {eyebrow}
            {site ? (
                <span className="block">
                    {site.name}
                    <span className="font-normal text-primary-foreground/70"> — Checklists</span>
                </span>
            ) : (
                <span className="block">
                    <span className="font-normal text-primary-foreground/85">
                        Kia ora {firstName}, here's where compliance stands —
                    </span>{' '}
                    <span className="border-b-2 border-primary-foreground/40 pb-0.5">{dateLabel}</span>
                </span>
            )}
        </>
    );

    const description = site
        ? `${stats.onTrack}% of this home's checklists are on track. ${stats.dueToday} due today and ${stats.overdue} overdue need attention.`
        : `${stats.dueToday} checklist${stats.dueToday === 1 ? '' : 's'} due today across ${siteCount} site${siteCount === 1 ? '' : 's'}, ${stats.overdue} overdue to clear, and ${stats.inProgress} part-complete waiting to be finished.`;

    const badges: PageHeroBadge[] = [];
    if (stats.overdue > 0) {
        badges.push({ icon: AlertTriangle, label: `${stats.overdue} overdue`, tone: 'critical' });
    }
    if (stats.dueToday > 0) {
        badges.push({ icon: CalendarClock, label: `${stats.dueToday} due today`, tone: 'warning' });
    }
    if (stats.failures > 0) {
        badges.push({ icon: TriangleAlert, label: `${stats.failures} failures → hazards`, tone: 'info' });
    }

    const heroStats: PageHeroStat[] = [
        { label: 'On track', value: `${stats.onTrack}%`, hideOnMobile: false },
        {
            label: 'Due today',
            value: stats.dueToday,
            tone: stats.dueToday > 0 ? 'warning' : 'neutral',
            hideOnMobile: false,
        },
        {
            label: 'Overdue',
            value: stats.overdue,
            tone: stats.overdue > 0 ? 'critical' : 'neutral',
            hideOnMobile: false,
        },
        { label: 'Done · 30d', value: stats.completed30 },
    ];

    const actions =
        can.run || can.manageTemplates ? (
            <>
                {can.run ? (
                    <Button size="sm" onClick={onStart}>
                        <PlayCircle className="h-4 w-4" />
                        Start a checklist
                    </Button>
                ) : null}
                {can.manageTemplates ? (
                    <Button size="sm" onClick={onNewTemplate}>
                        <Plus className="h-4 w-4" />
                        New template
                    </Button>
                ) : null}
            </>
        ) : undefined;

    return (
        <PageHero
            category="ops"
            icon={site ? House : ClipboardCheck}
            backHref={scope.mode === 'site' ? scope.backHref : undefined}
            backLabel={site ? `Back to ${site.name}` : undefined}
            title={title}
            description={description}
            meta={[
                { icon: Building2, label: site ? siteTypeLabel(site.type) : `${siteCount} sites` },
                { icon: Layers, label: `${templateCount} templates · ${categoryCount} categories` },
                { icon: CalendarClock, label: `${stats.scheduled} scheduled this week` },
            ]}
            badges={badges}
            stats={heroStats}
            actions={actions}
            footer={footer}
        />
    );
}

function siteTypeLabel(type?: string): string {
    if (type === 'house') return 'House';
    if (type === 'head_office') return 'Head Office';
    if (type === 'facility') return 'Facility';
    return 'Site';
}
