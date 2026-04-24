import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useCallback, useEffect, useState } from 'react';

import axios from 'axios';
import { ChevronDown, ChevronUp, History } from 'lucide-react';

interface Version {
    id: number;
    version_number: number;
    name: string;
    dosage: string;
    frequency: string;
    route: string;
    instructions: string;
    state: string;
    change_reason: string;
    changed_by: string;
    changed_at: string;
}

interface MedicationVersionHistoryProps {
    clientId: number;
    medicationId: number;
    medicationName: string;
}

export default function MedicationVersionHistory({
    clientId,
    medicationId,
    medicationName,
}: MedicationVersionHistoryProps) {
    const [versions, setVersions] = useState<Version[]>([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const [expandedVersion, setExpandedVersion] = useState<number | null>(null);

    const loadVersions = useCallback(async () => {
        setLoading(true);
        try {
            const response = await axios.get(
                `/api/medications/clients/${clientId}/medications/${medicationId}/versions`,
            );
            setVersions(response.data.versions);
        } catch (error) {
            console.error('Failed to load versions:', error);
        } finally {
            setLoading(false);
        }
    }, [clientId, medicationId]);

    useEffect(() => {
        if (open) {
            loadVersions();
        }
    }, [open, loadVersions]);

    const getStateBadge = (state: string) => {
        const colors: Record<string, string> = {
            active: 'bg-status-success-bg text-status-success',
            paused: 'bg-status-warning-bg text-status-warning',
            ceased: 'bg-status-critical-bg text-status-critical',
        };
        return <Badge className={colors[state] || 'bg-muted'}>{state}</Badge>;
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm" className="text-xs">
                    <History className="mr-1 h-3 w-3" />
                    History
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[80vh] max-w-2xl">
                <DialogHeader>
                    <DialogTitle className="text-lg">
                        Version History: {medicationName}
                    </DialogTitle>
                </DialogHeader>

                {loading ? (
                    <div className="py-8 text-center text-sm text-muted-foreground">
                        Loading history...
                    </div>
                ) : versions.length === 0 ? (
                    <div className="py-8 text-center text-sm text-muted-foreground">
                        No version history available.
                    </div>
                ) : (
                    <div className="max-h-[60vh] overflow-y-auto">
                        <div className="space-y-3 pr-4">
                            {versions.map((version, index) => (
                                <div
                                    key={version.id}
                                    className={`rounded-lg border p-3 ${index === 0 ? 'border-status-info/30 bg-status-info-bg' : 'bg-muted'}`}
                                >
                                    <div
                                        className="flex cursor-pointer items-center justify-between"
                                        onClick={() =>
                                            setExpandedVersion(
                                                expandedVersion === version.id
                                                    ? null
                                                    : version.id,
                                            )
                                        }
                                    >
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-medium">
                                                v{version.version_number}
                                            </span>
                                            {getStateBadge(version.state)}
                                            {index === 0 && (
                                                <Badge className="bg-status-info-bg text-status-info">
                                                    Current
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                            <span>
                                                {new Date(
                                                    version.changed_at,
                                                ).toLocaleDateString()}
                                            </span>
                                            {expandedVersion === version.id ? (
                                                <ChevronUp className="h-4 w-4" />
                                            ) : (
                                                <ChevronDown className="h-4 w-4" />
                                            )}
                                        </div>
                                    </div>

                                    {expandedVersion === version.id && (
                                        <div className="mt-3 space-y-2 text-sm">
                                            <div className="grid grid-cols-2 gap-2">
                                                <div>
                                                    <span className="text-xs text-muted-foreground">
                                                        Name:
                                                    </span>
                                                    <p>{version.name}</p>
                                                </div>
                                                <div>
                                                    <span className="text-xs text-muted-foreground">
                                                        Dosage:
                                                    </span>
                                                    <p>
                                                        {version.dosage || '—'}
                                                    </p>
                                                </div>
                                                <div>
                                                    <span className="text-xs text-muted-foreground">
                                                        Frequency:
                                                    </span>
                                                    <p>
                                                        {version.frequency ||
                                                            '—'}
                                                    </p>
                                                </div>
                                                <div>
                                                    <span className="text-xs text-muted-foreground">
                                                        Route:
                                                    </span>
                                                    <p>
                                                        {version.route || '—'}
                                                    </p>
                                                </div>
                                            </div>
                                            {version.instructions && (
                                                <div>
                                                    <span className="text-xs text-muted-foreground">
                                                        Instructions:
                                                    </span>
                                                    <p className="text-foreground">
                                                        {version.instructions}
                                                    </p>
                                                </div>
                                            )}
                                            <div className="border-t border-border pt-2">
                                                <div className="flex justify-between text-xs">
                                                    <span className="text-muted-foreground">
                                                        Changed by:{' '}
                                                        {version.changed_by}
                                                    </span>
                                                    {version.change_reason && (
                                                        <span className="text-muted-foreground italic">
                                                            "
                                                            {
                                                                version.change_reason
                                                            }
                                                            "
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
