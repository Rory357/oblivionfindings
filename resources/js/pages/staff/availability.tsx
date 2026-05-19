import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Calendar } from 'lucide-react';

import { PageHero } from '@/components/page';
type Availability = {
    id: number;
    day_of_week: number;
    starts_at: string;
    ends_at: string;
};

type Props = {
    user: { id: number; name: string; email: string };
    availability: Availability[];
    canManage: boolean;
};

const dayLabels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

export default function StaffAvailability({ user, availability, canManage }: Props) {
    const form = useForm({ day_of_week: '1', starts_at: '09:00', ends_at: '17:00' });

    const grouped = dayLabels.map((label, day) => ({
        label,
        day,
        blocks: availability.filter((a) => a.day_of_week === day),
    }));

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Staff', href: '/staff' },
                { title: user.name, href: `/staff/${user.id}` },
                { title: 'Availability', href: `/staff/${user.id}/availability` },
            ]}
        >
            <Head title={`Availability: ${user.name}`} />

            <PageShell>
                <PageHero
                    icon={Calendar}
                    title="Availability"
                    description={`${user.name} • ${user.email}`}
                    stats={[
                        { label: 'Blocks', value: availability.length },
                        { label: 'Days covered', value: new Set(availability.map((a) => a.day_of_week)).size },
                    ]}
                />

                <div className="flex items-center justify-end gap-2">
                    <Link href={`/staff/${user.id}`}>
                        <Button variant="outline">Back</Button>
                    </Link>
                </div>

                {canManage ? (
                    <form
                        className="rounded-md border p-4 space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/staff/${user.id}/availability`, { preserveScroll: true });
                        }}
                    >
                        <div className="font-medium">Add availability block</div>
                        <div className="grid gap-3 md:grid-cols-3">
                            <div className="space-y-1">
                                <Label>Day</Label>
                                <Select value={form.data.day_of_week} onValueChange={(v) => form.setData('day_of_week', v)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select day" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {dayLabels.map((d, idx) => (
                                            <SelectItem key={d} value={String(idx)}>
                                                {d}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label>Start</Label>
                                <Input type="time" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label>End</Label>
                                <Input type="time" value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} />
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Button type="submit" disabled={form.processing}>Add</Button>
                            {form.recentlySuccessful ? <span className="text-xs text-muted-foreground">Saved.</span> : null}
                        </div>
                    </form>
                ) : null}

                <div className="grid gap-4 md:grid-cols-2">
                    {grouped.map((g) => (
                        <div key={g.day} className="rounded-md border">
                            <div className="p-4 font-medium">{g.label}</div>
                            <div className="divide-y">
                                {g.blocks.length ? (
                                    g.blocks.map((b) => (
                                        <div key={b.id} className="p-4 flex items-center justify-between gap-3">
                                            <div className="text-sm">
                                                {b.starts_at} – {b.ends_at}
                                            </div>
                                            {canManage ? (
                                                <Button
                                                    variant="destructive"
                                                    onClick={() => {
                                                        if (!confirm('Remove this availability block?')) return;
                                                        form.delete(`/staff/${user.id}/availability/${b.id}`, { preserveScroll: true });
                                                    }}
                                                >
                                                    Remove
                                                </Button>
                                            ) : null}
                                        </div>
                                    ))
                                ) : (
                                    <div className="p-4 text-sm text-muted-foreground">No availability blocks.</div>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </PageShell>
        </AppLayout>
    );
}
