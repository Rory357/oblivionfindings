import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Clock, Eye, FileText, Mail, Pencil, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface Props {
    templates: {
        data: any[];
        links: any[];
    };
    preview?: {
        id: number;
        template_name: string;
        subject: string;
        body: string;
    };
    emailLog?:
        | {
              data: any[];
              links?: any[];
          }
        | any[];
    showLog?: boolean;
    can: {
        manage: boolean;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/people' },
    { title: 'Onboarding', href: '/hr/onboarding' },
    { title: 'Email Templates', href: '/hr/onboarding/emails' },
];

type Tab = 'templates' | 'preview' | 'log';

export default function OnboardingEmails({
    templates,
    preview,
    emailLog,
    showLog,
    can,
}: Props) {
    const hasPreview = !!preview;
    const hasLog = !!showLog;
    const logEntries = Array.isArray(emailLog)
        ? emailLog
        : (emailLog?.data ?? []);
    const logLinks = Array.isArray(emailLog) ? [] : (emailLog?.links ?? []);

    const [activeTab, setActiveTab] = useState<Tab>(
        hasPreview ? 'preview' : hasLog ? 'log' : 'templates',
    );

    const formatDate = (d: string) =>
        new Date(d).toLocaleDateString('en-NZ', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });

    const tabs: { key: Tab; label: string; show: boolean }[] = [
        { key: 'templates', label: 'Templates', show: true },
        { key: 'preview', label: 'Preview', show: hasPreview },
        { key: 'log', label: 'Sent Log', show: true },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Onboarding Email Templates" />
            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/hr/onboarding"
                        title="Onboarding Email Templates"
                        description="Configure and preview the automated emails sent during onboarding."
                    />
                }
            >
                {/* Tab Navigation */}
                <div className="flex gap-1 border-b">
                    {tabs
                        .filter((t) => t.show)
                        .map((tab) => (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                key={tab.key}
                                onClick={() => {
                                    if (tab.key === 'log') {
                                        router.get(
                                            '/hr/onboarding/emails/log',
                                            {},
                                            { preserveState: true },
                                        );
                                    } else if (tab.key === 'templates') {
                                        router.get(
                                            '/hr/onboarding/emails',
                                            {},
                                            { preserveState: true },
                                        );
                                    }
                                    setActiveTab(tab.key);
                                }}
                                className={`-mb-px rounded-none border-b-2 px-4 py-2 text-sm font-medium ${
                                    activeTab === tab.key
                                        ? 'border-primary text-primary'
                                        : 'border-transparent text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                {tab.label}
                            </Button>
                        ))}
                </div>

                {/* Templates Tab */}
                {activeTab === 'templates' && (
                    <div className="space-y-3">
                        {templates.data.length === 0 ? (
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="py-8 text-center text-sm text-muted-foreground">
                                        <Mail className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                        <p>
                                            No email templates configured yet.
                                        </p>
                                    </div>
                                </CardContent>
                            </Card>
                        ) : (
                            templates.data.map((tpl: any) => (
                                <Card key={tpl.id}>
                                    <CardContent className="pt-4">
                                        <div className="flex items-start justify-between gap-4">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Mail className="h-4 w-4 text-muted-foreground" />
                                                    <span className="font-medium">
                                                        {tpl.template_name}
                                                    </span>
                                                    {tpl.trigger && (
                                                        <Badge
                                                            variant="outline"
                                                            className="capitalize"
                                                        >
                                                            {tpl.trigger.replace(
                                                                /_/g,
                                                                ' ',
                                                            )}
                                                        </Badge>
                                                    )}
                                                </div>
                                                {tpl.subject && (
                                                    <p className="mt-1 truncate text-sm text-muted-foreground">
                                                        Subject: {tpl.subject}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="flex shrink-0 items-center gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/hr/onboarding/emails/${tpl.id}/preview`}
                                                    >
                                                        <Eye className="h-4 w-4" />
                                                    </Link>
                                                </Button>
                                                {can.manage && (
                                                    <>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/hr/onboarding/emails/${tpl.id}/edit`}
                                                            >
                                                                <Pencil className="h-4 w-4" />
                                                            </Link>
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => {
                                                                if (
                                                                    confirm(
                                                                        'Are you sure you want to delete this template?',
                                                                    )
                                                                ) {
                                                                    router.delete(
                                                                        `/hr/onboarding/emails/${tpl.id}`,
                                                                    );
                                                                }
                                                            }}
                                                        >
                                                            <Trash2 className="h-4 w-4 text-destructive" />
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))
                        )}

                        {/* Pagination */}
                        {templates.links?.length > 3 && (
                            <LaravelPagination links={templates.links} />
                        )}
                    </div>
                )}

                {/* Preview Tab */}
                {activeTab === 'preview' && preview && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileText className="h-5 w-5" />
                                {preview.template_name}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {/* eslint-disable-next-line no-restricted-syntax -- Email preview frame intentionally mimics the rendered message body. */}
                            <div className="rounded-lg border bg-white">
                                <div className="border-b px-6 py-3">
                                    <p className="text-sm text-muted-foreground">
                                        Subject
                                    </p>
                                    <p className="font-medium">
                                        {preview.subject}
                                    </p>
                                </div>
                                <div className="px-6 py-4">
                                    <div
                                        className="prose prose-sm max-w-none"
                                        dangerouslySetInnerHTML={{
                                            __html: preview.body,
                                        }}
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Log Tab */}
                {activeTab === 'log' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Send className="h-5 w-5" />
                                Sent Email Log
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {logEntries.length === 0 ? (
                                <div className="py-8 text-center text-sm text-muted-foreground">
                                    <Send className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                    <p>No emails have been sent yet.</p>
                                </div>
                            ) : (
                                <div className="space-y-4">
                                    <table className="w-full text-sm">
                                        <thead className="border-b bg-muted/50">
                                            <tr>
                                                <th className="px-4 py-3 text-left font-medium">
                                                    Recipient
                                                </th>
                                                <th className="px-4 py-3 text-left font-medium">
                                                    Template
                                                </th>
                                                <th className="px-4 py-3 text-left font-medium">
                                                    Sent At
                                                </th>
                                                <th className="px-4 py-3 text-center font-medium">
                                                    Status
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {logEntries.map(
                                                (entry: any, i: number) => (
                                                    <tr
                                                        key={entry.id ?? i}
                                                        className="hover:bg-muted/30"
                                                    >
                                                        <td className="px-4 py-3">
                                                            {entry.recipient ??
                                                                entry.to ??
                                                                entry
                                                                    .employee_profile
                                                                    ?.user
                                                                    ?.name ??
                                                                '-'}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            {entry.template_name ??
                                                                entry.template ??
                                                                entry
                                                                    .onboarding_email
                                                                    ?.template_name ??
                                                                '-'}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <span className="flex items-center gap-1 text-muted-foreground">
                                                                <Clock className="h-3 w-3" />
                                                                {entry.sent_at ||
                                                                entry.created_at
                                                                    ? formatDate(
                                                                          entry.sent_at ??
                                                                              entry.created_at,
                                                                      )
                                                                    : '-'}
                                                            </span>
                                                        </td>
                                                        <td className="px-4 py-3 text-center">
                                                            <Badge
                                                                className={
                                                                    entry.status ===
                                                                        'sent' ||
                                                                    entry.status ===
                                                                        'delivered'
                                                                        ? 'bg-status-success-bg text-status-success'
                                                                        : entry.status ===
                                                                            'failed'
                                                                          ? 'bg-status-critical-bg text-status-critical'
                                                                          : 'bg-muted text-foreground'
                                                                }
                                                            >
                                                                {entry.status ??
                                                                    'unknown'}
                                                            </Badge>
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                    {logLinks.length > 3 && (
                                        <LaravelPagination links={logLinks} />
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
        </AppLayout>
    );
}
