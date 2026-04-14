import EventRecordSheet from '@/components/clinical/event-record-sheet';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { Activity, Plus } from 'lucide-react';
import { useCallback, useState } from 'react';

interface Props {
    shiftId: number;
}

export default function ShiftClinicalEventCard({ shiftId }: Props) {
    const [sheetOpen, setSheetOpen] = useState(false);

    const handleRecorded = useCallback(() => {
        router.reload({
            only: ['notes'],
            preserveScroll: true,
            preserveState: true,
        });
    }, []);

    return (
        <>
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Activity className="h-4 w-4 text-rose-600" />
                        Clinical Event
                    </CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1">
                        <p className="text-sm font-medium">Record a shift-linked clinical event</p>
                        <p className="text-xs text-muted-foreground">
                            Use this when something clinically significant happens during the shift and should be logged immediately.
                        </p>
                    </div>
                    <Button onClick={() => setSheetOpen(true)}>
                        <Plus className="mr-1 h-3.5 w-3.5" />
                        Record Event
                    </Button>
                </CardContent>
            </Card>

            <EventRecordSheet
                shiftId={shiftId}
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                onRecorded={handleRecorded}
            />
        </>
    );
}
