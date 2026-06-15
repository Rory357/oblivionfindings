import { OnboardingTabs } from '@/components/hr';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Clock, Eye, FileText, Mail, Pencil, Plus, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface EmailTemplate {
    id: number;
    template_name: string;
    subject: string;
    body: string;
    send_days_before_start: number;
    is_active: boolean;
    trigger?: string | null;
}

interface Props {
    templates: {
        data: EmailTemplate[];
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

const MERGE_TOKENS = [
    '{{employee_name}}',
    '{{position_title}}',
    '{{start_date}}',
    '{{manager_name}}',
    '{{company_name}}',
];

/**
 * Create / edit an onboarding email template. Replaces the dead
 * `/hr/onboarding/emails/{id}/edit` page link (no such route existed) with an
 * in-page modal, matching the pop-up-not-page convention used across HR.
 */
function EmailTemplateDialog({
    open,
    onClose,
    template,
}: {
    open: boolean;
    onClose: () => void;
    template: EmailTemplate | null;
}) {
    const isEdit = !!template;
    const form = useForm({
        template_name: template?.template_name ?? '',
        subject: template?.subject ?? '',
        body: template?.body ?? '',
        send_days_before_start: template?.send_days_before_start ?? 0,
        is_active: template?.is_active ?? true,
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (isEdit) {
            form.put(`/hr/onboarding/emails/${template!.id}`, {
                preserveScroll: true,
                onSuccess: close,
            });
        } else {
            form.post('/hr/onboarding/emails', {
                preserveScroll: true,
                onSuccess: close,
            });
        }
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && close()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? 'Edit email template' : 'New email template'}
                    </DialogTitle>
                    <DialogDescription>
                        Automated onboarding email. Use merge tokens to
                        personalise each message.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="template_name">Template name</Label>
                        <Input
                            id="template_name"
                            value={form.data.template_name}
                            onChange={(e) =>
                                form.setData('template_name', e.target.value)
                            }
                            placeholder="e.g. Welcome — before day one"
                        />
                        {form.errors.template_name && (
                            <p className="text-xs text-status-critical">
                                {form.errors.template_name}
                            </p>
                        )}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="subject">Subject</Label>
                        <Input
                            id="subject"
                            value={form.data.subject}
                            onChange={(e) =>
                                form.setData('subject', e.target.value)
                            }
                            placeholder="Welcome to {{company_name}}, {{employee_name}}!"
                        />
                        {form.errors.subject && (
                            <p className="text-xs text-status-critical">
                                {form.errors.subject}
                            </p>
                        )}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="body">Body (HTML)</Label>
                        <Textarea
                            id="body"
                            rows={8}
                            value={form.data.body}
                            onChange={(e) =>
                                form.setData('body', e.target.value)
                            }
                            placeholder="Hi {{employee_name}}, we're excited for your start on {{start_date}}…"
                        />
                        {form.errors.body && (
                            <p className="text-xs text-status-critical">
                                {form.errors.body}
                            </p>
                        )}
                        <p className="text-xs text-muted-foreground">
                            Merge tokens: {MERGE_TOKENS.join(' · ')}
                        </p>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="send_days_before_start">
                                Send days before start
                            </Label>
                            <Input
                                id="send_days_before_start"
                                type="number"
                                min={-90}
                                max={90}
                                value={form.data.send_days_before_start}
                                onChange={(e) =>
                                    form.setData(
                                        'send_days_before_start',
                                        Number(e.target.value),
                                    )
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                e.g. 7 sends a week before; 0 on the start date;
                                −1 the day after.
                            </p>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="is_active">Status</Label>
                            <label className="flex h-10 items-center gap-2 text-sm">
                                <input
                                    id="is_active"
                                    type="checkbox"
                                    checked={form.data.is_active}
                                    onChange={(e) =>
                                        form.setData(
                                            'is_active',
                                            e.target.checked,
                                        )
                                    }
                                    className="rounded border-border"
                                />
                                Active (eligible for scheduled sending)
                            </label>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={close}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save changes'
                                  : 'Create template'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
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
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<EmailTemplate | null>(null);

    const openCreate = () => {
        setEditing(null);
        setDialogOpen(true);
    };
    const openEdit = (tpl: EmailTemplate) => {
        setEditing(tpl);
        setDialogOpen(true);
    };

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
                    <PageHero category="hr"
                        variant="compact"
                        title="Onboarding Email Templates"
                        description="Configure and preview the automated emails sent during onboarding."
                    />
                }
            >
                <OnboardingTabs active="emails" />
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
                        {can.manage && (
                            <div className="flex justify-end">
                                <Button type="button" size="sm" onClick={openCreate}>
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New template
                                </Button>
                            </div>
                        )}
                        {templates.data.length === 0 ? (
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="py-8 text-center text-sm text-muted-foreground">
                                        <Mail className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                        <p>
                                            No email templates configured yet.
                                        </p>
                                        {can.manage && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                className="mt-4"
                                                onClick={openCreate}
                                            >
                                                <Plus className="mr-1.5 h-4 w-4" />
                                                Create your first template
                                            </Button>
                                        )}
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
                                                            onClick={() =>
                                                                openEdit(tpl)
                                                            }
                                                        >
                                                            <Pencil className="h-4 w-4" />
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

                {can.manage && (
                    <EmailTemplateDialog
                        key={editing?.id ?? 'new'}
                        open={dialogOpen}
                        onClose={() => setDialogOpen(false)}
                        template={editing}
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
