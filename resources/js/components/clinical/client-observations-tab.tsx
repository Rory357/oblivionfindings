import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import EventRecordSheet from '@/components/clinical/event-record-sheet';
import ObservationRecordSheet, {
    OBSERVATION_TYPES,
} from '@/components/clinical/observation-record-sheet';
import { router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface Observation {
    id: number;
    observation_type: string;
    observation_type_label: string;
    recorded_at: string;
    data: Record<string, any>;
    notes: string | null;
    is_flagged: boolean;
    recorder: { id: number; name: string } | null;
    shift_id: number | null;
}

interface PaginatedResponse {
    data: Observation[];
    current_page: number;
    last_page: number;
    total: number;
}

function formatObsValue(obs: Observation): string {
    const d = obs.data;
    switch (obs.observation_type) {
        case 'vitals': {
            const parts: string[] = [];
            if (d.systolic && d.diastolic) parts.push(`BP ${d.systolic}/${d.diastolic}`);
            if (d.pulse) parts.push(`P${d.pulse}`);
            if (d.temperature) parts.push(`${d.temperature}\u00B0C`);
            if (d.o2_saturation) parts.push(`O\u2082 ${d.o2_saturation}%`);
            return parts.join(' \u00B7 ') || '\u2014';
        }
        case 'weight':
            return d.weight_kg ? `${d.weight_kg} kg` : '\u2014';
        case 'bowel':
            return d.bristol_type ? `Bristol type ${d.bristol_type}` : '\u2014';
        case 'sleep': {
            const parts: string[] = [];
            if (d.quality) parts.push(d.quality);
            if (d.interruptions > 0) parts.push(`${d.interruptions} interruptions`);
            return parts.join(' \u00B7 ') || '\u2014';
        }
        case 'fluid_intake':
            return d.amount_ml ? `${d.amount_ml}ml ${d.fluid_type ?? ''}`.trim() : '\u2014';
        case 'pain':
            return d.score !== undefined ? `${d.score}/10 \u00B7 ${d.location ?? ''}`.trim() : '\u2014';
        default:
            return '\u2014';
    }
}

function formatTime(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const diffH = Math.floor((now.getTime() - d.getTime()) / 3600000);
    if (diffH < 1) return 'just now';
    if (diffH < 24) return `${diffH}h ago`;
    const days = Math.floor(diffH / 24);
    if (days === 1) return 'yesterday';
    if (days < 7) return `${days}d ago`;
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

export default function ClientObservationsTab({
    clientId,
    canRecordObservation,
    canRecordClinical,
    canRecordEvent,
}: {
    clientId: number;
    canRecordObservation: boolean;
    canRecordClinical: boolean;
    canRecordEvent: boolean;
}) {
    const [observations, setObservations] = useState<Observation[]>([]);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);
    const [total, setTotal] = useState(0);
    const [typeFilter, setTypeFilter] = useState<string>('all');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [eventSheetOpen, setEventSheetOpen] = useState(false);

    const fetchObservations = useCallback(
        async (p: number, type: string) => {
            setLoading(true);
            try {
                const params = new URLSearchParams({ page: String(p) });
                if (type !== 'all') params.set('type', type);

                const res = await fetch(
                    `/clients/${clientId}/clinical/observations?${params}`,
                    { headers: { Accept: 'application/json' } },
                );
                if (!res.ok) throw new Error('Failed to load observations');

                const json: PaginatedResponse = await res.json();
                setObservations(json.data);
                setLastPage(json.last_page);
                setTotal(json.total);
            } catch {
                setObservations([]);
            } finally {
                setLoading(false);
            }
        },
        [clientId],
    );

    useEffect(() => {
        fetchObservations(page, typeFilter);
    }, [page, typeFilter, fetchObservations]);

    const handleSheetClose = useCallback(
        (open: boolean) => {
            setSheetOpen(open);
            if (!open) {
                // Refresh after recording
                fetchObservations(1, typeFilter);
                setPage(1);
            }
        },
        [fetchObservations, typeFilter],
    );

    const handleEventRecorded = useCallback(() => {
        router.reload({
            only: ['events', 'health_summary'],
            preserveScroll: true,
            preserveState: true,
        });
    }, []);

    const handleObservationRecorded = useCallback(() => {
        router.reload({
            only: ['health_summary'],
            preserveScroll: true,
            preserveState: true,
        });
    }, []);

    return (
        <>
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center justify-between text-base">
                        <span>Clinical Observations</span>
                        <div className="flex items-center gap-2">
                            <Select
                                value={typeFilter}
                                onValueChange={(v) => {
                                    setTypeFilter(v);
                                    setPage(1);
                                }}
                            >
                                <SelectTrigger className="h-8 w-[140px] text-xs">
                                    <SelectValue placeholder="All types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All types
                                    </SelectItem>
                                    {OBSERVATION_TYPES.filter(
                                        (t) => !t.clinical || canRecordClinical,
                                    ).map((t) => (
                                        <SelectItem key={t.value} value={t.value}>
                                            {t.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {canRecordEvent ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setEventSheetOpen(true)}
                                >
                                    <Plus className="mr-1 h-3.5 w-3.5" />
                                    Event
                                </Button>
                            ) : null}
                            {canRecordObservation ? (
                                <Button
                                    size="sm"
                                    onClick={() => setSheetOpen(true)}
                                >
                                    <Plus className="mr-1 h-3.5 w-3.5" />
                                    Observation
                                </Button>
                            ) : null}
                        </div>
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {loading ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            Loading observations...
                        </p>
                    ) : observations.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            No observations recorded yet.
                        </p>
                    ) : (
                        <>
                            <div className="divide-y">
                                {observations.map((obs) => (
                                    <div
                                        key={obs.id}
                                        className="flex items-start justify-between py-3"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <Badge
                                                    variant="outline"
                                                    className="text-[10px]"
                                                >
                                                    {obs.observation_type_label}
                                                </Badge>
                                                {obs.is_flagged && (
                                                    <Badge
                                                        variant="destructive"
                                                        className="text-[10px]"
                                                    >
                                                        Flagged
                                                    </Badge>
                                                )}
                                                {obs.shift_id && (
                                                    <Badge
                                                        variant="secondary"
                                                        className="text-[10px]"
                                                    >
                                                        Shift
                                                    </Badge>
                                                )}
                                            </div>
                                            <p className="mt-1 text-sm font-medium">
                                                {formatObsValue(obs)}
                                            </p>
                                            {obs.notes && (
                                                <p className="mt-0.5 text-xs text-muted-foreground line-clamp-1">
                                                    {obs.notes}
                                                </p>
                                            )}
                                        </div>
                                        <div className="ml-4 shrink-0 text-right">
                                            <p className="text-xs text-muted-foreground">
                                                {formatTime(obs.recorded_at)}
                                            </p>
                                            {obs.recorder && (
                                                <p className="text-[10px] text-muted-foreground">
                                                    {obs.recorder.name}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {/* Pagination */}
                            {lastPage > 1 && (
                                <div className="mt-3 flex items-center justify-between border-t pt-3">
                                    <p className="text-xs text-muted-foreground">
                                        {total} observation{total !== 1 ? 's' : ''}
                                    </p>
                                    <div className="flex gap-1">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={page <= 1}
                                            onClick={() => setPage(page - 1)}
                                        >
                                            Prev
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={page >= lastPage}
                                            onClick={() => setPage(page + 1)}
                                        >
                                            Next
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </CardContent>
            </Card>

            {canRecordObservation ? (
                <ObservationRecordSheet
                    clientId={clientId}
                    open={sheetOpen}
                    onOpenChange={handleSheetClose}
                    canRecordClinical={canRecordClinical}
                    onRecorded={handleObservationRecorded}
                />
            ) : null}

            {canRecordEvent ? (
                <EventRecordSheet
                    clientId={clientId}
                    open={eventSheetOpen}
                    onOpenChange={setEventSheetOpen}
                    onRecorded={handleEventRecorded}
                />
            ) : null}
        </>
    );
}
