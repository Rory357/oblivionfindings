import { useState, useEffect } from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

import { AlertTriangle, Plus, ShieldAlert } from 'lucide-react';
import axios from 'axios';

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

export default function DrugInteractionManager({ canManage }: DrugInteractionManagerProps) {
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
            minor: 'bg-blue-100 text-blue-800',
            moderate: 'bg-yellow-100 text-yellow-800',
            major: 'bg-orange-100 text-orange-800',
            contraindicated: 'bg-red-100 text-red-800',
        };
        return <Badge className={colors[severity] || 'bg-slate-100'}>{severity}</Badge>;
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <ShieldAlert className="mr-1 h-4 w-4" />
                    Drug Interactions
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-3xl max-h-[80vh]">
                <DialogHeader>
                    <DialogTitle className="text-lg flex items-center justify-between">
                        <span>Drug Interaction Database</span>
                        {canManage && (
                            <Button size="sm" onClick={() => setShowAddForm(!showAddForm)}>
                                <Plus className="mr-1 h-3 w-3" />
                                Add Interaction
                            </Button>
                        )}
                    </DialogTitle>
                </DialogHeader>

                {showAddForm && canManage && (
                    <form onSubmit={handleCreate} className="rounded-lg border p-4 space-y-3">
                        <h4 className="font-medium text-sm">Add New Drug Interaction</h4>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label className="text-xs">Medication A *</Label>
                                <Input
                                    value={medA}
                                    onChange={(e) => setMedA(e.target.value)}
                                    placeholder="e.g., Warfarin"
                                    required
                                />
                            </div>
                            <div>
                                <Label className="text-xs">Medication B *</Label>
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
                                className="w-full h-9 rounded-md border border-input bg-background px-3 text-sm"
                                value={severity}
                                onChange={(e) => setSeverity(e.target.value)}
                                required
                            >
                                <option value="minor">Minor - Minimally clinically significant</option>
                                <option value="moderate">Moderate - Moderately clinically significant</option>
                                <option value="major">Major - Highly clinically significant</option>
                                <option value="contraindicated">Contraindicated - Combination should be avoided</option>
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
                                onChange={(e) => setClinicalEffects(e.target.value)}
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
                            <Button type="submit" size="sm">Add Interaction</Button>
                            <Button type="button" variant="outline" size="sm" onClick={() => setShowAddForm(false)}>
                                Cancel
                            </Button>
                        </div>
                    </form>
                )}

                {loading ? (
                    <div className="py-8 text-center text-sm text-slate-500">Loading interactions...</div>
                ) : interactions.length === 0 ? (
                    <div className="py-8 text-center text-sm text-slate-500">No drug interactions defined.</div>
                ) : (
                    <div className="max-h-[50vh] overflow-y-auto">
                        <div className="space-y-2 pr-4">
                            {interactions.map((interaction) => (
                                <div
                                    key={interaction.id}
                                    className={`rounded-lg border p-3 ${
                                        interaction.severity === 'contraindicated'
                                            ? 'border-red-200 bg-red-50'
                                            : interaction.severity === 'major'
                                            ? 'border-orange-200 bg-orange-50'
                                            : 'bg-slate-50'
                                    }`}
                                >
                                    <div className="flex items-start justify-between">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2 mb-1">
                                                {getSeverityBadge(interaction.severity)}
                                                <span className="font-medium">
                                                    {interaction.medication_a} + {interaction.medication_b}
                                                </span>
                                            </div>
                                            <p className="text-sm text-slate-700">{interaction.description}</p>
                                            {interaction.clinical_effects && (
                                                <p className="text-xs text-slate-500 mt-1">
                                                    <strong>Effects:</strong> {interaction.clinical_effects}
                                                </p>
                                            )}
                                            {interaction.management && (
                                                <p className="text-xs text-slate-600 mt-1">
                                                    <strong>Management:</strong> {interaction.management}
                                                </p>
                                            )}
                                        </div>
                                        {interaction.severity === 'contraindicated' && (
                                            <AlertTriangle className="h-5 w-5 text-red-500 ml-2" />
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <div className="text-xs text-slate-500 pt-2 border-t">
                    <p>These interactions are automatically checked when administering medications.</p>
                </div>
            </DialogContent>
        </Dialog>
    );
}
