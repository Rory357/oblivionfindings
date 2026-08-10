import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { store as storeShiftClinicalEvent } from '@/routes/shifts/clinical/events';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

const EVENT_TYPES = [
    { value: 'fall', label: 'Fall' },
    { value: 'seizure', label: 'Seizure' },
    { value: 'choking', label: 'Choking Incident' },
    { value: 'deterioration', label: 'Health Deterioration' },
    { value: 'allergic_reaction', label: 'Allergic Reaction' },
    { value: 'skin_integrity', label: 'Skin Integrity Issue' },
    { value: 'infection_sign', label: 'Sign of Infection' },
    { value: 'behavioural_crisis', label: 'Behavioural Crisis' },
    { value: 'mental_health_episode', label: 'Mental Health Episode' },
    { value: 'other', label: 'Other Clinical Event' },
] as const;

const SEVERITIES = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' },
] as const;

type EventType = (typeof EVENT_TYPES)[number]['value'];
type Severity = (typeof SEVERITIES)[number]['value'];
const HS_LINKED_EVENT_TYPES: ReadonlySet<EventType> = new Set([
    'fall',
    'seizure',
    'choking',
]);

interface Props {
    clientId?: number;
    shiftId?: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onRecorded?: () => void;
}

function toDateTimeLocalValue(date: Date): string {
    const pad = (value: number) => String(value).padStart(2, '0');

    return (
        [
            date.getFullYear(),
            pad(date.getMonth() + 1),
            pad(date.getDate()),
        ].join('-') + `T${pad(date.getHours())}:${pad(date.getMinutes())}`
    );
}

export default function EventRecordSheet({
    clientId,
    shiftId,
    open,
    onOpenChange,
    onRecorded,
}: Props) {
    const [eventType, setEventType] = useState<EventType>('other');
    const [severity, setSeverity] = useState<Severity>('medium');
    const [occurredAt, setOccurredAt] = useState(
        toDateTimeLocalValue(new Date()),
    );
    const [description, setDescription] = useState('');
    const [immediateActionTaken, setImmediateActionTaken] = useState('');
    const [outcome, setOutcome] = useState('');
    const [requiresFollowup, setRequiresFollowup] = useState(false);
    const [followupNotes, setFollowupNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const immediateActionRequired = HS_LINKED_EVENT_TYPES.has(eventType);

    const resetForm = useCallback(() => {
        setEventType('other');
        setSeverity('medium');
        setOccurredAt(toDateTimeLocalValue(new Date()));
        setDescription('');
        setImmediateActionTaken('');
        setOutcome('');
        setRequiresFollowup(false);
        setFollowupNotes('');
        setErrors({});
    }, []);

    useEffect(() => {
        if (!open) {
            resetForm();
        }
    }, [open, resetForm]);

    const handleSubmit = useCallback(() => {
        if (immediateActionRequired && !immediateActionTaken.trim()) {
            setErrors({
                immediate_action_taken:
                    'Record the immediate action taken before saving this Health & Safety-linked event.',
            });

            return;
        }

        const url = shiftId
            ? storeShiftClinicalEvent.url(shiftId)
            : `/clients/${clientId}/clinical/events`;

        setSubmitting(true);
        setErrors({});

        router.post(
            url,
            {
                event_type: eventType,
                severity,
                occurred_at: occurredAt,
                description,
                immediate_action_taken: immediateActionTaken || undefined,
                outcome: outcome || undefined,
                requires_followup: requiresFollowup,
                followup_notes:
                    requiresFollowup && followupNotes
                        ? followupNotes
                        : undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    onRecorded?.();
                },
                onError: (formErrors) => {
                    setErrors(formErrors as Record<string, string>);
                },
                onFinish: () => {
                    setSubmitting(false);
                },
            },
        );
    }, [
        clientId,
        description,
        eventType,
        followupNotes,
        immediateActionTaken,
        immediateActionRequired,
        occurredAt,
        onOpenChange,
        onRecorded,
        outcome,
        requiresFollowup,
        severity,
        shiftId,
    ]);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>Record Clinical Event</SheetTitle>
                    <SheetDescription>
                        Record a clinical event for this{' '}
                        {shiftId ? 'shift' : 'client'}.
                    </SheetDescription>
                </SheetHeader>

                <div className="mt-4 space-y-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label>Event Type</Label>
                            <Select
                                value={eventType}
                                onValueChange={(value) =>
                                    setEventType(value as EventType)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {EVENT_TYPES.map((type) => (
                                        <SelectItem
                                            key={type.value}
                                            value={type.value}
                                        >
                                            {type.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-2">
                            <Label>Severity</Label>
                            <Select
                                value={severity}
                                onValueChange={(value) =>
                                    setSeverity(value as Severity)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {SEVERITIES.map((item) => (
                                        <SelectItem
                                            key={item.value}
                                            value={item.value}
                                        >
                                            {item.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label>Occurred At</Label>
                        <Input
                            type="datetime-local"
                            value={occurredAt}
                            onChange={(event) =>
                                setOccurredAt(event.target.value)
                            }
                        />
                    </div>

                    <div className="space-y-2">
                        <Label>Description</Label>
                        <Textarea
                            placeholder="Describe what happened."
                            rows={4}
                            value={description}
                            onChange={(event) =>
                                setDescription(event.target.value)
                            }
                        />
                    </div>

                    <div className="space-y-2">
                        <Label>
                            Immediate Action Taken
                            {immediateActionRequired ? ' *' : ''}
                        </Label>
                        <Textarea
                            aria-invalid={Boolean(
                                errors.immediate_action_taken,
                            )}
                            placeholder={
                                immediateActionRequired
                                    ? 'Required: document exactly what was done straight away.'
                                    : 'Document immediate actions taken.'
                            }
                            rows={3}
                            value={immediateActionTaken}
                            onChange={(event) =>
                                setImmediateActionTaken(event.target.value)
                            }
                        />
                        {immediateActionRequired ? (
                            <p className="text-xs text-muted-foreground">
                                Required because this event is linked to Health
                                &amp; Safety.
                            </p>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <Label>Outcome</Label>
                        <Textarea
                            placeholder="Record the current outcome or condition."
                            rows={3}
                            value={outcome}
                            onChange={(event) => setOutcome(event.target.value)}
                        />
                    </div>

                    <div className="rounded-lg border p-3">
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id={`requires-followup-${shiftId ?? clientId ?? 'event'}`}
                                checked={requiresFollowup}
                                onCheckedChange={(checked) => {
                                    const nextValue = Boolean(checked);
                                    setRequiresFollowup(nextValue);
                                    if (!nextValue) {
                                        setFollowupNotes('');
                                    }
                                }}
                            />
                            <div className="space-y-1">
                                <Label
                                    htmlFor={`requires-followup-${shiftId ?? clientId ?? 'event'}`}
                                >
                                    Requires follow-up
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    Flag this if a coordinator or clinical lead
                                    should review what happens next.
                                </p>
                            </div>
                        </div>

                        {requiresFollowup ? (
                            <div className="mt-3 space-y-2">
                                <Label>Follow-up Notes</Label>
                                <Textarea
                                    placeholder="Add any follow-up or review notes."
                                    rows={3}
                                    value={followupNotes}
                                    onChange={(event) =>
                                        setFollowupNotes(event.target.value)
                                    }
                                />
                            </div>
                        ) : null}
                    </div>

                    {Object.keys(errors).length > 0 ? (
                        <div className="rounded-md border border-status-critical/30 bg-status-critical-bg p-3">
                            {Object.entries(errors).map(([field, message]) => (
                                <p
                                    key={field}
                                    className="text-xs text-status-critical"
                                >
                                    {message}
                                </p>
                            ))}
                        </div>
                    ) : null}
                </div>

                <SheetFooter className="mt-4">
                    <Button
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        disabled={submitting}
                    >
                        Cancel
                    </Button>
                    <Button onClick={handleSubmit} disabled={submitting}>
                        {submitting ? 'Saving...' : 'Record Event'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
