import { FleetStatCard } from '@/components/fleet-stat-card';
import { FLEET_COLORS } from '@/components/fleet-charts';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
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
import { Head, router } from '@inertiajs/react';
import { ArrowLeftRight, Car, Key, Lock, LogIn, LogOut, Search, Unlock } from 'lucide-react';
import { useState } from 'react';
import { formatDate, formatDateTime } from '@/lib/fleet-utils';


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
    current_holders: KeyHolder[];
    recent_logs: KeyLogEntry[];
    users: UserOption[];
    vehicles: VehicleOption[];
};

function actionBadge(action: string) {
    switch (action) {
        case 'checked_out':
            return <Badge variant="default">Checked Out</Badge>;
        case 'returned':
            return <Badge variant="secondary">Returned</Badge>;
        case 'transferred':
            return <Badge className="bg-amber-100 text-amber-800 hover:bg-amber-200">Transferred</Badge>;
        default:
            return <Badge variant="outline">{action}</Badge>;
    }
}

// Using shared formatDateTime from fleet-utils

export default function KeyManagement({
    current_holders: rawHolders,
    recent_logs: rawLogs,
    users,
    vehicles,
}: Props) {
    const current_holders = rawHolders ?? [];
    const recent_logs = rawLogs ?? [];

    const totalVehicles = current_holders.length;
    const keysOut = current_holders.filter((h) => h.status === 'checked_out').length;
    const keysInSafe = current_holders.filter((h) => h.status === 'returned' || h.location === 'key_safe').length;
    const transfersToday = recent_logs.filter((l) => {
        if (!l.created_at) return false;
        const today = new Date();
        const logDate = new Date(l.created_at);
        return logDate.toDateString() === today.toDateString();
    }).length;

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
                <FleetHero title="Key Management" description="Track vehicle key check-outs, returns, and transfers." />

                {/* Dark KPI Cards */}
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                    <FleetStatCard label="TOTAL VEHICLES" value={totalVehicles} icon={Car} subtitle="Tracked vehicles" />
                    <FleetStatCard label="KEYS OUT" value={keysOut} icon={Unlock} color="amber" valueClassName="text-amber-400" subtitle="Currently checked out" />
                    <FleetStatCard label="KEYS IN SAFE" value={keysInSafe} icon={Lock} color="amber" valueClassName="text-green-400" subtitle="Returned to safe" />
                    <FleetStatCard label="TRANSFERS TODAY" value={transfersToday} icon={ArrowLeftRight} subtitle="Activity today" />
                </div>

                {/* Action Buttons */}
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

                {/* Checkout Form */}
                {showCheckout && (
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
                {showReturn && (
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
                {showTransfer && (
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
                                                <td className="py-3 pr-4">
                                                    <div className="font-medium">{h.vehicle_name}</div>
                                                    {h.asset_tag && <div className="text-xs text-muted-foreground">{h.asset_tag}</div>}
                                                </td>
                                                <td className="py-3 pr-4">
                                                    <div className="flex items-center gap-2">
                                                        <span className={`h-2 w-2 rounded-full ${h.status === 'checked_out' ? 'bg-amber-500' : 'bg-green-500'}`} />
                                                        {h.holder_name ?? '-'}
                                                    </div>
                                                </td>
                                                <td className="py-3 pr-4 text-muted-foreground">{formatDate(h.since)}</td>
                                                <td className="py-3 pr-4">{actionBadge(h.status)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
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
                                                <td className="py-3 pr-4 whitespace-nowrap text-xs">{formatDate(l.created_at)}</td>
                                                <td className="py-3 pr-4">{l.vehicle ?? '-'}</td>
                                                <td className="py-3 pr-4">
                                                    {actionBadge(l.action)}
                                                    {l.transferred_to && <span className="ml-1 text-xs text-muted-foreground">to {l.transferred_to}</span>}
                                                </td>
                                                <td className="py-3 pr-4">{l.user ?? '-'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
