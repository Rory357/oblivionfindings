import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    ClipboardCheck,
    FileWarning,
    LogIn,
    LogOut,
    MessageSquareWarning,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { RespiteStayRow } from '../types';

function nowInput() {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 16);
}

export function CheckInModal({
    stay,
    onClose,
}: {
    stay: RespiteStayRow | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [acknowledged, setAcknowledged] = useState(false);
    const [epipenLocation, setEpipenLocation] = useState('');
    const [escalationNote, setEscalationNote] = useState('');
    const needsAnaphylaxis = stay?.criticalAlerts.some(
        (alert) => alert.type === 'allergy' && alert.requiresAcknowledgement,
    );

    useEffect(() => {
        if (!stay) return;
        setProcessing(false);
        setAcknowledged(!needsAnaphylaxis);
        setEpipenLocation('');
        setEscalationNote('');
    }, [needsAnaphylaxis, stay]);

    const canSubmit =
        !!stay &&
        (!needsAnaphylaxis ||
            (acknowledged && epipenLocation.trim() && escalationNote.trim()));

    const submit = () => {
        if (!stay || !canSubmit) return;
        setProcessing(true);
        router.post(
            `/respite/stays/${stay.id}/check-in`,
            {
                anaphylaxis_acknowledged: acknowledged,
                epipen_location: epipenLocation || null,
                anaphylaxis_escalation_note: escalationNote || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={stay != null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                {stay ? (
                    <>
                        <div>
                            <DialogTitle className="text-left text-lg">
                                Check in {stay.client}
                            </DialogTitle>
                            <DialogDescription className="text-left">
                                Complete the arrival safety acknowledgement before activating the stay.
                            </DialogDescription>
                        </div>
                        {needsAnaphylaxis ? (
                            <div className="grid gap-3 rounded-[10px] border border-status-critical/30 bg-status-critical-bg p-3">
                                <div className="flex items-start gap-2 text-sm font-semibold text-status-critical">
                                    <AlertTriangle className="mt-0.5 h-4 w-4" />
                                    Life-threatening allergy acknowledgement
                                </div>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={acknowledged}
                                        onChange={(event) =>
                                            setAcknowledged(
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    Emergency response plan sighted
                                </label>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="respite-epipen-location">
                                        EpiPen / emergency medicine location
                                    </Label>
                                    <Input
                                        id="respite-epipen-location"
                                        value={epipenLocation}
                                        onChange={(event) =>
                                            setEpipenLocation(
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="respite-escalation-note">
                                        Escalation note
                                    </Label>
                                    <Textarea
                                        id="respite-escalation-note"
                                        value={escalationNote}
                                        onChange={(event) =>
                                            setEscalationNote(
                                                event.target.value,
                                            )
                                        }
                                        rows={3}
                                    />
                                </div>
                            </div>
                        ) : null}
                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button
                                onClick={submit}
                                disabled={processing || !canSubmit}
                            >
                                <LogIn className="h-4 w-4" />
                                Check in
                            </Button>
                        </div>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

export function MedicationReconciliationModal({
    stay,
    onClose,
}: {
    stay: RespiteStayRow | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [source, setSource] = useState('pharmacy_pack');
    const [count, setCount] = useState('0');
    const [discrepancy, setDiscrepancy] = useState('');
    const [firstDoseDue, setFirstDoseDue] = useState('');

    useEffect(() => {
        if (!stay) return;
        setProcessing(false);
        setSource('pharmacy_pack');
        setCount('0');
        setDiscrepancy('');
        setFirstDoseDue('');
    }, [stay]);

    const submit = () => {
        if (!stay) return;
        setProcessing(true);
        router.post(
            `/respite/stays/${stay.id}/medication-reconciliation`,
            {
                type: 'admission',
                status: 'completed',
                source,
                count_received: Number(count || 0),
                discrepancies: discrepancy.trim()
                    ? [{ note: discrepancy.trim() }]
                    : [],
                first_dose_due_at: firstDoseDue || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={stay != null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                {stay ? (
                    <>
                        <div>
                            <DialogTitle className="text-left text-lg">
                                Reconcile medications
                            </DialogTitle>
                            <DialogDescription className="text-left">
                                Complete admission medication reconciliation for {stay.client}.
                            </DialogDescription>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label>Source</Label>
                                <Select value={source} onValueChange={setSource}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="pharmacy_pack">
                                            Pharmacy pack
                                        </SelectItem>
                                        <SelectItem value="mar_chart">
                                            MAR chart
                                        </SelectItem>
                                        <SelectItem value="gp_list">
                                            GP list
                                        </SelectItem>
                                        <SelectItem value="whanau">
                                            Family / whanau
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="respite-med-count">
                                    Count received
                                </Label>
                                <Input
                                    id="respite-med-count"
                                    type="number"
                                    min="0"
                                    value={count}
                                    onChange={(event) =>
                                        setCount(event.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5 sm:col-span-2">
                                <Label htmlFor="respite-first-dose">
                                    First dose due
                                </Label>
                                <Input
                                    id="respite-first-dose"
                                    type="datetime-local"
                                    value={firstDoseDue}
                                    onChange={(event) =>
                                        setFirstDoseDue(event.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5 sm:col-span-2">
                                <Label htmlFor="respite-med-discrepancy">
                                    Discrepancies
                                </Label>
                                <Textarea
                                    id="respite-med-discrepancy"
                                    value={discrepancy}
                                    onChange={(event) =>
                                        setDiscrepancy(event.target.value)
                                    }
                                    rows={3}
                                />
                            </div>
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button onClick={submit} disabled={processing}>
                                <ClipboardCheck className="h-4 w-4" />
                                Save med-rec
                            </Button>
                        </div>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

export function IncidentModal({
    stay,
    onClose,
}: {
    stay: RespiteStayRow | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [type, setType] = useState('injury');
    const [severity, setSeverity] = useState('medium');
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [isNotifiable, setIsNotifiable] = useState(false);
    const [authority, setAuthority] = useState('health_nz');
    const [incidentType, setIncidentType] = useState('health_safety');

    useEffect(() => {
        if (!stay) return;
        setProcessing(false);
        setType('injury');
        setSeverity('medium');
        setTitle('');
        setDescription('');
        setIsNotifiable(false);
        setAuthority('health_nz');
        setIncidentType('health_safety');
    }, [stay]);

    const canSubmit = !!stay && title.trim() && description.trim();

    const submit = () => {
        if (!stay || !canSubmit) return;
        setProcessing(true);
        router.post(
            `/respite/stays/${stay.id}/incidents`,
            {
                type,
                severity,
                title,
                description,
                occurred_at: nowInput(),
                is_notifiable: isNotifiable,
                notification_authority: isNotifiable ? authority : null,
                incident_type: isNotifiable ? incidentType : null,
            },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={stay != null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                {stay ? (
                    <>
                        <div>
                            <DialogTitle className="text-left text-lg">
                                Log incident
                            </DialogTitle>
                            <DialogDescription className="text-left">
                                Record a stay-linked incident for {stay.client}.
                            </DialogDescription>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <InputField
                                label="Nature"
                                value={type}
                                setValue={setType}
                            />
                            <SelectField
                                label="Severity"
                                value={severity}
                                setValue={setSeverity}
                                options={[
                                    ['low', 'Low'],
                                    ['medium', 'Medium'],
                                    ['high', 'High'],
                                    ['critical', 'Critical'],
                                ]}
                            />
                            <InputField
                                label="Title"
                                value={title}
                                setValue={setTitle}
                            />
                            <label className="mt-6 flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={isNotifiable}
                                    onChange={(event) =>
                                        setIsNotifiable(event.target.checked)
                                    }
                                />
                                Notifiable
                            </label>
                            <TextareaField
                                label="Description"
                                value={description}
                                setValue={setDescription}
                            />
                            {isNotifiable ? (
                                <div className="grid gap-3">
                                    <SelectField
                                        label="Authority"
                                        value={authority}
                                        setValue={setAuthority}
                                        options={[
                                            ['health_nz', 'Health NZ'],
                                            ['worksafe', 'WorkSafe'],
                                            [
                                                'privacy_commissioner',
                                                'Privacy Commissioner',
                                            ],
                                            [
                                                'charities_services',
                                                'Charities Services',
                                            ],
                                        ]}
                                    />
                                    <SelectField
                                        label="Incident type"
                                        value={incidentType}
                                        setValue={setIncidentType}
                                        options={[
                                            ['health_safety', 'Health and safety'],
                                            ['serious_harm', 'Serious harm'],
                                            ['serious_injury', 'Serious injury'],
                                            ['privacy_breach', 'Privacy breach'],
                                            ['death', 'Death'],
                                        ]}
                                    />
                                </div>
                            ) : null}
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button
                                onClick={submit}
                                disabled={processing || !canSubmit}
                            >
                                <FileWarning className="h-4 w-4" />
                                Save incident
                            </Button>
                        </div>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

export function DischargeModal({
    stay,
    onClose,
}: {
    stay: RespiteStayRow | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [summary, setSummary] = useState('');
    const [reason, setReason] = useState('planned');
    const [returnedTo, setReturnedTo] = useState('');
    const [count, setCount] = useState('0');
    const [receivedBy, setReceivedBy] = useState('');
    const [changedDuringStay, setChangedDuringStay] = useState(false);
    const [handoverSent, setHandoverSent] = useState(true);
    const [whanauBriefed, setWhanauBriefed] = useState(true);

    useEffect(() => {
        if (!stay) return;
        setProcessing(false);
        setSummary('');
        setReason('planned');
        setReturnedTo('');
        setCount('0');
        setReceivedBy('');
        setChangedDuringStay(false);
        setHandoverSent(true);
        setWhanauBriefed(true);
    }, [stay]);

    const needsMedRec = !!stay?.requiresAdmissionMedRec;
    const canSubmit =
        !!stay &&
        summary.trim() &&
        (!needsMedRec || (returnedTo.trim() && count !== ''));

    const submit = () => {
        if (!stay || !canSubmit) return;
        setProcessing(true);
        router.post(
            `/respite/stays/${stay.id}/discharge`,
            {
                discharge_summary: summary,
                discharge_reason: reason,
                discharge_medication_reconciliation: {
                    medicines_returned_to: returnedTo || null,
                    count: Number(count || 0),
                    received_by: receivedBy || null,
                    changed_during_stay: changedDuringStay,
                    gp_pharmacy_handover_sent: handoverSent,
                    whanau_briefing_acknowledged: whanauBriefed,
                },
            },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={stay != null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                {stay ? (
                    <>
                        <div>
                            <DialogTitle className="text-left text-lg">
                                Discharge {stay.client}
                            </DialogTitle>
                            <DialogDescription className="text-left">
                                Capture structured discharge and medication handover details.
                            </DialogDescription>
                        </div>
                        <TextareaField
                            label="Discharge summary"
                            value={summary}
                            setValue={setSummary}
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <SelectField
                                label="Reason"
                                value={reason}
                                setValue={setReason}
                                options={[
                                    ['planned', 'Planned'],
                                    ['early_by_family', 'Early by family'],
                                    ['clinical', 'Clinical'],
                                    ['incident', 'Incident'],
                                    [
                                        'transferred_to_hospital',
                                        'Transferred to hospital',
                                    ],
                                ]}
                            />
                            <InputField
                                label="Medicines returned to"
                                value={returnedTo}
                                setValue={setReturnedTo}
                            />
                            <InputField
                                label="Medicine count"
                                type="number"
                                value={count}
                                setValue={setCount}
                            />
                            <InputField
                                label="Received by"
                                value={receivedBy}
                                setValue={setReceivedBy}
                            />
                        </div>
                        <div className="grid gap-2 rounded-[10px] border border-border p-3 text-sm">
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={changedDuringStay}
                                    onChange={(event) =>
                                        setChangedDuringStay(
                                            event.target.checked,
                                        )
                                    }
                                />
                                Medicines changed during stay
                            </label>
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={handoverSent}
                                    onChange={(event) =>
                                        setHandoverSent(event.target.checked)
                                    }
                                />
                                GP / pharmacy handover sent
                            </label>
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={whanauBriefed}
                                    onChange={(event) =>
                                        setWhanauBriefed(event.target.checked)
                                    }
                                />
                                Family / whanau briefing acknowledged
                            </label>
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                onClick={submit}
                                disabled={processing || !canSubmit}
                            >
                                <LogOut className="h-4 w-4" />
                                Discharge stay
                            </Button>
                        </div>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

export function ComplaintModal({
    stay,
    onClose,
}: {
    stay: RespiteStayRow | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [source, setSource] = useState('client');
    const [receivedAt, setReceivedAt] = useState(nowInput());
    const [nature, setNature] = useState('');
    const [details, setDetails] = useState('');
    const [escalatedToHdc, setEscalatedToHdc] = useState('no');

    useEffect(() => {
        if (!stay) return;
        setProcessing(false);
        setSource('client');
        setReceivedAt(nowInput());
        setNature('');
        setDetails('');
        setEscalatedToHdc('no');
    }, [stay]);

    const canSubmit = !!stay && nature.trim();

    const submit = () => {
        if (!stay || !canSubmit) return;
        setProcessing(true);
        router.post(
            `/respite/stays/${stay.id}/complaints`,
            {
                source,
                received_at: receivedAt,
                nature,
                details: details || null,
                escalated_to_hdc: escalatedToHdc,
            },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={stay != null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                {stay ? (
                    <>
                        <div>
                            <DialogTitle className="text-left text-lg">
                                Log complaint
                            </DialogTitle>
                            <DialogDescription className="text-left">
                                Record rights, advocacy or service feedback for {stay.client}.
                            </DialogDescription>
                        </div>
                        <div className="grid gap-3">
                            <SelectField
                                label="Source"
                                value={source}
                                setValue={setSource}
                                options={[
                                    ['client', 'Client'],
                                    ['whanau', 'Family / whanau'],
                                    ['staff', 'Staff'],
                                    ['advocate', 'Advocate'],
                                    ['external', 'External'],
                                    ['other', 'Other'],
                                ]}
                            />
                            <InputField
                                label="Received"
                                type="datetime-local"
                                value={receivedAt}
                                setValue={setReceivedAt}
                            />
                            <InputField
                                label="Nature"
                                value={nature}
                                setValue={setNature}
                            />
                            <TextareaField
                                label="Details"
                                value={details}
                                setValue={setDetails}
                            />
                            <SelectField
                                label="HDC escalation"
                                value={escalatedToHdc}
                                setValue={setEscalatedToHdc}
                                options={[
                                    ['no', 'No'],
                                    ['offered', 'Offered'],
                                    ['requested', 'Requested'],
                                    ['submitted', 'Submitted'],
                                ]}
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button
                                onClick={submit}
                                disabled={processing || !canSubmit}
                            >
                                <MessageSquareWarning className="h-4 w-4" />
                                Save complaint
                            </Button>
                        </div>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function InputField({
    label,
    value,
    setValue,
    type = 'text',
}: {
    label: string;
    value: string;
    setValue: (value: string) => void;
    type?: string;
}) {
    const id = `respite-${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                type={type}
                value={value}
                onChange={(event) => setValue(event.target.value)}
            />
        </div>
    );
}

function TextareaField({
    label,
    value,
    setValue,
}: {
    label: string;
    value: string;
    setValue: (value: string) => void;
}) {
    const id = `respite-${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Textarea
                id={id}
                value={value}
                onChange={(event) => setValue(event.target.value)}
                rows={3}
            />
        </div>
    );
}

function SelectField({
    label,
    value,
    setValue,
    options,
}: {
    label: string;
    value: string;
    setValue: (value: string) => void;
    options: [string, string][];
}) {
    return (
        <div className="grid gap-1.5">
            <Label>{label}</Label>
            <Select value={value} onValueChange={setValue}>
                <SelectTrigger>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map(([optionValue, optionLabel]) => (
                        <SelectItem key={optionValue} value={optionValue}>
                            {optionLabel}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
