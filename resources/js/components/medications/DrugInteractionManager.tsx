import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useEffect, useState } from 'react';

import axios from 'axios';
import { AlertTriangle, Plus, ShieldAlert } from 'lucide-react';

interface DrugInteraction {
    id: number;
    medication_a: string;
    medication_b: string;
    severity: 'minor' | 'moderate' | 'major' | 'contraindicated';
    severity_info: {
        label: string;
        color: string;
        description: string;
    };
    description: string;
    clinical_effects?: string | null;
    management: string | null;
    active: boolean;
}

interface DrugInteractionManagerProps {
    canManage: boolean;
}

export default function DrugInteractionManager({
    canManage,
}: DrugInteractionManagerProps) {
    const [interactions, setInteractions] = useState<DrugInteraction[]>([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const [showAddForm, setShowAddForm] = useState(false);

    // Form states
    const [medA, setMedA] = useState('');
    const [medB, setMedB] = useState('');
    const [severity, setSeverity] = useState('moderate');
    const [description, setDescription] = useState('');
    const [clinicalEffects, setClinicalEffects] = useState('');
    const [management, setManagement] = useState('');

    useEffect(() => {
        if (open) {
            loadInteractions();
        }
    }, [open]);

    const loadInteractions = async () => {
        setLoading(true);
        try {
            const response = await axios.get('/api/medications/interactions');
            setInteractions(response.data.interactions);
        } catch (error) {
            console.error('Failed to load interactions:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleCreate = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            await axios.post('/api/medications/interactions', {
                medication_a: medA,
                medication_b: medB,
                severity,
                description,
                clinical_effects: clinicalEffects || null,
                management: management || null,
            });
            setShowAddForm(false);
            setMedA('');
            setMedB('');
            setSeverity('moderate');
            setDescription('');
            setClinicalEffects('');
            setManagement('');
            loadInteractions();
        } catch (error) {
            console.error('Failed to create interaction:', error);
            alert('Failed to create drug interaction');
        }
    };

    const getSeverityBadge = (severity: string) => {
        const colors: Record<string, string> = {
            minor: 'bg-status-info-bg text-status-info',
            moderate: 'bg-status-warning-bg text-status-warning',
            major: 'bg-status-warning-bg text-status-warning',
            contraindicated: 'bg-status-critical-bg text-status-critical',
        };
        return (
            <Badge className={colors[severity] || 'bg-muted'}>{severity}</Badge>
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <ShieldAlert className="mr-1 h-4 w-4" />
                    Drug Interactions
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[80vh] max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center justify-between text-lg">
                        <span>Drug Interaction Database</span>
                        {canManage && (
                            <Button
                                size="sm"
                                onClick={() => setShowAddForm(!showAddForm)}
                            >
                                <Plus className="mr-1 h-3 w-3" />
                                Add Interaction
                            </Button>
                        )}
                    </DialogTitle>
                </DialogHeader>

                {showAddForm && canManage && (
                    <form
                        onSubmit={handleCreate}
                        className="space-y-3 rounded-lg border p-4"
                    >
                        <h4 className="text-sm font-medium">
                            Add New Drug Interaction
                        </h4>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label className="text-xs">
                                    Medication A *
                                </Label>
                                <Input
                                    value={medA}
                                    onChange={(e) => setMedA(e.target.value)}
                                    placeholder="e.g., Warfarin"
                                    required
                                />
                            </div>
                            <div>
                                <Label className="text-xs">
                                    Medication B *
                                </Label>
                                <Input
                                    value={medB}
                                    onChange={(e) => setMedB(e.target.value)}
                                    placeholder="e.g., Aspirin"
                                    required
                                />
                            </div>
                        </div>
                        <div>
                            <Label className="text-xs">Severity *</Label>
                            <select
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                value={severity}
                                onChange={(e) => setSeverity(e.target.value)}
                                required
                            >
                                <option value="minor">
                                    Minor - Minimally clinically significant
                                </option>
                                <option value="moderate">
                                    Moderate - Moderately clinically significant
                                </option>
                                <option value="major">
                                    Major - Highly clinically significant
                                </option>
                                <option value="contraindicated">
                                    Contraindicated - Combination should be
                                    avoided
                                </option>
                            </select>
                        </div>
                        <div>
                            <Label className="text-xs">Description *</Label>
                            <Textarea
                                value={description}
                                onChange={(e) => setDescription(e.target.value)}
                                placeholder="Describe the interaction..."
                                rows={2}
                                required
                            />
                        </div>
                        <div>
                            <Label className="text-xs">Clinical Effects</Label>
                            <Input
                                value={clinicalEffects}
                                onChange={(e) =>
                                    setClinicalEffects(e.target.value)
                                }
                                placeholder="Expected clinical effects..."
                            />
                        </div>
                        <div>
                            <Label className="text-xs">Management</Label>
                            <Input
                                value={management}
                                onChange={(e) => setManagement(e.target.value)}
                                placeholder="How to manage this interaction..."
                            />
                        </div>
                        <div className="flex gap-2">
                            <Button type="submit" size="sm">
                                Add Interaction
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setShowAddForm(false)}
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                )}

                {loading ? (
                    <div className="py-8 text-center text-sm text-muted-foreground">
                        Loading interactions...
                    </div>
                ) : interactions.length === 0 ? (
                    <div className="py-8 text-center text-sm text-muted-foreground">
                        No drug interactions defined.
                    </div>
                ) : (
                    <div className="max-h-[50vh] overflow-y-auto">
                        <div className="space-y-2 pr-4">
                            {interactions.map((interaction) => (
                                <div
                                    key={interaction.id}
                                    className={`rounded-lg border p-3 ${
                                        interaction.severity ===
                                        'contraindicated'
                                            ? 'border-status-critical/30 bg-status-critical-bg'
                                            : interaction.severity === 'major'
                                              ? 'border-status-warning/30 bg-status-warning-bg'
                                              : 'bg-muted'
                                    }`}
                                >
                                    <div className="flex items-start justify-between">
                                        <div className="flex-1">
                                            <div className="mb-1 flex items-center gap-2">
                                                {getSeverityBadge(
                                                    interaction.severity,
                                                )}
                                                <span className="font-medium">
                                                    {interaction.medication_a} +{' '}
                                                    {interaction.medication_b}
                                                </span>
                                            </div>
                                            <p className="text-sm text-foreground">
                                                {interaction.description}
                                            </p>
                                            {interaction.clinical_effects && (
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    <strong>Effects:</strong>{' '}
                                                    {
                                                        interaction.clinical_effects
                                                    }
                                                </p>
                                            )}
                                            {interaction.management && (
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    <strong>Management:</strong>{' '}
                                                    {interaction.management}
                                                </p>
                                            )}
                                        </div>
                                        {interaction.severity ===
                                            'contraindicated' && (
                                            <AlertTriangle className="ml-2 h-5 w-5 text-status-critical" />
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <div className="border-t pt-2 text-xs text-muted-foreground">
                    <p>
                        These interactions are automatically checked when
                        administering medications.
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    );
}
