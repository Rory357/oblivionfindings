import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Archive, Download, Lock, Shield } from 'lucide-react';
import { useState } from 'react';

const exportModules = [
    { id: 'clients', label: 'Clients' },
    { id: 'shifts', label: 'Shifts' },
    { id: 'timesheets', label: 'Timesheets' },
    { id: 'hr-records', label: 'HR Records' },
    { id: 'incidents', label: 'Incidents' },
    { id: 'documents', label: 'Documents' },
];

interface RecentExport {
    id: string;
    date: string;
    modules: string[];
    format: string;
    size: string;
    status: 'ready' | 'processing';
}

const recentExports: RecentExport[] = [
    { id: '1', date: '2026-03-20', modules: ['Clients', 'Shifts'], format: 'CSV', size: '4.2 MB', status: 'ready' },
    { id: '2', date: '2026-03-01', modules: ['All'], format: 'JSON', size: '28.7 MB', status: 'ready' },
];

export default function Data() {
    const [showExportDialog, setShowExportDialog] = useState(false);
    const [selectedModules, setSelectedModules] = useState<string[]>([]);
    const [exportFormat, setExportFormat] = useState('csv');

    // Data retention
    const [auditLogRetention, setAuditLogRetention] = useState('5-years');
    const [timesheetRetention, setTimesheetRetention] = useState('7-years');
    const [incidentRetention, setIncidentRetention] = useState('5-years');
    const [autoArchive, setAutoArchive] = useState('never');

    // Privacy
    const [anonymisation, setAnonymisation] = useState(false);
    const [consentRequired, setConsentRequired] = useState(true);
    const [privacyUrl, setPrivacyUrl] = useState('');
    const [dpoContact, setDpoContact] = useState('');

    function toggleModule(id: string) {
        setSelectedModules((prev) => (prev.includes(id) ? prev.filter((m) => m !== id) : [...prev, id]));
    }

    function handleExport() {
        // UI-only: would trigger backend export
        setShowExportDialog(false);
        setSelectedModules([]);
    }

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Settings', href: '/settings' },
        { title: 'Data & Privacy' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Data & Privacy" />
            <SettingsLayout>

            <div className="space-y-6">
                {/* Data Export */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Download className="h-5 w-5 text-violet-600" />
                                <div>
                                    <CardTitle>Data Export</CardTitle>
                                    <CardDescription>Download your organisation's data for compliance or backup</CardDescription>
                                </div>
                            </div>
                            <Button onClick={() => setShowExportDialog(true)} className="bg-violet-600 hover:bg-violet-700">
                                Export All Data
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-3">
                            <h4 className="text-sm font-medium">Recent Exports</h4>
                            {recentExports.map((exp) => (
                                <div key={exp.id} className="flex items-center justify-between rounded-lg border p-3">
                                    <div>
                                        <p className="text-sm font-medium">{exp.date}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {exp.modules.join(', ')} &middot; {exp.format} &middot; {exp.size}
                                        </p>
                                    </div>
                                    <Button variant="outline" size="sm" disabled={exp.status !== 'ready'}>
                                        <Download className="mr-1.5 h-3 w-3" />
                                        {exp.status === 'ready' ? 'Download' : 'Processing...'}
                                    </Button>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* Data Retention */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Archive className="h-5 w-5 text-violet-600" />
                            <div>
                                <CardTitle>Data Retention</CardTitle>
                                <CardDescription>Configure automatic data cleanup policies</CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-5">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <Label>Retain audit logs</Label>
                                    <Select value={auditLogRetention} onValueChange={setAuditLogRetention}>
                                        <SelectTrigger className="mt-1">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="1-year">1 year</SelectItem>
                                            <SelectItem value="2-years">2 years</SelectItem>
                                            <SelectItem value="5-years">5 years</SelectItem>
                                            <SelectItem value="forever">Forever</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Retain completed timesheets</Label>
                                    <Select value={timesheetRetention} onValueChange={setTimesheetRetention}>
                                        <SelectTrigger className="mt-1">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="2-years">2 years</SelectItem>
                                            <SelectItem value="5-years">5 years</SelectItem>
                                            <SelectItem value="7-years">7 years</SelectItem>
                                            <SelectItem value="forever">Forever</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Retain closed incidents</Label>
                                    <Select value={incidentRetention} onValueChange={setIncidentRetention}>
                                        <SelectTrigger className="mt-1">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="2-years">2 years</SelectItem>
                                            <SelectItem value="5-years">5 years</SelectItem>
                                            <SelectItem value="forever">Forever</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Auto-archive inactive clients after</Label>
                                    <Select value={autoArchive} onValueChange={setAutoArchive}>
                                        <SelectTrigger className="mt-1">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="never">Never</SelectItem>
                                            <SelectItem value="6-months">6 months</SelectItem>
                                            <SelectItem value="1-year">1 year</SelectItem>
                                            <SelectItem value="2-years">2 years</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Privacy */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Shield className="h-5 w-5 text-violet-600" />
                            <div>
                                <CardTitle>Privacy</CardTitle>
                                <CardDescription>Manage privacy and compliance settings</CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-5">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium">Enable client data anonymisation</p>
                                    <p className="text-xs text-muted-foreground">Anonymise personal data when clients are archived</p>
                                </div>
                                <Switch checked={anonymisation} onCheckedChange={setAnonymisation} />
                            </div>
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium">Require consent before data collection</p>
                                    <p className="text-xs text-muted-foreground">Clients must provide consent before their data is recorded</p>
                                </div>
                                <Switch checked={consentRequired} onCheckedChange={setConsentRequired} />
                            </div>
                            <div>
                                <Label htmlFor="privacy-url">Privacy Policy URL</Label>
                                <Input
                                    id="privacy-url"
                                    value={privacyUrl}
                                    onChange={(e) => setPrivacyUrl(e.target.value)}
                                    placeholder="https://example.com/privacy"
                                    className="mt-1"
                                />
                            </div>
                            <div>
                                <Label htmlFor="dpo-contact">Data Protection Officer Contact</Label>
                                <Input
                                    id="dpo-contact"
                                    value={dpoContact}
                                    onChange={(e) => setDpoContact(e.target.value)}
                                    placeholder="dpo@example.com"
                                    className="mt-1"
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div className="flex justify-end">
                    <Button className="bg-violet-600 hover:bg-violet-700">Save Changes</Button>
                </div>
            </div>

            {/* Export Dialog */}
            <Dialog open={showExportDialog} onOpenChange={setShowExportDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Export Data</DialogTitle>
                        <DialogDescription>Select the modules and format you want to export</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div>
                            <Label>Modules to Export</Label>
                            <div className="mt-2 space-y-2">
                                {exportModules.map((mod) => (
                                    <div key={mod.id} className="flex items-center gap-2">
                                        <Checkbox
                                            id={`export-${mod.id}`}
                                            checked={selectedModules.includes(mod.id)}
                                            onCheckedChange={() => toggleModule(mod.id)}
                                        />
                                        <Label htmlFor={`export-${mod.id}`} className="text-sm font-normal">{mod.label}</Label>
                                    </div>
                                ))}
                            </div>
                        </div>
                        <div>
                            <Label>Format</Label>
                            <Select value={exportFormat} onValueChange={setExportFormat}>
                                <SelectTrigger className="mt-1">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="csv">CSV</SelectItem>
                                    <SelectItem value="json">JSON</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowExportDialog(false)}>Cancel</Button>
                        <Button onClick={handleExport} disabled={selectedModules.length === 0} className="bg-violet-600 hover:bg-violet-700">
                            Export
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            </SettingsLayout>
        </AppLayout>
    );
}
