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
import { Head, useForm } from '@inertiajs/react';
import { Shield } from 'lucide-react';

interface Obligation {
    id: number;
    obligation_title: string;
    framework: string;
    description: string;
    due_date: string | null;
    review_frequency: string;
    status: string;
}

export default function EditCompliance({
    auth,
    obligation,
}: {
    auth: any;
    obligation: Obligation;
}) {
    const { data, setData, put, processing, errors } = useForm({
        obligation_title: obligation.obligation_title,
        framework: obligation.framework,
        description: obligation.description ?? '',
        due_date: obligation.due_date ?? '',
        review_frequency: obligation.review_frequency ?? 'annual',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/governance/compliance/${obligation.id}`);
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Compliance', href: '/governance/compliance' },
                {
                    title: obligation.obligation_title,
                    href: `/governance/compliance/${obligation.id}`,
                },
                {
                    title: 'Edit',
                    href: `/governance/compliance/${obligation.id}/edit`,
                },
            ]}
        >
            <Head title={`Edit: ${obligation.obligation_title}`} />
            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        backHref={`/governance/compliance/${obligation.id}`}
                        icon={Shield}
                        title="Edit Obligation"
                        description={obligation.obligation_title}
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <CardTitle>Obligation Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <Label>Title</Label>
                                <Input
                                    value={data.obligation_title}
                                    onChange={(e) =>
                                        setData(
                                            'obligation_title',
                                            e.target.value,
                                        )
                                    }
                                />
                                {errors.obligation_title && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.obligation_title}
                                    </p>
                                )}
                            </div>
                            <div>
                                <Label>Framework</Label>
                                <Select
                                    value={data.framework}
                                    onValueChange={(v) =>
                                        setData('framework', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="charities">
                                            Charities Services
                                        </SelectItem>
                                        <SelectItem value="nga_paerewa">
                                            Nga Paerewa NZS 8134:2021
                                        </SelectItem>
                                        <SelectItem value="health_disability_act">
                                            Health & Disability Services Act
                                        </SelectItem>
                                        <SelectItem value="privacy_act">
                                            Privacy Act 2020
                                        </SelectItem>
                                        <SelectItem value="hswa">
                                            HSWA 2015
                                        </SelectItem>
                                        <SelectItem value="employment">
                                            Employment
                                        </SelectItem>
                                        <SelectItem value="funding">
                                            Funding/Contract
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Description</Label>
                                <Textarea
                                    value={data.description}
                                    onChange={(e) =>
                                        setData('description', e.target.value)
                                    }
                                    rows={4}
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label>Due Date</Label>
                                    <Input
                                        type="date"
                                        value={data.due_date}
                                        onChange={(e) =>
                                            setData('due_date', e.target.value)
                                        }
                                    />
                                </div>
                                <div>
                                    <Label>Review Frequency</Label>
                                    <Select
                                        value={data.review_frequency}
                                        onValueChange={(v) =>
                                            setData('review_frequency', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="monthly">
                                                Monthly
                                            </SelectItem>
                                            <SelectItem value="quarterly">
                                                Quarterly
                                            </SelectItem>
                                            <SelectItem value="annual">
                                                Annual
                                            </SelectItem>
                                            <SelectItem value="biennial">
                                                Biennial
                                            </SelectItem>
                                            <SelectItem value="triennial">
                                                Triennial
                                            </SelectItem>
                                            <SelectItem value="as_needed">
                                                As Needed
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="flex gap-2 pt-4">
                                <Button type="submit" disabled={processing}>
                                    Update Obligation
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => window.history.back()}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
