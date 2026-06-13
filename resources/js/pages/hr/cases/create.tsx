import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Head, Link, useForm } from '@inertiajs/react';

type BreadcrumbItem = { title: string; href: string };

type Staff = {
    id: number;
    name: string;
    email: string;
};

type Option = {
    value: string;
    label: string;
};

type Props = {
    staff: Staff[];
    caseTypes: Option[];
    severities: Option[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Cases', href: '/hr/cases' },
    { title: 'New Case', href: '/hr/cases/create' },
];

export default function CreateCase({ staff, caseTypes, severities }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        user_id: '',
        case_type: '',
        severity: '',
        title: '',
        description: '',
        assigned_to: '',
        is_confidential: false,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/hr/cases');
    };

    const getSeverityColor = (severity: string) => {
        switch (severity) {
            case 'critical':
                return 'text-status-critical bg-status-critical-bg';
            case 'high':
                return 'text-status-warning bg-status-warning-bg';
            case 'medium':
                return 'text-status-warning bg-status-warning-bg';
            case 'low':
                return 'text-muted-foreground bg-muted';
            default:
                return '';
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New HR Case" />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        variant="compact"
                        backHref="/hr/cases"
                        title="New HR Case"
                        description="Open a new HR case for investigation or action"
                    />
                }
            >
                <div className="max-w-4xl space-y-6">

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Case Information</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="user_id">
                                        Subject (Staff Member){' '}
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
                                    <Label htmlFor="assigned_to">
                                        Assigned To
                                    </Label>
                                    <Select
                                        value={data.assigned_to || '__none__'}
                                        onValueChange={(value) =>
                                            setData(
                                                'assigned_to',
                                                value === '__none__'
                                                    ? ''
                                                    : value,
                                            )
                                        }
                                    >
                                        <SelectTrigger id="assigned_to">
                                            <SelectValue placeholder="Select assignee" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">
                                                Unassigned
                                            </SelectItem>
                                            {staff.map((s) => (
                                                <SelectItem
                                                    key={s.id}
                                                    value={String(s.id)}
                                                >
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="case_type">
                                        Case Type{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Select
                                        value={data.case_type}
                                        onValueChange={(value) =>
                                            setData('case_type', value)
                                        }
                                    >
                                        <SelectTrigger
                                            id="case_type"
                                            className={
                                                errors.case_type
                                                    ? 'border-status-critical/30'
                                                    : ''
                                            }
                                        >
                                            <SelectValue placeholder="Select case type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {caseTypes.map((type) => (
                                                <SelectItem
                                                    key={type.value}
                                                    value={type.value}
                                                >
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.case_type && (
                                        <p className="text-sm text-status-critical">
                                            {errors.case_type}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="severity">
                                        Severity{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Select
                                        value={data.severity}
                                        onValueChange={(value) =>
                                            setData('severity', value)
                                        }
                                    >
                                        <SelectTrigger
                                            id="severity"
                                            className={
                                                errors.severity
                                                    ? 'border-status-critical/30'
                                                    : ''
                                            }
                                        >
                                            <SelectValue placeholder="Select severity" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {severities.map((sev) => (
                                                <SelectItem
                                                    key={sev.value}
                                                    value={sev.value}
                                                    className={getSeverityColor(
                                                        sev.value,
                                                    )}
                                                >
                                                    {sev.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.severity && (
                                        <p className="text-sm text-status-critical">
                                            {errors.severity}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="title">
                                    Case Title{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Input
                                    id="title"
                                    placeholder="Brief summary of the case"
                                    value={data.title}
                                    onChange={(e) =>
                                        setData('title', e.target.value)
                                    }
                                    className={
                                        errors.title
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.title && (
                                    <p className="text-sm text-status-critical">
                                        {errors.title}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    placeholder="Detailed description of the situation, including relevant dates, witnesses, and any initial actions taken..."
                                    rows={6}
                                    value={data.description}
                                    onChange={(e) =>
                                        setData('description', e.target.value)
                                    }
                                    className={
                                        errors.description
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.description && (
                                    <p className="text-sm text-status-critical">
                                        {errors.description}
                                    </p>
                                )}
                            </div>

                            <div className="flex items-center space-x-2 pt-2">
                                <Checkbox
                                    id="is_confidential"
                                    checked={data.is_confidential}
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'is_confidential',
                                            checked as boolean,
                                        )
                                    }
                                />
                                <div className="space-y-1">
                                    <Label
                                        htmlFor="is_confidential"
                                        className="text-sm font-medium"
                                    >
                                        Mark as confidential
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        Confidential cases are only visible to
                                        HR managers and assigned personnel
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-4">
                        <Link href="/hr/cases">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Open Case'}
                        </Button>
                    </div>
                </form>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
