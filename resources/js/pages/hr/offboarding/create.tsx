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
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, UserMinus } from 'lucide-react';

interface Employee {
    id: number;
    name: string;
    email?: string;
    position_title?: string;
    end_date?: string | null;
}

interface Props {
    employees: Employee[];
    defaultEndDate: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Offboarding', href: '/hr/offboarding' },
    { title: 'Start Checklist', href: '/hr/offboarding/create' },
];

export default function CreateOffboarding({
    employees,
    defaultEndDate,
}: Props) {
    const { data, setData, post, processing, errors } = useForm({
        employee_profile_id: '',
        end_date: defaultEndDate,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/hr/offboarding');
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Start Offboarding Checklist" />
            <div className="mx-auto flex max-w-2xl flex-col gap-6 p-6">
                <div className="flex items-center gap-4">
                    <Link href="/hr/offboarding">
                        <Button variant="outline" size="icon">
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                    </Link>
                    <h1 className="text-2xl font-bold">
                        Start Offboarding Checklist
                    </h1>
                </div>

                {employees.length === 0 ? (
                    <Card>
                        <CardContent className="p-8 text-center">
                            <UserMinus className="mx-auto mb-4 h-12 w-12 text-muted-foreground" />
                            <h3 className="mb-2 text-lg font-medium">
                                No eligible employees
                            </h3>
                            <p className="mb-4 text-sm text-muted-foreground">
                                All active employees already have offboarding
                                checklists in progress.
                            </p>
                            <Link href="/hr/offboarding">
                                <Button variant="outline">
                                    Back to Offboarding
                                </Button>
                            </Link>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>Select Employee</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
                                <div className="space-y-2">
                                    <Label htmlFor="employee_profile_id">
                                        Employee
                                    </Label>
                                    <Select
                                        value={data.employee_profile_id}
                                        onValueChange={(value) =>
                                            setData(
                                                'employee_profile_id',
                                                value,
                                            )
                                        }
                                    >
                                        <SelectTrigger id="employee_profile_id">
                                            <SelectValue placeholder="Select an employee..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {employees.map((employee) => (
                                                <SelectItem
                                                    key={employee.id}
                                                    value={String(employee.id)}
                                                >
                                                    <div className="flex flex-col items-start">
                                                        <span className="font-medium">
                                                            {employee.name}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {employee.position_title ||
                                                                'No position'}{' '}
                                                            -{' '}
                                                            {employee.email ||
                                                                'No email'}
                                                        </span>
                                                    </div>
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.employee_profile_id && (
                                        <p className="text-sm text-status-critical">
                                            {errors.employee_profile_id}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="end_date">
                                        Last Working Day
                                    </Label>
                                    <Input
                                        id="end_date"
                                        type="date"
                                        value={data.end_date}
                                        onChange={(e) =>
                                            setData('end_date', e.target.value)
                                        }
                                    />
                                    {errors.end_date && (
                                        <p className="text-sm text-status-critical">
                                            {errors.end_date}
                                        </p>
                                    )}
                                </div>

                                <div className="flex items-center justify-end gap-3">
                                    <Link href="/hr/offboarding">
                                        <Button type="button" variant="outline">
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button type="submit" disabled={processing}>
                                        Start Checklist
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
