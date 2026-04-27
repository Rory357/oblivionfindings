import { router } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useState } from 'react';

import HandoverWriteForm, {
    emptyHandoverWriteValue,
    type HandoverWriteValue,
} from '@/components/handover-write-form';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

export default function HandoverWriteSheet({
    shiftId,
    alreadySubmitted,
    open,
    onOpenChange,
}: {
    shiftId: number | null;
    alreadySubmitted?: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [value, setValue] = useState<HandoverWriteValue>(
        emptyHandoverWriteValue,
    );
    const [submitting, setSubmitting] = useState(false);

    const submit = () => {
        if (!shiftId || alreadySubmitted) {
            onOpenChange(false);
            return;
        }

        setSubmitting(true);
        router.post(
            '/attendance/handover',
            {
                shift_id: shiftId,
                meds_completed: value.meds_completed,
                shift_rating: value.shift_rating,
                handover_notes: value.handover_notes,
                follow_up_needed: value.follow_up_needed,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setValue(emptyHandoverWriteValue);
                    onOpenChange(false);
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="max-h-[92vh] overflow-y-auto rounded-t-2xl"
            >
                <SheetHeader className="pr-12">
                    <SheetTitle className="flex items-center gap-2">
                        <FileText className="h-4 w-4" />
                        Shift note
                    </SheetTitle>
                    <SheetDescription>
                        Capture what the next support worker should know.
                    </SheetDescription>
                </SheetHeader>

                <div className="px-4">
                    <HandoverWriteForm
                        value={value}
                        onChange={setValue}
                        disabled={submitting}
                        alreadySubmitted={alreadySubmitted}
                    />
                </div>

                <SheetFooter>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={submitting || !shiftId}
                    >
                        {submitting ? 'Saving...' : 'Save note'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
