import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    GovernanceAttachmentsPanel,
    type GovernanceAttachment,
} from '@/components/governance/GovernanceAttachmentsPanel';
import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { StatusBadge } from '@/components/ui/status-badge';
import { InfoCard } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatDateTimeLong } from '@/lib/datetime';
import { governanceStatus, refSuffix } from '@/lib/governance-labels';
import { download as downloadPack } from '@/routes/governance/packs';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    ArrowRight,
    BookOpen,
    CalendarDays,
    CheckCircle,
    FileDown,
    FolderOpen,
    History,
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

interface ReadingItem {
    title: string;
    reference: string | null;
    href: string | null;
}

export interface ReadingSection {
    key: string;
    title: string;
    summary: string;
    href: string | null;
    items: ReadingItem[];
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
    newer_version?: {
        id: number;
        revision_number: number;
        is_distributed: boolean;
    } | null;
    is_distributed: boolean;
    can_manage?: boolean;
    can_mark_read: boolean;
    is_recipient?: boolean;
    has_read?: boolean;
    my_receipt?: {
        receipt_id: string;
        board_member_id: number;
        revision_number: number;
        read_at: string;
    } | null;
    meeting_url?: string | null;
    readingSections?: ReadingSection[];
    distributionStats: {
        intended_recipients: number;
        read_count: number;
        download_count: number;
        outstanding_reads: number;
        read_rate: number;
        download_rate: number;
    } | null;
    distribution_recipient_count?: number | null;
    supplementaryAttachments: GovernanceAttachment[];
}

const scrollTo = (id: string) =>
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });

/** One header chip: the most useful state of THIS version for the viewer. */
export function packHeaderChip(
    buildStatus: string,
    isDistributed: boolean,
    newerVersion: Props['newer_version'],
) {
    if (buildStatus === 'failed') {
        return governanceStatus('board_pack_status', 'failed');
    }
    if (newerVersion?.is_distributed) {
        return governanceStatus('board_pack_status', 'superseded');
    }
    return governanceStatus(
        'board_pack_status',
        isDistributed ? 'distributed' : 'draft',
    );
}

export default function PackShow({
    auth,
    pack,
    all_revisions = [],
    newer_version = null,
    is_distributed,
    can_manage,
    can_mark_read,
    is_recipient = false,
    has_read = false,
    my_receipt = null,
    meeting_url = null,
    readingSections = [],
    distributionStats,
    distribution_recipient_count = null,
    supplementaryAttachments,
}: Props) {
    const [confirm, setConfirm] = useState<
        'read' | 'distribute' | 'new-version' | null
    >(null);
    const [acknowledging, setAcknowledging] = useState(false);
    const [acknowledgeError, setAcknowledgeError] = useState<string | null>(
        null,
    );
    const [busy, setBusy] = useState(false);

    const canManagePack =
        can_manage ?? Boolean(auth.can?.governance?.packs?.manage);
    const version = pack.revision_number ?? 1;
    const nextVersion = version + 1;
    const meetingTitle = pack.meeting.title;
    const title = `${meetingTitle} — board pack`;
    const failed = pack.build_status === 'failed';
    const chip = packHeaderChip(pack.build_status, is_distributed, newer_version);
    const downloadUrl = downloadPack.url({ pack: pack.id });
    const recipients = distribution_recipient_count ?? 0;
    const canSend = canManagePack && !is_distributed && !failed;

    const markAsRead = async () => {
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
        } catch (err: unknown) {
            const message = (
                err as { response?: { data?: { message?: string } } }
            )?.response?.data?.message;
            setAcknowledgeError(
                message ??
                    "We couldn't save that you've read the pack. Try again.",
            );
        } finally {
            setAcknowledging(false);
        }
    };

    const distribute = () => {
        setBusy(true);
        router.post(
            `/governance/packs/${pack.id}/distribute`,
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const createNewVersion = () => {
        setBusy(true);
        router.post(
            `/governance/packs/${pack.id}/regenerate`,
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const hasMultipleVersions = all_revisions.length > 1;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Board packs', href: '/governance/packs' },
                { title, href: `/governance/packs/${pack.id}` },
            ]}
        >
            <Head title={title} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/governance/packs"
                        icon={FolderOpen}
                        title={title}
                        titleDusk="pack-heading"
                        wrapTitle
                        titleChip={
                            <PageHeaderStatusChip variant={chip.variant}>
                                {chip.label}
                            </PageHeaderStatusChip>
                        }
                        subline={[
                            pack.meeting.scheduled_at
                                ? `Meeting on ${formatDateTimeLong(pack.meeting.scheduled_at)}`
                                : null,
                            `Version ${version}`,
                            pack.generated_at
                                ? `Generated ${formatDateLong(pack.generated_at)}`
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                        actions={
                            <>
                                {meeting_url ? (
                                    <PageHeaderGlassButton
                                        icon={CalendarDays}
                                        onClick={() => router.visit(meeting_url)}
                                    >
                                        Go to meeting
                                    </PageHeaderGlassButton>
                                ) : null}
                                {canManagePack ? (
                                    <PageHeaderGlassButton
                                        icon={RotateCw}
                                        onClick={() => setConfirm('new-version')}
                                        dusk="regenerate-pack"
                                    >
                                        Create new version
                                    </PageHeaderGlassButton>
                                ) : null}
                                {canSend ? (
                                    <>
                                        <PageHeaderGlassButton
                                            icon={FileDown}
                                            onClick={() => {
                                                window.location.href =
                                                    downloadUrl;
                                            }}
                                            dusk="download-pack"
                                        >
                                            Download pack
                                        </PageHeaderGlassButton>
                                        <PageHeaderPrimaryButton
                                            icon={Users}
                                            onClick={() =>
                                                setConfirm('distribute')
                                            }
                                            disabled={busy}
                                            dusk="distribute-pack"
                                        >
                                            Send to members
                                        </PageHeaderPrimaryButton>
                                    </>
                                ) : !failed ? (
                                    <PageHeaderPrimaryButton
                                        icon={FileDown}
                                        onClick={() => {
                                            window.location.href = downloadUrl;
                                        }}
                                        dusk="download-pack"
                                    >
                                        Download pack
                                    </PageHeaderPrimaryButton>
                                ) : null}
                            </>
                        }
                        meters={
                            <>
                                {is_recipient ? (
                                    <PageHeaderMeterBlock
                                        label="Your reading"
                                        tone={has_read ? 'success' : 'warning'}
                                        onClick={() => scrollTo('pack-reading')}
                                        ariaLabel="Confirm you've read the pack"
                                    >
                                        <PageHeaderMeterBig>
                                            {has_read ? 'Read' : 'Not read yet'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {has_read && my_receipt
                                                ? `Confirmed ${formatDateLong(my_receipt.read_at)}`
                                                : "Confirm once you've read it"}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                                {canManagePack && distributionStats ? (
                                    <PageHeaderMeterBlock
                                        label="Read by"
                                        value={
                                            is_distributed
                                                ? `${distributionStats.read_count}/${distributionStats.intended_recipients}`
                                                : undefined
                                        }
                                        onClick={() =>
                                            scrollTo('pack-distribution')
                                        }
                                        ariaLabel="View who has read the pack"
                                    >
                                        {is_distributed ? (
                                            <PageHeaderMeterBar
                                                percent={distributionStats.read_rate}
                                            />
                                        ) : (
                                            <PageHeaderMeterBig>
                                                Not sent
                                            </PageHeaderMeterBig>
                                        )}
                                        <PageHeaderMeterCaption>
                                            {is_distributed
                                                ? `${distributionStats.outstanding_reads} not read yet`
                                                : `Goes to ${recipients} board member${recipients === 1 ? '' : 's'}`}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                                <PageHeaderMeterBlock
                                    label="Papers"
                                    onClick={() => scrollTo('pack-contents')}
                                    ariaLabel="View what's in the pack"
                                >
                                    <PageHeaderMeterBig>
                                        {pack.actual_document_count}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Reports, resolutions and documents
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Version"
                                    onClick={() =>
                                        scrollTo(
                                            hasMultipleVersions
                                                ? 'pack-versions'
                                                : 'pack-contents',
                                        )
                                    }
                                    ariaLabel="View the pack's versions"
                                >
                                    <PageHeaderMeterBig>
                                        Version {version}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {newer_version?.is_distributed
                                            ? `Version ${newer_version.revision_number} is newer`
                                            : hasMultipleVersions
                                              ? `${all_revisions.length} versions`
                                              : 'The only version'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                {meeting_url ? (
                                    <PageHeaderMeterBlock
                                        label="Meeting"
                                        href={meeting_url}
                                        ariaLabel="Go to the meeting"
                                    >
                                        <PageHeaderMeterBig>
                                            {formatDateLong(
                                                pack.meeting.scheduled_at,
                                            )}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {meetingTitle}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                            </>
                        }
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    {newer_version &&
                    (newer_version.is_distributed || canManagePack) ? (
                        <Card>
                            <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
                                <div className="flex items-start gap-3">
                                    <History className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" />
                                    <div>
                                        <p className="font-medium text-foreground">
                                            {newer_version.is_distributed
                                                ? `There's a newer version of this pack`
                                                : `Version ${newer_version.revision_number} is a draft`}
                                        </p>
                                        <p className="text-subtle">
                                            {newer_version.is_distributed
                                                ? `You're looking at version ${version}. Version ${newer_version.revision_number} has been sent to members.`
                                                : `Members keep seeing version ${version} until you send version ${newer_version.revision_number} to them.`}
                                        </p>
                                    </div>
                                </div>
                                <Button asChild size="sm" variant="outline">
                                    <Link
                                        href={`/governance/packs/${newer_version.id}`}
                                    >
                                        Open version{' '}
                                        {newer_version.revision_number}
                                        <ArrowRight className="h-3.5 w-3.5" />
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>
                    ) : null}

                    {failed ? (
                        <InfoCard icon={AlertCircle} tone="crit">
                            <p className="font-medium">
                                This version couldn&apos;t be prepared
                            </p>
                            <p className="text-subtle">
                                {canManagePack
                                    ? 'Nothing was sent to members. Create a new version to try again.'
                                    : 'Ask the board secretary to create a new version.'}
                            </p>
                            {canManagePack ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="mt-2"
                                    onClick={() => setConfirm('new-version')}
                                >
                                    Create a new version
                                </Button>
                            ) : null}
                        </InfoCard>
                    ) : null}

                    <div className="grid items-start gap-5 lg:grid-cols-3">
                        <div className="flex flex-col gap-5 lg:col-span-2">
                            <Card id="pack-contents" className="scroll-mt-5">
                                <CardHeader>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <CardTitle className="text-section-title flex items-center gap-2">
                                                <BookOpen className="h-4 w-4 text-primary" />
                                                Read the pack
                                            </CardTitle>
                                            <CardDescription>
                                                Download the whole pack, or open
                                                each paper on its own page.
                                            </CardDescription>
                                        </div>
                                        {!failed ? (
                                            <Button asChild>
                                                <a href={downloadUrl}>
                                                    <FileDown className="h-4 w-4" />
                                                    Download pack
                                                </a>
                                            </Button>
                                        ) : null}
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    {readingSections.length === 0 ? (
                                        <p className="text-subtle">
                                            This version has no sections to list.
                                            Download the pack to read it.
                                        </p>
                                    ) : (
                                        <ol className="divide-y divide-border">
                                            {readingSections.map((section) => (
                                                <li
                                                    key={section.key}
                                                    className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0"
                                                >
                                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                                        <div className="min-w-0">
                                                            <p className="font-medium text-foreground">
                                                                {section.title}
                                                            </p>
                                                            <p className="text-caption">
                                                                {section.summary}
                                                            </p>
                                                        </div>
                                                        {section.href ? (
                                                            <Button
                                                                asChild
                                                                size="sm"
                                                                variant="outline"
                                                            >
                                                                <Link
                                                                    href={
                                                                        section.href
                                                                    }
                                                                >
                                                                    Open{' '}
                                                                    {section.title}
                                                                    <ArrowRight className="h-3.5 w-3.5" />
                                                                </Link>
                                                            </Button>
                                                        ) : section.items
                                                              .length === 0 ? (
                                                            <span className="text-caption">
                                                                In the download
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                    {section.items.length > 0 ? (
                                                        <ul className="flex flex-col gap-1.5 pl-3">
                                                            {section.items.map(
                                                                (item, index) => (
                                                                    <li
                                                                        key={`${section.key}-${index}`}
                                                                        className="flex flex-wrap items-center justify-between gap-2 text-sm"
                                                                    >
                                                                        <span className="min-w-0">
                                                                            {item.href ? (
                                                                                <Link
                                                                                    href={
                                                                                        item.href
                                                                                    }
                                                                                    className="font-medium text-primary underline-offset-4 hover:underline"
                                                                                >
                                                                                    {
                                                                                        item.title
                                                                                    }
                                                                                </Link>
                                                                            ) : (
                                                                                <span>
                                                                                    {
                                                                                        item.title
                                                                                    }
                                                                                </span>
                                                                            )}
                                                                            {item.reference ? (
                                                                                <span className="text-caption ml-2">
                                                                                    {refSuffix(
                                                                                        item.reference,
                                                                                    )}
                                                                                </span>
                                                                            ) : null}
                                                                        </span>
                                                                        {!item.href ? (
                                                                            <span className="text-caption">
                                                                                In the download
                                                                            </span>
                                                                        ) : null}
                                                                    </li>
                                                                ),
                                                            )}
                                                        </ul>
                                                    ) : null}
                                                </li>
                                            ))}
                                        </ol>
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-section-title flex items-center gap-2">
                                        <Paperclip className="h-4 w-4 text-primary" />
                                        Extra documents
                                        <span className="text-caption font-normal">
                                            ({supplementaryAttachments.length})
                                        </span>
                                    </CardTitle>
                                    <CardDescription>
                                        Files added to this pack by hand, such
                                        as a legal opinion or a late report.
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
                                        helperText="PDF, Office, images, CSV or text — up to 20 MB each."
                                        emptyText={{
                                            managed:
                                                'No extra documents yet. Drop files above to add one.',
                                            readOnly:
                                                'No extra documents were added to this pack.',
                                        }}
                                    />
                                </CardContent>
                            </Card>
                        </div>

                        <div className="flex flex-col gap-5">
                            {is_recipient ? (
                                <Card id="pack-reading" className="scroll-mt-5">
                                    <CardHeader>
                                        <CardTitle className="text-section-title flex items-center gap-2">
                                            <CheckCircle className="h-4 w-4 text-primary" />
                                            Your reading
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="flex flex-col gap-3">
                                        {has_read && my_receipt ? (
                                            <p className="text-sm">
                                                You confirmed you read version{' '}
                                                {my_receipt.revision_number} on{' '}
                                                {formatDateLong(my_receipt.read_at)}.
                                            </p>
                                        ) : can_mark_read ? (
                                            <>
                                                <p className="text-subtle">
                                                    When you&apos;ve read the
                                                    pack, confirm it here so the
                                                    board secretary knows.
                                                    Downloading the pack
                                                    doesn&apos;t count as
                                                    reading it.
                                                </p>
                                                <Button
                                                    onClick={() =>
                                                        setConfirm('read')
                                                    }
                                                    disabled={acknowledging}
                                                    dusk="mark-read-button"
                                                >
                                                    <CheckCircle className="h-4 w-4" />
                                                    {acknowledging
                                                        ? 'Saving…'
                                                        : "I've read this pack"}
                                                </Button>
                                                {acknowledgeError ? (
                                                    <p
                                                        role="alert"
                                                        className="text-sm text-status-critical"
                                                    >
                                                        {acknowledgeError}
                                                    </p>
                                                ) : null}
                                            </>
                                        ) : (
                                            <p className="text-subtle">
                                                You can confirm reading once the
                                                pack has been sent to you.
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            ) : null}

                            {canManagePack && distributionStats ? (
                                <Card id="pack-distribution" className="scroll-mt-5">
                                    <CardHeader>
                                        <CardTitle className="text-section-title flex items-center gap-2">
                                            <Users className="h-4 w-4 text-primary" />
                                            Who has read it
                                        </CardTitle>
                                        <CardDescription>
                                            Only people who manage board packs
                                            see this.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="flex flex-col gap-3">
                                        {is_distributed ? (
                                            <>
                                                <dl className="grid grid-cols-2 gap-3 text-sm">
                                                    <div>
                                                        <dt className="text-caption">
                                                            Sent to
                                                        </dt>
                                                        <dd className="font-medium tabular-nums">
                                                            {
                                                                distributionStats.intended_recipients
                                                            }
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-caption">
                                                            Read
                                                        </dt>
                                                        <dd className="font-medium tabular-nums">
                                                            {
                                                                distributionStats.read_count
                                                            }
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-caption">
                                                            Not read yet
                                                        </dt>
                                                        <dd className="font-medium tabular-nums">
                                                            {
                                                                distributionStats.outstanding_reads
                                                            }
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-caption">
                                                            Downloads
                                                        </dt>
                                                        <dd className="font-medium tabular-nums">
                                                            {
                                                                distributionStats.download_count
                                                            }
                                                        </dd>
                                                    </div>
                                                </dl>
                                                <Progress
                                                    value={
                                                        distributionStats.read_rate
                                                    }
                                                    aria-label="Share of members who have read the pack"
                                                />
                                            </>
                                        ) : (
                                            <p className="text-subtle">
                                                Not sent yet.{' '}
                                                {failed
                                                    ? ''
                                                    : `When you send it, ${recipients} board member${recipients === 1 ? '' : 's'} will get it.`}
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            ) : null}

                            {hasMultipleVersions ? (
                                <Card id="pack-versions" className="scroll-mt-5">
                                    <CardHeader>
                                        <CardTitle className="text-section-title flex items-center gap-2">
                                            <History className="h-4 w-4 text-primary" />
                                            Versions
                                        </CardTitle>
                                        <CardDescription>
                                            Earlier versions stay available.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        <ul className="divide-y divide-border">
                                            {all_revisions.map((rev) => {
                                                const revChip =
                                                    rev.build_status === 'failed'
                                                        ? governanceStatus(
                                                              'board_pack_status',
                                                              'failed',
                                                          )
                                                        : governanceStatus(
                                                              'board_pack_status',
                                                              rev.distributed_at
                                                                  ? 'distributed'
                                                                  : 'draft',
                                                          );
                                                return (
                                                    <li
                                                        key={rev.id}
                                                        className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm"
                                                    >
                                                        <span className="flex flex-col">
                                                            <span className="font-medium">
                                                                Version{' '}
                                                                {rev.revision_number}
                                                            </span>
                                                            <span className="text-caption">
                                                                Generated{' '}
                                                                {formatDateLong(
                                                                    rev.generated_at,
                                                                )}
                                                            </span>
                                                        </span>
                                                        <span className="flex items-center gap-2">
                                                            <StatusBadge
                                                                size="sm"
                                                                variant={
                                                                    revChip.variant
                                                                }
                                                            >
                                                                {revChip.label}
                                                            </StatusBadge>
                                                            {rev.id === pack.id ? (
                                                                <span className="text-caption">
                                                                    You&apos;re
                                                                    here
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
                                                                        Open
                                                                    </Link>
                                                                </Button>
                                                            )}
                                                        </span>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    </CardContent>
                                </Card>
                            ) : null}

                            <InfoCard icon={ShieldAlert} tone="warn">
                                <span className="inline-flex items-center gap-1 font-medium">
                                    Confidential — board only
                                    <GovernanceTermHint term="board_pack" />
                                </span>
                                <br />
                                Please don&apos;t share this pack outside the
                                board.
                            </InfoCard>
                        </div>
                    </div>
                </div>
            </PageLayout>

            <ConfirmDialog
                open={confirm === 'read'}
                onClose={() => setConfirm(null)}
                onConfirm={() => void markAsRead()}
                title="Confirm you've read the pack?"
                description={`Confirm you've read the pack for ${meetingTitle} (version ${version}). The board secretary can see who has confirmed.`}
                confirmText="I've read this pack"
                variant="default"
            />
            <ConfirmDialog
                open={confirm === 'distribute'}
                onClose={() => setConfirm(null)}
                onConfirm={distribute}
                title={`Send the pack to ${recipients} board member${recipients === 1 ? '' : 's'}?`}
                description={`Each of them gets an email and a notification with a link to version ${version} of the pack for ${meetingTitle}. Once it's sent, it can't be unsent — to change it, create a new version.`}
                confirmText="Send the pack"
                variant="default"
            />
            <ConfirmDialog
                open={confirm === 'new-version'}
                onClose={() => setConfirm(null)}
                onConfirm={createNewVersion}
                title={`Create version ${nextVersion}?`}
                description={
                    is_distributed
                        ? `Version ${nextVersion} is created as a draft with up-to-date reports, figures and resolutions. Members keep seeing version ${version} until you send version ${nextVersion} to them.`
                        : `Version ${nextVersion} is created as a draft with up-to-date reports, figures and resolutions. Version ${version} stays available. Nothing is sent to members.`
                }
                confirmText={`Create version ${nextVersion}`}
                variant="default"
            />
        </AppLayout>
    );
}
