import { router, usePage } from '@inertiajs/react';
import {
    HeartPulse,
    ListPlus,
    ShieldAlert,
    StickyNote,
    Utensils,
} from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    launcherWorkflows,
    ReportLauncher,
} from '@/pages/health-safety/components/report-launcher';
import { reportPageFor } from '@/pages/health-safety/components/report-pages';
import type { SharedData } from '@/types';

import type { MyDayResident } from '../lib/types';

interface Props {
    people: MyDayResident[];
    selectedPerson: 'all' | number;
    canRecordObservation: boolean;
    onNote: (id: number) => void;
    onMeal: () => void;
    onObservation: () => void;
    onIncident: () => void;
}

export function RecordCareActions(p: Props) {
    const [choosingPerson, setChoosingPerson] = useState(false);
    // "Report something" (MASTER for Stephan, 30 Oct, option A): the H&S chooser, showing only what this person may
    // use. Offered beside "Report incident" when they can report more than an incident.
    const [reportOpen, setReportOpen] = useState(false);
    const reportCan = usePage<SharedData>().props.auth
        ?.can as unknown as Parameters<typeof launcherWorkflows>[0];
    const moreToReport = launcherWorkflows(reportCan).some(
        (w) => w.key !== 'incident',
    );
    const openReport = (key: string) => {
        setReportOpen(false);
        if (key === 'incident') {
            p.onIncident();
            return;
        }
        router.visit(reportPageFor(key, '/my-day') ?? '/compliance/hazards');
    };
    const note = () => {
        const person =
            p.people.find((person) => person.id === p.selectedPerson) ??
            (p.people.length === 1 ? p.people[0] : null);
        if (person) p.onNote(person.id);
        else setChoosingPerson(true);
    };
    return (
        <>
            <Card>
                <CardContent className="space-y-4 p-5">
                    <div>
                        <h2 className="text-section-title">Record care</h2>
                        <p className="text-subtle mt-1">
                            Something you’ve already done or noticed.
                        </p>
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                        {p.people.length > 0 && (
                            <>
                                <Button
                                    variant="outline"
                                    className="h-auto min-h-20 flex-col px-2 py-3"
                                    onClick={note}
                                >
                                    <StickyNote className="size-5" />
                                    Daily note
                                </Button>
                                <Button
                                    variant="outline"
                                    className="h-auto min-h-20 flex-col px-2 py-3"
                                    onClick={p.onMeal}
                                >
                                    <Utensils className="size-5" />
                                    Meal
                                </Button>
                            </>
                        )}
                        {p.canRecordObservation && (
                            <Button
                                variant="outline"
                                className="h-auto min-h-20 flex-col px-2 py-3"
                                onClick={p.onObservation}
                            >
                                <HeartPulse className="size-5" />
                                Observation
                            </Button>
                        )}
                        <Button
                            variant="outline"
                            className="h-auto min-h-20 flex-col px-2 py-3"
                            onClick={p.onIncident}
                        >
                            <ShieldAlert className="size-5" />
                            Report incident
                        </Button>
                        {moreToReport && (
                            <Button
                                variant="outline"
                                className="h-auto min-h-20 flex-col px-2 py-3"
                                onClick={() => setReportOpen(true)}
                            >
                                <ListPlus className="size-5" />
                                Report something
                            </Button>
                        )}
                    </div>
                </CardContent>
            </Card>
            <ReportLauncher
                open={reportOpen}
                onClose={() => setReportOpen(false)}
                onWorkflow={openReport}
            />
            <Dialog open={choosingPerson} onOpenChange={setChoosingPerson}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Who is this note for?</DialogTitle>
                        <DialogDescription>
                            Choose the person you supported.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        {p.people.map((person) => (
                            <Button
                                key={person.id}
                                variant="outline"
                                className="frontline-tap justify-start"
                                onClick={() => {
                                    setChoosingPerson(false);
                                    p.onNote(person.id);
                                }}
                            >
                                {person.name}
                            </Button>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
