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
import { ShieldAlert } from 'lucide-react';

interface Risk {
    id: number;
    title: string;
    category: string;
    description: string;
    likelihood_score: number;
    impact_score: number;
    control_effectiveness: string;
    mitigation_strategy: string;
    review_frequency: string;
}

export default function EditRisk({ auth, risk }: { auth: any; risk: Risk }) {
    const { data, setData, put, processing, errors } = useForm({
        title: risk.title,
        category: risk.category,
        description: risk.description,
        likelihood_score: risk.likelihood_score,
        impact_score: risk.impact_score,
        control_effectiveness: risk.control_effectiveness,
        mitigation_strategy: risk.mitigation_strategy,
        review_frequency: risk.review_frequency,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/governance/risks/${risk.id}`);
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Risks', href: '/governance/risks' },
                { title: risk.title, href: `/governance/risks/${risk.id}` },
                { title: 'Edit', href: `/governance/risks/${risk.id}/edit` },
            ]}
        >
            <Head title={`Edit: ${risk.title}`} />
            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        backHref="/governance/risks"
                        icon={ShieldAlert}
                        title="Edit Risk"
                        description={risk.title}
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <CardTitle>Risk Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <Label htmlFor="title">Risk Title</Label>
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={(e) =>
                                        setData('title', e.target.value)
                                    }
                                />
                                {errors.title && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.title}
                                    </p>
                                )}
                            </div>
                            <div>
                                <Label htmlFor="category">Category</Label>
                                <Select
                                    value={data.category}
                                    onValueChange={(v) =>
                                        setData('category', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="client_safety">
                                            Client Safety
                                        </SelectItem>
                                        <SelectItem value="reputational">
                                            Reputational
                                        </SelectItem>
                                        <SelectItem value="financial">
                                            Financial
                                        </SelectItem>
                                        <SelectItem value="it_cyber">
                                            IT/Cyber
                                        </SelectItem>
                                        <SelectItem value="workforce">
                                            Workforce
                                        </SelectItem>
                                        <SelectItem value="legal_compliance">
                                            Legal/Compliance
                                        </SelectItem>
                                        <SelectItem value="operational">
                                            Operational
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    value={data.description}
                                    onChange={(e) =>
                                        setData('description', e.target.value)
                                    }
                                    rows={4}
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label>Likelihood (1-5)</Label>
                                    <Input
                                        type="number"
                                        min={1}
                                        max={5}
                                        value={data.likelihood_score}
                                        onChange={(e) =>
                                            setData(
                                                'likelihood_score',
                                                parseInt(e.target.value),
                                            )
                                        }
                                    />
                                </div>
                                <div>
                                    <Label>Impact (1-5)</Label>
                                    <Input
                                        type="number"
                                        min={1}
                                        max={5}
                                        value={data.impact_score}
                                        onChange={(e) =>
                                            setData(
                                                'impact_score',
                                                parseInt(e.target.value),
                                            )
                                        }
                                    />
                                </div>
                            </div>
                            <div>
                                <Label>Control Effectiveness</Label>
                                <Select
                                    value={data.control_effectiveness}
                                    onValueChange={(v) =>
                                        setData('control_effectiveness', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            None
                                        </SelectItem>
                                        <SelectItem value="weak">
                                            Weak
                                        </SelectItem>
                                        <SelectItem value="moderate">
                                            Moderate
                                        </SelectItem>
                                        <SelectItem value="strong">
                                            Strong
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Mitigation Strategy</Label>
                                <Select
                                    value={data.mitigation_strategy}
                                    onValueChange={(v) =>
                                        setData('mitigation_strategy', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="treat">
                                            Treat
                                        </SelectItem>
                                        <SelectItem value="transfer">
                                            Transfer
                                        </SelectItem>
                                        <SelectItem value="terminate">
                                            Terminate
                                        </SelectItem>
                                        <SelectItem value="tolerate">
                                            Tolerate
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex gap-2 pt-4">
                                <Button type="submit" disabled={processing}>
                                    Update Risk
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
