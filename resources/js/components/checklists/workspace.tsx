import {
    BarChart3,
    CalendarDays,
    ClipboardList,
    Layers,
    LayoutDashboard,
    ListTodo,
    PlayCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { TabStrip, type RosterTabItem } from '@/components/rostering/tab-strip';
import {
    addDaysWP,
    startOfWeek,
    ymd,
} from '@/components/rostering/week-picker';

import { ChecklistConfigProvider, type PaneCtx } from './context';
import { ChecklistHero } from './hero';
import { HeroFooter, type WeekInfo } from './hero-footer';
import { AssignmentsPane } from './panes/assignments';
import { DueNowPane } from './panes/due-now';
import { LibraryPane } from './panes/library';
import { OverviewPane } from './panes/overview';
import { ReportsPane } from './panes/reports';
import { RunsPane } from './panes/runs';
import { SchedulePane } from './panes/schedule';
import { RunModal } from './run-modal';
import { TemplateBuilderModal } from './template-builder';
import type { ChecklistScope, ChecklistsData, SiteOverview } from './types';

function mondayOf(today: string, offset: number): string {
    // Local-date math only (ymd uses getFullYear/Month/Date) — never toISOString,
    // which converts to UTC and shifts the day back in NZ (UTC+12/13).
    return ymd(
        addDaysWP(startOfWeek(new Date(`${today}T00:00:00`)), offset * 7),
    );
}

function weekInfo(today: string, offset: number): WeekInfo {
    const mon = new Date(`${mondayOf(today, offset)}T00:00:00`);
    const sun = new Date(mon);
    sun.setDate(mon.getDate() + 6);
    const lbl = (o: number) =>
        o === 0
            ? 'This week'
            : o === -1
              ? 'Last week'
              : o === 1
                ? 'Next week'
                : o < 0
                  ? `${-o} weeks ago`
                  : `In ${o} weeks`;
    const opt: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short' };
    const sameMonth = mon.getMonth() === sun.getMonth();
    const range = sameMonth
        ? `${mon.getDate()}–${sun.toLocaleDateString('en-NZ', opt)}`
        : `${mon.toLocaleDateString('en-NZ', opt)} – ${sun.toLocaleDateString('en-NZ', opt)}`;
    return {
        label: lbl(offset),
        range,
        prevLabel: lbl(offset - 1),
        nextLabel: lbl(offset + 1),
    };
}

export function ChecklistsWorkspace({
    scope,
    data,
}: {
    scope: ChecklistScope;
    data: ChecklistsData;
}) {
    const [tab, setTab] = useState('overview');
    const [weekOffset, setWeekOffset] = useState(0);
    const [query, setQuery] = useState('');
    const [cat, setCat] = useState('all');
    const [runId, setRunId] = useState<number | null>(() => {
        if (typeof window === 'undefined') return null;
        const raw = new URLSearchParams(window.location.search).get('run');
        const parsed = raw ? Number(raw) : NaN;
        return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
    });
    const [builderTarget, setBuilderTarget] = useState<number | 'new' | null>(
        null,
    );

    const categoryMap = useMemo(
        () => Object.fromEntries(data.categories.map((c) => [c.key, c])),
        [data.categories],
    );

    const sites: SiteOverview[] = useMemo(() => {
        if (scope.mode === 'org') return data.sitesOverview;
        return [
            {
                id: scope.site.id,
                name: scope.site.name,
                type: scope.site.type ?? '',
                active_assignments: data.assignments.length,
                overdue_runs: data.activeRuns.filter((r) => r.is_overdue)
                    .length,
                scheduled_runs: data.activeRuns.filter(
                    (r) => r.status === 'scheduled',
                ).length,
                on_track_rate: data.stats.onTrack,
            },
        ];
    }, [scope, data]);

    const ctx: PaneCtx = {
        runs: data.activeRuns,
        history: data.recentRuns,
        skippedRuns: data.skippedRuns ?? [],
        assignments: data.assignments,
        templates: data.templates,
        sites,
        reports: data.reports,
        query,
        cat,
        setCat,
        today: data.today,
    };

    const week = useMemo(
        () => weekInfo(data.today, weekOffset),
        [data.today, weekOffset],
    );
    const weekStart = useMemo(
        () => mondayOf(data.today, weekOffset),
        [data.today, weekOffset],
    );

    const jumpToWeek = (target: Date) => {
        const todayMonday = startOfWeek(new Date(`${data.today}T00:00:00`));
        const targetMonday = startOfWeek(target);
        const offset = Math.round(
            (targetMonday.getTime() - todayMonday.getTime()) / (7 * 86_400_000),
        );
        setWeekOffset(offset);
    };

    const tabs: RosterTabItem[] = [
        {
            id: 'overview',
            label: 'Overview',
            icon: LayoutDashboard,
            tone: 'primary',
        },
        {
            id: 'due',
            label: 'Due now',
            icon: ListTodo,
            tone: 'critical',
            badge: data.stats.overdue + data.stats.dueToday || undefined,
        },
        {
            id: 'runs',
            label: 'Runs',
            icon: PlayCircle,
            tone: 'info',
            badge:
                data.activeRuns.length +
                    data.recentRuns.length +
                    (data.skippedRuns?.length ?? 0) || undefined,
        },
        {
            id: 'schedule',
            label: 'Schedule',
            icon: CalendarDays,
            tone: 'primary',
        },
        {
            id: 'library',
            label: 'Library',
            icon: Layers,
            tone: 'primary',
            badge: data.templates.length || undefined,
        },
        {
            id: 'assignments',
            label: 'Assignments',
            icon: ClipboardList,
            tone: 'success',
            badge: data.assignments.length || undefined,
        },
        { id: 'reports', label: 'Reports', icon: BarChart3, tone: 'info' },
    ];

    return (
        <ChecklistConfigProvider
            value={{
                categories: data.categories,
                categoryMap,
                freqLabels: data.frequencyLabels,
                typeLabels: data.typeLabels,
                today: data.today,
                can: data.can,
                scope,
                assignableUsers: data.assignableUsers ?? [],
                openRun: setRunId,
                openBuilder: setBuilderTarget,
            }}
        >
            <div className="space-y-5">
                <ChecklistHero
                    stats={data.stats}
                    siteCount={
                        scope.mode === 'org' ? data.sitesOverview.length : 1
                    }
                    templateCount={data.templates.length}
                    categoryCount={data.categories.length}
                    onStart={() => setTab('due')}
                    onNewTemplate={() => setBuilderTarget('new')}
                    footer={
                        <HeroFooter
                            week={week}
                            onPrevWeek={() => setWeekOffset((o) => o - 1)}
                            onNextWeek={() => setWeekOffset((o) => o + 1)}
                            selectedWeekStart={
                                new Date(`${weekStart}T00:00:00`)
                            }
                            today={new Date(`${data.today}T00:00:00`)}
                            onJumpToWeek={jumpToWeek}
                            query={query}
                            onQuery={setQuery}
                            cat={cat}
                            onCat={setCat}
                            sites={data.sitesOverview}
                        />
                    }
                />

                <TabStrip value={tab} onChange={setTab} items={tabs} />

                <div>
                    {tab === 'overview' ? (
                        <OverviewPane
                            ctx={ctx}
                            stats={data.stats}
                            goTab={setTab}
                        />
                    ) : null}
                    {tab === 'due' ? <DueNowPane ctx={ctx} /> : null}
                    {tab === 'runs' ? <RunsPane ctx={ctx} /> : null}
                    {tab === 'schedule' ? (
                        <SchedulePane ctx={ctx} weekStart={weekStart} />
                    ) : null}
                    {tab === 'library' ? (
                        <LibraryPane
                            ctx={ctx}
                            onNewTemplate={() => setBuilderTarget('new')}
                        />
                    ) : null}
                    {tab === 'assignments' ? (
                        <AssignmentsPane ctx={ctx} />
                    ) : null}
                    {tab === 'reports' ? (
                        <ReportsPane ctx={ctx} stats={data.stats} />
                    ) : null}
                </div>
            </div>

            {runId != null ? (
                <RunModal runId={runId} onClose={() => setRunId(null)} />
            ) : null}
            {builderTarget != null && data.can.manageTemplates ? (
                <TemplateBuilderModal
                    target={builderTarget}
                    onClose={() => setBuilderTarget(null)}
                />
            ) : null}
        </ChecklistConfigProvider>
    );
}
