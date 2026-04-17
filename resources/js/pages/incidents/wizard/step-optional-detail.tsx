import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import VoiceInputButton from '@/components/voice-input-button';

export type StepThreeData = {
    immediate_action_taken: string;
    witnesses: string;
    injured_person_name: string;
    injured_person_role: string;
    injury_body_part: string;
    injury_nature: string;
    medical_treatment_type: string;
};

type Props = {
    data: StepThreeData;
    onChange: (patch: Partial<StepThreeData>) => void;
    showInjuryFields: boolean;
};

const INJURED_PERSON_ROLES = [
    { value: 'staff', label: 'Staff' },
    { value: 'client', label: 'Client' },
    { value: 'visitor', label: 'Visitor' },
    { value: 'contractor', label: 'Contractor' },
];

const INJURY_NATURES = [
    { value: 'fracture', label: 'Fracture' },
    { value: 'burn', label: 'Burn' },
    { value: 'laceration', label: 'Laceration' },
    { value: 'sprain', label: 'Sprain' },
    { value: 'bruising', label: 'Bruising' },
    { value: 'concussion', label: 'Concussion' },
    { value: 'poisoning', label: 'Poisoning' },
    { value: 'other', label: 'Other' },
];

const MEDICAL_TREATMENT_TYPES = [
    { value: 'none', label: 'None' },
    { value: 'first_aid', label: 'First aid' },
    { value: 'medical_centre', label: 'Medical centre' },
    { value: 'hospital', label: 'Hospital' },
    { value: 'ambulance', label: 'Ambulance' },
];

export default function StepOptionalDetail({ data, onChange, showInjuryFields }: Props) {
    return (
        <div className="space-y-6">
            <div className="space-y-1">
                <h2 className="text-lg font-semibold">Anything else we should know?</h2>
                <p className="text-sm text-muted-foreground">
                    All optional — the incident is already saved. Add what you know, skip the rest.
                </p>
            </div>

            <div className="space-y-2">
                <div className="flex items-center justify-between">
                    <Label className="text-sm font-medium">What you did straight away</Label>
                    <VoiceInputButton
                        value={data.immediate_action_taken}
                        onChange={(next) => onChange({ immediate_action_taken: next })}
                        fieldLabel="What you did straight away"
                    />
                </div>
                <Textarea
                    value={data.immediate_action_taken}
                    onChange={(e) => onChange({ immediate_action_taken: e.target.value })}
                    placeholder="First aid, moved the client, called a manager…"
                    rows={3}
                    className="text-base"
                />
            </div>

            <div className="space-y-2">
                <div className="flex items-center justify-between">
                    <Label className="text-sm font-medium">Who else was there</Label>
                    <VoiceInputButton
                        value={data.witnesses}
                        onChange={(next) => onChange({ witnesses: next })}
                        fieldLabel="Who else was there"
                    />
                </div>
                <Textarea
                    value={data.witnesses}
                    onChange={(e) => onChange({ witnesses: e.target.value })}
                    placeholder="Names of anyone who saw it, and how to reach them."
                    rows={2}
                    className="text-base"
                />
            </div>

            {showInjuryFields && (
                <div className="space-y-4 rounded-lg border bg-muted/30 p-4">
                    <p className="text-sm font-medium">Injury details</p>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">Injured person</Label>
                            <Input
                                value={data.injured_person_name}
                                onChange={(e) => onChange({ injured_person_name: e.target.value })}
                                placeholder="Name"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">Role</Label>
                            <Select
                                value={data.injured_person_role || '__none__'}
                                onValueChange={(v) => onChange({ injured_person_role: v === '__none__' ? '' : v })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select…" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">Select…</SelectItem>
                                    {INJURED_PERSON_ROLES.map((r) => (
                                        <SelectItem key={r.value} value={r.value}>
                                            {r.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">Body part</Label>
                            <Input
                                value={data.injury_body_part}
                                onChange={(e) => onChange({ injury_body_part: e.target.value })}
                                placeholder="e.g. Left wrist"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">Type of injury</Label>
                            <Select
                                value={data.injury_nature || '__none__'}
                                onValueChange={(v) => onChange({ injury_nature: v === '__none__' ? '' : v })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select…" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">Select…</SelectItem>
                                    {INJURY_NATURES.map((n) => (
                                        <SelectItem key={n.value} value={n.value}>
                                            {n.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label className="text-xs font-medium">Medical treatment</Label>
                        <Select
                            value={data.medical_treatment_type || '__none__'}
                            onValueChange={(v) => onChange({ medical_treatment_type: v === '__none__' ? '' : v })}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Select…" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none__">Select…</SelectItem>
                                {MEDICAL_TREATMENT_TYPES.map((m) => (
                                    <SelectItem key={m.value} value={m.value}>
                                        {m.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>
            )}
        </div>
    );
}
