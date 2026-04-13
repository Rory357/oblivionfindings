import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import ObservationRecordSheet from '@/components/clinical/observation-record-sheet';
import { ClipboardCheck, Stethoscope } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface DueItem {
    protocol_id: number;
    protocol_name: string;
    observation_type: string;
    observation_type_label: string;
    instructions: string | null;
    schedule_id: number | null;
    due_at: string | null;
    is_overdue: boolean;
}

interface Props {
    shiftId: number;
    clientId: number;
    canRecordClinical: boolean;
}

export default function ShiftObservationsDueCard({
    shiftId,
    clientId,
    canRecordClinical,
}: Props) {
    const [dueItems, setDueItems] = useState<DueItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [sheetOpen, setSheetOpen] = useState(false);
    const [selectedType, setSelectedType] = useState<string | null>(null);
    const [selectedScheduleId, setSelectedScheduleId] = useState<number | null>(null);

    const fetchDue = useCallback(async () => {
        setLoading(true);
        try {
            const res = await fetch(
                `/shifts/${shiftId}/clinical/observations/due`,
                { headers: { Accept: 'application/json' } },
            );
            if (!res.ok) throw new Error('Failed to load');
            const json = await res.json();
            setDueItems(json.items ?? []);
        } catch {
            setDueItems([]);
        } finally {
            setLoading(false);
        }
    }, [shiftId]);

    useEffect(() => {
        fetchDue();
    }, [fetchDue]);

    const handleRecord = useCallback(
        (item: DueItem) => {
            setSelectedType(item.observation_type);
            setSelectedScheduleId(item.schedule_id);
            setSheetOpen(true);
        },
        [],
    );

    const handleSheetClose = useCallback(
        (open: boolean) => {
            setSheetOpen(open);
            if (!open) {
                setSelectedType(null);
                setSelectedScheduleId(null);
                fetchDue();
            }
        },
        [fetchDue],
    );

    if (loading) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Stethoscope className="h-4 w-4" />
                        Observations Due
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p className="text-sm text-muted-foreground">Loading...</p>
                </CardContent>
            </Card>
        );
    }

    if (dueItems.length === 0) {
        return null; // Don't render card if nothing is due
    }

    return (
        <>
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Stethoscope className="h-4 w-4 text-emerald-600" />
                        Observations Due
                        <Badge variant="secondary" className="ml-auto text-xs">
                            {dueItems.length}
                        </Badge>
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="divide-y">
                        {dueItems.map((item, idx) => (
                            <div
                                key={`${item.protocol_id}-${item.schedule_id ?? idx}`}
                                className="flex items-center justify-between py-2"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-medium">
                                            {item.observation_type_label}
                                        </span>
                                        {item.is_overdue && (
                                            <Badge
                                                variant="destructive"
                                                className="text-[10px]"
                                            >
                                                Overdue
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="text-xs text-muted-foreground line-clamp-1">
                                        {item.protocol_name}
                                        {item.instructions
                                            ? ` \u2014 ${item.instructions}`
                                            : ''}
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="ml-3 shrink-0"
                                    onClick={() => handleRecord(item)}
                                >
                                    <ClipboardCheck className="mr-1 h-3.5 w-3.5" />
                                    Record
                                </Button>
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>

            <ObservationRecordSheet
                clientId={clientId}
                shiftId={shiftId}
                open={sheetOpen}
                onOpenChange={handleSheetClose}
                canRecordClinical={canRecordClinical}
                defaultType={selectedType}
                protocolScheduleId={selectedScheduleId}
            />
        </>
    );
}
