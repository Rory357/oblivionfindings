/**
 * My Day's three health and safety parts (board G4-1, approved by Stephan 1 Nov 2026: "Keep My Day, add H&S only").
 *
 * - "Something happened?" comes first in the body: one main button, "Report something" (the same chooser the top
 *   bar opens, with an incident already chosen), and "Raise a safeguarding concern". All buttons are 44 px.
 * - "What you reported": only the person's own reports, status only, in the four words. A draft has "Finish".
 *   Safeguarding concerns and restraints are never listed; the lock line says where they went.
 * - "Your workplace injury": modified duties for now, the next review date (not when the duties end) and who is
 *   coordinating the person's recovery.
 */
import { Link, router } from '@inertiajs/react';
import { Lock, Plus, ShieldAlert } from 'lucide-react';
import { useState } from 'react';

import { injuryTypeLabel } from '@/components/health-safety/injury-constants';
import { incidentTypeLabel } from '@/components/incidents/incident-stages';
import {
    ReportSomethingDialog,
    useCanReportSomething,
} from '@/components/report-something';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    formatDate,
    formatTime,
    toDateInput,
    WORKER_TIMEZONE,
} from '@/lib/datetime';
import { REPORT_WORDS } from '@/lib/incidents/report-words';

import type { MyDayOwnInjury, MyDayReport } from '../lib/types';

/* ------------------------------------------------------------------ */
/*  Something happened?                                                */
/* ------------------------------------------------------------------ */

export function SomethingHappened({
    canSafeguarding,
    onIncident,
}: {
    canSafeguarding: boolean;
    /** My Day's own incident link, which keeps the shift. */
    onIncident: () => void;
}) {
    const canReport = useCanReportSomething();
    const [open, setOpen] = useState(false);
    if (!canReport) return null;
    return (
        <>
            <Card>
                <CardContent className="flex flex-wrap items-center gap-4 px-[18px] py-3.5">
                    <span className="popup-lens" aria-hidden="true">
                        <ShieldAlert className="size-5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h2 className="text-section-title">
                            Something happened?
                        </h2>
                        <p className="text-subtle">
                            Someone hurt or nearly hurt, or you’re worried about
                            a person. Tell us and we’ll take it from there.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2.5">
                        {canSafeguarding ? (
                            <Button
                                variant="outline"
                                className="frontline-tap"
                                onClick={() =>
                                    router.visit('/safeguarding/create')
                                }
                            >
                                Raise a safeguarding concern
                            </Button>
                        ) : null}
                        <Button
                            className="frontline-tap"
                            onClick={() => setOpen(true)}
                        >
                            <Plus className="size-4" aria-hidden="true" />
                            Report something
                        </Button>
                    </div>
                </CardContent>
            </Card>
            <ReportSomethingDialog
                open={open}
                onClose={() => setOpen(false)}
                onIncident={onIncident}
            />
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  What you reported                                                  */
/* ------------------------------------------------------------------ */

function dayMonth(at: string): string {
    return new Date(at).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        timeZone: WORKER_TIMEZONE,
    });
}

/** The report's title, in the incident's own words ("Fall — Aroha Kingi"), never another person's name for an injury. */
export function reportTitle(report: MyDayReport): string {
    if (report.kind === 'injury') {
        const what = [
            report.injury_type
                ? injuryTypeLabel(report.injury_type)
                : 'Workplace injury',
            report.body_part,
        ]
            .filter(Boolean)
            .join(' — ');
        return report.own ? `${what} (your injury)` : what;
    }
    const type = incidentTypeLabel(report.type);
    return report.client_name ? `${type} — ${report.client_name}` : type;
}

export function WhatYouReported({ reports }: { reports: MyDayReport[] }) {
    const today = toDateInput(new Date());
    return (
        <Card>
            <CardContent className="grid gap-3 p-5">
                <div className="flex items-baseline justify-between gap-3">
                    <h2 className="text-section-title">What you reported</h2>
                    <span className="text-caption">Your last 5</span>
                </div>
                {reports.length ? (
                    <ul
                        aria-label="What you reported"
                        className="grid divide-y divide-border"
                    >
                        {reports.map((report) => {
                            const word = REPORT_WORDS[report.status];
                            const isToday =
                                !!report.at && toDateInput(report.at) === today;
                            return (
                                <li
                                    key={`${report.kind}-${report.id}`}
                                    className="grid grid-cols-[3.75rem_minmax(0,1fr)_auto] items-center gap-3 py-2.5"
                                >
                                    <span className="text-caption leading-tight">
                                        {report.at ? (
                                            <>
                                                <b className="block font-semibold text-foreground">
                                                    {isToday
                                                        ? 'Today'
                                                        : dayMonth(report.at)}
                                                </b>
                                                {isToday
                                                    ? formatTime(report.at)
                                                    : null}
                                            </>
                                        ) : (
                                            '—'
                                        )}
                                    </span>
                                    <span className="min-w-0">
                                        <b className="block truncate text-sm font-semibold text-foreground">
                                            {reportTitle(report)}
                                        </b>
                                        <span className="text-caption flex flex-wrap items-center gap-1.5">
                                            {report.kind === 'injury'
                                                ? 'Workplace injury'
                                                : 'Incident'}
                                            {report.kind === 'incident' &&
                                            report.site_name
                                                ? ` · ${report.site_name}`
                                                : ''}
                                            {' · '}
                                            <StatusBadge variant={word.variant}>
                                                {word.label}
                                            </StatusBadge>
                                        </span>
                                    </span>
                                    {report.finish_url ? (
                                        <Button
                                            asChild
                                            variant="outline"
                                            className="frontline-tap"
                                        >
                                            <Link href={report.finish_url}>
                                                Finish
                                            </Link>
                                        </Button>
                                    ) : (
                                        <span />
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                ) : (
                    <p className="text-subtle">
                        You haven’t reported anything yet.
                    </p>
                )}
                <p className="flex items-start gap-2 rounded-lg bg-muted px-3 py-2.5 text-xs text-muted-foreground">
                    <Lock
                        className="mt-0.5 size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <span>
                        Safeguarding concerns and restraints you record go
                        straight to the people who follow them up. They’re kept
                        private, so they aren’t listed here.
                    </span>
                </p>
            </CardContent>
        </Card>
    );
}

/* ------------------------------------------------------------------ */
/*  Your workplace injury                                              */
/* ------------------------------------------------------------------ */

/** A YYYY-MM-DD date as "Tue 3 Nov" (midnight UTC is the same calendar day in New Zealand). */
function reviewDay(date: string): string {
    return formatDate(`${date}T00:00:00Z`);
}

export function YourWorkplaceInjury({ injury }: { injury: MyDayOwnInjury }) {
    const what = [
        injury.injury_type
            ? injuryTypeLabel(injury.injury_type)
            : 'Workplace injury',
        injury.body_part,
    ]
        .filter(Boolean)
        .join(' — ');
    const facts = [
        injury.on_modified_duties ? 'On modified duties for now' : null,
        injury.next_review_date ? (
            <>
                next review{' '}
                <b className="font-semibold text-foreground">
                    {reviewDay(injury.next_review_date)}
                </b>
            </>
        ) : null,
    ].filter(Boolean);
    return (
        <Card>
            <CardContent className="grid gap-2 p-5">
                <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                    <h2 className="text-section-title">
                        Your workplace injury
                    </h2>
                    <span className="text-caption">
                        {what}
                        {injury.injury_date
                            ? ` · ${dayMonth(injury.injury_date)}`
                            : ''}
                    </span>
                </div>
                <p className="text-sm text-muted-foreground">
                    {facts.length
                        ? facts.map((fact, index) => (
                              <span key={index}>
                                  {index ? ' · ' : ''}
                                  {fact}
                              </span>
                          ))
                        : null}
                    {facts.length ? '. ' : ''}
                    {injury.coordinator
                        ? `${injury.coordinator} is coordinating your recovery.`
                        : 'Nobody is coordinating your recovery yet.'}
                </p>
                <div>
                    <Button
                        asChild
                        variant="link"
                        className="frontline-tap h-auto px-0"
                    >
                        <Link href="/hr/my/injuries">
                            See your care and return-to-work plan
                        </Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}
