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
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';

type Props = {
    positions: Array<{ id: number; title: string }>;
    employees: Array<{ id: number; name: string }>;
};

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Succession', href: '/hr/succession' },
    { title: 'Create', href: '/hr/succession/create' },
];

export default function SuccessionCreate({ positions, employees }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        role_title: '',
        department: '',
        risk_level: 'medium',
        position_id: '',
        current_holder_user_id: '',
        notes: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/hr/succession');
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Succession Plan" />
            <PageShell>
                <PageHero category="hr" variant="compact"
                    title="Create Succession Plan"
                    description="Define a key role and identify potential successors."
                />
                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader>
                            <CardTitle>Plan Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label>
                                        Role Title{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Input
                                        value={data.role_title}
                                        onChange={(e) =>
                                            setData(
                                                'role_title',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g. Head of Operations"
                                    />
                                    {errors.role_title && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {errors.role_title}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label>Department</Label>
                                    <Input
                                        value={data.department}
                                        onChange={(e) =>
                                            setData(
                                                'department',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {errors.department && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {errors.department}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label>
                                        Risk Level{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Select
                                        value={data.risk_level}
                                        onValueChange={(v) =>
                                            setData('risk_level', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="low">
                                                Low
                                            </SelectItem>
                                            <SelectItem value="medium">
                                                Medium
                                            </SelectItem>
                                            <SelectItem value="high">
                                                High
                                            </SelectItem>
                                            <SelectItem value="critical">
                                                Critical
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.risk_level && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {errors.risk_level}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label>Current Holder</Label>
                                    <Select
                                        value={data.current_holder_user_id}
                                        onValueChange={(v) =>
                                            setData('current_holder_user_id', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {employees.map((e) => (
                                                <SelectItem
                                                    key={e.id}
                                                    value={String(e.id)}
                                                >
                                                    {e.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.current_holder_user_id && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {errors.current_holder_user_id}
                                        </p>
                                    )}
                                </div>
                            </div>
                            {positions && positions.length > 0 && (
                                <div>
                                    <Label>Position</Label>
                                    <Select
                                        value={data.position_id}
                                        onValueChange={(v) =>
                                            setData('position_id', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select position..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {positions.map((p) => (
                                                <SelectItem
                                                    key={p.id}
                                                    value={String(p.id)}
                                                >
                                                    {p.title}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.position_id && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {errors.position_id}
                                        </p>
                                    )}
                                </div>
                            )}
                            <div>
                                <Label>Notes</Label>
                                <Textarea
                                    rows={3}
                                    value={data.notes}
                                    onChange={(e) =>
                                        setData('notes', e.target.value)
                                    }
                                />
                                {errors.notes && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.notes}
                                    </p>
                                )}
                            </div>
                            <div className="flex items-center gap-2">
                                <Button type="submit" disabled={processing}>
                                    Create Plan
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        router.visit('/hr/succession')
                                    }
                                >
                                    Cancel
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </PageShell>
        </AppLayout>
    );
}
