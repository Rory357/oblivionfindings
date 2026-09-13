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
import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, ShieldCheck, XCircle } from 'lucide-react';
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
    met: boolean;
    is_mandatory: boolean;
};

type Props = {
    shift: Shift;
    results: QualificationResult[];
    allMandatoryMet: boolean;
};

function formatDateTime(value: string | null): string {
    if (!value) return '-';
    return new Date(value).toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function QualificationCheckShift({
    shift,
    results = [],
    allMandatoryMet,
}: Props) {
    const [search, setSearch] = useState('');

    const title = shift.staff?.name ?? `Shift #${shift.id}`;
    const metCount = results.filter((r) => r.met).length;
    const mandatoryGaps = results.filter(
        (r) => r.is_mandatory && !r.met,
    ).length;

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
                allMandatoryMet ? (
                    <PageHeaderStatusChip variant="success">
                        Mandatory requirements met
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="critical">
                        Mandatory gaps found
                    </PageHeaderStatusChip>
                )
            }
            subline={sublineParts.join(' · ')}
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
                        label="Mandatory gaps"
                        tone={mandatoryGaps > 0 ? 'critical' : 'success'}
                        ariaLabel="Review qualification requirements"
                        href="/operations/qualifications"
                    >
                        <PageHeaderMeterBig>{mandatoryGaps}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {mandatoryGaps > 0
                                ? 'must be resolved before the shift'
                                : 'nothing outstanding'}
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
                    href: `/operations/qualifications/check-shift/${shift.id}`,
                },
            ]}
        >
            <Head title={`Qualification check · ${title}`} />

            <PageLayout hero={header}>
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
                                const Icon = result.met
                                    ? CheckCircle2
                                    : XCircle;
                                return (
                                    <div
                                        key={result.requirement.id}
                                        className="flex items-start gap-3 rounded-lg border p-3"
                                    >
                                        <Icon
                                            className={
                                                result.met
                                                    ? 'mt-0.5 h-5 w-5 text-status-success'
                                                    : 'mt-0.5 h-5 w-5 text-status-critical'
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
                                                            : result.is_mandatory
                                                              ? 'critical'
                                                              : 'warning'
                                                    }
                                                >
                                                    {result.met
                                                        ? 'Met'
                                                        : 'Missing'}
                                                </StatusBadge>
                                            </div>
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
