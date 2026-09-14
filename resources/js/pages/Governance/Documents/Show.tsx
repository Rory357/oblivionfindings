import { Head, router } from '@inertiajs/react';
import { Download, FileText, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
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
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatFileSize } from '@/components/ui/file-dropzone';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatDateTimeLong } from '@/lib/datetime';
import { PageProps } from '@/types';

import { documentTypeIcon } from './_dialogs';

interface Document {
    id: number;
    title: string;
    category: string;
    description: string | null;
    file_name: string;
    file_size: number;
    mime_type: string | null;
    version: number;
    is_current: boolean;
    uploaded_by: { id: number; name: string } | null;
    created_at: string | null;
    updated_at: string | null;
}

interface Props extends PageProps {
    document: Document;
}

function DetailItem({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <p className="text-caption">{label}</p>
            <div className="mt-0.5 text-sm">{children}</div>
        </div>
    );
}

export default function DocumentShow({ auth, document }: Props) {
    const canManage = Boolean(auth.can?.governance?.documents?.manage);
    const [confirmRemove, setConfirmRemove] = useState(false);
    const TypeIcon = documentTypeIcon(document.category);
    const typeLabel = document.category.replace(/_/g, ' ');
    const downloadHref = `/governance/documents/${document.id}/download`;

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Documents', href: '/governance/documents' },
                {
                    title: document.title,
                    href: `/governance/documents/${document.id}`,
                },
            ]}
        >
            <Head title={document.title} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/governance/documents"
                        icon={TypeIcon}
                        title={document.title}
                        wrapTitle
                        titleChip={
                            <PageHeaderStatusChip
                                variant={document.is_current ? 'success' : 'neutral'}
                            >
                                {document.is_current ? 'Current' : 'Archived'}
                            </PageHeaderStatusChip>
                        }
                        subline={`${typeLabel.charAt(0).toUpperCase()}${typeLabel.slice(1)} · Version ${document.version} · ${document.file_name}`}
                        actions={
                            <>
                                {canManage ? (
                                    <PageHeaderGlassButton
                                        icon={Trash2}
                                        onClick={() => setConfirmRemove(true)}
                                    >
                                        Remove
                                    </PageHeaderGlassButton>
                                ) : null}
                                <PageHeaderPrimaryButton
                                    icon={Download}
                                    onClick={() => {
                                        window.location.href = downloadHref;
                                    }}
                                >
                                    Download
                                </PageHeaderPrimaryButton>
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Document type"
                                    ariaLabel={`View ${typeLabel} documents`}
                                    href={`/governance/documents?document_type=${document.category}`}
                                >
                                    <PageHeaderMeterBig>
                                        <span className="capitalize">
                                            {typeLabel}
                                        </span>
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Version {document.version}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Last updated"
                                    ariaLabel="View recently updated documents"
                                    href="/governance/documents"
                                >
                                    <PageHeaderMeterBig>
                                        {formatDateLong(document.updated_at)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {document.uploaded_by
                                            ? `Uploaded by ${document.uploaded_by.name}`
                                            : 'Uploader not recorded'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="File"
                                    value={formatFileSize(document.file_size) || '—'}
                                    ariaLabel="View all documents"
                                    href="/governance/documents"
                                >
                                    <PageHeaderMeterBig>
                                        <span className="uppercase">
                                            {document.file_name.split('.').pop() ?? '—'}
                                        </span>
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {document.mime_type ?? 'Unknown format'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                    />
                }
            >
                <div className="grid gap-5 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileText className="h-4 w-4 text-primary" />
                                Document
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <DetailItem label="Filename">
                                <span className="font-mono">
                                    {document.file_name}
                                </span>
                            </DetailItem>
                            <DetailItem label="Description">
                                {document.description ? (
                                    <p className="whitespace-pre-wrap">
                                        {document.description}
                                    </p>
                                ) : (
                                    <span className="text-muted-foreground">
                                        No description recorded
                                    </span>
                                )}
                            </DetailItem>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <DetailItem label="Format">
                                    {document.mime_type ?? '—'}
                                </DetailItem>
                                <DetailItem label="Size">
                                    {formatFileSize(document.file_size) || '—'}
                                </DetailItem>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Metadata</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <DetailItem label="Uploaded by">
                                <span className="font-medium">
                                    {document.uploaded_by?.name ?? 'Unknown'}
                                </span>
                            </DetailItem>
                            <DetailItem label="Created">
                                {formatDateTimeLong(document.created_at)}
                            </DetailItem>
                            <DetailItem label="Last updated">
                                {formatDateTimeLong(document.updated_at)}
                            </DetailItem>
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>

            <ConfirmDialog
                open={confirmRemove}
                onClose={() => setConfirmRemove(false)}
                onConfirm={() =>
                    router.delete(`/governance/documents/${document.id}`)
                }
                title="Remove this document?"
                description={`“${document.title}” will be removed from the governance library.`}
                confirmText="Remove document"
            />
        </AppLayout>
    );
}
