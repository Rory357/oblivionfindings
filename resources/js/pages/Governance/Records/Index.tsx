import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    BookOpen,
    Calendar,
    ChevronRight,
    Download,
    FileCheck,
    FileIcon,
    FileText,
    FolderArchive,
    Gavel,
    Search,
    Shield,
} from 'lucide-react';
import { useState } from 'react';

import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';

interface DocumentRecord {
    id: number;
    title: string;
    category: string;
    file_name: string;
    file_size: number;
    is_confidential: boolean;
    version: number;
    updated_at: string;
}

interface MeetingRecord {
    id: number;
    title: string;
    meeting_type: string;
    scheduled_at: string;
    status: string;
    has_minutes: boolean;
    minutes_status?: string;
    minutes_version?: number;
}

interface ResolutionRecord {
    id: number;
    resolution_reference: string;
    title: string;
    status: string;
    outcome: string;
    voting_threshold: string;
    meeting_title?: string;
    created_at: string;
}

interface PolicyRecord {
    id: number;
    policy_code: string;
    title: string;
    category: string;
    version_number: number;
    effective_from?: string;
}

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

interface Props extends PageProps {
    tab: string;
    search?: string;
    capabilities: {
        documents: boolean;
        meetings: boolean;
        resolutions: boolean;
        policies: boolean;
    };
    documents: PaginatedData<DocumentRecord> | null;
    meetings: PaginatedData<MeetingRecord> | null;
    resolutions: PaginatedData<ResolutionRecord> | null;
    policies: PaginatedData<PolicyRecord> | null;
    categories: Array<{ value: string; label: string }>;
}

export default function RecordsIndex({
    auth,
    tab: initialTab,
    search: initialSearch,
    capabilities,
    documents,
    meetings,
    resolutions,
    policies,
    categories,
}: Props) {
    const [currentTab, setCurrentTab] = useState(initialTab || 'all');
    const [searchQuery, setSearchQuery] = useState(initialSearch || '');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            '/governance/records',
            { tab: currentTab, search: searchQuery || undefined },
            { preserveState: true, replace: true },
        );
    };

    const handleTabChange = (newTab: string) => {
        setCurrentTab(newTab);
        router.get(
            '/governance/records',
            { tab: newTab, search: searchQuery || undefined },
            { preserveState: true, replace: true },
        );
    };

    const formatBytes = (bytes: number) => {
        if (!bytes) return '0 B';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    };

    const railTabs = [
        { key: 'all', label: 'All records', count: (meetings?.total ?? 0) + (resolutions?.total ?? 0) + (policies?.total ?? 0) + (documents?.total ?? 0) },
        ...(capabilities.meetings ? [{ key: 'meetings', label: 'Meetings & minutes', count: meetings?.total ?? 0 }] : []),
        ...(capabilities.resolutions ? [{ key: 'resolutions', label: 'Decisions', count: resolutions?.total ?? 0 }] : []),
        ...(capabilities.policies ? [{ key: 'policies', label: 'Policies', count: policies?.total ?? 0 }] : []),
        ...(capabilities.documents ? [{ key: 'documents', label: 'Documents', count: documents?.total ?? 0 }] : []),
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Records', href: '/governance/records' },
            ]}
        >
            <Head title="Governance Records & Archives" />
            <PageLayout
                hero={
                    <PageHeader
                        icon={FolderArchive}
                        title="Governance Records"
                        subline="Canonical historical archive of past meetings, minutes, carried decisions, approved policies, and documents"
                        meters={
                            <>
                                {capabilities.meetings && (
                                    <PageHeaderMeterBlock
                                        label="Past meetings"
                                        href="/governance/records?tab=meetings"
                                    >
                                        <PageHeaderMeterBig>
                                            {meetings?.total ?? 0}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Historical sessions
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                )}
                                {capabilities.resolutions && (
                                    <PageHeaderMeterBlock
                                        label="Decisions made"
                                        href="/governance/records?tab=resolutions"
                                    >
                                        <PageHeaderMeterBig>
                                            {resolutions?.total ?? 0}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Carried resolutions
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                )}
                                {capabilities.policies && (
                                    <PageHeaderMeterBlock
                                        label="Approved policies"
                                        href="/governance/records?tab=policies"
                                    >
                                        <PageHeaderMeterBig>
                                            {policies?.total ?? 0}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Active policy library
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                )}
                                {capabilities.documents && (
                                    <PageHeaderMeterBlock
                                        label="Documents"
                                        href="/governance/records?tab=documents"
                                    >
                                        <PageHeaderMeterBig>
                                            {documents?.total ?? 0}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Charters & templates
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                )}
                            </>
                        }
                        actions={
                            <PageHeaderSearch
                                value={searchQuery}
                                onChange={setSearchQuery}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        handleSearch(e as unknown as React.FormEvent);
                                    }
                                }}
                                placeholder="Search records by title or reference..."
                            />
                        }
                        rail={
                            <PageHeaderRail
                                value={currentTab}
                                onSelect={handleTabChange}
                                items={railTabs.map((t) => ({
                                    key: t.key,
                                    label: t.label,
                                    count: t.count,
                                }))}
                            />
                        }
                    />
                }
            >
                <div className="space-y-6">
                    {/* Meetings section */}
                    {(currentTab === 'all' || currentTab === 'meetings') && capabilities.meetings && (
                        <Card>
                            <div className="border-b border-border px-6 py-4 flex items-center justify-between">
                                <div>
                                    <h3 className="text-base font-semibold text-foreground flex items-center gap-2">
                                        <Calendar className="h-4 w-4 text-primary" />
                                        Past Meetings & Minutes
                                    </h3>
                                    <p className="text-xs text-muted-foreground mt-0.5">
                                        Completed meetings with confirmed agendas and signed minutes
                                    </p>
                                </div>
                                {meetings && (
                                    <Badge variant="outline" className="text-xs font-normal">
                                        {meetings.total} records
                                    </Badge>
                                )}
                            </div>
                            <CardContent className="p-0">
                                {meetings?.data && meetings.data.length > 0 ? (
                                    <div className="divide-y divide-border">
                                        {meetings.data.map((meeting) => (
                                            <div
                                                key={meeting.id}
                                                className="flex items-center justify-between p-4 hover:bg-muted/40 transition-colors"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <Link
                                                            href={`/governance/meetings/${meeting.id}`}
                                                            className="font-medium text-foreground hover:text-primary hover:underline truncate"
                                                        >
                                                            {meeting.title}
                                                        </Link>
                                                        <StatusBadge status={meeting.status} />
                                                        {meeting.has_minutes && (
                                                            <Badge
                                                                variant="outline"
                                                                className="border-status-success/30 bg-status-success-bg text-status-success text-[10px]"
                                                            >
                                                                Minutes {meeting.minutes_status ?? 'recorded'}
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <p className="text-xs text-muted-foreground mt-1">
                                                        {meeting.scheduled_at ? new Date(meeting.scheduled_at).toLocaleDateString('en-NZ', { dateStyle: 'medium' }) : 'Date not set'}
                                                        {' · '}
                                                        <span className="capitalize">{meeting.meeting_type.replace(/_/g, ' ')}</span>
                                                    </p>
                                                </div>
                                                <Button variant="ghost" size="sm" asChild>
                                                    <Link href={`/governance/meetings/${meeting.id}`}>
                                                        View workspace &rarr;
                                                    </Link>
                                                </Button>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="p-8 text-center text-muted-foreground text-sm">
                                        No historical meetings match the criteria.
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {/* Decisions / Resolutions section */}
                    {(currentTab === 'all' || currentTab === 'resolutions') && capabilities.resolutions && (
                        <Card>
                            <div className="border-b border-border px-6 py-4 flex items-center justify-between">
                                <div>
                                    <h3 className="text-base font-semibold text-foreground flex items-center gap-2">
                                        <Gavel className="h-4 w-4 text-primary" />
                                        Decisions & Resolutions
                                    </h3>
                                    <p className="text-xs text-muted-foreground mt-0.5">
                                        Carried, implemented, and archived formal board decisions
                                    </p>
                                </div>
                                {resolutions && (
                                    <Badge variant="outline" className="text-xs font-normal">
                                        {resolutions.total} records
                                    </Badge>
                                )}
                            </div>
                            <CardContent className="p-0">
                                {resolutions?.data && resolutions.data.length > 0 ? (
                                    <div className="divide-y divide-border">
                                        {resolutions.data.map((res) => (
                                            <div
                                                key={res.id}
                                                className="flex items-center justify-between p-4 hover:bg-muted/40 transition-colors"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <Link
                                                            href={`/governance/resolutions/${res.id}`}
                                                            className="font-medium text-foreground hover:text-primary hover:underline truncate"
                                                        >
                                                            {res.title}
                                                        </Link>
                                                        <StatusBadge status={res.status} />
                                                        {res.outcome && (
                                                            <Badge variant="outline" className="text-[10px] capitalize">
                                                                {res.outcome}
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <p className="text-xs text-muted-foreground mt-1">
                                                        {res.resolution_reference}
                                                        {res.meeting_title ? ` · From ${res.meeting_title}` : ''}
                                                        {' · '}
                                                        {res.created_at ? new Date(res.created_at).toLocaleDateString('en-NZ', { dateStyle: 'medium' }) : ''}
                                                    </p>
                                                </div>
                                                <Button variant="ghost" size="sm" asChild>
                                                    <Link href={`/governance/resolutions/${res.id}`}>
                                                        View decision &rarr;
                                                    </Link>
                                                </Button>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="p-8 text-center text-muted-foreground text-sm">
                                        No carried decisions match the criteria.
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {/* Policies section */}
                    {(currentTab === 'all' || currentTab === 'policies') && capabilities.policies && (
                        <Card>
                            <div className="border-b border-border px-6 py-4 flex items-center justify-between">
                                <div>
                                    <h3 className="text-base font-semibold text-foreground flex items-center gap-2">
                                        <BookOpen className="h-4 w-4 text-primary" />
                                        Approved Policies
                                    </h3>
                                    <p className="text-xs text-muted-foreground mt-0.5">
                                        Current board-approved governance policies
                                    </p>
                                </div>
                                {policies && (
                                    <Badge variant="outline" className="text-xs font-normal">
                                        {policies.total} records
                                    </Badge>
                                )}
                            </div>
                            <CardContent className="p-0">
                                {policies?.data && policies.data.length > 0 ? (
                                    <div className="divide-y divide-border">
                                        {policies.data.map((policy) => (
                                            <div
                                                key={policy.id}
                                                className="flex items-center justify-between p-4 hover:bg-muted/40 transition-colors"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <Link
                                                            href={`/governance/policies/${policy.id}`}
                                                            className="font-medium text-foreground hover:text-primary hover:underline truncate"
                                                        >
                                                            {policy.title}
                                                        </Link>
                                                        <Badge variant="outline" className="text-[10px]">
                                                            v{policy.version_number}
                                                        </Badge>
                                                    </div>
                                                    <p className="text-xs text-muted-foreground mt-1">
                                                        {policy.policy_code} · Category: <span className="capitalize">{policy.category}</span>
                                                        {policy.effective_from ? ` · Effective: ${policy.effective_from}` : ''}
                                                    </p>
                                                </div>
                                                <Button variant="ghost" size="sm" asChild>
                                                    <Link href={`/governance/policies/${policy.id}`}>
                                                        View policy &rarr;
                                                    </Link>
                                                </Button>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="p-8 text-center text-muted-foreground text-sm">
                                        No approved policies match the criteria.
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {/* Documents section (strictly capability-gated) */}
                    {(currentTab === 'all' || currentTab === 'documents') && capabilities.documents && (
                        <Card>
                            <div className="border-b border-border px-6 py-4 flex items-center justify-between">
                                <div>
                                    <h3 className="text-base font-semibold text-foreground flex items-center gap-2">
                                        <FileText className="h-4 w-4 text-primary" />
                                        Governance Documents & Charters
                                    </h3>
                                    <p className="text-xs text-muted-foreground mt-0.5">
                                        Constitutions, charters, terms of reference, and organizational archives
                                    </p>
                                </div>
                                {documents && (
                                    <Badge variant="outline" className="text-xs font-normal">
                                        {documents.total} records
                                    </Badge>
                                )}
                            </div>
                            <CardContent className="p-0">
                                {documents?.data && documents.data.length > 0 ? (
                                    <div className="divide-y divide-border">
                                        {documents.data.map((doc) => (
                                            <div
                                                key={doc.id}
                                                className="flex items-center justify-between p-4 hover:bg-muted/40 transition-colors"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <Link
                                                            href={`/governance/documents/${doc.id}`}
                                                            className="font-medium text-foreground hover:text-primary hover:underline truncate"
                                                        >
                                                            {doc.title}
                                                        </Link>
                                                        <Badge variant="outline" className="text-[10px]">
                                                            v{doc.version}
                                                        </Badge>
                                                    </div>
                                                    <p className="text-xs text-muted-foreground mt-1">
                                                        {doc.file_name} · {formatBytes(doc.file_size)} · Category:{' '}
                                                        <span className="capitalize">{doc.category.replace(/_/g, ' ')}</span>
                                                    </p>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Button variant="ghost" size="sm" asChild>
                                                        <a
                                                            href={`/governance/documents/${doc.id}/download`}
                                                            aria-label={`Download ${doc.title}`}
                                                        >
                                                            <Download className="h-4 w-4 mr-1" />
                                                            Download
                                                        </a>
                                                    </Button>
                                                    <Button variant="ghost" size="sm" asChild>
                                                        <Link href={`/governance/documents/${doc.id}`}>
                                                            Details &rarr;
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="p-8 text-center text-muted-foreground text-sm">
                                        No documents match the criteria.
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
