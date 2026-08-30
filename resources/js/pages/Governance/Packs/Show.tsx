import {
    GovernanceAttachmentsPanel,
    type GovernanceAttachment,
} from '@/components/governance/GovernanceAttachmentsPanel';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { download as downloadPack } from '@/routes/governance/packs';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    CheckCircle,
    Clock,
    FileDown,
    Files,
    FolderOpen,
    Paperclip,
    RotateCw,
    ShieldAlert,
    Users,
} from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props extends PageProps {
    pack: {
        id: number;
        generated_at: string;
        distributed_at: string | null;
        file_size: string | null;
        watermark_text: string;
        meeting: {
            title: string;
            scheduled_at: string;
        };
    };
    is_distributed: boolean;
    can_mark_read: boolean;
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

export default function PackShow({
    auth,
    pack,
    is_distributed,
    can_mark_read,
    manifestSections,
    contentSections,
    distributionStats,
    supplementaryAttachments,
}: Props) {
    const [distributing, setDistributing] = useState(false);
    const canManagePack = !!auth.can?.governance?.packs?.manage;

    useEffect(() => {
        if (can_mark_read) {
            void axios
                .post(`/governance/packs/${pack.id}/read`)
                .catch(() => undefined);
        }
    }, [can_mark_read, pack.id]);

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

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Packs', href: '/governance/meetings' },
                { title: 'Board Pack', href: `/governance/packs/${pack.id}` },
            ]}
        >
            <Head title="Board Pack" />

            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        backHref="/governance/packs"
                        icon={FolderOpen}
                        title={
                            <span
                                className="flex flex-wrap items-center gap-3"
                                dusk="pack-heading"
                            >
                                Board Pack
                                <Badge
                                    className={cn(
                                        'border text-xs uppercase',
                                        is_distributed
                                            ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                            : 'border-status-warning/30 bg-status-warning-bg text-status-warning',
                                    )}
                                >
                                    {is_distributed ? 'Distributed' : 'Draft'}
                                </Badge>
                            </span>
                        }
                        description={
                            <span className="flex flex-col gap-1">
                                <span className="font-medium">
                                    {pack.meeting.title}
                                </span>
                                <span className="text-xs">
                                    Generated{' '}
                                    {new Date(pack.generated_at).toLocaleString(
                                        'en-NZ',
                                        {
                                            timeZone: 'Pacific/Auckland',
                                        },
                                    )}
                                </span>
                            </span>
                        }
                        stats={[
                            {
                                label: 'Sections',
                                value: manifestSections.length,
                            },
                            {
                                label: 'Recipients',
                                value: distributionStats.intended_recipients,
                            },
                            {
                                label: 'Read',
                                value: distributionStats.read_count,
                            },
                            {
                                label: 'Downloads',
                                value: distributionStats.download_count,
                            },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                {canManagePack && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/governance/packs/${pack.id}/regenerate`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                        dusk="regenerate-pack"
                                    >
                                        <RotateCw className="mr-2 h-4 w-4" />
                                        Regenerate
                                    </Button>
                                )}
                                {canManagePack && !is_distributed && (
                                    <Button
                                        variant="outline"
                                        onClick={distributePack}
                                        disabled={distributing}
                                        dusk="distribute-pack"
                                    >
                                        <Users className="mr-2 h-4 w-4" />
                                        {distributing
                                            ? 'Distributing…'
                                            : 'Distribute pack'}
                                    </Button>
                                )}
                                <Button asChild>
                                    <Link
                                        href={downloadPack.url({
                                            pack: pack.id,
                                        })}
                                        dusk="download-pack"
                                    >
                                        <FileDown className="mr-2 h-4 w-4" />
                                        Download pack
                                    </Link>
                                </Button>
                            </div>
                        }
                    />
                }
            >
                <Card
                    className={cn(
                        is_distributed
                            ? 'border-status-success/30 bg-status-success-bg'
                            : 'border-status-warning/30 bg-status-warning-bg',
                    )}
                >
                    <CardContent className="flex items-start gap-3 pt-6">
                        {is_distributed ? (
                            <CheckCircle className="mt-0.5 h-6 w-6 text-status-success" />
                        ) : (
                            <Clock className="mt-0.5 h-6 w-6 text-status-warning" />
                        )}
                        <div className="space-y-1">
                            <p className="font-medium text-foreground">
                                {is_distributed
                                    ? 'Pack distributed'
                                    : 'Pack ready for distribution'}
                            </p>
                            <p className="text-sm text-foreground">
                                {is_distributed
                                    ? `Distributed ${new Date(pack.distributed_at!).toLocaleDateString('en-NZ', { timeZone: 'Pacific/Auckland' })}.`
                                    : 'Generate any final papers, then distribute to the board when ready.'}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <div className="my-6 grid gap-6 lg:grid-cols-[1.2fr,1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Users className="h-5 w-5" />
                                Distribution
                            </CardTitle>
                            <CardDescription>
                                Real pack engagement from distribution, read,
                                and download tracking.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
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
                                        <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                            {label}
                                        </p>
                                        <p className="mt-2 text-3xl font-bold text-foreground">
                                            {value}
                                        </p>
                                    </div>
                                ))}
                            </div>

                            <div className="space-y-3">
                                <div>
                                    <div className="mb-1 flex items-center justify-between text-sm text-muted-foreground">
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
                                    <div className="mb-1 flex items-center justify-between text-sm text-muted-foreground">
                                        <span>Download rate</span>
                                        <span>
                                            {distributionStats.download_rate}%
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

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Files className="h-5 w-5" />
                                Manifest
                            </CardTitle>
                            <CardDescription>
                                {manifestSections.length} included section(s)
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {manifestSections.map((section, index) => (
                                <div
                                    key={`${section.id}-${index}`}
                                    className="flex items-center justify-between rounded-lg border border-border px-3 py-3"
                                >
                                    <div className="flex items-center gap-3">
                                        <span className="text-xs text-muted-foreground">
                                            {index + 1}.
                                        </span>
                                        <div>
                                            <p className="font-medium text-foreground">
                                                {section.title}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {section.type}
                                            </p>
                                        </div>
                                    </div>
                                    {section.included && (
                                        <CheckCircle className="h-5 w-5 text-status-success" />
                                    )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>

                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Paperclip className="h-5 w-5" />
                            Supplementary documents
                            <span className="ml-1 text-sm font-normal text-muted-foreground">
                                ({supplementaryAttachments.length})
                            </span>
                        </CardTitle>
                        <CardDescription>
                            Manually-uploaded papers that travel with this pack
                            — legal opinions, external reports, late additions.
                            The auto-generated sections above are unaffected.
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
                        <CardTitle>Content Sections</CardTitle>
                        <CardDescription>
                            What this pack actually contains right now.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {contentSections.map((section) => (
                            <div
                                key={section.key}
                                className="rounded-lg border border-border p-4"
                            >
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <p className="font-medium text-foreground">
                                            {section.title}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {section.summary}
                                        </p>
                                    </div>
                                    <Badge variant="outline">
                                        {section.type}
                                    </Badge>
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card className="mt-6 border-status-critical/30">
                    <CardContent className="flex items-start gap-3 pt-6">
                        <ShieldAlert className="mt-0.5 h-5 w-5 text-status-critical" />
                        <div className="space-y-1">
                            <p className="font-medium text-status-critical">
                                Confidential — Board only
                            </p>
                            <p className="text-sm text-status-critical">
                                This pack is confidential governance material.
                                Watermark: {pack.watermark_text}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
