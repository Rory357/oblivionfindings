import { PageHero, PageLayout } from '@/components/page';
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';

interface Staff {
    id: number;
    name: string;
    email: string;
}

interface CheckType {
    value: string;
    label: string;
}

interface Props {
    staff: Staff[];
    checkTypes: CheckType[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Vetting Register', href: '/hr/compliance/vetting' },
    { title: 'Add Check', href: '/hr/compliance/vetting/create' },
];

export default function CreateVetting({ staff, checkTypes }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        user_id: '',
        check_type: '',
        provider: '',
        reference_number: '',
        check_date: '',
        notes: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/hr/compliance/vetting');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Background Check" />
            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/hr/compliance/vetting"
                        title="Add Background Check"
                    />
                }
            >
                <div className="mx-auto max-w-2xl">
                <Card>
                    <CardHeader>
                        <CardTitle>Check Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div className="space-y-2">
                                <Label htmlFor="user_id">
                                    Staff Member{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Select
                                    value={data.user_id}
                                    onValueChange={(value) =>
                                        setData('user_id', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="user_id"
                                        className={
                                            errors.user_id
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    >
                                        <SelectValue placeholder="Select staff member" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {staff.map((s) => (
                                            <SelectItem
                                                key={s.id}
                                                value={String(s.id)}
                                            >
                                                {s.name} ({s.email})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.user_id && (
                                    <p className="text-sm text-status-critical">
                                        {errors.user_id}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="check_type">
                                    Check Type{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Select
                                    value={data.check_type}
                                    onValueChange={(value) =>
                                        setData('check_type', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="check_type"
                                        className={
                                            errors.check_type
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    >
                                        <SelectValue placeholder="Select check type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {checkTypes.map((type) => (
                                            <SelectItem
                                                key={type.value}
                                                value={type.value}
                                            >
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.check_type && (
                                    <p className="text-sm text-status-critical">
                                        {errors.check_type}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="provider">Provider</Label>
                                <Input
                                    id="provider"
                                    value={data.provider}
                                    onChange={(e) =>
                                        setData('provider', e.target.value)
                                    }
                                    placeholder="e.g., NZ Police Vetting Service"
                                    className={
                                        errors.provider
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.provider && (
                                    <p className="text-sm text-status-critical">
                                        {errors.provider}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="reference_number">
                                    Reference Number
                                </Label>
                                <Input
                                    id="reference_number"
                                    value={data.reference_number}
                                    onChange={(e) =>
                                        setData(
                                            'reference_number',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g., VET-12345"
                                    className={
                                        errors.reference_number
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.reference_number && (
                                    <p className="text-sm text-status-critical">
                                        {errors.reference_number}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="check_date">Check Date</Label>
                                <Input
                                    id="check_date"
                                    type="date"
                                    value={data.check_date}
                                    onChange={(e) =>
                                        setData('check_date', e.target.value)
                                    }
                                    className={
                                        errors.check_date
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.check_date && (
                                    <p className="text-sm text-status-critical">
                                        {errors.check_date}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) =>
                                        setData('notes', e.target.value)
                                    }
                                    placeholder="Any additional notes about this check..."
                                    rows={3}
                                    className={
                                        errors.notes
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.notes && (
                                    <p className="text-sm text-status-critical">
                                        {errors.notes}
                                    </p>
                                )}
                            </div>

                            <div className="flex items-center justify-end gap-3">
                                <Link href="/hr/compliance/vetting">
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </Link>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Creating...' : 'Add Check'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
