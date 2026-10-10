import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeInZone } from '@/lib/datetime';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    CircleHelp,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type Shift = {
    id: number;
    starts_at: string | null;
    ends_at: string | null;
    staff?: { id: number; name: string } | null;
    client?: { id: number; first_name: string; last_name: string } | null;
};

type QualificationResult = {
    requirement: {
        id: number;
        qualification_name: string;
        qualification_type?: string | null;
        description?: string | null;
    };
    status?: string;
    severity?: 'info' | 'warning' | 'block';
    reasons?: string[];
    requires_acknowledgement?: boolean;
    met: boolean;
    is_mandatory: boolean;
};

type Props = {
    shift: Shift;
    results: QualificationResult[];
    allMandatoryMet: boolean;
    hasBlocks?: boolean;
    hasWarnings?: boolean;
};

export default function QualificationCheckShift({
    shift,
    results = [],
    allMandatoryMet,
}: Props) {
    const [search, setSearch] = useState('');
    const page = usePage().props as unknown as { workerTimezone?: string };
    const timezone = page.workerTimezone ?? 'Pacific/Auckland';
    const formatDateTime = (value: string | null) =>
        formatDateTimeInZone(value, timezone);
    const severity = (result: QualificationResult) =>
        result.severity ??
        (result.met ? 'info' : result.is_mandatory ? 'block' : 'warning');
    const blocks = results.filter(
        (result) => severity(result) === 'block',
    ).length;
    const warnings = results.filter(
        (result) => severity(result) === 'warning',
    ).length;
    const statusLabel = (result: QualificationResult) => {
        if (result.met) return 'Met';
        if (result.status === 'unassigned') return 'Worker not assigned';
        if (result.status === 'unmapped') return 'Qualification not linked';
        if (result.status === 'unavailable') return 'Evidence unavailable';
        if (result.status === 'expired') return 'Expired or no longer valid';
        if (result.status === 'not_started') return 'Evidence missing';
        return 'Needs review';
    };

    const title = shift.staff?.name ?? `Shift #${shift.id}`;
    const metCount = results.filter((r) => r.met).length;

    const sublineParts = [
        'Qualification check',
        shift.client
            ? `${shift.client.first_name} ${shift.client.last_name}`
            : 'No client',
        `${formatDateTime(shift.starts_at)} – ${formatDateTime(shift.ends_at)}`,
    ];

    const shownResults = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return results;
        return results.filter((r) =>
            `${r.requirement.qualification_name} ${
                r.requirement.description ?? ''
            }`
                .toLowerCase()
                .includes(q),
        );
    }, [results, search]);

    const header = (
        <PageHeader
            variant="profile"
            backHref={`/operations/shifts/${shift.id}`}
            icon={ShieldCheck}
            title={title}
            titleChip={
                <PageHeaderStatusChip
                    variant={
                        blocks
                            ? 'critical'
                            : warnings
                              ? 'warning'
                              : !shift.staff || results.length === 0
                                ? 'neutral'
                                : 'success'
                    }
                >
                    {blocks
                        ? 'Qualification blockers'
                        : warnings
                          ? 'Qualification warnings'
                          : !shift.staff
                            ? 'Worker not assigned'
                            : results.length === 0
                              ? 'No requirements recorded'
                              : allMandatoryMet
                                ? 'Mandatory requirements met'
                                : 'Review qualifications'}
                </PageHeaderStatusChip>
            }
            subline={sublineParts.join(' · ') + ' · ' + timezone}
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search requirements…"
                />
            }
            meters={
                <>
                    {results.length > 0 ? (
                        <PageHeaderMeterBlock
                            label="Requirements met"
                            ariaLabel="Review qualification requirements"
                            href="/operations/qualifications"
                        >
                            <PageHeaderMeterDonut
                                percent={(metCount / results.length) * 100}
                                caption={
                                    <>
                                        {metCount} of {results.length}
                                        <br />
                                        met
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Qualification blockers"
                        tone={blocks > 0 ? 'critical' : undefined}
                        ariaLabel="Review qualification requirements"
                        href="/operations/qualifications"
                    >
                        <PageHeaderMeterBig>{blocks}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {blocks > 0
                                ? 'resolve before assignment'
                                : warnings > 0
                                  ? `${warnings} ${warnings === 1 ? 'warning needs' : 'warnings need'} review`
                                  : 'no qualification blockers recorded'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Shifts', href: '/operations/shifts' },
                {
                    title: `Shift #${shift.id}`,
                    href: `/operations/shifts/${shift.id}`,
                },
                {
                    title: 'Qualification check',
                    href: `/operations/qualifications/check/${shift.id}`,
                },
            ]}
        >
            <Head title={`Qualification check · ${title}`} />

            <PageLayout hero={header}>
                <p className="mb-4 text-sm text-muted-foreground">
                    This checks Client qualification requirements against the
                    recorded worker and duty. House coverage and other safety
                    checks are assessed separately when assigning or publishing.
                </p>
                <div className="grid gap-4 lg:grid-cols-[320px_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Shift</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div>
                                <p className="text-xs text-muted-foreground">
                                    Client
                                </p>
                                <p className="font-medium">
                                    {shift.client
                                        ? `${shift.client.first_name} ${shift.client.last_name}`
                                        : 'No client'}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">
                                    Worker
                                </p>
                                <p className="font-medium">
                                    {shift.staff?.name ?? 'Unassigned'}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">
                                    Scheduled
                                </p>
                                <p className="font-medium">
                                    {formatDateTime(shift.starts_at)} -{' '}
                                    {formatDateTime(shift.ends_at)}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <ShieldCheck className="h-4 w-4" />
                                Requirements
                                {search.trim() !== '' && (
                                    <span className="text-xs font-normal text-muted-foreground">
                                        {shownResults.length} of{' '}
                                        {results.length} match
                                    </span>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {shownResults.length === 0 && (
                                <div className="rounded-lg border border-dashed p-8 text-center">
                                    <ShieldCheck className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                                    <p className="text-sm font-medium">
                                        {search.trim() !== ''
                                            ? 'No requirements match your search.'
                                            : 'No requirements configured for this client.'}
                                    </p>
                                    <Link
                                        href="/operations/qualifications"
                                        className="mt-1 block text-xs text-muted-foreground hover:underline"
                                    >
                                        Review qualification requirements
                                    </Link>
                                </div>
                            )}
                            {shownResults.map((result) => {
                                const tone = severity(result);
                                const Icon = result.met
                                    ? CheckCircle2
                                    : tone === 'block'
                                      ? XCircle
                                      : tone === 'warning'
                                        ? AlertTriangle
                                        : CircleHelp;
                                return (
                                    <div
                                        key={result.requirement.id}
                                        className="flex items-start gap-3 rounded-lg border p-3"
                                    >
                                        <Icon
                                            className={
                                                result.met
                                                    ? 'mt-0.5 h-5 w-5 text-status-success'
                                                    : tone === 'block'
                                                      ? 'mt-0.5 h-5 w-5 text-status-critical'
                                                      : tone === 'warning'
                                                        ? 'mt-0.5 h-5 w-5 text-status-warning'
                                                        : 'mt-0.5 h-5 w-5 text-muted-foreground'
                                            }
                                        />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="font-medium">
                                                    {
                                                        result.requirement
                                                            .qualification_name
                                                    }
                                                </p>
                                                <Badge
                                                    variant="outline"
                                                    className="h-5 text-[10px]"
                                                >
                                                    {result.is_mandatory
                                                        ? 'Mandatory'
                                                        : 'Optional'}
                                                </Badge>
                                                <StatusBadge
                                                    variant={
                                                        result.met
                                                            ? 'success'
                                                            : tone === 'block'
                                                              ? 'critical'
                                                              : tone ===
                                                                  'warning'
                                                                ? 'warning'
                                                                : 'neutral'
                                                    }
                                                >
                                                    {statusLabel(result)}
                                                </StatusBadge>
                                            </div>
                                            {result.reasons?.map(
                                                (reason, index) => (
                                                    <p
                                                        key={index}
                                                        className="mt-2 text-sm"
                                                    >
                                                        {reason}
                                                    </p>
                                                ),
                                            )}
                                            {result.requires_acknowledgement ? (
                                                <p className="mt-2 text-sm text-status-warning">
                                                    An authorised manager must
                                                    acknowledge this warning and
                                                    record a reason when
                                                    proceeding.
                                                </p>
                                            ) : null}
                                            {result.requirement.description && (
                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    {
                                                        result.requirement
                                                            .description
                                                    }
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
