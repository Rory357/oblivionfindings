import { AlertTriangle, Phone, UserCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    administrationId: number;
    clientId: number;
    medicationName: string;
}

const reasonCategoryOptions = [
    { value: 'personal_choice', label: 'Personal choice' },
    { value: 'side_effects', label: 'Side effects' },
    { value: 'difficulty_swallowing', label: 'Difficulty swallowing' },
    { value: 'nausea', label: 'Nausea' },
    { value: 'pain', label: 'Pain' },
    { value: 'cognitive', label: 'Cognitive' },
    { value: 'behavioural', label: 'Behavioural' },
    { value: 'sleeping', label: 'Sleeping' },
    { value: 'other', label: 'Other' },
];

const capacityOptions = [
    { value: 'has_capacity', label: 'Has capacity' },
    { value: 'lacks_capacity', label: 'Lacks capacity' },
    { value: 'fluctuating', label: 'Fluctuating' },
    { value: 'not_assessed', label: 'Not assessed' },
];

export default function RefusalFollowUpDialog({
    isOpen,
    onClose,
    administrationId,
    clientId,
    medicationName,
}: Props) {
    const [autoGpChecked, setAutoGpChecked] = useState(false);

    const form = useForm({
        client_id: clientId,
        client_medication_administration_id: administrationId,
        reason_category: '' as string,
        detailed_reason: '',
        client_capacity_at_time: 'not_assessed',
        offered_alternative: false,
        alternative_details: '',
        gp_notification_required: false,
        family_notified: false,
        follow_up_action: '',
        follow_up_due_at: '',
    });

    // Reset form when dialog opens with new props
    useEffect(() => {
        if (isOpen) {
            form.reset();
            form.setData({
                client_id: clientId,
                client_medication_administration_id: administrationId,
                reason_category: '',
                detailed_reason: '',
                client_capacity_at_time: 'not_assessed',
                offered_alternative: false,
                alternative_details: '',
                gp_notification_required: false,
                family_notified: false,
                follow_up_action: '',
                follow_up_due_at: '',
            });
            setAutoGpChecked(false);
        }
    }, [isOpen, administrationId, clientId]);

    // Auto-check GP notification if 3+ refusals in 7 days (checked server-side,
    // but we also set the hint visually via autoGpChecked flag passed from parent or detected here)
    useEffect(() => {
        if (autoGpChecked && !form.data.gp_notification_required) {
            form.setData('gp_notification_required', true);
        }
    }, [autoGpChecked]);

    const handleSubmit = () => {
        form.post('/emar/refusal-followups', {
            onSuccess: () => {
                onClose();
            },
        });
    };

    const canSubmit = !!form.data.reason_category && !form.processing;

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <AlertTriangle className="h-5 w-5 text-status-warning" />
                        Refusal / Withholding Follow-Up
                    </DialogTitle>
                </DialogHeader>

                <div className="space-y-4">
                    {/* Medication Info */}
                    <div className="rounded-md bg-status-warning-bg p-3">
                        <div className="text-sm font-medium text-status-warning">
                            Recording follow-up for refusal/withholding of:
                        </div>
                        <div className="mt-1 font-semibold text-status-warning">{medicationName}</div>
                    </div>

                    {/* Reason Category */}
                    <div>
                        <Label>Reason for Refusal/Withholding *</Label>
                        <Select
                            value={form.data.reason_category}
                            onValueChange={(value) => form.setData('reason_category', value)}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Select reason..." />
                            </SelectTrigger>
                            <SelectContent>
                                {reasonCategoryOptions.map((opt) => (
                                    <SelectItem key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {form.errors.reason_category && (
                            <p className="mt-1 text-xs text-status-critical">{form.errors.reason_category}</p>
                        )}
                    </div>

                    {/* Detailed Reason */}
                    <div>
                        <Label>Detailed Reason</Label>
                        <Textarea
                            value={form.data.detailed_reason}
                            onChange={(e) => form.setData('detailed_reason', e.target.value)}
                            placeholder="Provide additional details about the refusal/withholding..."
                            className="min-h-[60px]"
                        />
                        {form.errors.detailed_reason && (
                            <p className="mt-1 text-xs text-status-critical">{form.errors.detailed_reason}</p>
                        )}
                    </div>

                    {/* Client Capacity */}
                    <div>
                        <Label className="flex items-center gap-1">
                            <UserCheck className="h-3 w-3" />
                            Client Capacity at Time *
                        </Label>
                        <Select
                            value={form.data.client_capacity_at_time}
                            onValueChange={(value) => form.setData('client_capacity_at_time', value)}
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {capacityOptions.map((opt) => (
                                    <SelectItem key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {form.errors.client_capacity_at_time && (
                            <p className="mt-1 text-xs text-status-critical">{form.errors.client_capacity_at_time}</p>
                        )}
                    </div>

                    {/* Offered Alternative */}
                    <div className="space-y-2">
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="offered_alternative"
                                checked={form.data.offered_alternative}
                                onCheckedChange={(checked) =>
                                    form.setData('offered_alternative', checked === true)
                                }
                            />
                            <Label htmlFor="offered_alternative" className="cursor-pointer">
                                Alternative offered
                            </Label>
                        </div>
                        {form.data.offered_alternative && (
                            <div>
                                <Label>Alternative Details</Label>
                                <Input
                                    value={form.data.alternative_details}
                                    onChange={(e) => form.setData('alternative_details', e.target.value)}
                                    placeholder="Describe the alternative offered..."
                                />
                            </div>
                        )}
                    </div>

                    {/* GP Notification Required */}
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="gp_notification_required"
                            checked={form.data.gp_notification_required}
                            onCheckedChange={(checked) =>
                                form.setData('gp_notification_required', checked === true)
                            }
                        />
                        <Label htmlFor="gp_notification_required" className="flex cursor-pointer items-center gap-1">
                            <Phone className="h-3 w-3" />
                            GP notification required
                        </Label>
                        {autoGpChecked && (
                            <span className="text-xs text-status-warning">
                                (Auto-flagged: 3+ refusals in 7 days)
                            </span>
                        )}
                    </div>

                    {/* Family Notified */}
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="family_notified"
                            checked={form.data.family_notified}
                            onCheckedChange={(checked) =>
                                form.setData('family_notified', checked === true)
                            }
                        />
                        <Label htmlFor="family_notified" className="cursor-pointer">
                            Family/whanau notified
                        </Label>
                    </div>

                    {/* Follow-Up Action */}
                    <div>
                        <Label>Follow-Up Action</Label>
                        <Textarea
                            value={form.data.follow_up_action}
                            onChange={(e) => form.setData('follow_up_action', e.target.value)}
                            placeholder="Describe any follow-up actions required..."
                            className="min-h-[60px]"
                        />
                    </div>

                    {/* Follow-Up Due Date */}
                    <div>
                        <Label>Follow-Up Due Date</Label>
                        <Input
                            type="datetime-local"
                            value={form.data.follow_up_due_at}
                            onChange={(e) => form.setData('follow_up_due_at', e.target.value)}
                        />
                    </div>
                </div>

                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={onClose} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button onClick={handleSubmit} disabled={!canSubmit}>
                        {form.processing ? 'Saving...' : 'Record Follow-Up'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
