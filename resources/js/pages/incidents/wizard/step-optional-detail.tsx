import DictateButton from '@/components/dictate-button';
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

export default function StepOptionalDetail({
    data,
    onChange,
    showInjuryFields,
}: Props) {
    return (
        <div className="space-y-6">
            <div className="space-y-1">
                <h2 className="text-lg font-semibold">
                    Anything else we should know?
                </h2>
                <p className="text-sm text-muted-foreground">
                    All optional — the incident is already saved. Add what you
                    know, skip the rest.
                </p>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div className="space-y-2">
                    <div className="flex items-center justify-between">
                        <Label
                            htmlFor="incident-immediate-action"
                            className="text-sm font-medium"
                        >
                            What you did straight away
                        </Label>
                        <DictateButton
                            value={data.immediate_action_taken}
                            onChange={(next) =>
                                onChange({ immediate_action_taken: next })
                            }
                            fieldLabel="What you did straight away"
                        />
                    </div>
                    <Textarea
                        id="incident-immediate-action"
                        data-test="incident-immediate-action"
                        value={data.immediate_action_taken}
                        onChange={(e) =>
                            onChange({
                                immediate_action_taken: e.target.value,
                            })
                        }
                        placeholder="First aid, moved the client, called a manager…"
                        rows={3}
                        className="text-base"
                    />
                </div>

                <div className="space-y-2">
                    <div className="flex items-center justify-between">
                        <Label
                            htmlFor="incident-witnesses"
                            className="text-sm font-medium"
                        >
                            Who else was there
                        </Label>
                        <DictateButton
                            value={data.witnesses}
                            onChange={(next) => onChange({ witnesses: next })}
                            fieldLabel="Who else was there"
                        />
                    </div>
                    <Textarea
                        id="incident-witnesses"
                        data-test="incident-witnesses"
                        value={data.witnesses}
                        onChange={(e) =>
                            onChange({ witnesses: e.target.value })
                        }
                        placeholder="Names of anyone who saw it, and how to reach them."
                        rows={2}
                        className="text-base"
                    />
                </div>
            </div>

            {showInjuryFields && (
                <div className="space-y-4 rounded-lg border bg-muted/30 p-4">
                    <p className="text-sm font-medium">Injury details</p>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label
                                htmlFor="incident-injured-person-name"
                                className="text-xs font-medium"
                            >
                                Injured person
                            </Label>
                            <Input
                                id="incident-injured-person-name"
                                data-test="incident-injured-person-name"
                                value={data.injured_person_name}
                                onChange={(e) =>
                                    onChange({
                                        injured_person_name: e.target.value,
                                    })
                                }
                                placeholder="Name"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label
                                htmlFor="incident-injured-person-role"
                                className="text-xs font-medium"
                            >
                                Role
                            </Label>
                            <Select
                                value={data.injured_person_role || '__none__'}
                                onValueChange={(v) =>
                                    onChange({
                                        injured_person_role:
                                            v === '__none__' ? '' : v,
                                    })
                                }
                            >
                                <SelectTrigger
                                    id="incident-injured-person-role"
                                    data-test="incident-injured-person-role"
                                    aria-label="Injured person role"
                                >
                                    <SelectValue placeholder="Select…" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">
                                        Select…
                                    </SelectItem>
                                    {INJURED_PERSON_ROLES.map((r) => (
                                        <SelectItem
                                            key={r.value}
                                            value={r.value}
                                        >
                                            {r.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label
                                htmlFor="incident-injury-body-part"
                                className="text-xs font-medium"
                            >
                                Body part
                            </Label>
                            <Input
                                id="incident-injury-body-part"
                                data-test="incident-injury-body-part"
                                value={data.injury_body_part}
                                onChange={(e) =>
                                    onChange({
                                        injury_body_part: e.target.value,
                                    })
                                }
                                placeholder="e.g. Left wrist"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label
                                htmlFor="incident-injury-nature"
                                className="text-xs font-medium"
                            >
                                Type of injury
                            </Label>
                            <Select
                                value={data.injury_nature || '__none__'}
                                onValueChange={(v) =>
                                    onChange({
                                        injury_nature:
                                            v === '__none__' ? '' : v,
                                    })
                                }
                            >
                                <SelectTrigger
                                    id="incident-injury-nature"
                                    data-test="incident-injury-nature"
                                    aria-label="Type of injury"
                                >
                                    <SelectValue placeholder="Select…" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">
                                        Select…
                                    </SelectItem>
                                    {INJURY_NATURES.map((n) => (
                                        <SelectItem
                                            key={n.value}
                                            value={n.value}
                                        >
                                            {n.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label
                            htmlFor="incident-medical-treatment-type"
                            className="text-xs font-medium"
                        >
                            Medical treatment
                        </Label>
                        <Select
                            value={data.medical_treatment_type || '__none__'}
                            onValueChange={(v) =>
                                onChange({
                                    medical_treatment_type:
                                        v === '__none__' ? '' : v,
                                })
                            }
                        >
                            <SelectTrigger
                                id="incident-medical-treatment-type"
                                data-test="incident-medical-treatment-type"
                                aria-label="Medical treatment"
                            >
                                <SelectValue placeholder="Select…" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none__">
                                    Select…
                                </SelectItem>
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
