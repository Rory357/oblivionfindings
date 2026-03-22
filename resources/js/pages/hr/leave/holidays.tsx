import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { CalendarDays, Plus, Trash2, Globe, MapPin } from 'lucide-react';
import { useState } from 'react';

type PublicHoliday = {
    id: number;
    name: string;
    date: string;
    region: string | null;
    is_national: boolean;
    year: number;
};

type Props = {
    holidays: PublicHoliday[];
    year: number;
    can: {
        manage?: boolean;
    };
};

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Leave', href: '/hr/leave' },
    { title: 'Public Holidays', href: '/hr/leave/holidays' },
];

export default function Holidays({ holidays, year, can }: Props) {
    const [showForm, setShowForm] = useState(false);

    const form = useForm({
        name: '',
        date: '',
        region: '',
        is_national: true,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/hr/leave/holidays', {
            onSuccess: () => {
                form.reset();
                setShowForm(false);
            },
        });
    };

    const handleDelete = (id: number) => {
        if (!confirm('Are you sure you want to remove this public holiday?')) return;
        router.delete(`/hr/leave/holidays/${id}`);
    };

    const handleYearChange = (newYear: number) => {
        router.get('/hr/leave/holidays', { year: newYear }, { preserveState: true });
    };

    const formatDate = (dateStr: string) => {
        const date = new Date(dateStr + 'T00:00:00');
        return date.toLocaleDateString('en-NZ', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Public Holidays" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <h1 className="text-2xl font-bold">Public Holidays</h1>
                        <div className="flex items-center gap-1">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => handleYearChange(year - 1)}
                            >
                                &larr;
                            </Button>
                            <span className="px-3 text-lg font-semibold">{year}</span>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => handleYearChange(year + 1)}
                            >
                                &rarr;
                            </Button>
                        </div>
                    </div>
                    {can.manage && (
                        <Button onClick={() => setShowForm(!showForm)}>
                            <Plus className="mr-2 h-4 w-4" />
                            Add Holiday
                        </Button>
                    )}
                </div>

                {/* Add Holiday Form */}
                {showForm && can.manage && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Add Public Holiday</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="flex flex-wrap items-end gap-4">
                                <div className="min-w-[200px] flex-1">
                                    <Label htmlFor="name">Holiday Name</Label>
                                    <Input
                                        id="name"
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        placeholder="e.g. Auckland Anniversary"
                                        required
                                    />
                                    {form.errors.name && (
                                        <p className="mt-1 text-xs text-red-400">{form.errors.name}</p>
                                    )}
                                </div>
                                <div className="w-48">
                                    <Label htmlFor="date">Date</Label>
                                    <Input
                                        id="date"
                                        type="date"
                                        value={form.data.date}
                                        onChange={(e) => form.setData('date', e.target.value)}
                                        required
                                    />
                                    {form.errors.date && (
                                        <p className="mt-1 text-xs text-red-400">{form.errors.date}</p>
                                    )}
                                </div>
                                <div className="w-48">
                                    <Label htmlFor="region">Region (Optional)</Label>
                                    <Input
                                        id="region"
                                        value={form.data.region}
                                        onChange={(e) => form.setData('region', e.target.value)}
                                        placeholder="e.g. auckland"
                                    />
                                </div>
                                <div className="flex items-center gap-2 pb-1">
                                    <Checkbox
                                        id="is_national"
                                        checked={form.data.is_national}
                                        onCheckedChange={(checked) =>
                                            form.setData('is_national', checked === true)
                                        }
                                    />
                                    <Label htmlFor="is_national" className="cursor-pointer">
                                        National Holiday
                                    </Label>
                                </div>
                                <div className="flex gap-2 pb-0.5">
                                    <Button type="submit" disabled={form.processing}>
                                        Save
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setShowForm(false)}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Holidays Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Holiday</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Region</TableHead>
                                    <TableHead>Scope</TableHead>
                                    {can.manage && (
                                        <TableHead className="text-right">Actions</TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {holidays.map((holiday) => {
                                    const isPast = new Date(holiday.date + 'T00:00:00') < new Date();
                                    return (
                                        <TableRow
                                            key={holiday.id}
                                            className={isPast ? 'opacity-60' : ''}
                                        >
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <CalendarDays className="h-4 w-4 text-muted-foreground" />
                                                    <span className="font-medium">{holiday.name}</span>
                                                </div>
                                            </TableCell>
                                            <TableCell>{formatDate(holiday.date)}</TableCell>
                                            <TableCell>
                                                {holiday.region ? (
                                                    <span className="capitalize">{holiday.region}</span>
                                                ) : (
                                                    <span className="text-muted-foreground">&mdash;</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {holiday.is_national ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-blue-500/30 bg-blue-500/10 text-blue-400"
                                                    >
                                                        <Globe className="mr-1 h-3 w-3" />
                                                        National
                                                    </Badge>
                                                ) : (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-amber-500/30 bg-amber-500/10 text-amber-400"
                                                    >
                                                        <MapPin className="mr-1 h-3 w-3" />
                                                        Regional
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            {can.manage && (
                                                <TableCell className="text-right">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => handleDelete(holiday.id)}
                                                        className="text-red-400 hover:text-red-300"
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    );
                                })}
                                {holidays.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={can.manage ? 5 : 4}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            No public holidays found for {year}.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Summary */}
                <div className="text-sm text-muted-foreground">
                    {holidays.length} public holiday{holidays.length !== 1 ? 's' : ''} for {year}.
                    Holidays are used in leave calculations under the NZ Holidays Act 2003.
                </div>
            </div>
        </AppLayout>
    );
}
