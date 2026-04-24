import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    tasks: { data: any[]; links: any[] };
};

const priorityColors: Record<string, string> = {
    low: 'bg-muted text-foreground',
    medium: 'bg-blue-100 text-blue-800',
    high: 'bg-orange-100 text-orange-800',
    urgent: 'bg-red-100 text-red-800',
};

export default function TasksAwaitingApproval({ tasks }: Props) {
    const [notes, setNotes] = useState<Record<number, string>>({});

    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Tasks', href: '/respite/tasks' }, { title: 'Awaiting Approval', href: '/respite/tasks/awaiting-approval' }]}>
            <Head title="Tasks Awaiting Approval" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">Tasks Awaiting Approval</h1>
                    <div className="mt-1 text-sm text-muted-foreground">Tasks submitted for your approval.</div>
                </div>
                <RespiteSubnav />

                <div className="space-y-2">
                    {tasks.data.map((t: any) => (
                        <Card key={t.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">{t.title}</div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge className={priorityColors[t.priority] || ''}>{t.priority}</Badge>
                                                <Badge className="bg-amber-100 text-amber-800">Awaiting Approval</Badge>
                                            </div>
                                            {t.assigned_to && (
                                                <div className="mt-2 text-xs text-muted-foreground">Submitted by: {t.assigned_to?.name}</div>
                                            )}
                                            {t.due_at && (
                                                <div className="mt-1 text-xs text-muted-foreground">Due: {formatDateTime(t.due_at)}</div>
                                            )}
                                        </div>
                                        <Link href={`/respite/tasks/${t.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                <Textarea
                                    placeholder="Approval notes..."
                                    value={notes[t.id] || ''}
                                    onChange={(e) => setNotes({ ...notes, [t.id]: e.target.value })}
                                    rows={2}
                                />
                                <div className="flex gap-2">
                                    <Button size="sm" onClick={() => router.post(`/respite/tasks/${t.id}/approve`, { notes: notes[t.id] || '' })}>
                                        Approve
                                    </Button>
                                    <Button size="sm" variant="destructive" onClick={() => router.post(`/respite/tasks/${t.id}/reject`, { notes: notes[t.id] || '' })}>
                                        Reject
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                    {!tasks.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">No tasks awaiting approval.</div>
                    )}
                </div>

                {tasks?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {tasks.links.map((l: any) => (
                            <Button
                                key={l.label}
                                variant="outline"
                                size="sm"
                                disabled={!l.url}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
