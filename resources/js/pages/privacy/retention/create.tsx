import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Head, useForm } from '@inertiajs/react';
import { Database } from 'lucide-react';

export default function CreateRetentionPolicy() {
    const { data, setData, post, processing, errors } = useForm({
        model_type: '',
        policy_name: '',
        description: '',
        retention_period_years: '',
        archive_after_years: '',
        hard_delete_after_years: '',
        legal_basis: '',
        business_justification: '',
        applies_to_soft_deleted: true,
        legal_hold_exemption: true,
        active_case_exemption: true,
        active: true,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/privacy/retention');
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
            { title: 'Retention Policies', href: '/privacy/retention' },
            { title: 'New Policy', href: '/privacy/retention/create' },
        ]}>
            <Head title="New Data Retention Policy" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">New Data Retention Policy</h1>
                        <div className="mt-1 text-sm text-muted-foreground">
                            Define retention periods for data types
                        </div>
                    </div>
                </div>

                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Database className="h-5 w-5 text-primary" />
                                Policy Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="policy_name">Policy Name *</Label>
                                    <Input
                                        id="policy_name"
                                        value={data.policy_name}
                                        onChange={(e) => setData('policy_name', e.target.value)}
                                        placeholder="e.g., Client Records Retention"
                                    />
                                    {errors.policy_name && (
                                        <p className="text-xs text-red-500">{errors.policy_name}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="model_type">Data Type (Model) *</Label>
                                    <Input
                                        id="model_type"
                                        value={data.model_type}
                                        onChange={(e) => setData('model_type', e.target.value)}
                                        placeholder="e.g., App\\Models\\Client"
                                    />
                                    {errors.model_type && (
                                        <p className="text-xs text-red-500">{errors.model_type}</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Describe what this policy covers"
                                    rows={2}
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="space-y-2">
                                    <Label htmlFor="retention_period_years">Retention Period (Years) *</Label>
                                    <Input
                                        id="retention_period_years"
                                        type="number"
                                        min="1"
                                        max="100"
                                        value={data.retention_period_years}
                                        onChange={(e) => setData('retention_period_years', e.target.value)}
                                    />
                                    {errors.retention_period_years && (
                                        <p className="text-xs text-red-500">{errors.retention_period_years}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="archive_after_years">Archive After (Years)</Label>
                                    <Input
                                        id="archive_after_years"
                                        type="number"
                                        min="1"
                                        max="100"
                                        value={data.archive_after_years}
                                        onChange={(e) => setData('archive_after_years', e.target.value)}
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="hard_delete_after_years">Hard Delete After (Years)</Label>
                                    <Input
                                        id="hard_delete_after_years"
                                        type="number"
                                        min="1"
                                        max="100"
                                        value={data.hard_delete_after_years}
                                        onChange={(e) => setData('hard_delete_after_years', e.target.value)}
                                    />
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="legal_basis">Legal Basis</Label>
                                <Input
                                    id="legal_basis"
                                    value={data.legal_basis}
                                    onChange={(e) => setData('legal_basis', e.target.value)}
                                    placeholder="e.g., GDPR Article 17, Care Act 2014"
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="business_justification">Business Justification</Label>
                                <Textarea
                                    id="business_justification"
                                    value={data.business_justification}
                                    onChange={(e) => setData('business_justification', e.target.value)}
                                    placeholder="Explain why this retention period is appropriate"
                                    rows={2}
                                />
                            </div>

                            <div className="space-y-3 pt-2">
                                <div className="flex items-center space-x-2">
                                    <Checkbox
                                        id="applies_to_soft_deleted"
                                        checked={data.applies_to_soft_deleted}
                                        onCheckedChange={(checked) => setData('applies_to_soft_deleted', checked as boolean)}
                                    />
                                    <Label htmlFor="applies_to_soft_deleted" className="text-sm font-normal">
                                        Applies to soft-deleted records
                                    </Label>
                                </div>

                                <div className="flex items-center space-x-2">
                                    <Checkbox
                                        id="legal_hold_exemption"
                                        checked={data.legal_hold_exemption}
                                        onCheckedChange={(checked) => setData('legal_hold_exemption', checked as boolean)}
                                    />
                                    <Label htmlFor="legal_hold_exemption" className="text-sm font-normal">
                                        Exempt records under legal hold
                                    </Label>
                                </div>

                                <div className="flex items-center space-x-2">
                                    <Checkbox
                                        id="active_case_exemption"
                                        checked={data.active_case_exemption}
                                        onCheckedChange={(checked) => setData('active_case_exemption', checked as boolean)}
                                    />
                                    <Label htmlFor="active_case_exemption" className="text-sm font-normal">
                                        Exempt active cases
                                    </Label>
                                </div>

                                <div className="flex items-center space-x-2">
                                    <Checkbox
                                        id="active"
                                        checked={data.active}
                                        onCheckedChange={(checked) => setData('active', checked as boolean)}
                                    />
                                    <Label htmlFor="active" className="text-sm font-normal">
                                        Policy is active
                                    </Label>
                                </div>
                            </div>

                            <div className="flex justify-end gap-2 pt-4">
                                <Button type="button" variant="outline" onClick={() => window.history.back()}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Creating...' : 'Create Policy'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </div>
        </AppLayout>
    );
}
