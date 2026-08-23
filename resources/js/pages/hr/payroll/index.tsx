import { PayrollTabs } from '@/components/hr';
import { PayrollHero } from '@/components/hr/payroll-hero';
import {
    CreateRunWizard,
    ExportProfileWizard,
    type ExportFieldOption,
    type PayrollExportProfile,
} from '@/components/hr/payroll-wizards';
import {
    useRowContextMenu,
    type RowCtxItem,
} from '@/components/hr/row-context-menu';
import { PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    CircleDollarSign,
    Download,
    LockKeyhole,
    Plus,
    RefreshCw,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

interface PayrollRun {
    id: number;
    period_start: string;
    period_end: string;
    status: 'draft' | 'locked' | 'exported' | 'void';
    total_hours: number;
    total_gross: number;
    items_count: number;
    created_at: string;
    locked_at: string | null;
    exported_at: string | null;
    gl_posted_at: string | null;
    gl_error: string | null;
    net_paid_at: string | null;
    net_settlement: {
        status:
            | 'prepared'
            | 'exported'
            | 'accepted'
            | 'rejected'
            | 'settled'
            | 'reconciled';
        artifact_sha256: string;
        exported_at: string | null;
        accepted_at: string | null;
        acceptance_reference: string | null;
        rejection_reason: string | null;
    } | null;
    export_profile: {
        id: number;
        name: string;
        provider_key: string | null;
    } | null;
    validation_errors: string[];
}

interface Props {
    runs: {
        data: PayrollRun[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    profiles: PayrollExportProfile[];
    exportFieldOptions: ExportFieldOption[];
    statusCounts: {
        total: number;
        draft: number;
        locked: number;
        exported: number;
    };
    can: { manage: boolean; export_data: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Payroll', href: '/hr/payroll' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning-bg',
        label: 'Draft',
    },
    locked: {
        className: 'border-status-info/30 text-status-info bg-status-info-bg',
        label: 'Locked',
    },
    exported: {
        className:
            'border-status-success/30 text-status-success bg-status-success-bg',
        label: 'Exported',
    },
    void: {
        className: 'border-muted-foreground/30 text-muted-foreground bg-muted',
        label: 'Voided',
    },
};

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(amount);
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(date);
}

type ProfileWizardState = { profile: PayrollExportProfile | null } | null;

export default function PayrollIndex({
    runs,
    profiles,
    exportFieldOptions,
    statusCounts,
    can,
}: Props) {
    const [runWizardOpen, setRunWizardOpen] = useState(false);
    const [profileWizard, setProfileWizard] =
        useState<ProfileWizardState>(null);
    const [selectedProfileByRun, setSelectedProfileByRun] = useState<
        Record<number, string>
    >({});
    const page = usePage<{ errors?: Record<string, string | string[]> }>();
    const { open: openRunContext, element: runContextElement } =
        useRowContextMenu();

    const lockError = page.props?.errors?.lock;
    const exportError = page.props?.errors?.export;
    const defaultProfile =
        profiles.find((profile) => profile.is_default) ?? null;

    function handleExport(runId: number) {
        const selectedProfileId =
            selectedProfileByRun[runId] ||
            (defaultProfile ? String(defaultProfile.id) : '');
        router.post(
            `/hr/payroll/runs/${runId}/export`,
            selectedProfileId ? { profile_id: Number(selectedProfileId) } : {},
            { preserveScroll: true },
        );
    }

    function handlePrepareNetPay(runId: number) {
        router.post(
            `/hr/payroll/runs/${runId}/prepare-net-pay`,
            {},
            { preserveScroll: true },
        );
    }

    function handleSettleNetPay(run: PayrollRun) {
        const existingReference = run.net_settlement?.acceptance_reference;
        const reference =
            existingReference || window.prompt('Bank acceptance reference');
        if (!reference) return;
        const confirmationDigest = existingReference
            ? null
            : window.prompt(
                  'Bank confirmation digest or immutable evidence reference',
              );
        if (!existingReference && !confirmationDigest) return;

        router.post(
            `/hr/payroll/runs/${run.id}/pay`,
            {
                idempotency_key: `payroll-net:${run.id}:${reference}`,
                acceptance_reference: reference,
                acceptance_evidence: confirmationDigest
                    ? { confirmation_digest: confirmationDigest }
                    : undefined,
            },
            { preserveScroll: true },
        );
    }

    function handleRejectNetPay(run: PayrollRun) {
        const reference = window.prompt('Bank rejection reference');
        const reason = window.prompt('Bank rejection reason');
        const evidenceReference = window.prompt(
            'Bank rejection digest or immutable evidence reference',
        );
        if (!reference || !reason || !evidenceReference) return;

        router.post(
            `/hr/payroll/runs/${run.id}/reject-net-pay`,
            {
                idempotency_key: `payroll-reject:${run.id}:${reference}:${evidenceReference}`.slice(
                    0,
                    128,
                ),
                reference,
                reason,
                evidence: { rejection_digest: evidenceReference },
            },
            { preserveScroll: true },
        );
    }

    function handleReconcileNetPay(run: PayrollRun) {
        const bankTransactionInput = window.prompt(
            'Cleared bank transaction ID',
        );
        const reference = window.prompt('Bank reconciliation reference');
        const evidenceReference = window.prompt(
            'Bank reconciliation digest or immutable evidence reference',
        );
        if (!bankTransactionInput || !reference || !evidenceReference) return;

        const bankTransactionId = Number(bankTransactionInput);
        if (!Number.isSafeInteger(bankTransactionId) || bankTransactionId < 1)
            return;

        router.post(
            `/hr/payroll/runs/${run.id}/reconcile-net-pay`,
            {
                idempotency_key: `payroll-reconcile:${run.id}:${bankTransactionId}`,
                bank_transaction_id: bankTransactionId,
                reference,
                evidence: { reconciliation_digest: evidenceReference },
            },
            { preserveScroll: true },
        );
    }

    function handleSetDefaultProfile(profileId: number) {
        router.post(
            `/hr/payroll/export-profiles/${profileId}/set-default`,
            {},
            { preserveScroll: true },
        );
    }

    function renderMobileRunActions(run: PayrollRun) {
        return (
            <div className="flex flex-wrap items-center gap-2">
                {run.status === 'draft' && can.manage ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.post(
                                `/hr/payroll/runs/${run.id}/lock`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Lock
                    </Button>
                ) : null}
                {can.manage &&
                run.gl_posted_at &&
                !run.net_paid_at &&
                (!run.net_settlement ||
                    run.net_settlement.status === 'rejected') ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => handlePrepareNetPay(run.id)}
                    >
                        Prepare bank file
                    </Button>
                ) : null}
                {can.manage &&
                ['exported', 'accepted'].includes(
                    run.net_settlement?.status || '',
                ) &&
                !run.net_paid_at ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => handleSettleNetPay(run)}
                    >
                        Record acceptance &amp; settle
                    </Button>
                ) : null}
                {can.manage && run.net_settlement?.status === 'settled' ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => handleReconcileNetPay(run)}
                    >
                        Record reconciliation
                    </Button>
                ) : null}
                {can.manage &&
                ['exported', 'accepted'].includes(
                    run.net_settlement?.status || '',
                ) ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => handleRejectNetPay(run)}
                    >
                        <XCircle className="mr-1 h-3 w-3" />
                        Record rejection
                    </Button>
                ) : null}
                {run.net_paid_at ? (
                    <span className="rounded-md bg-status-success-bg px-2 py-1 text-xs font-semibold text-status-success">
                        Paid
                    </span>
                ) : null}
                {run.gl_error && !run.gl_posted_at ? (
                    <>
                        <span
                            className="rounded-md bg-status-critical-bg px-2 py-1 text-xs font-semibold text-status-critical"
                            title={run.gl_error}
                        >
                            GL failed
                        </span>
                        {can.manage ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        `/hr/payroll/runs/${run.id}/retry-gl`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Retry GL
                            </Button>
                        ) : null}
                    </>
                ) : null}
                {can.export_data &&
                run.net_settlement &&
                ['prepared', 'exported', 'accepted'].includes(
                    run.net_settlement.status,
                ) ? (
                    <Button variant="outline" size="sm" asChild>
                        <a href={`/hr/payroll/runs/${run.id}/net-pay-file`}>
                            <Download className="mr-1 h-3 w-3" />
                            Bank file
                        </a>
                    </Button>
                ) : null}
                {can.export_data && run.status === 'locked' ? (
                    <>
                        {profiles.length > 0 ? (
                            <Select
                                value={
                                    selectedProfileByRun[run.id] ||
                                    (defaultProfile
                                        ? String(defaultProfile.id)
                                        : undefined)
                                }
                                onValueChange={(value) =>
                                    setSelectedProfileByRun((previous) => ({
                                        ...previous,
                                        [run.id]: value,
                                    }))
                                }
                            >
                                <SelectTrigger className="h-8 min-w-[160px] flex-1">
                                    <SelectValue placeholder="Default mapping" />
                                </SelectTrigger>
                                <SelectContent>
                                    {profiles.map((profile) => (
                                        <SelectItem
                                            key={profile.id}
                                            value={String(profile.id)}
                                        >
                                            {profile.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        ) : null}
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handleExport(run.id)}
                        >
                            <Download className="mr-1 h-3 w-3" />
                            Export
                        </Button>
                    </>
                ) : null}
            </div>
        );
    }

    function runContextItems(run: PayrollRun): RowCtxItem[] {
        const items: RowCtxItem[] = [];
        if (run.status === 'draft' && can.manage)
            items.push({
                kind: 'item',
                label: 'Lock run',
                icon: LockKeyhole,
                onSelect: () =>
                    router.post(
                        `/hr/payroll/runs/${run.id}/lock`,
                        {},
                        { preserveScroll: true },
                    ),
            });
        if (
            can.manage &&
            run.gl_posted_at &&
            !run.net_paid_at &&
            (!run.net_settlement || run.net_settlement.status === 'rejected')
        )
            items.push({
                kind: 'item',
                label: 'Prepare net-pay bank file',
                icon: CircleDollarSign,
                onSelect: () => handlePrepareNetPay(run.id),
            });
        if (
            can.manage &&
            ['exported', 'accepted'].includes(run.net_settlement?.status || '') &&
            !run.net_paid_at
        )
            items.push({
                kind: 'item',
                label: 'Record acceptance and settle',
                icon: CircleDollarSign,
                onSelect: () => handleSettleNetPay(run),
            });
        if (
            can.manage &&
            ['exported', 'accepted'].includes(run.net_settlement?.status || '')
        )
            items.push({
                kind: 'item',
                label: 'Record bank rejection',
                icon: XCircle,
                onSelect: () => handleRejectNetPay(run),
            });
        if (can.manage && run.net_settlement?.status === 'settled')
            items.push({
                kind: 'item',
                label: 'Record bank reconciliation',
                icon: CircleDollarSign,
                onSelect: () => handleReconcileNetPay(run),
            });
        if (can.manage && run.gl_error && !run.gl_posted_at)
            items.push({
                kind: 'item',
                label: 'Retry GL',
                icon: RefreshCw,
                onSelect: () =>
                    router.post(
                        `/hr/payroll/runs/${run.id}/retry-gl`,
                        {},
                        { preserveScroll: true },
                    ),
            });
        if (can.export_data && run.status === 'locked')
            items.push({
                kind: 'item',
                label: 'Export run',
                icon: Download,
                onSelect: () => handleExport(run.id),
            });
        if (
            can.export_data &&
            run.net_settlement &&
            ['prepared', 'exported', 'accepted'].includes(
                run.net_settlement.status,
            )
        )
            items.push({
                kind: 'item',
                label: 'Download bank file',
                icon: Download,
                onSelect: () => {
                    window.location.href = `/hr/payroll/runs/${run.id}/net-pay-file`;
                },
            });
        return items;
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payroll" />
            <PageLayout
                hero={
                    <PayrollHero
                        surface="runs"
                        counts={statusCounts}
                        actions={
                            can.manage ? (
                                <Button onClick={() => setRunWizardOpen(true)}>
                                    <Plus className="mr-2 h-4 w-4" />
                                    Create Run
                                </Button>
                            ) : undefined
                        }
                    />
                }
            >
                <PayrollTabs active="runs" />

                {lockError ? (
                    <Card className="border-status-critical/40 bg-status-critical-bg">
                        <CardContent className="py-3 text-sm text-status-critical">
                            {Array.isArray(lockError)
                                ? lockError.join(' ')
                                : lockError}
                        </CardContent>
                    </Card>
                ) : null}
                {exportError ? (
                    <Card className="border-status-critical/40 bg-status-critical-bg">
                        <CardContent className="py-3 text-sm text-status-critical">
                            {Array.isArray(exportError)
                                ? exportError.join(' ')
                                : exportError}
                        </CardContent>
                    </Card>
                ) : null}

                {runWizardOpen && (
                    <CreateRunWizard onClose={() => setRunWizardOpen(false)} />
                )}

                {profileWizard !== null && (
                    <ExportProfileWizard
                        key={profileWizard.profile?.id ?? 'new'}
                        profile={profileWizard.profile}
                        fieldOptions={exportFieldOptions}
                        isFirstProfile={profiles.length === 0}
                        onClose={() => setProfileWizard(null)}
                    />
                )}

                <Card>
                    <CardContent className="py-4">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="text-base font-semibold">
                                Payroll Export Profiles
                            </h2>
                            {can.manage && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setProfileWizard({ profile: null })
                                    }
                                >
                                    <Plus className="mr-2 h-4 w-4" />
                                    New Export Profile
                                </Button>
                            )}
                        </div>
                        {profiles.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No export profiles configured yet. The default
                                payroll CSV schema will be used.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {profiles.map((profile) => (
                                    <div
                                        key={profile.id}
                                        className="flex items-center justify-between rounded-md border p-3"
                                    >
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">
                                                    {profile.name}
                                                </span>
                                                {profile.is_default && (
                                                    <Badge variant="outline">
                                                        Default
                                                    </Badge>
                                                )}
                                                {profile.provider_key ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="text-xs text-muted-foreground"
                                                    >
                                                        {profile.provider_key}
                                                    </Badge>
                                                ) : null}
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                {profile.mappings?.length ?? 0}{' '}
                                                mappings, delimiter "
                                                {profile.delimiter}", enclosure
                                                "{profile.enclosure}"
                                            </p>
                                        </div>
                                        {can.manage && (
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        setProfileWizard({
                                                            profile,
                                                        })
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                                {!profile.is_default && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            handleSetDefaultProfile(
                                                                profile.id,
                                                            )
                                                        }
                                                    >
                                                        Set Default
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <div data-payroll-desktop className="hidden md:block">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Period
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Status
                                        </th>
                                        <th className="px-4 py-3 text-right font-medium">
                                            Total Hours
                                        </th>
                                        <th className="px-4 py-3 text-right font-medium">
                                            Total Gross
                                        </th>
                                        <th className="px-4 py-3 text-right font-medium">
                                            Items
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Created
                                        </th>
                                        <th className="px-4 py-3 text-right font-medium">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {runs.data.map((run) => {
                                        const config =
                                            statusConfig[run.status] ||
                                            statusConfig.draft;
                                        return (
                                            <tr
                                                key={run.id}
                                                className="hover:bg-muted/30"
                                                onContextMenu={openRunContext(
                                                    runContextItems(run),
                                                )}
                                            >
                                                <td className="px-4 py-3">
                                                    <span className="font-medium">
                                                        {formatDate(
                                                            run.period_start,
                                                        )}{' '}
                                                        -{' '}
                                                        {formatDate(
                                                            run.period_end,
                                                        )}
                                                    </span>
                                                    {run.validation_errors
                                                        ?.length > 0 ? (
                                                        <div className="mt-1 text-xs text-status-critical">
                                                            {
                                                                run
                                                                    .validation_errors[0]
                                                            }
                                                            {run
                                                                .validation_errors
                                                                .length > 1
                                                                ? ` (+${run.validation_errors.length - 1} more)`
                                                                : ''}
                                                        </div>
                                                    ) : null}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="flex flex-col items-start gap-1">
                                                        <Badge
                                                            variant="outline"
                                                            className={
                                                                config.className
                                                            }
                                                        >
                                                            {config.label}
                                                        </Badge>
                                                        {run.net_settlement ? (
                                                            <span className="text-xs text-muted-foreground">
                                                                Net pay:{' '}
                                                                {run.net_settlement.status.replace(
                                                                    '_',
                                                                    ' ',
                                                                )}
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 text-right text-muted-foreground">
                                                    {run.total_hours.toFixed(1)}
                                                    h
                                                </td>
                                                <td className="px-4 py-3 text-right font-medium">
                                                    {formatCurrency(
                                                        run.total_gross,
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-right text-muted-foreground">
                                                    {run.items_count}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {formatDate(run.created_at)}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        {run.status ===
                                                            'draft' &&
                                                            can.manage && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            `/hr/payroll/runs/${run.id}/lock`,
                                                                            {},
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    Lock
                                                                </Button>
                                                            )}
                                                        {can.manage &&
                                                            run.gl_posted_at &&
                                                            !run.net_paid_at &&
                                                            !run.net_settlement && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        handlePrepareNetPay(
                                                                            run.id,
                                                                        )
                                                                    }
                                                                >
                                                                    Prepare
                                                                    bank file
                                                                </Button>
                                                            )}
                                                        {can.manage &&
                                                            ['exported', 'accepted'].includes(
                                                                run.net_settlement?.status || '',
                                                            ) &&
                                                            !run.net_paid_at && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        handleSettleNetPay(
                                                                            run,
                                                                        )
                                                                    }
                                                                >
                                                                    Accept &amp;
                                                                    settle
                                                                </Button>
                                                            )}
                                                        {run.net_paid_at && (
                                                            <span className="inline-flex items-center rounded-md bg-status-success-bg px-2 py-1 text-xs font-semibold text-status-success">
                                                                Paid
                                                            </span>
                                                        )}
                                                        {can.manage &&
                                                            run.net_settlement
                                                                ?.status ===
                                                                'settled' && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        handleReconcileNetPay(
                                                                            run,
                                                                        )
                                                                    }
                                                                >
                                                                    Reconcile
                                                                </Button>
                                                            )}
                                                        {run.gl_error &&
                                                            !run.gl_posted_at && (
                                                                <>
                                                                    <span
                                                                        className="inline-flex items-center rounded-md bg-status-critical-bg px-2 py-1 text-xs font-semibold text-status-critical"
                                                                        title={
                                                                            run.gl_error
                                                                        }
                                                                    >
                                                                        GL
                                                                        failed
                                                                    </span>
                                                                    {can.manage && (
                                                                        <Button
                                                                            variant="outline"
                                                                            size="sm"
                                                                            onClick={() =>
                                                                                router.post(
                                                                                    `/hr/payroll/runs/${run.id}/retry-gl`,
                                                                                    {},
                                                                                    {
                                                                                        preserveScroll: true,
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            Retry
                                                                            GL
                                                                        </Button>
                                                                    )}
                                                                </>
                                                            )}
                                                        {can.export_data &&
                                                            run.net_settlement &&
                                                            ['prepared', 'exported', 'accepted'].includes(
                                                                run.net_settlement.status,
                                                            ) && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    asChild
                                                                >
                                                                    <a
                                                                        href={`/hr/payroll/runs/${run.id}/net-pay-file`}
                                                                    >
                                                                        <Download className="mr-1 h-3 w-3" />
                                                                        Bank
                                                                        file
                                                                    </a>
                                                                </Button>
                                                            )}
                                                        {can.export_data &&
                                                            run.status ===
                                                                'locked' && (
                                                                <div className="flex items-center gap-2">
                                                                    {profiles.length >
                                                                        0 && (
                                                                        <Select
                                                                            value={
                                                                                selectedProfileByRun[
                                                                                    run
                                                                                        .id
                                                                                ] ||
                                                                                (defaultProfile
                                                                                    ? String(
                                                                                          defaultProfile.id,
                                                                                      )
                                                                                    : undefined)
                                                                            }
                                                                            onValueChange={(
                                                                                value,
                                                                            ) =>
                                                                                setSelectedProfileByRun(
                                                                                    (
                                                                                        previous,
                                                                                    ) => ({
                                                                                        ...previous,
                                                                                        [run.id]:
                                                                                            value,
                                                                                    }),
                                                                                )
                                                                            }
                                                                        >
                                                                            <SelectTrigger className="h-8 w-[180px]">
                                                                                <SelectValue placeholder="Default mapping" />
                                                                            </SelectTrigger>
                                                                            <SelectContent>
                                                                                {profiles.map(
                                                                                    (
                                                                                        profile,
                                                                                    ) => (
                                                                                        <SelectItem
                                                                                            key={
                                                                                                profile.id
                                                                                            }
                                                                                            value={String(
                                                                                                profile.id,
                                                                                            )}
                                                                                        >
                                                                                            {
                                                                                                profile.name
                                                                                            }
                                                                                        </SelectItem>
                                                                                    ),
                                                                                )}
                                                                            </SelectContent>
                                                                        </Select>
                                                                    )}
                                                                    <Button
                                                                        variant="outline"
                                                                        size="sm"
                                                                        onClick={() =>
                                                                            handleExport(
                                                                                run.id,
                                                                            )
                                                                        }
                                                                    >
                                                                        <Download className="mr-1 h-3 w-3" />
                                                                        Export
                                                                    </Button>
                                                                </div>
                                                            )}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {runs.data.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={7}
                                                className="px-4 py-8 text-center text-muted-foreground"
                                            >
                                                No payroll runs found.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <div data-payroll-mobile className="divide-y md:hidden">
                            {runs.data.map((run) => {
                                const config =
                                    statusConfig[run.status] ||
                                    statusConfig.draft;
                                return (
                                    <article
                                        key={run.id}
                                        className="space-y-3 p-4"
                                        onContextMenu={openRunContext(
                                            runContextItems(run),
                                        )}
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="font-semibold">
                                                    {formatDate(
                                                        run.period_start,
                                                    )}{' '}
                                                    -{' '}
                                                    {formatDate(run.period_end)}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {run.total_hours.toFixed(1)}
                                                    h · {run.items_count} items
                                                    ·{' '}
                                                    {formatDate(run.created_at)}
                                                </p>
                                            </div>
                                            <Badge
                                                variant="outline"
                                                className={config.className}
                                            >
                                                {config.label}
                                            </Badge>
                                        </div>
                                        {run.net_settlement ? (
                                            <p className="text-xs text-muted-foreground">
                                                Net pay:{' '}
                                                {run.net_settlement.status.replace(
                                                    '_',
                                                    ' ',
                                                )}
                                            </p>
                                        ) : null}
                                        <p className="text-lg font-semibold">
                                            {formatCurrency(run.total_gross)}
                                        </p>
                                        {run.validation_errors?.length ? (
                                            <p className="text-xs text-status-critical">
                                                {run.validation_errors[0]}
                                            </p>
                                        ) : null}
                                        {renderMobileRunActions(run)}
                                    </article>
                                );
                            })}
                            {runs.data.length === 0 ? (
                                <p className="p-8 text-center text-sm text-muted-foreground">
                                    No payroll runs found.
                                </p>
                            ) : null}
                        </div>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {runs.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing{' '}
                            {(runs.current_page - 1) * runs.per_page + 1} to{' '}
                            {Math.min(
                                runs.current_page * runs.per_page,
                                runs.total,
                            )}{' '}
                            of {runs.total} results
                        </p>
                        <LaravelPagination links={runs.links} />
                    </div>
                )}
            </PageLayout>
            {runContextElement}
        </AppLayout>
    );
}
