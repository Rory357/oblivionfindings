import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';

export default function CalendarSyncCreate() {
    const { data, setData, post, processing, errors } = useForm({
        provider: 'google',
        calendar_id: '',
        sync_direction: 'both',
        is_active: true,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/operations/calendar-sync');
    };

    return (
        <AppLayout>
            <Head title="Add Calendar Connection" />
            <PageHero variant="compact" title="Add Calendar Connection" description="Connect an external calendar for shift synchronisation." backHref="/operations/calendar-sync" />
            <PageShell>
                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Connection Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="provider">Provider *</Label>
                                    <Select value={data.provider} onValueChange={(v) => setData('provider', v)}>
                                        <SelectTrigger id="provider">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="google">Google Calendar</SelectItem>
                                            <SelectItem value="outlook">Outlook / Microsoft 365</SelectItem>
                                            <SelectItem value="ical">iCal (Apple Calendar)</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.provider && <p className="text-xs text-destructive">{errors.provider}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="sync_direction">Sync Direction *</Label>
                                    <Select value={data.sync_direction} onValueChange={(v) => setData('sync_direction', v)}>
                                        <SelectTrigger id="sync_direction">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="push">Push (shifts to calendar)</SelectItem>
                                            <SelectItem value="pull">Pull (calendar to shifts)</SelectItem>
                                            <SelectItem value="both">Both ways</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.sync_direction && <p className="text-xs text-destructive">{errors.sync_direction}</p>}
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="calendar_id">Calendar ID</Label>
                                <Input
                                    id="calendar_id"
                                    value={data.calendar_id}
                                    onChange={(e) => setData('calendar_id', e.target.value)}
                                    placeholder="Optional - e.g. primary or specific calendar ID"
                                />
                                {errors.calendar_id && <p className="text-xs text-destructive">{errors.calendar_id}</p>}
                            </div>

                            <div className="flex items-center gap-2">
                                <input
                                    id="is_active"
                                    type="checkbox"
                                    checked={data.is_active}
                                    onChange={(e) => setData('is_active', e.target.checked)}
                                    className="h-4 w-4 rounded border-border"
                                />
                                <Label htmlFor="is_active" className="cursor-pointer">Enable sync immediately</Label>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="mt-4 flex items-center justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get('/operations/calendar-sync')}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Add Connection
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
