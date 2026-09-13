import { router } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useState } from 'react';

import HandoverWriteForm, {
    emptyHandoverWriteValue,
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
import { useHandoverEditor } from '@/hooks/use-handover-editor';

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
    const { editor, value, setValue, loading, error, retry } =
        useHandoverEditor(shiftId, open);
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
                worker_notes: value.worker_notes,
                expected_version: value.expected_version,
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
                        Shift notes
                    </SheetTitle>
                    <SheetDescription>
                        A separate note for each person you supported. Save a
                        draft, then review and send the handover.
                    </SheetDescription>
                </SheetHeader>

                <div className="px-4">
                    {loading ? (
                        <p role="status">Loading saved notes…</p>
                    ) : error ? (
                        <div role="alert">
                            <p>{error}</p>
                            <Button onClick={retry}>Retry</Button>
                        </div>
                    ) : (
                        <HandoverWriteForm
                            value={value}
                            onChange={setValue}
                            disabled={submitting}
                            people={editor?.people}
                            alreadySubmitted={
                                alreadySubmitted ||
                                editor?.status === 'submitted' ||
                                editor?.status === 'acknowledged'
                            }
                        />
                    )}
                </div>

                <SheetFooter>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={
                            submitting ||
                            !shiftId ||
                            loading ||
                            !!error ||
                            alreadySubmitted ||
                            editor?.status === 'submitted' ||
                            editor?.status === 'acknowledged'
                        }
                    >
                        {submitting ? 'Saving...' : 'Save draft'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
