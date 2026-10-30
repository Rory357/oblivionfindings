import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    shownCount,
    suppressionCaption,
    type HistoryMeasure,
    type ReferralReport,
} from './report-types';
import { label } from './types';

const hours = (value: number | null) =>
    value === null
        ? '—'
        : value > 0 && value < 0.1
          ? '<0.1'
          : value.toLocaleString('en-NZ', { maximumFractionDigits: 1 });

const plural = (count: number | null, one: string, many: string) =>
    count === 1 ? one : many;

function CompletedVisits({
    report,
    title,
    rows,
}: {
    report: ReferralReport;
    title: string;
    rows: HistoryMeasure[];
}) {
    return (
        <section aria-label={title} className="space-y-3">
            <h3 className="text-subtle font-semibold">{title}</h3>
            {!rows.length && (
                <p className="text-subtle">
                    No completed intervals recorded in this scope.
                </p>
            )}
            {rows.map((row) => (
                <section
                    key={row.key}
                    aria-label={`${title}: ${label(row.key)}`}
                    className="space-y-3 rounded-lg border p-4"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h4 className="text-subtle font-semibold">
                            {label(row.key)}
                        </h4>
                        <span className="text-caption">
                            {shownCount(report, row.count, row.suppressed)}{' '}
                            completed{' '}
                            {plural(row.count, 'interval', 'intervals')}
                        </span>
                    </div>
                    <dl className="grid grid-cols-2 gap-3">
                        {(['elapsed', 'working'] as const).map((clock) => (
                            <div key={clock}>
                                <dt className="text-caption">
                                    {clock === 'elapsed'
                                        ? 'Elapsed hours'
                                        : 'Working hours'}
                                </dt>
                                <dd className="text-section-title tabular-nums">
                                    {row[clock].suppressed
                                        ? 'Not shown'
                                        : hours(row[clock].mean_hours)}
                                </dd>
                                <dd className="text-caption">
                                    Mean ·{' '}
                                    {shownCount(
                                        report,
                                        row[clock].count,
                                        row[clock].suppressed,
                                    ).toLowerCase()}{' '}
                                    measured{' '}
                                    {plural(
                                        row[clock].count,
                                        'interval',
                                        'intervals',
                                    )}
                                </dd>
                                <dd className="text-caption">
                                    Total:{' '}
                                    {row[clock].suppressed
                                        ? 'not shown'
                                        : `${hours(row[clock].total_hours)} hours`}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    {(row.unknown_dates_suppressed ||
                        (row.unknown_dates ?? 0) > 0) && (
                        <p className="text-caption">
                            Missing interval dates:{' '}
                            {shownCount(
                                report,
                                row.unknown_dates,
                                row.unknown_dates_suppressed,
                            ).toLowerCase()}
                            .
                        </p>
                    )}
                    {(row.unknown_calendar_suppressed ||
                        (row.unknown_calendar ?? 0) > 0) && (
                        <p className="text-caption">
                            Working calendar unavailable for{' '}
                            {shownCount(
                                report,
                                row.unknown_calendar,
                                row.unknown_calendar_suppressed,
                            ).toLowerCase()}{' '}
                            measured{' '}
                            {plural(
                                row.unknown_calendar,
                                'interval',
                                'intervals',
                            )}
                            .
                        </p>
                    )}
                </section>
            ))}
        </section>
    );
}

export function ReferralHistoryReport({
    report,
    onReopened,
}: {
    report: ReferralReport;
    onReopened: () => void;
}) {
    if (!report.history) return null;
    const history = report.history;
    const hidden =
        !!history.reopenings_suppressed ||
        !!history.reopened_referrals_suppressed ||
        !!history.incomplete_referrals_suppressed ||
        [...history.stages, ...history.holds].some(
            (row) =>
                row.suppressed ||
                row.elapsed.suppressed ||
                row.working.suppressed,
        );
    return (
        <Card>
            <CardHeader>
                <CardTitle>Stage history & waiting periods</CardTitle>
                <p className="text-subtle">
                    Completed intervals across repeated visits. Current open
                    intervals are shown separately above.
                </p>
                {hidden && (
                    <p className="text-subtle">{suppressionCaption(report)}</p>
                )}
            </CardHeader>
            <CardContent className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-subtle">
                        {shownCount(
                            report,
                            history.reopenings,
                            history.reopenings_suppressed,
                        )}{' '}
                        reopening{' '}
                        {plural(history.reopenings, 'event', 'events')} across{' '}
                        {shownCount(
                            report,
                            history.reopened_referrals,
                            history.reopened_referrals_suppressed,
                        ).toLowerCase()}{' '}
                        {plural(
                            history.reopened_referrals,
                            'referral',
                            'referrals',
                        )}
                        . These are not new people.
                    </p>
                    <Button variant="outline" onClick={onReopened}>
                        View reopened referrals
                    </Button>
                </div>
                {(history.incomplete_referrals_suppressed ||
                    (history.incomplete_referrals ?? 0) > 0) && (
                    <p className="text-subtle">
                        History is incomplete for{' '}
                        {shownCount(
                            report,
                            history.incomplete_referrals,
                            history.incomplete_referrals_suppressed,
                        ).toLowerCase()}{' '}
                        of{' '}
                        {shownCount(
                            report,
                            history.referrals,
                            history.referrals_suppressed,
                        ).toLowerCase()}{' '}
                        referrals. Only intervals with known boundaries
                        contribute to time totals and means.
                    </p>
                )}
                <div className="grid gap-5 lg:grid-cols-2">
                    <CompletedVisits
                        report={report}
                        title="Completed stage visits"
                        rows={history.stages}
                    />
                    <CompletedVisits
                        report={report}
                        title="Completed waiting segments"
                        rows={history.holds}
                    />
                </div>
                <details className="text-subtle">
                    <summary className="frontline-tap cursor-pointer rounded-md focus-visible:outline-2 focus-visible:outline-ring">
                        How historical time is calculated
                    </summary>
                    <p className="mt-2">{report.definitions.history}</p>
                </details>
            </CardContent>
        </Card>
    );
}
