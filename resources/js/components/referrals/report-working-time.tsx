import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    shownCount,
    suppressionCaption,
    type ReferralReport,
} from './report-types';

export function ReferralWorkingTimeReport({
    report,
}: {
    report: ReferralReport;
}) {
    if (!report.working_time) return null;
    const hidden = report.working_time.some(
        (row) =>
            row.elapsed.suppressed ||
            row.working.suppressed ||
            row.unknown_dates_suppressed ||
            row.unknown_calendar_suppressed,
    );
    return (
        <Card>
            <CardHeader>
                <CardTitle>Elapsed time & company working time</CardTitle>
                <p className="text-subtle">{report.definitions.working_time}</p>
                {hidden && (
                    <p className="text-subtle">
                        Means from fewer than {report.privacy?.threshold ?? 5}{' '}
                        referrals are not shown. {suppressionCaption(report)}
                    </p>
                )}
            </CardHeader>
            <CardContent className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {report.working_time.map((row) => (
                    <section
                        key={row.key}
                        aria-label={row.label}
                        className="space-y-3 rounded-lg border p-4"
                    >
                        <h3 className="text-subtle font-semibold">
                            {row.label}
                        </h3>
                        <dl className="grid grid-cols-2 gap-3">
                            {(['elapsed', 'working'] as const).map((kind) => (
                                <div key={kind}>
                                    <dt className="text-caption">
                                        {kind === 'elapsed'
                                            ? 'Elapsed hours'
                                            : 'Working hours'}
                                    </dt>
                                    <dd className="text-section-title tabular-nums">
                                        {row[kind].suppressed
                                            ? 'Not shown'
                                            : (row[kind].mean_hours ?? '—')}
                                    </dd>
                                    <dd className="text-caption">
                                        {shownCount(
                                            report,
                                            row[kind].count,
                                            row[kind].suppressed,
                                        )}{' '}
                                        {row[kind].count === 1
                                            ? 'referral'
                                            : 'referrals'}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        {(row.unknown_calendar_suppressed ||
                            (row.unknown_calendar ?? 0) > 0) && (
                            <p className="text-caption">
                                Working calendar unavailable for{' '}
                                {shownCount(
                                    report,
                                    row.unknown_calendar,
                                    row.unknown_calendar_suppressed,
                                ).toLowerCase()}
                                .
                            </p>
                        )}
                        {(row.unknown_dates_suppressed ||
                            (row.unknown_dates ?? 0) > 0) && (
                            <p className="text-caption">
                                Missing or invalid dates:{' '}
                                {shownCount(
                                    report,
                                    row.unknown_dates,
                                    row.unknown_dates_suppressed,
                                ).toLowerCase()}
                                .
                            </p>
                        )}
                        {row.elapsed.count === 0 && row.unknown_dates === 0 && (
                            <p className="text-caption">
                                No matching recorded interval.
                            </p>
                        )}
                    </section>
                ))}
            </CardContent>
        </Card>
    );
}
