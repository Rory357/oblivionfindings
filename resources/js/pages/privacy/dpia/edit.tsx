import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head, useForm } from '@inertiajs/react';
import { Activity } from 'lucide-react';

type DPIA = {
    id: number;
    assessment_name: string;
    project_or_process: string;
    description: string | null;
    assessment_type: string;
    personal_data_types: string[] | null;
    data_subjects: string[] | null;
    processing_purpose: string;
    legal_basis: string;
    identified_risks: string[] | null;
    overall_risk_level: string;
    mitigation_measures: string[] | null;
    residual_risk_level: string | null;
    review_date: string | null;
    outcome: string | null;
};

type Props = {
    dpia: DPIA;
    staff: Array<{ id: number; name: string }>;
};

const RISK_LEVELS = ['low', 'medium', 'high', 'very_high'] as const;

const parseList = (value: string) => {
    const items = value
        .split(/\r?\n|,/)
        .map((v) => v.trim())
        .filter(Boolean);
    return items.length ? items : null;
};

export default function EditDPIA({ dpia, staff: _staff }: Props) {
    const form = useForm({
        assessment_name: dpia.assessment_name || '',
        project_or_process: dpia.project_or_process || '',
        description: dpia.description || '',
        personal_data_types: (dpia.personal_data_types || []).join('\n'),
        data_subjects: (dpia.data_subjects || []).join('\n'),
        processing_purpose: dpia.processing_purpose || '',
        legal_basis: dpia.legal_basis || '',
        identified_risks: (dpia.identified_risks || []).join('\n'),
        overall_risk_level: dpia.overall_risk_level || '',
        mitigation_measures: (dpia.mitigation_measures || []).join('\n'),
        residual_risk_level: dpia.residual_risk_level || '',
        review_date: dpia.review_date || '',
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
        form.put(`/privacy/dpia/${dpia.id}`, {
            onFinish: () => form.transform((d) => d),
        });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
            { title: 'Impact Assessments', href: '/privacy/dpia' },
            { title: dpia.assessment_name, href: `/privacy/dpia/${dpia.id}/edit` },
        ]}>
            <Head title={`Edit DPIA - ${dpia.assessment_name}`} />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">Edit DPIA</h1>
                    <div className="mt-1 text-sm text-muted-foreground">
                        Outcome: {dpia.outcome ? dpia.outcome.replace(/_/g, ' ') : 'Pending review'}
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Activity className="h-5 w-5 text-status-success" />
                                Assessment Overview
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Assessment Name</Label>
                                    <Input
                                        value={data.assessment_name}
                                        onChange={(e) => setData('assessment_name', e.target.value)}
                                    />
                                    {errors.assessment_name && <p className="text-xs text-status-critical">{errors.assessment_name}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Project or Process</Label>
                                    <Input
                                        value={data.project_or_process}
                                        onChange={(e) => setData('project_or_process', e.target.value)}
                                    />
                                    {errors.project_or_process && <p className="text-xs text-status-critical">{errors.project_or_process}</p>}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label>Description</Label>
                                <Textarea
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    rows={3}
                                />
                                {errors.description && <p className="text-xs text-status-critical">{errors.description}</p>}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Processing Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label>Processing Purpose</Label>
                                <Textarea
                                    value={data.processing_purpose}
                                    onChange={(e) => setData('processing_purpose', e.target.value)}
                                    rows={3}
                                />
                                {errors.processing_purpose && <p className="text-xs text-status-critical">{errors.processing_purpose}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label>Legal Basis</Label>
                                <Textarea
                                    value={data.legal_basis}
                                    onChange={(e) => setData('legal_basis', e.target.value)}
                                    rows={3}
                                />
                                {errors.legal_basis && <p className="text-xs text-status-critical">{errors.legal_basis}</p>}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Personal Data Types</Label>
                                    <Textarea
                                        value={data.personal_data_types}
                                        onChange={(e) => setData('personal_data_types', e.target.value)}
                                        rows={3}
                                    />
                                    {errors.personal_data_types && <p className="text-xs text-status-critical">{errors.personal_data_types}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Data Subjects</Label>
                                    <Textarea
                                        value={data.data_subjects}
                                        onChange={(e) => setData('data_subjects', e.target.value)}
                                        rows={3}
                                    />
                                    {errors.data_subjects && <p className="text-xs text-status-critical">{errors.data_subjects}</p>}
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
                                />
                                {errors.identified_risks && <p className="text-xs text-status-critical">{errors.identified_risks}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label>Mitigation Measures</Label>
                                <Textarea
                                    value={data.mitigation_measures}
                                    onChange={(e) => setData('mitigation_measures', e.target.value)}
                                    rows={3}
                                />
                                {errors.mitigation_measures && <p className="text-xs text-status-critical">{errors.mitigation_measures}</p>}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Overall Risk Level</Label>
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
                                    {errors.overall_risk_level && <p className="text-xs text-status-critical">{errors.overall_risk_level}</p>}
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
                                    {errors.residual_risk_level && <p className="text-xs text-status-critical">{errors.residual_risk_level}</p>}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label>Review Date</Label>
                                <Input
                                    type="date"
                                    value={data.review_date}
                                    onChange={(e) => setData('review_date', e.target.value)}
                                />
                                {errors.review_date && <p className="text-xs text-status-critical">{errors.review_date}</p>}
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => window.history.back()}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
