import {
    GovernanceAttachmentsPanel,
    type GovernanceAttachment,
} from '@/components/governance/GovernanceAttachmentsPanel';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Progress } from '@/components/ui/progress';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { download as downloadPack } from '@/routes/governance/packs';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    AlertTriangle,
    BookOpen,
    CheckCircle,
    FileDown,
    Files,
    FolderOpen,
    History,
    Layers,
    Paperclip,
    RotateCw,
    ShieldAlert,
    Users,
} from 'lucide-react';
import { useState } from 'react';

interface PackRevisionSummary {
    id: number;
    revision_number: number;
    build_status: string;
    is_current: boolean;
    generated_at: string;
    distributed_at: string | null;
    file_size: string | null;
}

interface Props extends PageProps {
    pack: {
        id: number;
        revision_number: number;
        supersedes_id: number | null;
        build_status: string;
        error_reference: string | null;
        is_current: boolean;
        generated_at: string;
        distributed_at: string | null;
        file_size: string | null;
        checksum: string | null;
        watermark_text: string;
        meeting: {
            id: number;
            title: string;
            scheduled_at: string;
            location?: string;
            meeting_type?: string;
        };
        actual_document_count: number;
    };
    all_revisions?: PackRevisionSummary[];
    current_pack_id?: number;
    current_revision_number?: number;
    is_distributed: boolean;
    can_mark_read: boolean;
    has_read?: boolean;
    my_receipt?: {
        receipt_id: string;
        board_member_id: number;
        revision_number: number;
        read_at: string;
    } | null;
    read_count?: number;
    download_count?: number;
    manifestSections: Array<{
        id: string;
        title: string;
        type: string;
        included: boolean;
    }>;
    contentSections: Array<{
        key: string;
        title: string;
        summary: string;
        type: string;
    }>;
    distributionStats: {
        intended_recipients: number;
        read_count: number;
        download_count: number;
        outstanding_reads: number;
        read_rate: number;
        download_rate: number;
    };
    supplementaryAttachments: GovernanceAttachment[];
}

const scrollTo = (id: string) =>
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });

export default function PackShow({
    auth,
    pack,
    all_revisions = [],
    current_pack_id,
    current_revision_number,
    is_distributed,
    can_mark_read,
    has_read = false,
    my_receipt = null,
    manifestSections,
    contentSections,
    distributionStats,
    supplementaryAttachments,
}: Props) {
    const [distributing, setDistributing] = useState(false);
    const [acknowledging, setAcknowledging] = useState(false);
    const [acknowledgeError, setAcknowledgeError] = useState<string | null>(
        null,
    );
    const [regenerateDialogOpen, setRegenerateDialogOpen] = useState(false);
    const [regenerating, setRegenerating] = useState(false);

    const canManagePack = !!auth.can?.governance?.packs?.manage;
    const isCurrent = pack.is_current;
    const revisionNumber = pack.revision_number ?? 1;
    const isSuperseded =
        !isCurrent && (current_revision_number ?? 1) > revisionNumber;

    // Explicit manual read acknowledgement (NO automatic on-mount POST)
    const handleMarkAsRead = async () => {
        setAcknowledging(true);
        setAcknowledgeError(null);

        try {
            await axios.post(
                `/governance/packs/${pack.id}/read`,
                {},
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );
            router.reload({ preserveScroll: true });
        } catch (err: any) {
            const message =
                err?.response?.data?.message ||
                'Failed to record reading acknowledgement. Please try again.';
            setAcknowledgeError(message);
        } finally {
            setAcknowledging(false);
        }
    };

    const distributePack = () => {
        setDistributing(true);

        router.post(
            `/governance/packs/${pack.id}/distribute`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setDistributing(false),
            },
        );
    };

    const handleRegenerate = () => {
        setRegenerating(true);
        router.post(
            `/governance/packs/${pack.id}/regenerate`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setRegenerating(false);
                    setRegenerateDialogOpen(false);
                },
            },
        );
    };

    const formatNZDate = (iso: string | null | undefined) => {
        if (!iso) return '—';
        return new Date(iso).toLocaleString('en-NZ', {
            timeZone: 'Pacific/Auckland',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Board packs', href: '/governance/packs' },
                {
                    title: `${pack.meeting.title} · Rev ${revisionNumber}`,
                    href: `/governance/packs/${pack.id}`,
                },
            ]}
        >
            <Head title={`Board Pack - Rev ${revisionNumber}`} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/governance/packs"
                        icon={FolderOpen}
                        title={`Board Pack · Revision ${revisionNumber}`}
                        titleDusk="pack-heading"
                        titleChip={
                            <>
                                <PageHeaderStatusChip
                                    variant={isCurrent ? 'success' : 'neutral'}
                                >
                                    {isCurrent ? 'Current' : 'Superseded'}
                                </PageHeaderStatusChip>
                                <PageHeaderStatusChip
                                    variant={
                                        is_distributed ? 'success' : 'warning'
                                    }
                                >
                                    {is_distributed ? 'Distributed' : 'Draft'}
                                </PageHeaderStatusChip>
                                {pack.build_status === 'failed' && (
                                    <PageHeaderStatusChip variant="critical">
                                        Failed
                                    </PageHeaderStatusChip>
                                )}
                            </>
                        }
                        subline={`${pack.meeting.title} · Generated ${formatNZDate(pack.generated_at)}${pack.checksum ? ` · SHA-256: ${pack.checksum.slice(0, 12)}…` : ''}`}
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Revision"
                                    onClick={() => scrollTo('pack-versions')}
                                    ariaLabel="View pack version history"
                                >
                                    <PageHeaderMeterBig>
                                        v{revisionNumber}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {all_revisions.length > 1
                                            ? `of ${all_revisions.length} editions`
                                            : 'Edition'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Papers & docs"
                                    onClick={() => scrollTo('pack-sections')}
                                    ariaLabel="View included sections"
                                >
                                    <PageHeaderMeterBig>
                                        {pack.actual_document_count ??
                                            manifestSections.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Included items
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Read"
                                    value={`${distributionStats.read_rate}%`}
                                    onClick={() =>
                                        scrollTo('pack-distribution')
                                    }
                                    ariaLabel="View distribution and engagement"
                                    tone={
                                        is_distributed &&
                                        distributionStats.outstanding_reads > 0
                                            ? 'warning'
                                            : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {distributionStats.read_count}/
                                        {distributionStats.intended_recipients}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {distributionStats.outstanding_reads}{' '}
                                        outstanding
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Downloads"
                                    onClick={() =>
                                        scrollTo('pack-distribution')
                                    }
                                    ariaLabel="View download engagement"
                                >
                                    <PageHeaderMeterBig>
                                        {distributionStats.download_count}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        PDF retrievals
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        actions={
                            <>
                                <PageHeaderGlassButton
                                    icon={FileDown}
                                    onClick={() => {
                                        window.location.href = downloadPack.url(
                                            { pack: pack.id },
                                        );
                                    }}
                                    dusk="download-pack"
                                >
                                    Download PDF
                                </PageHeaderGlassButton>

                                {canManagePack && (
                                    <PageHeaderGlassButton
                                        icon={RotateCw}
                                        onClick={() =>
                                            setRegenerateDialogOpen(true)
                                        }
                                        dusk="regenerate-pack"
                                    >
                                        Create new version
                                    </PageHeaderGlassButton>
                                )}

                                {canManagePack && !is_distributed && (
                                    <PageHeaderGlassButton
                                        icon={Users}
                                        onClick={distributePack}
                                        disabled={distributing}
                                        dusk="distribute-pack"
                                    >
                                        {distributing
                                            ? 'Distributing…'
                                            : 'Distribute pack'}
                                    </PageHeaderGlassButton>
                                )}

                                {can_mark_read && (
                                    <PageHeaderPrimaryButton
                                        icon={BookOpen}
                                        onClick={handleMarkAsRead}
                                        disabled={acknowledging}
                                        dusk="mark-read-button"
                                    >
                                        {acknowledging
                                            ? 'Confirming…'
                                            : `Mark Rev ${revisionNumber} as read`}
                                    </PageHeaderPrimaryButton>
                                )}
                            </>
                        }
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    {/* Notice if viewing a superseded version */}
                    {isSuperseded && current_pack_id && (
                        <Card className="border-status-warning/40 bg-status-warning-bg">
                            <CardContent className="flex items-center justify-between gap-4 pt-6">
                                <div className="flex items-start gap-3">
                                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-status-warning" />
                                    <div>
                                        <p className="font-medium text-foreground">
                                            You are viewing Revision{' '}
                                            {revisionNumber} (Superseded)
                                        </p>
                                        <p className="text-subtle">
                                            A newer version (Revision{' '}
                                            {current_revision_number}) has been
                                            published for this meeting.
                                        </p>
                                    </div>
                                </div>
                                <Button asChild size="sm">
                                    <Link
                                        href={`/governance/packs/${current_pack_id}`}
                                    >
                                        View current version (v
                                        {current_revision_number})
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>
                    )}

                    {/* Build failure notice */}
                    {pack.build_status === 'failed' && (
                        <Card className="border-status-critical/40 bg-status-critical-bg">
                            <CardContent className="flex items-start gap-3 pt-6">
                                <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-status-critical" />
                                <div className="space-y-1">
                                    <p className="font-medium text-status-critical">
                                        Pack generation failed
                                    </p>
                                    <p className="text-subtle">
                                        {pack.error_reference ||
                                            'An unexpected error occurred during PDF generation.'}
                                    </p>
                                    {canManagePack && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="mt-2"
                                            onClick={() =>
                                                setRegenerateDialogOpen(true)
                                            }
                                        >
                                            Retry generation
                                        </Button>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Personal Reading Acknowledgement Receipt */}
                    {has_read && my_receipt && (
                        <Card className="border-status-success/30 bg-status-success-bg">
                            <CardContent className="flex items-center gap-3 pt-6">
                                <CheckCircle className="h-5 w-5 shrink-0 text-status-success" />
                                <div>
                                    <p className="font-medium text-foreground">
                                        Reading acknowledged
                                    </p>
                                    <p className="text-subtle">
                                        You acknowledged reading Revision{' '}
                                        {revisionNumber} on{' '}
                                        {formatNZDate(my_receipt.read_at)}{' '}
                                        (Receipt #
                                        {my_receipt.receipt_id.slice(0, 8)}).
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Explicit Read Action Card when eligible and unacknowledged */}
                    {can_mark_read && (
                        <Card className="border-primary/40 bg-primary/5">
                            <CardContent className="flex flex-col justify-between gap-4 pt-6 sm:flex-row sm:items-center">
                                <div className="flex items-start gap-3">
                                    <BookOpen className="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                                    <div>
                                        <p className="font-medium text-foreground">
                                            Board member reading required
                                        </p>
                                        <p className="text-subtle">
                                            Please review the papers and confirm
                                            reading for Revision{' '}
                                            {revisionNumber}. Note: Downloading
                                            the PDF does not mark the pack as
                                            read.
                                        </p>
                                        {acknowledgeError && (
                                            <p className="mt-1 text-sm font-medium text-status-critical">
                                                {acknowledgeError}
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <Button
                                    onClick={handleMarkAsRead}
                                    disabled={acknowledging}
                                    dusk="hero-mark-read"
                                    className="shrink-0"
                                >
                                    <CheckCircle className="mr-2 h-4 w-4" />
                                    {acknowledging
                                        ? 'Confirming…'
                                        : `Mark Version ${revisionNumber} as Read`}
                                </Button>
                            </CardContent>
                        </Card>
                    )}

                    {/* Revisions line-up / history */}
                    {all_revisions.length > 1 && (
                        <Card id="pack-versions" className="scroll-mt-5">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-section-title flex items-center gap-2">
                                    <History className="h-4 w-4 text-primary" />
                                    Pack version history
                                </CardTitle>
                                <CardDescription>
                                    Immutable revisions published for this
                                    meeting.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="divide-y divide-border">
                                    {all_revisions.map((rev) => (
                                        <div
                                            key={rev.id}
                                            className="flex items-center justify-between py-2.5 text-sm"
                                        >
                                            <div className="flex items-center gap-2.5">
                                                <Badge
                                                    variant={
                                                        rev.id === pack.id
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    className="text-xs"
                                                >
                                                    v{rev.revision_number}
                                                </Badge>
                                                {rev.is_current && (
                                                    <StatusBadge
                                                        size="sm"
                                                        variant="success"
                                                    >
                                                        Current
                                                    </StatusBadge>
                                                )}
                                                {rev.build_status ===
                                                    'failed' && (
                                                    <StatusBadge
                                                        size="sm"
                                                        variant="critical"
                                                    >
                                                        Failed
                                                    </StatusBadge>
                                                )}
                                                <span className="text-caption">
                                                    Generated{' '}
                                                    {formatNZDate(
                                                        rev.generated_at,
                                                    )}
                                                </span>
                                            </div>
                                            {rev.id === pack.id ? (
                                                <span className="text-xs font-semibold text-primary">
                                                    Viewing
                                                </span>
                                            ) : (
                                                <Button
                                                    asChild
                                                    variant="ghost"
                                                    size="sm"
                                                >
                                                    <Link
                                                        href={`/governance/packs/${rev.id}`}
                                                    >
                                                        View version
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    <div className="grid gap-5 lg:grid-cols-[1.2fr_1fr]">
                        <Card id="pack-distribution" className="scroll-mt-5">
                            <CardHeader>
                                <CardTitle className="text-section-title flex items-center gap-2">
                                    <Users className="h-4 w-4" />
                                    Distribution & engagement
                                </CardTitle>
                                <CardDescription>
                                    Engagement tracking for Revision{' '}
                                    {revisionNumber}.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                                    {[
                                        [
                                            'Recipients',
                                            distributionStats.intended_recipients,
                                        ],
                                        ['Read', distributionStats.read_count],
                                        [
                                            'Downloads',
                                            distributionStats.download_count,
                                        ],
                                        [
                                            'Outstanding',
                                            distributionStats.outstanding_reads,
                                        ],
                                    ].map(([label, value]) => (
                                        <div
                                            key={label}
                                            className="rounded-lg bg-muted p-4 text-center"
                                        >
                                            <p className="text-caption tracking-wide uppercase">
                                                {label}
                                            </p>
                                            <p className="mt-2 text-lg font-semibold text-foreground tabular-nums">
                                                {value}
                                            </p>
                                        </div>
                                    ))}
                                </div>

                                <div className="space-y-3">
                                    <div>
                                        <div className="text-subtle mb-1 flex items-center justify-between">
                                            <span>Read rate</span>
                                            <span>
                                                {distributionStats.read_rate}%
                                            </span>
                                        </div>
                                        <Progress
                                            value={distributionStats.read_rate}
                                        />
                                    </div>
                                    <div>
                                        <div className="text-subtle mb-1 flex items-center justify-between">
                                            <span>Download rate</span>
                                            <span>
                                                {
                                                    distributionStats.download_rate
                                                }
                                                %
                                            </span>
                                        </div>
                                        <Progress
                                            value={Math.min(
                                                distributionStats.download_rate,
                                                100,
                                            )}
                                        />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card id="pack-sections" className="scroll-mt-5">
                            <CardHeader>
                                <CardTitle className="text-section-title flex items-center gap-2">
                                    <Files className="h-4 w-4" />
                                    Included sections
                                </CardTitle>
                                <CardDescription>
                                    {manifestSections.length} structural
                                    section(s)
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {manifestSections.length === 0 ? (
                                    <EmptyState
                                        variant="compact"
                                        icon={Files}
                                        title="No sections recorded"
                                        description="This revision has no structural sections in its manifest."
                                    />
                                ) : (
                                    manifestSections.map((section, index) => (
                                        <div
                                            key={`${section.id}-${index}`}
                                            className="flex items-center justify-between rounded-lg border border-border px-3 py-3"
                                        >
                                            <div className="flex items-center gap-3">
                                                <span className="text-caption">
                                                    {index + 1}.
                                                </span>
                                                <div>
                                                    <p className="font-medium text-foreground">
                                                        {section.title}
                                                    </p>
                                                    <p className="text-caption">
                                                        {section.type}
                                                    </p>
                                                </div>
                                            </div>
                                            {section.included && (
                                                <CheckCircle
                                                    className="h-5 w-5 text-status-success"
                                                    aria-label="Included"
                                                />
                                            )}
                                        </div>
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-section-title flex items-center gap-2">
                                <Paperclip className="h-4 w-4" />
                                Supplementary documents
                                <span className="text-caption ml-1 font-normal">
                                    ({supplementaryAttachments.length})
                                </span>
                            </CardTitle>
                            <CardDescription>
                                Manually-uploaded papers that travel with this
                                pack — legal opinions, external reports, late
                                additions. The auto-generated sections above
                                are unaffected.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <GovernanceAttachmentsPanel
                                canManage={canManagePack}
                                attachments={supplementaryAttachments}
                                urls={{
                                    upload: `/governance/packs/${pack.id}/attachments`,
                                    delete: (id) =>
                                        `/governance/packs/${pack.id}/attachments/${id}`,
                                }}
                                reloadProp="supplementaryAttachments"
                                helperText="PDF, Office, images, CSV / TXT — up to 20 MB each. These do not change the audit checksum."
                                emptyText={{
                                    managed:
                                        'No supplementary documents yet. Drop files above to attach one.',
                                    readOnly:
                                        'No supplementary documents are attached to this pack.',
                                }}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-section-title flex items-center gap-2">
                                <Layers className="h-4 w-4" />
                                Content sections & decision papers
                            </CardTitle>
                            <CardDescription>
                                Frozen snapshot data captured for Revision{' '}
                                {revisionNumber}.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {contentSections.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={Layers}
                                    title="No content sections"
                                    description="No snapshot content was captured for this revision."
                                />
                            ) : (
                                contentSections.map((section) => (
                                    <div
                                        key={section.key}
                                        className="rounded-lg border border-border p-4"
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <div>
                                                <p className="font-medium text-foreground">
                                                    {section.title}
                                                </p>
                                                <p className="text-subtle">
                                                    {section.summary}
                                                </p>
                                            </div>
                                            <Badge variant="outline">
                                                {section.type}
                                            </Badge>
                                        </div>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card className="border-status-critical/30">
                        <CardContent className="flex items-start gap-3 pt-6">
                            <ShieldAlert className="mt-0.5 h-5 w-5 text-status-critical" />
                            <div className="space-y-1">
                                <p className="font-medium text-status-critical">
                                    Confidential — Board only
                                </p>
                                <p className="text-sm text-status-critical">
                                    This pack is confidential governance
                                    material. Watermark: {pack.watermark_text}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>

            {/* Create New Version Dialog */}
            <Dialog
                open={regenerateDialogOpen}
                onOpenChange={setRegenerateDialogOpen}
            >
                <DialogContent style={{ maxWidth: 'min(92vw, 480px)' }}>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <RotateCw className="h-4 w-4 text-primary" />
                            Create new board pack revision
                        </DialogTitle>
                        <DialogDescription>
                            Generate Revision {revisionNumber + 1} for this
                            meeting.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="text-subtle space-y-3 py-3">
                        <p>
                            A new immutable revision will be generated with
                            updated dashboard metrics, CEO reports, and decision
                            paper snapshots.
                        </p>
                        <div className="space-y-1.5 rounded-md border border-border bg-muted/40 p-3 text-xs">
                            <p className="font-semibold text-foreground">
                                Version management rules:
                            </p>
                            <ul className="list-inside list-disc space-y-1">
                                <li>
                                    Current Revision {revisionNumber} remains
                                    preserved and accessible as superseded.
                                </li>
                                <li>
                                    New Revision {revisionNumber + 1} will
                                    become the active version for members.
                                </li>
                                <li>
                                    Board members will be required to
                                    acknowledge reading the new revision.
                                </li>
                            </ul>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setRegenerateDialogOpen(false)}
                            disabled={regenerating}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={handleRegenerate}
                            disabled={regenerating}
                        >
                            {regenerating
                                ? 'Generating…'
                                : `Generate Revision ${revisionNumber + 1}`}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
