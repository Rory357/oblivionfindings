import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head, useForm } from '@inertiajs/react';
import { Activity } from 'lucide-react';

type Props = {
    staff: Array<{ id: number; name: string }>;
};

const ASSESSMENT_TYPES = [
    { value: 'new_project', label: 'New Project' },
    { value: 'process_change', label: 'Process Change' },
    { value: 'system_upgrade', label: 'System Upgrade' },
    { value: 'periodic_review', label: 'Periodic Review' },
];

const RISK_LEVELS = ['low', 'medium', 'high', 'very_high'] as const;

const parseList = (value: string) => {
    const items = value
        .split(/\r?\n|,/)
        .map((v) => v.trim())
        .filter(Boolean);
    return items.length ? items : null;
};

export default function CreateDPIA({ staff: _staff }: Props) {
    const form = useForm({
        assessment_name: '',
        project_or_process: '',
        description: '',
        assessment_type: '',
        personal_data_types: '',
        data_subjects: '',
        processing_purpose: '',
        legal_basis: '',
        identified_risks: '',
        overall_risk_level: '',
        mitigation_measures: '',
        residual_risk_level: '',
        review_date: '',
    });

    const { data, setData, processing, errors } = form;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const payload = {
            ...data,
            personal_data_types: parseList(data.personal_data_types),
            data_subjects: parseList(data.data_subjects),
            identified_risks: parseList(data.identified_risks),
            mitigation_measures: parseList(data.mitigation_measures),
            residual_risk_level: data.residual_risk_level || null,
            review_date: data.review_date || null,
            description: data.description || null,
        };
        form.transform(() => payload);
        form.post('/privacy/dpia', {
            onFinish: () => form.transform((d) => d),
        });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
            { title: 'Impact Assessments', href: '/privacy/dpia' },
            { title: 'New DPIA', href: '/privacy/dpia/create' },
        ]}>
            <Head title="New DPIA" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">New DPIA</h1>
                    <div className="mt-1 text-sm text-muted-foreground">
                        Document data processing risks and mitigation steps.
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Activity className="h-5 w-5 text-green-500" />
                                Assessment Overview
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Assessment Name *</Label>
                                    <Input
                                        value={data.assessment_name}
                                        onChange={(e) => setData('assessment_name', e.target.value)}
                                    />
                                    {errors.assessment_name && <p className="text-xs text-red-500">{errors.assessment_name}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Project or Process *</Label>
                                    <Input
                                        value={data.project_or_process}
                                        onChange={(e) => setData('project_or_process', e.target.value)}
                                    />
                                    {errors.project_or_process && <p className="text-xs text-red-500">{errors.project_or_process}</p>}
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Assessment Type *</Label>
                                    <Select value={data.assessment_type} onValueChange={(v) => setData('assessment_type', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                        <SelectContent>
                                            {ASSESSMENT_TYPES.map((t) => (
                                                <SelectItem key={t.value} value={t.value}>
                                                    {t.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.assessment_type && <p className="text-xs text-red-500">{errors.assessment_type}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Review Date</Label>
                                    <Input
                                        type="date"
                                        value={data.review_date}
                                        onChange={(e) => setData('review_date', e.target.value)}
                                    />
                                    {errors.review_date && <p className="text-xs text-red-500">{errors.review_date}</p>}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label>Description</Label>
                                <Textarea
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    rows={3}
                                />
                                {errors.description && <p className="text-xs text-red-500">{errors.description}</p>}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Processing Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label>Processing Purpose *</Label>
                                <Textarea
                                    value={data.processing_purpose}
                                    onChange={(e) => setData('processing_purpose', e.target.value)}
                                    rows={3}
                                />
                                {errors.processing_purpose && <p className="text-xs text-red-500">{errors.processing_purpose}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label>Legal Basis *</Label>
                                <Textarea
                                    value={data.legal_basis}
                                    onChange={(e) => setData('legal_basis', e.target.value)}
                                    rows={3}
                                />
                                {errors.legal_basis && <p className="text-xs text-red-500">{errors.legal_basis}</p>}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Personal Data Types</Label>
                                    <Textarea
                                        value={data.personal_data_types}
                                        onChange={(e) => setData('personal_data_types', e.target.value)}
                                        rows={3}
                                        placeholder="Comma or newline separated"
                                    />
                                    {errors.personal_data_types && <p className="text-xs text-red-500">{errors.personal_data_types}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Data Subjects</Label>
                                    <Textarea
                                        value={data.data_subjects}
                                        onChange={(e) => setData('data_subjects', e.target.value)}
                                        rows={3}
                                        placeholder="Comma or newline separated"
                                    />
                                    {errors.data_subjects && <p className="text-xs text-red-500">{errors.data_subjects}</p>}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Risk Assessment</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label>Identified Risks</Label>
                                <Textarea
                                    value={data.identified_risks}
                                    onChange={(e) => setData('identified_risks', e.target.value)}
                                    rows={3}
                                    placeholder="Comma or newline separated"
                                />
                                {errors.identified_risks && <p className="text-xs text-red-500">{errors.identified_risks}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label>Mitigation Measures</Label>
                                <Textarea
                                    value={data.mitigation_measures}
                                    onChange={(e) => setData('mitigation_measures', e.target.value)}
                                    rows={3}
                                    placeholder="Comma or newline separated"
                                />
                                {errors.mitigation_measures && <p className="text-xs text-red-500">{errors.mitigation_measures}</p>}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Overall Risk Level *</Label>
                                    <Select value={data.overall_risk_level} onValueChange={(v) => setData('overall_risk_level', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select risk level" /></SelectTrigger>
                                        <SelectContent>
                                            {RISK_LEVELS.map((level) => (
                                                <SelectItem key={level} value={level}>
                                                    {level}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.overall_risk_level && <p className="text-xs text-red-500">{errors.overall_risk_level}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Residual Risk Level</Label>
                                    <Select value={data.residual_risk_level || ''} onValueChange={(v) => setData('residual_risk_level', v)}>
                                        <SelectTrigger><SelectValue placeholder="Optional" /></SelectTrigger>
                                        <SelectContent>
                                            {RISK_LEVELS.map((level) => (
                                                <SelectItem key={level} value={level}>
                                                    {level}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.residual_risk_level && <p className="text-xs text-red-500">{errors.residual_risk_level}</p>}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => window.history.back()}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create DPIA'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
