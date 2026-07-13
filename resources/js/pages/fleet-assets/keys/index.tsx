import PageShell from '@/components/page-shell';
import { FleetResponsiveTable } from '@/pages/fleet-assets/components/fleet-responsive-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import {
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import { Head, router } from '@inertiajs/react';
import { ArrowLeftRight, Key, KeyRound, LogIn, LogOut, Search } from 'lucide-react';
import { useState } from 'react';
import { formatDate } from '@/lib/fleet-utils';


type KeyHolder = {
    vehicle_id: number;
    vehicle_name: string;
    asset_tag: string | null;
    holder_id: number | null;
    holder_name: string | null;
    since: string | null;
    location: string | null;
    key_number: string | null;
    status: string;
};

type KeyLogEntry = {
    id: number;
    vehicle: string | null;
    action: string;
    user: string | null;
    transferred_to: string | null;
    key_number: string | null;
    location: string | null;
    notes: string | null;
    created_at: string | null;
};

type UserOption = { id: number; name: string };
type VehicleOption = { id: number; name: string; asset_tag: string | null };

type Props = {
    hero: {
        tracked: number;
        checked_out: number;
        in_safe: number;
        activity_today: number;
    };
    current_holders: KeyHolder[];
    recent_logs: KeyLogEntry[];
    users: UserOption[];
    vehicles: VehicleOption[];
    can: {
        manage: boolean;
    };
};

function actionBadge(action: string) {
    switch (action) {
        case 'checked_out':
            return <Badge variant="default">Checked Out</Badge>;
        case 'returned':
            return <Badge variant="secondary">Returned</Badge>;
        case 'transferred':
            return <Badge className="bg-status-warning-bg text-status-warning hover:bg-status-warning-bg">Transferred</Badge>;
        default:
            return <Badge variant="outline">{action}</Badge>;
    }
}

// Using shared formatDateTime from fleet-utils

export default function KeyManagement({
    hero,
    current_holders: rawHolders,
    recent_logs: rawLogs,
    users,
    vehicles,
    can,
}: Props) {
    const current_holders = rawHolders ?? [];
    const recent_logs = rawLogs ?? [];
    const heroStats = hero ?? { tracked: 0, checked_out: 0, in_safe: 0, activity_today: 0 };

    const [search, setSearch] = useState('');
    const [showCheckout, setShowCheckout] = useState(false);
    const [showReturn, setShowReturn] = useState(false);
    const [showTransfer, setShowTransfer] = useState(false);

    const [selectedVehicle, setSelectedVehicle] = useState('');
    const [selectedUser, setSelectedUser] = useState('');
    const [selectedTransferTo, setSelectedTransferTo] = useState('');
    const [keyNumber, setKeyNumber] = useState('');
    const [location, setLocation] = useState('');
    const [notes, setNotes] = useState('');

    const filteredHolders = current_holders.filter((h) => {
        const q = search.toLowerCase();
        return !q || h.vehicle_name?.toLowerCase().includes(q) || h.holder_name?.toLowerCase().includes(q) || h.asset_tag?.toLowerCase().includes(q);
    });

    const filteredLogs = recent_logs.filter((l) => {
        const q = search.toLowerCase();
        return !q || l.vehicle?.toLowerCase().includes(q) || l.user?.toLowerCase().includes(q);
    });

    const resetForm = () => {
        setSelectedVehicle(''); setSelectedUser(''); setSelectedTransferTo('');
        setKeyNumber(''); setLocation(''); setNotes('');
    };

    const handleCheckout = () => {
        router.post('/fleet-assets/keys/checkout', {
            asset_id: selectedVehicle, user_id: selectedUser,
            key_number: keyNumber || undefined, location: location || 'with_driver', notes: notes || undefined,
        }, { onSuccess: () => { resetForm(); setShowCheckout(false); } });
    };

    const handleReturn = () => {
        router.post('/fleet-assets/keys/return', {
            asset_id: selectedVehicle, key_number: keyNumber || undefined,
            location: location || 'key_safe', notes: notes || undefined,
        }, { onSuccess: () => { resetForm(); setShowReturn(false); } });
    };

    const handleTransfer = () => {
        router.post('/fleet-assets/keys/transfer', {
            asset_id: selectedVehicle, transferred_to_user_id: selectedTransferTo,
            key_number: keyNumber || undefined, location: location || 'with_driver', notes: notes || undefined,
        }, { onSuccess: () => { resetForm(); setShowTransfer(false); } });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Key Management', href: '/fleet-assets/keys' },
            ]}
        >
            <Head title="Key Management" />
            <PageShell>
                <HeroShell>
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={KeyRound} />
                        <div className="min-w-0">
                            <HeroStatusPill>Key ledger · live</HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">Key Management</h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                Track vehicle key check-outs, returns, and transfers.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4 lg:ml-auto lg:max-w-2xl">
                            <HeroClusterTile
                                label="Keys tracked"
                                value={fmt(heroStats.tracked)}
                                caption="vehicles in the ledger"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                label="Checked out"
                                value={fmt(heroStats.checked_out)}
                                caption="with drivers now"
                                tone={heroStats.checked_out > 0 ? 'warning' : 'success'}
                            />
                            <HeroClusterTile
                                label="In key safe"
                                value={fmt(heroStats.in_safe)}
                                caption="returned and secured"
                                tone="success"
                            />
                            <HeroClusterTile
                                label="Activity today"
                                value={fmt(heroStats.activity_today)}
                                caption="ledger entries logged"
                                tone="neutral"
                            />
                        </div>
                    </div>
                </HeroShell>

                {/* Action Buttons */}
                {can.manage ? (
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={() => { resetForm(); setShowCheckout(!showCheckout); setShowReturn(false); setShowTransfer(false); }}>
                            <LogOut className="mr-2 h-4 w-4" /> Check Out Key
                        </Button>
                        <Button variant="outline" onClick={() => { resetForm(); setShowReturn(!showReturn); setShowCheckout(false); setShowTransfer(false); }}>
                            <LogIn className="mr-2 h-4 w-4" /> Return Key
                        </Button>
                        <Button variant="outline" onClick={() => { resetForm(); setShowTransfer(!showTransfer); setShowCheckout(false); setShowReturn(false); }}>
                            <ArrowLeftRight className="mr-2 h-4 w-4" /> Transfer Key
                        </Button>
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Key activity is view-only for your account.
                    </p>
                )}

                {/* Checkout Form */}
                {can.manage && showCheckout && (
                    <Card>
                        <CardHeader><CardTitle>Check Out Key</CardTitle></CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                                <Select value={selectedVehicle} onValueChange={setSelectedVehicle}>
                                    <SelectTrigger><SelectValue placeholder="Select Vehicle" /></SelectTrigger>
                                    <SelectContent>{vehicles.map((v) => (<SelectItem key={v.id} value={String(v.id)}>{v.name}</SelectItem>))}</SelectContent>
                                </Select>
                                <Select value={selectedUser} onValueChange={setSelectedUser}>
                                    <SelectTrigger><SelectValue placeholder="Select Staff" /></SelectTrigger>
                                    <SelectContent>{users.map((u) => (<SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>))}</SelectContent>
                                </Select>
                                <Input placeholder="Key Number (optional)" value={keyNumber} onChange={(e) => setKeyNumber(e.target.value)} />
                                <Input placeholder="Notes (optional)" value={notes} onChange={(e) => setNotes(e.target.value)} />
                            </div>
                            <div className="mt-4 flex gap-2">
                                <Button onClick={handleCheckout} disabled={!selectedVehicle || !selectedUser}>Confirm Check Out</Button>
                                <Button variant="ghost" onClick={() => setShowCheckout(false)}>Cancel</Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Return Form */}
                {can.manage && showReturn && (
                    <Card>
                        <CardHeader><CardTitle>Return Key</CardTitle></CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                                <Select value={selectedVehicle} onValueChange={setSelectedVehicle}>
                                    <SelectTrigger><SelectValue placeholder="Select Vehicle" /></SelectTrigger>
                                    <SelectContent>{vehicles.map((v) => (<SelectItem key={v.id} value={String(v.id)}>{v.name}</SelectItem>))}</SelectContent>
                                </Select>
                                <Select value={location} onValueChange={setLocation}>
                                    <SelectTrigger><SelectValue placeholder="Return Location" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="key_safe">Key Safe</SelectItem>
                                        <SelectItem value="office">Office</SelectItem>
                                        <SelectItem value="reception">Reception</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Input placeholder="Key Number (optional)" value={keyNumber} onChange={(e) => setKeyNumber(e.target.value)} />
                                <Input placeholder="Notes (optional)" value={notes} onChange={(e) => setNotes(e.target.value)} />
                            </div>
                            <div className="mt-4 flex gap-2">
                                <Button onClick={handleReturn} disabled={!selectedVehicle}>Confirm Return</Button>
                                <Button variant="ghost" onClick={() => setShowReturn(false)}>Cancel</Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Transfer Form */}
                {can.manage && showTransfer && (
                    <Card>
                        <CardHeader><CardTitle>Transfer Key</CardTitle></CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                                <Select value={selectedVehicle} onValueChange={setSelectedVehicle}>
                                    <SelectTrigger><SelectValue placeholder="Select Vehicle" /></SelectTrigger>
                                    <SelectContent>{vehicles.map((v) => (<SelectItem key={v.id} value={String(v.id)}>{v.name}</SelectItem>))}</SelectContent>
                                </Select>
                                <Select value={selectedTransferTo} onValueChange={setSelectedTransferTo}>
                                    <SelectTrigger><SelectValue placeholder="Transfer To Staff" /></SelectTrigger>
                                    <SelectContent>{users.map((u) => (<SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>))}</SelectContent>
                                </Select>
                                <Input placeholder="Key Number (optional)" value={keyNumber} onChange={(e) => setKeyNumber(e.target.value)} />
                                <Input placeholder="Notes (optional)" value={notes} onChange={(e) => setNotes(e.target.value)} />
                            </div>
                            <div className="mt-4 flex gap-2">
                                <Button onClick={handleTransfer} disabled={!selectedVehicle || !selectedTransferTo}>Confirm Transfer</Button>
                                <Button variant="ghost" onClick={() => setShowTransfer(false)}>Cancel</Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Search */}
                <div className="relative max-w-sm">
                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input placeholder="Search by vehicle or staff..." className="pl-10" value={search} onChange={(e) => setSearch(e.target.value)} />
                </div>

                {/* Current Key Holders + Recent Activity side by side */}
                <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                    {/* Current Key Holders */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Key className="h-5 w-5" /> Current Key Holders
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <FleetResponsiveTable>
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="pb-2 pr-4 font-medium">Vehicle</th>
                                            <th className="pb-2 pr-4 font-medium">Key Holder</th>
                                            <th className="pb-2 pr-4 font-medium">Since</th>
                                            <th className="pb-2 pr-4 font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filteredHolders.length === 0 && (
                                            <tr><td colSpan={4} className="py-8 text-center text-muted-foreground">No key records found.</td></tr>
                                        )}
                                        {filteredHolders.map((h) => (
                                            <tr key={h.vehicle_id} className="border-b last:border-0 transition-colors hover:bg-muted/30 transition-colors">
                                                <td data-fleet-row-identity className="py-3 pr-4">
                                                    <div className="font-medium">{h.vehicle_name}</div>
                                                    {h.asset_tag && <div className="text-xs text-muted-foreground">{h.asset_tag}</div>}
                                                </td>
                                                <td className="py-3 pr-4">
                                                    <div className="flex items-center gap-2">
                                                        <span className={`h-2 w-2 rounded-full ${h.status === 'checked_out' ? 'bg-status-warning' : 'bg-status-success'}`} />
                                                        {h.holder_name ?? '-'}
                                                    </div>
                                                </td>
                                                <td data-fleet-row-time className="py-3 pr-4 text-muted-foreground">{formatDate(h.since)}</td>
                                                <td data-fleet-row-status data-fleet-row-action className="py-3 pr-4">{actionBadge(h.status)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                </FleetResponsiveTable>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Recent Key Activity */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Recent Activity</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <FleetResponsiveTable>
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="pb-2 pr-4 font-medium">Date</th>
                                            <th className="pb-2 pr-4 font-medium">Vehicle</th>
                                            <th className="pb-2 pr-4 font-medium">Action</th>
                                            <th className="pb-2 pr-4 font-medium">User</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filteredLogs.length === 0 && (
                                            <tr><td colSpan={4} className="py-8 text-center text-muted-foreground">No key activity recorded yet.</td></tr>
                                        )}
                                        {filteredLogs.map((l) => (
                                            <tr key={l.id} className="border-b last:border-0">
                                                <td data-fleet-row-time className="py-3 pr-4 whitespace-nowrap text-xs">{formatDate(l.created_at)}</td>
                                                <td data-fleet-row-identity className="py-3 pr-4">{l.vehicle ?? '-'}</td>
                                                <td data-fleet-row-status data-fleet-row-action className="py-3 pr-4">
                                                    {actionBadge(l.action)}
                                                    {l.transferred_to && <span className="ml-1 text-xs text-muted-foreground">to {l.transferred_to}</span>}
                                                </td>
                                                <td className="py-3 pr-4">{l.user ?? '-'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                </FleetResponsiveTable>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
