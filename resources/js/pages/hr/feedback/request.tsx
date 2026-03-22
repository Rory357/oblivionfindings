import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { useState } from 'react';

type User = { id: number; name: string };

type Props = {
    employees: User[];
    reviewTypes: string[];
    questions: Record<string, string>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: '360 Feedback', href: '/hr/feedback' },
    { title: 'Request Feedback', href: '/hr/feedback/request' },
];

const reviewTypeLabels: Record<string, string> = {
    peer: 'Peer Review',
    manager: 'Manager Review',
    direct_report: 'Direct Report',
    self: 'Self Assessment',
};

export default function FeedbackRequest({ employees, reviewTypes, questions }: Props) {
    const form = useForm({
        subject_user_id: '',
        reviewer_user_ids: [] as string[],
        review_type: '',
        performance_review_id: null as number | null,
    });

    const toggleReviewer = (id: string) => {
        const current = form.data.reviewer_user_ids;
        if (current.includes(id)) {
            form.setData('reviewer_user_ids', current.filter((r) => r !== id));
        } else {
            form.setData('reviewer_user_ids', [...current, id]);
        }
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/hr/feedback/request');
    };

    // Exclude the subject from the reviewer list
    const availableReviewers = employees.filter(
        (emp) => String(emp.id) !== form.data.subject_user_id
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Request 360 Feedback" />
            <div className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">Request 360-Degree Feedback</h1>
                    <p className="text-sm text-muted-foreground">
                        Select an employee and choose reviewers to provide feedback
                    </p>
                </div>

                <form onSubmit={submit} className="max-w-2xl space-y-6">
                    {/* Subject Employee */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Subject Employee</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label>Employee being reviewed</Label>
                                <Select
                                    value={form.data.subject_user_id}
                                    onValueChange={(v) => form.setData('subject_user_id', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select an employee" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {employees.map((emp) => (
                                            <SelectItem key={emp.id} value={String(emp.id)}>
                                                {emp.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.subject_user_id && (
                                    <p className="text-sm text-destructive">{form.errors.subject_user_id}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label>Review Type</Label>
                                <Select
                                    value={form.data.review_type}
                                    onValueChange={(v) => form.setData('review_type', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select review type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {reviewTypes.map((type) => (
                                            <SelectItem key={type} value={type}>
                                                {reviewTypeLabels[type] || type}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.review_type && (
                                    <p className="text-sm text-destructive">{form.errors.review_type}</p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Select Reviewers */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Select Reviewers</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {form.errors.reviewer_user_ids && (
                                <p className="mb-3 text-sm text-destructive">{form.errors.reviewer_user_ids}</p>
                            )}
                            <div className="max-h-64 space-y-2 overflow-y-auto">
                                {availableReviewers.map((emp) => (
                                    <label
                                        key={emp.id}
                                        className="flex cursor-pointer items-center gap-3 rounded-md border p-3 hover:bg-muted/50"
                                    >
                                        <Checkbox
                                            checked={form.data.reviewer_user_ids.includes(String(emp.id))}
                                            onCheckedChange={() => toggleReviewer(String(emp.id))}
                                        />
                                        <span className="text-sm">{emp.name}</span>
                                    </label>
                                ))}
                                {availableReviewers.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        Select a subject employee first to see available reviewers
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Questions Preview */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Feedback Questions</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="mb-3 text-sm text-muted-foreground">
                                Reviewers will be asked to rate and comment on the following areas:
                            </p>
                            <ul className="space-y-2">
                                {Object.entries(questions).map(([key, question]) => (
                                    <li key={key} className="flex items-start gap-2 text-sm">
                                        <span className="mt-0.5 h-1.5 w-1.5 shrink-0 rounded-full bg-primary" />
                                        {question}
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>

                    <div className="flex gap-3">
                        <Button type="submit" disabled={form.processing}>
                            Send Feedback Requests
                        </Button>
                        <Button type="button" variant="outline" onClick={() => router.get('/hr/feedback')}>
                            Cancel
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
