import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { NotebookPen, Plus } from 'lucide-react';

type Props = {
    notes: { data: any[]; links: any[] };
    filters: {
        stay_id?: string;
        client_id?: string;
        date_from?: string;
        date_to?: string;
        shift_period?: string;
        with_concerns?: string;
        with_incidents?: string;
        sensitive?: string;
    };
    shiftPeriods: Record<string, string>;
    wellbeingLevels: any;
};

export default function DailyNotesIndex({ notes, filters, shiftPeriods }: Props) {
    const ANY = '__any__';
    const [localFilters, setLocalFilters] = useState(filters);

    const applyFilter = (key: string, value: string) => {
        const updated = { ...localFilters, [key]: value };
        setLocalFilters(updated);
        router.get('/respite/daily-notes', updated, { preserveState: true, preserveScroll: true });
    };

    const toggleFilter = (key: string) => {
        const current = localFilters[key as keyof typeof localFilters];
        applyFilter(key, current === '1' ? '' : '1');
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Daily Notes', href: '/respite/daily-notes' },
        ]}>
            <Head title="Daily Notes" />

            <PageLayout
                hero={
                    <PageHero
                        icon={NotebookPen}
                        title="Daily Notes"
                        description="Shift-by-shift wellbeing and activity records for respite stays."
                        stats={[
                            { label: 'Total notes', value: notes.data.length },
                            { label: 'With concerns', value: notes.data.filter((n: any) => n.has_concerns).length },
                            { label: 'Incidents', value: notes.data.filter((n: any) => n.incident_occurred).length },
                        ]}
                        actions={
                            <Link href="/respite/daily-notes/create">
                                <Button size="sm" variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New Note
                                </Button>
                            </Link>
                        }
                    />
                }
            >
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div>
                                <Label>Date From</Label>
                                <Input
                                    type="date"
                                    value={localFilters.date_from || ''}
                                    onChange={(e) => applyFilter('date_from', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label>Date To</Label>
                                <Input
                                    type="date"
                                    value={localFilters.date_to || ''}
                                    onChange={(e) => applyFilter('date_to', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label>Shift Period</Label>
                                <Select value={localFilters.shift_period || ANY} onValueChange={(v) => applyFilter('shift_period', v === ANY ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="All shifts" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>All shifts</SelectItem>
                                        {Object.entries(shiftPeriods).map(([value, label]) => (
                                            <SelectItem key={value} value={value}>{label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-4">
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={localFilters.with_concerns === '1'}
                                    onChange={() => toggleFilter('with_concerns')}
                                    className="rounded border-border"
                                />
                                With concerns
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={localFilters.with_incidents === '1'}
                                    onChange={() => toggleFilter('with_incidents')}
                                    className="rounded border-border"
                                />
                                With incidents
                            </label>
                        </div>
                    </CardContent>
                </Card>

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
                                                {note.has_concerns && <Badge variant="outline">Concern</Badge>}
                                                {note.incident_occurred && <Badge variant="outline">Incident</Badge>}
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
