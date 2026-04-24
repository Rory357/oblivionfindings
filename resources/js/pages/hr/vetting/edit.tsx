import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

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

type StaffBackgroundCheck = {
    id: number;
    user_id: number;
    check_type: string;
    status: string;
    provider: string | null;
    reference_number: string | null;
    check_date: string | null;
    issue_date: string | null;
    expires_at: string | null;
    disclosures_present: boolean;
    disclosure_details: string | null;
    conditions: string | null;
    risk_assessed: boolean;
    risk_assessment: string | null;
    risk_decision: string | null;
    certificate_path: string | null;
    enrolled_in_update_service: boolean;
    update_service_reference: string | null;
    notes: string | null;
    user: {
        id: number;
        name: string;
        email: string;
    };
};

type Props = {
    check: StaffBackgroundCheck;
    staff: Staff[];
    checkTypes: Option[];
    statuses: Option[];
    riskDecisions: Option[];
};

export default function EditVetting({ check, staff, checkTypes, statuses, riskDecisions }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Vetting Register', href: '/hr/compliance/vetting' },
        { title: check.user.name, href: `/hr/compliance/vetting/${check.id}` },
        { title: 'Edit', href: `/hr/compliance/vetting/${check.id}/edit` },
    ];

    const { data, setData, put, processing, errors } = useForm({
        check_type: check.check_type,
        status: check.status,
        provider: check.provider || '',
        reference_number: check.reference_number || '',
        check_date: check.check_date?.split('T')[0] || '',
        issue_date: check.issue_date?.split('T')[0] || '',
        expires_at: check.expires_at?.split('T')[0] || '',
        disclosures_present: check.disclosures_present || false,
        disclosure_details: check.disclosure_details || '',
        conditions: check.conditions || '',
        risk_assessed: check.risk_assessed || false,
        risk_assessment: check.risk_assessment || '',
        risk_decision: check.risk_decision || '',
        enrolled_in_update_service: check.enrolled_in_update_service || false,
        update_service_reference: check.update_service_reference || '',
        notes: check.notes || '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/hr/compliance/vetting/${check.id}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit Background Check" />

            <div className="space-y-6 max-w-4xl">
                <div className="flex items-center gap-4">
                    <Link href={`/hr/compliance/vetting/${check.id}`}>
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-2xl font-bold">Edit Background Check</h1>
                        <p className="text-muted-foreground">Update vetting record for {check.user.name}</p>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Check Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="user_id">Staff Member</Label>
                                    <Input value={check.user.name} disabled className="bg-muted" />
                                    <p className="text-xs text-muted-foreground">Employee cannot be changed</p>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="check_type">
                                        Check Type <span className="text-status-critical">*</span>
                                    </Label>
                                    <Select
                                        value={data.check_type}
                                        onValueChange={(value) => setData('check_type', value)}
                                    >
                                        <SelectTrigger id="check_type" className={errors.check_type ? 'border-status-critical/30' : ''}>
                                            <SelectValue placeholder="Select check type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {checkTypes.map((type) => (
                                                <SelectItem key={type.value} value={type.value}>
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.check_type && (
                                        <p className="text-sm text-status-critical">{errors.check_type}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="status">
                                        Status <span className="text-status-critical">*</span>
                                    </Label>
                                    <Select
                                        value={data.status}
                                        onValueChange={(value) => setData('status', value)}
                                    >
                                        <SelectTrigger id="status" className={errors.status ? 'border-status-critical/30' : ''}>
                                            <SelectValue placeholder="Select status" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {statuses.map((s) => (
                                                <SelectItem key={s.value} value={s.value}>
                                                    {s.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.status && (
                                        <p className="text-sm text-status-critical">{errors.status}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="provider">Provider</Label>
                                    <Input
                                        id="provider"
                                        value={data.provider}
                                        onChange={(e) => setData('provider', e.target.value)}
                                        placeholder="e.g., NZ Police Vetting Service"
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="reference_number">Reference Number</Label>
                                    <Input
                                        id="reference_number"
                                        value={data.reference_number}
                                        onChange={(e) => setData('reference_number', e.target.value)}
                                        placeholder="e.g., VET-12345"
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="check_date">Check Date</Label>
                                    <Input
                                        id="check_date"
                                        type="date"
                                        value={data.check_date}
                                        onChange={(e) => setData('check_date', e.target.value)}
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="issue_date">Issue Date</Label>
                                    <Input
                                        id="issue_date"
                                        type="date"
                                        value={data.issue_date}
                                        onChange={(e) => setData('issue_date', e.target.value)}
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="expires_at">Expiry Date</Label>
                                    <Input
                                        id="expires_at"
                                        type="date"
                                        value={data.expires_at}
                                        onChange={(e) => setData('expires_at', e.target.value)}
                                    />
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="General notes about this check..."
                                    rows={3}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Disclosure Information</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="disclosures_present"
                                    checked={data.disclosures_present}
                                    onCheckedChange={(checked) => setData('disclosures_present', checked as boolean)}
                                />
                                <Label htmlFor="disclosures_present" className="text-sm font-normal">
                                    Disclosures present on check
                                </Label>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="disclosure_details">Disclosure Details</Label>
                                <Textarea
                                    id="disclosure_details"
                                    value={data.disclosure_details}
                                    onChange={(e) => setData('disclosure_details', e.target.value)}
                                    placeholder="Details of any disclosures found..."
                                    rows={3}
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="conditions">Conditions</Label>
                                <Textarea
                                    id="conditions"
                                    value={data.conditions}
                                    onChange={(e) => setData('conditions', e.target.value)}
                                    placeholder="Any conditions placed on employment..."
                                    rows={2}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Risk Assessment</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="risk_assessed"
                                    checked={data.risk_assessed}
                                    onCheckedChange={(checked) => setData('risk_assessed', checked as boolean)}
                                />
                                <Label htmlFor="risk_assessed" className="text-sm font-normal">
                                    Risk assessment completed
                                </Label>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="risk_decision">Risk Decision</Label>
                                <Select
                                    value={data.risk_decision}
                                    onValueChange={(value) => setData('risk_decision', value)}
                                >
                                    <SelectTrigger id="risk_decision">
                                        <SelectValue placeholder="Select decision" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {riskDecisions.map((d) => (
                                            <SelectItem key={d.value} value={d.value}>
                                                {d.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="risk_assessment">Risk Assessment Notes</Label>
                                <Textarea
                                    id="risk_assessment"
                                    value={data.risk_assessment}
                                    onChange={(e) => setData('risk_assessment', e.target.value)}
                                    placeholder="Details of risk assessment conducted..."
                                    rows={4}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Update Service</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="enrolled_in_update_service"
                                    checked={data.enrolled_in_update_service}
                                    onCheckedChange={(checked) => setData('enrolled_in_update_service', checked as boolean)}
                                />
                                <Label htmlFor="enrolled_in_update_service" className="text-sm font-normal">
                                    Enrolled in update service
                                </Label>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="update_service_reference">Update Service Reference</Label>
                                <Input
                                    id="update_service_reference"
                                    value={data.update_service_reference}
                                    onChange={(e) => setData('update_service_reference', e.target.value)}
                                    placeholder="e.g., US-12345"
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-4">
                        <Link href={`/hr/compliance/vetting/${check.id}`}>
                            <Button type="button" variant="outline">Cancel</Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Update Background Check'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
