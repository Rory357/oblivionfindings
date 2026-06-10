import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';

type Props = {
    notes: { data: any[]; links: any[] };
};

export default function DailyNotesWithConcerns({ notes }: Props) {
    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Daily Notes', href: '/respite/daily-notes' },
            { title: 'With Concerns', href: '/respite/daily-notes/with-concerns' },
        ]}>
            <Head title="Notes with Concerns" />

            <PageLayout
                hero={
                    <PageHero
                        icon={AlertTriangle}
                        title="Notes with Concerns"
                        description="Daily notes that have recorded concerns."
                        stats={[
                            { label: 'Total', value: notes.data.length },
                        ]}
                    />
                }
            >
                <RespiteSubnav />

                <div className="space-y-2">
                    {notes.data.map((note: any) => (
                        <Card key={note.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">
                                                {note.stay?.client?.first_name} {note.stay?.client?.last_name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge variant="outline">{note.shift_period}</Badge>
                                                {note.mood && <Badge variant="outline">{note.mood}</Badge>}
                                                <Badge variant="outline">Concern</Badge>
                                            </div>
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                {formatDateTimeLong(note.note_date)}
                                            </div>
                                        </div>
                                        <Link href={`/respite/daily-notes/${note.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!notes.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No items found.
                        </div>
                    )}
                </div>

                {notes?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {notes.links.map((l: any) => (
                            <Button
                                key={l.label}
                                variant="outline"
                                size="sm"
                                disabled={!l.url}
                                className={l.active ? 'bg-muted' : ''}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}
