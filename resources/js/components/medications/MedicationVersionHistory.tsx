import { useState, useEffect } from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';

import { History, ChevronDown, ChevronUp } from 'lucide-react';
import axios from 'axios';

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

export default function MedicationVersionHistory({ clientId, medicationId, medicationName }: MedicationVersionHistoryProps) {
    const [versions, setVersions] = useState<Version[]>([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const [expandedVersion, setExpandedVersion] = useState<number | null>(null);

    useEffect(() => {
        if (open) {
            loadVersions();
        }
    }, [open]);

    const loadVersions = async () => {
        setLoading(true);
        try {
            const response = await axios.get(`/api/medications/clients/${clientId}/medications/${medicationId}/versions`);
            setVersions(response.data.versions);
        } catch (error) {
            console.error('Failed to load versions:', error);
        } finally {
            setLoading(false);
        }
    };

    const getStateBadge = (state: string) => {
        const colors: Record<string, string> = {
            active: 'bg-emerald-100 text-emerald-800',
            paused: 'bg-amber-100 text-amber-800',
            ceased: 'bg-red-100 text-red-800',
        };
        return <Badge className={colors[state] || 'bg-slate-100'}>{state}</Badge>;
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm" className="text-xs">
                    <History className="mr-1 h-3 w-3" />
                    History
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-2xl max-h-[80vh]">
                <DialogHeader>
                    <DialogTitle className="text-lg">Version History: {medicationName}</DialogTitle>
                </DialogHeader>

                {loading ? (
                    <div className="py-8 text-center text-sm text-slate-500">Loading history...</div>
                ) : versions.length === 0 ? (
                    <div className="py-8 text-center text-sm text-slate-500">No version history available.</div>
                ) : (
                    <div className="max-h-[60vh] overflow-y-auto">
                        <div className="space-y-3 pr-4">
                            {versions.map((version, index) => (
                                <div
                                    key={version.id}
                                    className={`rounded-lg border p-3 ${index === 0 ? 'border-blue-200 bg-blue-50' : 'bg-slate-50'}`}
                                >
                                    <div
                                        className="flex items-center justify-between cursor-pointer"
                                        onClick={() => setExpandedVersion(expandedVersion === version.id ? null : version.id)}
                                    >
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-medium">v{version.version_number}</span>
                                            {getStateBadge(version.state)}
                                            {index === 0 && <Badge className="bg-blue-100 text-blue-800">Current</Badge>}
                                        </div>
                                        <div className="flex items-center gap-2 text-xs text-slate-500">
                                            <span>{new Date(version.changed_at).toLocaleDateString()}</span>
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
                                                    <span className="text-xs text-slate-500">Name:</span>
                                                    <p>{version.name}</p>
                                                </div>
                                                <div>
                                                    <span className="text-xs text-slate-500">Dosage:</span>
                                                    <p>{version.dosage || '—'}</p>
                                                </div>
                                                <div>
                                                    <span className="text-xs text-slate-500">Frequency:</span>
                                                    <p>{version.frequency || '—'}</p>
                                                </div>
                                                <div>
                                                    <span className="text-xs text-slate-500">Route:</span>
                                                    <p>{version.route || '—'}</p>
                                                </div>
                                            </div>
                                            {version.instructions && (
                                                <div>
                                                    <span className="text-xs text-slate-500">Instructions:</span>
                                                    <p className="text-slate-700">{version.instructions}</p>
                                                </div>
                                            )}
                                            <div className="pt-2 border-t border-slate-200">
                                                <div className="flex justify-between text-xs">
                                                    <span className="text-slate-500">
                                                        Changed by: {version.changed_by}
                                                    </span>
                                                    {version.change_reason && (
                                                        <span className="text-slate-600 italic">
                                                            "{version.change_reason}"
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
