import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import {
    Building2,
    Home,
    Warehouse,
    ChevronRight,
    ChevronLeft,
    CheckCircle2,
    BedDouble,
    DoorOpen,
    LayoutGrid,
    ClipboardCheck,
    Package,
} from 'lucide-react';
import { useState } from 'react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Site = {
    id: number;
    name: string;
    type: 'head_office' | 'house' | 'facility';
    phone?: string;
    email?: string;
    manager_name?: string;
    manager_phone?: string;
    after_hours_phone?: string;
    emergency_plan_location?: string;
    medication_storage_location?: string;
    onboarding_progress: Record<string, any>;
    onboarding_completed_at?: string;
};

type Template = {
    id: number;
    name: string;
    description?: string;
    frequency: string;
};

type Step = {
    key: string;
    label: string;
    required: boolean;
};

type Props = {
    site: Site;
    currentStep: number;
    typeSpecificData: {
        rooms?: Array<{ id: number; name: string }>;
        resources?: Array<{ id: number; name: string; type: string }>;
        zones?: Array<{ id: number; name: string }>;
    };
    checklistTemplates: Template[];
    steps: Step[];
};

const typeIcons = {
    head_office: Building2,
    house: Home,
    facility: Warehouse,
};

export default function OnboardingWizard({ site, currentStep, typeSpecificData, checklistTemplates, steps }: Props) {
    const [activeStep, setActiveStep] = useState(currentStep);
    const [saving, setSaving] = useState(false);

    // Form states for each step
    const [basicInfo, setBasicInfo] = useState({
        phone: site.phone || '',
        email: site.email || '',
        manager_name: site.manager_name || '',
        manager_phone: site.manager_phone || '',
        after_hours_phone: site.after_hours_phone || '',
        emergency_plan_location: site.emergency_plan_location || '',
        medication_storage_location: site.medication_storage_location || '',
    });
    const [rooms, setRooms] = useState<{ id?: number; name: string }[]>(
        typeSpecificData.rooms?.map((room) => ({ id: room.id, name: room.name })) ?? [{ name: '' }]
    );
    const [resources, setResources] = useState<{ id?: number; name: string; type: string; capacity: string }[]>(
        typeSpecificData.resources?.map((resource) => ({
            id: resource.id,
            name: resource.name,
            type: resource.type,
            capacity: '',
        })) ?? [{ name: '', type: 'meeting_room', capacity: '' }]
    );
    const [zones, setZones] = useState<{ id?: number; name: string; type: string }[]>(
        typeSpecificData.zones?.map((zone) => ({ id: zone.id, name: zone.name, type: '' })) ?? [{ name: '', type: '' }]
    );
    const [assets, setAssets] = useState<{ name: string; category: string; quantity: string }[]>([{ name: '', category: '', quantity: '1' }]);
    const [contacts, setContacts] = useState<{ type: string; name: string; role: string; phone: string; email: string; is_primary: boolean; notes: string }[]>([
        { type: 'general', name: '', role: '', phone: '', email: '', is_primary: false, notes: '' },
    ]);
    const [documents, setDocuments] = useState<{ title: string; category: string; expiry_date: string; notes: string }[]>([
        { title: '', category: '', expiry_date: '', notes: '' },
    ]);
    const [checklistAssignments, setChecklistAssignments] = useState<Record<number, { enabled: boolean; frequency: string; assigned_to_user_id: string }>>({});

    const TypeIcon = typeIcons[site.type];
    const currentStepData = steps[activeStep - 1];

    const progress = ((activeStep - 1) / steps.length) * 100;

    const handleNext = async () => {
        // Save current step data
        setSaving(true);
        try {
            let stepData: Record<string, any> = {};
            
            switch (currentStepData.key) {
                case 'basic':
                    stepData = { ...basicInfo };
                    break;
                case 'rooms':
                    stepData = { rooms: rooms.filter(r => r.name.trim()) };
                    break;
                case 'resources':
                    stepData = { resources: resources.filter(r => r.name.trim()).map(r => ({
                        name: r.name,
                        resource_type: r.type,
                        capacity: r.capacity ? parseInt(r.capacity) : null,
                    })) };
                    break;
                case 'zones':
                    stepData = { zones: zones.filter(z => z.name.trim()).map(z => ({
                        name: z.name,
                        zone_type: z.type,
                    })) };
                    break;
                case 'assets':
                    stepData = { assets: assets.filter(a => a.name.trim()) };
                    break;
                case 'contacts':
                    stepData = {
                        contacts: contacts.filter((contact) => contact.name.trim()).map((contact) => ({
                            ...contact,
                            phone: contact.phone || null,
                            email: contact.email || null,
                            role: contact.role || null,
                            notes: contact.notes || null,
                        })),
                    };
                    break;
                case 'documents':
                    stepData = {
                        documents: documents.filter((document) => document.title.trim()),
                    };
                    break;
                case 'checklists':
                    stepData = {
                        assignments: Object.entries(checklistAssignments).map(([templateId, config]) => ({
                            template_id: Number(templateId),
                            enabled: config.enabled,
                            frequency: config.frequency,
                            assigned_to_user_id: config.assigned_to_user_id || null,
                            start_date: new Date().toISOString().slice(0, 10),
                        })),
                    };
                    break;
            }

            const response = await fetch(`/sites/${site.id}/onboarding/step`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                },
                body: JSON.stringify({ step: currentStepData.key, data: stepData }),
            });
            
            if (!response.ok) {
                console.error('Failed to save step:', await response.text());
            }

            if (activeStep < steps.length) {
                setActiveStep(activeStep + 1);
                router.visit(`/sites/${site.id}/onboarding?step=${activeStep + 1}`, { preserveState: true });
            } else {
                // Complete onboarding
                await fetch(`/sites/${site.id}/onboarding/complete`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                    },
                });
                router.visit(`/sites/${site.id}`);
            }
        } finally {
            setSaving(false);
        }
    };

    const handleBack = () => {
        if (activeStep > 1) {
            setActiveStep(activeStep - 1);
            router.visit(`/sites/${site.id}/onboarding?step=${activeStep - 1}`, { preserveState: true });
        }
    };

    const addRoom = () => setRooms([...rooms, { name: '' }]);
    const updateRoom = (index: number, name: string) => {
        const newRooms = [...rooms];
        newRooms[index].name = name;
        setRooms(newRooms);
    };
    const removeRoom = (index: number) => setRooms(rooms.filter((_, i) => i !== index));

    const addResource = () => setResources([...resources, { name: '', type: 'meeting_room', capacity: '' }]);
    const updateResource = (index: number, field: string, value: string) => {
        const newResources = [...resources];
        (newResources[index] as any)[field] = value;
        setResources(newResources);
    };
    const removeResource = (index: number) => setResources(resources.filter((_, i) => i !== index));

    const addZone = () => setZones([...zones, { name: '', type: '' }]);
    const updateZone = (index: number, field: string, value: string) => {
        const newZones = [...zones];
        (newZones[index] as any)[field] = value;
        setZones(newZones);
    };
    const removeZone = (index: number) => setZones(zones.filter((_, i) => i !== index));

    const addAsset = () => setAssets([...assets, { name: '', category: '', quantity: '1' }]);
    const updateAsset = (index: number, field: string, value: string) => {
        const newAssets = [...assets];
        (newAssets[index] as any)[field] = value;
        setAssets(newAssets);
    };
    const removeAsset = (index: number) => setAssets(assets.filter((_, i) => i !== index));

    const addContact = () =>
        setContacts([
            ...contacts,
            { type: 'general', name: '', role: '', phone: '', email: '', is_primary: false, notes: '' },
        ]);
    const updateContact = (index: number, field: string, value: string | boolean) => {
        const next = [...contacts];
        (next[index] as any)[field] = value;
        setContacts(next);
    };
    const removeContact = (index: number) => setContacts(contacts.filter((_, i) => i !== index));

    const addDocument = () =>
        setDocuments([
            ...documents,
            { title: '', category: '', expiry_date: '', notes: '' },
        ]);
    const updateDocument = (index: number, field: string, value: string) => {
        const next = [...documents];
        (next[index] as any)[field] = value;
        setDocuments(next);
    };
    const removeDocument = (index: number) => setDocuments(documents.filter((_, i) => i !== index));

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: site.name, href: `/sites/${site.id}` }, { title: 'Onboarding', href: `/sites/${site.id}/onboarding` }]}>
            <Head title={`Onboard ${site.name}`} />

            <div className="m-4 max-w-3xl mx-auto space-y-6">
                {/* Header */}
                <div className="text-center">
                    <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-indigo-500/10 mb-4">
                        <TypeIcon className="w-8 h-8 text-indigo-400" />
                    </div>
                    <h1 className="text-2xl font-semibold">Onboard New Site</h1>
                    <p className="text-slate-400">{site.name}</p>
                </div>

                {/* Progress */}
                <div className="space-y-2">
                    <div className="flex justify-between text-sm">
                        <span>Step {activeStep} of {steps.length}</span>
                        <span className="text-slate-400">{Math.round(progress)}%</span>
                    </div>
                    <Progress value={progress} />
                    <div className="flex justify-center gap-1 pt-2">
                        {steps.map((step, idx) => (
                            <div
                                key={step.key}
                                className={`w-2 h-2 rounded-full ${
                                    idx + 1 < activeStep ? 'bg-emerald-500' :
                                    idx + 1 === activeStep ? 'bg-indigo-500' :
                                    'bg-slate-700'
                                }`}
                            />
                        ))}
                    </div>
                </div>

                {/* Step Content */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            {currentStepData.key === 'rooms' && <BedDouble className="w-5 h-5" />}
                            {currentStepData.key === 'resources' && <DoorOpen className="w-5 h-5" />}
                            {currentStepData.key === 'zones' && <LayoutGrid className="w-5 h-5" />}
                            {currentStepData.key === 'assets' && <Package className="w-5 h-5" />}
                            {currentStepData.key === 'checklists' && <ClipboardCheck className="w-5 h-5" />}
                            {currentStepData.label}
                            {currentStepData.required && <Badge variant="outline" className="ml-2">Required</Badge>}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {/* Rooms Step */}
                        {currentStepData.key === 'rooms' && (
                            <div className="space-y-4">
                                <p className="text-sm text-slate-400">Add bedrooms for this house. You can assign clients to rooms later.</p>
                                {rooms.map((room, index) => (
                                    <div key={index} className="flex gap-2">
                                        <Input
                                            value={room.name}
                                            onChange={(e) => updateRoom(index, e.target.value)}
                                            placeholder={`Bedroom ${index + 1} name`}
                                        />
                                        {rooms.length > 1 && (
                                            <Button variant="ghost" size="sm" onClick={() => removeRoom(index)}>
                                                Remove
                                            </Button>
                                        )}
                                    </div>
                                ))}
                                <Button variant="outline" onClick={addRoom}>
                                    Add Bedroom
                                </Button>
                            </div>
                        )}

                        {/* Resources Step */}
                        {currentStepData.key === 'resources' && (
                            <div className="space-y-4">
                                <p className="text-sm text-slate-400">Add bookable rooms and resources for this head office.</p>
                                {resources.map((resource, index) => (
                                    <div key={index} className="grid gap-2 sm:grid-cols-3 p-3 rounded-lg border border">
                                        <Input
                                            value={resource.name}
                                            onChange={(e) => updateResource(index, 'name', e.target.value)}
                                            placeholder="Resource name"
                                        />
                                        <Select
                                            value={resource.type}
                                            onValueChange={(v) => updateResource(index, 'type', v)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="boardroom">Boardroom</SelectItem>
                                                <SelectItem value="training_room">Training Room</SelectItem>
                                                <SelectItem value="meeting_room">Meeting Room</SelectItem>
                                                <SelectItem value="other">Other</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <div className="flex gap-2">
                                            <Input
                                                value={resource.capacity}
                                                onChange={(e) => updateResource(index, 'capacity', e.target.value)}
                                                placeholder="Capacity"
                                                type="number"
                                            />
                                            {resources.length > 1 && (
                                                <Button variant="ghost" size="sm" onClick={() => removeResource(index)}>
                                                    Remove
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                                <Button variant="outline" onClick={addResource}>
                                    Add Resource
                                </Button>
                            </div>
                        )}

                        {/* Zones Step */}
                        {currentStepData.key === 'zones' && (
                            <div className="space-y-4">
                                <p className="text-sm text-slate-400">Add areas and zones for this facility.</p>
                                {zones.map((zone, index) => (
                                    <div key={index} className="flex gap-2">
                                        <Input
                                            value={zone.name}
                                            onChange={(e) => updateZone(index, 'name', e.target.value)}
                                            placeholder="Zone name"
                                        />
                                        <Input
                                            value={zone.type}
                                            onChange={(e) => updateZone(index, 'type', e.target.value)}
                                            placeholder="Zone type (optional)"
                                            className="w-40"
                                        />
                                        {zones.length > 1 && (
                                            <Button variant="ghost" size="sm" onClick={() => removeZone(index)}>
                                                Remove
                                            </Button>
                                        )}
                                    </div>
                                ))}
                                <Button variant="outline" onClick={addZone}>
                                    Add Zone
                                </Button>
                            </div>
                        )}

                        {/* Assets Step */}
                        {currentStepData.key === 'assets' && (
                            <div className="space-y-4">
                                <p className="text-sm text-slate-400">Add initial assets and equipment for this site.</p>
                                {assets.map((asset, index) => (
                                    <div key={index} className="grid gap-2 sm:grid-cols-3 p-3 rounded-lg border border">
                                        <Input
                                            value={asset.name}
                                            onChange={(e) => updateAsset(index, 'name', e.target.value)}
                                            placeholder="Asset name"
                                        />
                                        <Input
                                            value={asset.category}
                                            onChange={(e) => updateAsset(index, 'category', e.target.value)}
                                            placeholder="Category (e.g., Furniture, Equipment)"
                                        />
                                        <div className="flex gap-2">
                                            <Input
                                                type="number"
                                                value={asset.quantity}
                                                onChange={(e) => updateAsset(index, 'quantity', e.target.value)}
                                                placeholder="Qty"
                                                className="w-20"
                                            />
                                            {assets.length > 1 && (
                                                <Button variant="ghost" size="sm" onClick={() => removeAsset(index)}>
                                                    Remove
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                                <Button variant="outline" onClick={addAsset}>
                                    Add Asset
                                </Button>
                            </div>
                        )}

                        {/* Checklists Step */}
                        {currentStepData.key === 'checklists' && (
                            <div className="space-y-4">
                                <p className="text-sm text-slate-400">Set up recurring checklists for this site.</p>
                                {checklistTemplates.map((template) => (
                                    <div key={template.id} className="p-3 rounded-lg border border">
                                        <div className="flex items-center gap-2 mb-2">
                                            <Checkbox
                                                checked={checklistAssignments[template.id]?.enabled}
                                                onCheckedChange={(checked) => {
                                                    setChecklistAssignments({
                                                        ...checklistAssignments,
                                                        [template.id]: {
                                                            enabled: checked as boolean,
                                                            frequency: template.frequency,
                                                            assigned_to_user_id: '',
                                                        },
                                                    });
                                                }}
                                            />
                                            <span className="font-medium">{template.name}</span>
                                        </div>
                                        {template.description && (
                                            <p className="text-sm text-slate-400 ml-6">{template.description}</p>
                                        )}
                                        {checklistAssignments[template.id]?.enabled && (
                                            <div className="ml-6 mt-2">
                                                <Select
                                                    value={checklistAssignments[template.id]?.frequency || template.frequency}
                                                    onValueChange={(v) => {
                                                        setChecklistAssignments({
                                                            ...checklistAssignments,
                                                            [template.id]: {
                                                                ...checklistAssignments[template.id],
                                                                frequency: v,
                                                            },
                                                        });
                                                    }}
                                                >
                                                    <SelectTrigger className="w-[180px]">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="daily">Daily</SelectItem>
                                                        <SelectItem value="weekly">Weekly</SelectItem>
                                                        <SelectItem value="fortnightly">Fortnightly</SelectItem>
                                                        <SelectItem value="monthly">Monthly</SelectItem>
                                                        <SelectItem value="quarterly">Quarterly</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}

                        {/* Basic Step */}
                        {currentStepData.key === 'basic' && (
                            <div className="space-y-4">
                                <p className="text-sm text-slate-400">Enter the essential contact and safety information for this site.</p>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Phone</Label>
                                        <Input
                                            value={basicInfo.phone}
                                            onChange={(e) => setBasicInfo({ ...basicInfo, phone: e.target.value })}
                                            placeholder="Main site phone number"
                                        />
                                    </div>
                                    <div>
                                        <Label>Email</Label>
                                        <Input
                                            type="email"
                                            value={basicInfo.email}
                                            onChange={(e) => setBasicInfo({ ...basicInfo, email: e.target.value })}
                                            placeholder="Site email address"
                                        />
                                    </div>
                                    <div>
                                        <Label>Site Manager Name</Label>
                                        <Input
                                            value={basicInfo.manager_name}
                                            onChange={(e) => setBasicInfo({ ...basicInfo, manager_name: e.target.value })}
                                            placeholder="Manager or lead contact name"
                                        />
                                    </div>
                                    <div>
                                        <Label>Manager Phone</Label>
                                        <Input
                                            value={basicInfo.manager_phone}
                                            onChange={(e) => setBasicInfo({ ...basicInfo, manager_phone: e.target.value })}
                                            placeholder="Manager's phone number"
                                        />
                                    </div>
                                    <div>
                                        <Label>After-hours Phone</Label>
                                        <Input
                                            value={basicInfo.after_hours_phone}
                                            onChange={(e) => setBasicInfo({ ...basicInfo, after_hours_phone: e.target.value })}
                                            placeholder="Emergency after-hours number"
                                        />
                                    </div>
                                </div>
                                <div className="pt-4 border-t border">
                                    <h4 className="text-sm font-medium mb-3">Safety Information</h4>
                                    <div className="space-y-3">
                                        <div>
                                            <Label>Emergency Plan Location</Label>
                                            <Input
                                                value={basicInfo.emergency_plan_location}
                                                onChange={(e) => setBasicInfo({ ...basicInfo, emergency_plan_location: e.target.value })}
                                                placeholder="Where the emergency plan is kept"
                                            />
                                        </div>
                                        <div>
                                            <Label>Medication Storage Location</Label>
                                            <Input
                                                value={basicInfo.medication_storage_location}
                                                onChange={(e) => setBasicInfo({ ...basicInfo, medication_storage_location: e.target.value })}
                                                placeholder="Where medications are stored"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Contacts Step */}
                        {currentStepData.key === 'contacts' && (
                            <div className="space-y-4">
                                <p className="text-sm text-slate-400">Add key site contacts to speed up hazard assignment and after-hours handovers.</p>
                                {contacts.map((contact, index) => (
                                    <div key={index} className="grid gap-2 sm:grid-cols-2 p-3 rounded-lg border border">
                                        <Input
                                            value={contact.name}
                                            onChange={(e) => updateContact(index, 'name', e.target.value)}
                                            placeholder="Contact name"
                                        />
                                        <Input
                                            value={contact.role}
                                            onChange={(e) => updateContact(index, 'role', e.target.value)}
                                            placeholder="Role (e.g., Site Lead)"
                                        />
                                        <Input
                                            value={contact.phone}
                                            onChange={(e) => updateContact(index, 'phone', e.target.value)}
                                            placeholder="Phone"
                                        />
                                        <Input
                                            value={contact.email}
                                            onChange={(e) => updateContact(index, 'email', e.target.value)}
                                            placeholder="Email"
                                        />
                                        <div className="sm:col-span-2 flex items-center justify-between">
                                            <label className="flex items-center gap-2 text-sm">
                                                <Checkbox
                                                    checked={contact.is_primary}
                                                    onCheckedChange={(checked) => updateContact(index, 'is_primary', checked as boolean)}
                                                />
                                                Primary contact
                                            </label>
                                            {contacts.length > 1 && (
                                                <Button variant="ghost" size="sm" onClick={() => removeContact(index)}>
                                                    Remove
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                                <Button variant="outline" onClick={addContact}>
                                    Add Contact
                                </Button>
                            </div>
                        )}

                        {/* Documents Step */}
                        {currentStepData.key === 'documents' && (
                            <div className="space-y-4">
                                <p className="text-sm text-slate-400">Record which key documents are required first. You can upload files from the site profile after onboarding.</p>
                                {documents.map((document, index) => (
                                    <div key={index} className="grid gap-2 sm:grid-cols-2 p-3 rounded-lg border border">
                                        <Input
                                            value={document.title}
                                            onChange={(e) => updateDocument(index, 'title', e.target.value)}
                                            placeholder="Document title"
                                        />
                                        <Input
                                            value={document.category}
                                            onChange={(e) => updateDocument(index, 'category', e.target.value)}
                                            placeholder="Category (e.g., evacuation_plan)"
                                        />
                                        <Input
                                            type="date"
                                            value={document.expiry_date}
                                            onChange={(e) => updateDocument(index, 'expiry_date', e.target.value)}
                                        />
                                        <Input
                                            value={document.notes}
                                            onChange={(e) => updateDocument(index, 'notes', e.target.value)}
                                            placeholder="Notes"
                                        />
                                        <div className="sm:col-span-2 flex justify-end">
                                            {documents.length > 1 && (
                                                <Button variant="ghost" size="sm" onClick={() => removeDocument(index)}>
                                                    Remove
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                                <Button variant="outline" onClick={addDocument}>
                                    Add Document Requirement
                                </Button>
                            </div>
                        )}

                        {/* Navigation */}
                        <div className="flex justify-between mt-6 pt-6 border-t border">
                            <Button
                                variant="outline"
                                onClick={handleBack}
                                disabled={activeStep === 1}
                            >
                                <ChevronLeft className="w-4 h-4 mr-1" />
                                Back
                            </Button>
                            <Button onClick={handleNext} disabled={saving}>
                                {saving ? 'Saving...' : activeStep === steps.length ? 'Complete Onboarding' : 'Next'}
                                {!saving && activeStep !== steps.length && <ChevronRight className="w-4 h-4 ml-1" />}
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
