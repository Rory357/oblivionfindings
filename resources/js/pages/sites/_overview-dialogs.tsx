import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { Check, Loader2, MapPin, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

// ── Shared helpers ────────────────────────────────────────────────────────

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

// ── 1. Contact Information ────────────────────────────────────────────────

type ContactInfoValues = {
    phone: string;
    email: string;
    manager_name: string;
    manager_phone: string;
    after_hours_phone: string;
};

export function EditContactInfoDialog({
    siteId,
    isOpen,
    onClose,
    initial,
}: {
    siteId: number;
    isOpen: boolean;
    onClose: () => void;
    initial: Partial<ContactInfoValues>;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                {isOpen && (
                    <ContactInfoBody
                        siteId={siteId}
                        initial={initial}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function ContactInfoBody({
    siteId,
    initial,
    onClose,
}: {
    siteId: number;
    initial: Partial<ContactInfoValues>;
    onClose: () => void;
}) {
    const form = useForm<ContactInfoValues>({
        phone: initial.phone ?? '',
        email: initial.email ?? '',
        manager_name: initial.manager_name ?? '',
        manager_phone: initial.manager_phone ?? '',
        after_hours_phone: initial.after_hours_phone ?? '',
    });

    const handleSubmit = () => {
        form.patch(`/sites/${siteId}/contact-info`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle>Edit Contact Information</DialogTitle>
            </DialogHeader>

            <div className="space-y-4">
                <div>
                    <Label htmlFor="ci-phone">Phone</Label>
                    <Input
                        id="ci-phone"
                        value={form.data.phone}
                        onChange={(e) => form.setData('phone', e.target.value)}
                        placeholder="09 555 0100"
                    />
                    <FieldError message={form.errors.phone} />
                </div>
                <div>
                    <Label htmlFor="ci-email">Email</Label>
                    <Input
                        id="ci-email"
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        placeholder="house@example.org.nz"
                    />
                    <FieldError message={form.errors.email} />
                </div>
                <div>
                    <Label htmlFor="ci-lead">Site Lead</Label>
                    <Input
                        id="ci-lead"
                        value={form.data.manager_name}
                        onChange={(e) => form.setData('manager_name', e.target.value)}
                        placeholder="Lead's name"
                    />
                    <FieldError message={form.errors.manager_name} />
                </div>
                <div>
                    <Label htmlFor="ci-mphone">Manager Phone</Label>
                    <Input
                        id="ci-mphone"
                        value={form.data.manager_phone}
                        onChange={(e) => form.setData('manager_phone', e.target.value)}
                        placeholder="021 555 0100"
                    />
                    <FieldError message={form.errors.manager_phone} />
                </div>
                <div>
                    <Label htmlFor="ci-after">After Hours Phone</Label>
                    <Input
                        id="ci-after"
                        value={form.data.after_hours_phone}
                        onChange={(e) => form.setData('after_hours_phone', e.target.value)}
                        placeholder="0800 000 000"
                    />
                    <FieldError message={form.errors.after_hours_phone} />
                </div>
            </div>

            <DialogFooter className="gap-2">
                <Button variant="outline" onClick={onClose} disabled={form.processing}>
                    Cancel
                </Button>
                <Button onClick={handleSubmit} disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save'}
                </Button>
            </DialogFooter>
        </>
    );
}

// ── 2. Location with Nominatim autocomplete ───────────────────────────────

type LocationValues = {
    address_line_1: string;
    address_line_2: string;
    suburb: string;
    city: string;
    postcode: string;
    country: string;
    region: string;
    latitude: string;
    longitude: string;
    access_instructions: string;
};

type GeocodeResult = {
    display_name: string;
    lat: number | null;
    lng: number | null;
    address_line_1: string | null;
    suburb: string | null;
    city: string | null;
    postcode: string | null;
    country: string | null;
    region: string | null;
};

export function EditLocationDialog({
    siteId,
    isOpen,
    onClose,
    initial,
}: {
    siteId: number;
    isOpen: boolean;
    onClose: () => void;
    initial: Partial<LocationValues>;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] max-w-xl overflow-y-auto">
                {isOpen && (
                    <LocationBody
                        siteId={siteId}
                        initial={initial}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function LocationBody({
    siteId,
    initial,
    onClose,
}: {
    siteId: number;
    initial: Partial<LocationValues>;
    onClose: () => void;
}) {
    const form = useForm<LocationValues>({
        address_line_1: initial.address_line_1 ?? '',
        address_line_2: initial.address_line_2 ?? '',
        suburb: initial.suburb ?? '',
        city: initial.city ?? '',
        postcode: initial.postcode ?? '',
        country: initial.country ?? 'New Zealand',
        region: initial.region ?? '',
        latitude: initial.latitude ?? '',
        longitude: initial.longitude ?? '',
        access_instructions: initial.access_instructions ?? '',
    });

    const initialAddress = [
        initial.address_line_1,
        initial.suburb,
        initial.city,
        initial.postcode,
    ]
        .filter(Boolean)
        .join(', ');

    const [searchTerm, setSearchTerm] = useState(initialAddress);
    const [results, setResults] = useState<GeocodeResult[]>([]);
    const [searching, setSearching] = useState(false);
    const [showResults, setShowResults] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const wrapRef = useRef<HTMLDivElement>(null);

    // Click outside the suggestions panel closes it
    useEffect(() => {
        if (!showResults) return;
        const onDocClick = (e: MouseEvent) => {
            if (!wrapRef.current?.contains(e.target as Node)) {
                setShowResults(false);
            }
        };
        document.addEventListener('mousedown', onDocClick);
        return () => document.removeEventListener('mousedown', onDocClick);
    }, [showResults]);

    useEffect(() => {
        if (debounceRef.current) clearTimeout(debounceRef.current);
        if (searchTerm.trim().length < 3 || searchTerm === initialAddress) {
            setResults([]);
            return;
        }

        debounceRef.current = setTimeout(async () => {
            setSearching(true);
            try {
                const res = await fetch(
                    `/sites/geocode/search?q=${encodeURIComponent(searchTerm.trim())}`,
                    { headers: { Accept: 'application/json' } },
                );
                if (res.ok) {
                    const json = await res.json();
                    setResults(json.results ?? []);
                    setShowResults(true);
                }
            } catch {
                // silent — user can still type address manually below
            } finally {
                setSearching(false);
            }
        }, 400);

        return () => {
            if (debounceRef.current) clearTimeout(debounceRef.current);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchTerm]);

    const applyResult = (r: GeocodeResult) => {
        form.setData({
            ...form.data,
            address_line_1: r.address_line_1 ?? '',
            suburb: r.suburb ?? '',
            city: r.city ?? '',
            postcode: r.postcode ?? '',
            country: r.country ?? form.data.country,
            region: r.region ?? '',
            latitude: r.lat != null ? String(r.lat) : '',
            longitude: r.lng != null ? String(r.lng) : '',
        });
        setSearchTerm(r.display_name);
        setResults([]);
        setShowResults(false);
    };

    const handleSubmit = () => {
        form.patch(`/sites/${siteId}/location`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const hasCoords = form.data.latitude !== '' && form.data.longitude !== '';

    return (
        <>
            <DialogHeader>
                <DialogTitle>Edit Location</DialogTitle>
            </DialogHeader>

            <div className="space-y-4">
                {/* Headline: address autocomplete */}
                <div ref={wrapRef}>
                    <Label htmlFor="loc-search" className="flex items-center gap-1">
                        <Search className="h-3.5 w-3.5" />
                        Search for an address
                    </Label>
                    <div className="relative">
                        <Input
                            id="loc-search"
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            onFocus={() => results.length > 0 && setShowResults(true)}
                            placeholder="Start typing an address…"
                            autoComplete="off"
                        />
                        {searching && (
                            <Loader2 className="absolute right-2.5 top-2.5 h-4 w-4 animate-spin text-muted-foreground" />
                        )}
                        {showResults && (
                            <div className="absolute z-50 mt-1 max-h-64 w-full overflow-y-auto rounded-md border border-border bg-popover shadow-lg">
                                {results.length === 0 ? (
                                    <div className="p-3 text-center text-xs text-muted-foreground">
                                        No matches found. Try adding more detail
                                        (suburb, city, or postcode), or fill the
                                        fields below manually.
                                    </div>
                                ) : (
                                    results.map((r, i) => (
                                        <button
                                            type="button"
                                            key={i}
                                            // mousedown fires before input blur, so the click
                                            // always lands even if focus shifts away first
                                            onMouseDown={(e) => {
                                                e.preventDefault();
                                                applyResult(r);
                                            }}
                                            className="flex w-full items-start gap-2 border-b border-border/40 p-2 text-left text-sm last:border-0 hover:bg-accent"
                                        >
                                            <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary" />
                                            <span className="leading-snug">{r.display_name}</span>
                                        </button>
                                    ))
                                )}
                            </div>
                        )}
                    </div>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Powered by OpenStreetMap. Picking a result fills the address and
                        map location for you.
                    </p>
                </div>

                {/* GPS confirmation chip — read-only, set by the autocomplete */}
                {hasCoords && (
                    <div className="flex items-center gap-2 rounded-md border border-status-success/30 bg-status-success-bg/40 px-3 py-2 text-xs text-status-success">
                        <Check className="h-3.5 w-3.5" />
                        <span>
                            Map location set
                            <span className="ml-2 font-mono text-muted-foreground">
                                ({Number(form.data.latitude).toFixed(4)},{' '}
                                {Number(form.data.longitude).toFixed(4)})
                            </span>
                        </span>
                    </div>
                )}

                {/* Editable address fields (pre-filled from autocomplete) */}
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                        <Label htmlFor="loc-l1">Street address</Label>
                        <Input
                            id="loc-l1"
                            value={form.data.address_line_1}
                            onChange={(e) => form.setData('address_line_1', e.target.value)}
                        />
                        <FieldError message={form.errors.address_line_1} />
                    </div>
                    <div className="sm:col-span-2">
                        <Label htmlFor="loc-l2">
                            Address line 2{' '}
                            <span className="text-xs text-muted-foreground">(optional)</span>
                        </Label>
                        <Input
                            id="loc-l2"
                            value={form.data.address_line_2}
                            onChange={(e) => form.setData('address_line_2', e.target.value)}
                        />
                        <FieldError message={form.errors.address_line_2} />
                    </div>
                    <div>
                        <Label htmlFor="loc-suburb">Suburb</Label>
                        <Input
                            id="loc-suburb"
                            value={form.data.suburb}
                            onChange={(e) => form.setData('suburb', e.target.value)}
                        />
                        <FieldError message={form.errors.suburb} />
                    </div>
                    <div>
                        <Label htmlFor="loc-city">City</Label>
                        <Input
                            id="loc-city"
                            value={form.data.city}
                            onChange={(e) => form.setData('city', e.target.value)}
                        />
                        <FieldError message={form.errors.city} />
                    </div>
                    <div>
                        <Label htmlFor="loc-postcode">Postcode</Label>
                        <Input
                            id="loc-postcode"
                            value={form.data.postcode}
                            onChange={(e) => form.setData('postcode', e.target.value)}
                        />
                        <FieldError message={form.errors.postcode} />
                    </div>
                    <div>
                        <Label htmlFor="loc-region">Region</Label>
                        <Input
                            id="loc-region"
                            value={form.data.region}
                            onChange={(e) => form.setData('region', e.target.value)}
                        />
                        <FieldError message={form.errors.region} />
                    </div>
                    <div className="sm:col-span-2">
                        <Label htmlFor="loc-country">Country</Label>
                        <Input
                            id="loc-country"
                            value={form.data.country}
                            onChange={(e) => form.setData('country', e.target.value)}
                        />
                        <FieldError message={form.errors.country} />
                    </div>
                </div>

                <div>
                    <Label htmlFor="loc-access">
                        Access instructions{' '}
                        <span className="text-xs text-muted-foreground">(optional)</span>
                    </Label>
                    <Textarea
                        id="loc-access"
                        value={form.data.access_instructions}
                        onChange={(e) => form.setData('access_instructions', e.target.value)}
                        placeholder="Gate code, parking, key location…"
                        className="min-h-[80px]"
                    />
                    <FieldError message={form.errors.access_instructions} />
                </div>
            </div>

            <DialogFooter className="gap-2">
                <Button variant="outline" onClick={onClose} disabled={form.processing}>
                    Cancel
                </Button>
                <Button onClick={handleSubmit} disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save'}
                </Button>
            </DialogFooter>
        </>
    );
}

// ── 3. Safety & Medication ────────────────────────────────────────────────

type SafetyValues = {
    emergency_plan_location: string;
    medication_storage_location: string;
};

export function EditSafetyDialog({
    siteId,
    isOpen,
    onClose,
    initial,
}: {
    siteId: number;
    isOpen: boolean;
    onClose: () => void;
    initial: Partial<SafetyValues>;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                {isOpen && (
                    <SafetyBody
                        siteId={siteId}
                        initial={initial}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function SafetyBody({
    siteId,
    initial,
    onClose,
}: {
    siteId: number;
    initial: Partial<SafetyValues>;
    onClose: () => void;
}) {
    const form = useForm<SafetyValues>({
        emergency_plan_location: initial.emergency_plan_location ?? '',
        medication_storage_location: initial.medication_storage_location ?? '',
    });

    const handleSubmit = () => {
        form.patch(`/sites/${siteId}/safety`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle>Edit Safety & Medication</DialogTitle>
            </DialogHeader>

            <div className="space-y-4">
                <div>
                    <Label htmlFor="sf-plan">Emergency Plan</Label>
                    <Textarea
                        id="sf-plan"
                        value={form.data.emergency_plan_location}
                        onChange={(e) => form.setData('emergency_plan_location', e.target.value)}
                        placeholder="Where the emergency plan is kept (e.g. binder in kitchen, intranet link)"
                        className="min-h-[80px]"
                    />
                    <FieldError message={form.errors.emergency_plan_location} />
                </div>
                <div>
                    <Label htmlFor="sf-med">Medication Storage</Label>
                    <Textarea
                        id="sf-med"
                        value={form.data.medication_storage_location}
                        onChange={(e) => form.setData('medication_storage_location', e.target.value)}
                        placeholder="Where medications are stored (e.g. locked cabinet in office)"
                        className="min-h-[80px]"
                    />
                    <FieldError message={form.errors.medication_storage_location} />
                </div>
            </div>

            <DialogFooter className="gap-2">
                <Button variant="outline" onClick={onClose} disabled={form.processing}>
                    Cancel
                </Button>
                <Button onClick={handleSubmit} disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save'}
                </Button>
            </DialogFooter>
        </>
    );
}

// ── 4. Add Note ───────────────────────────────────────────────────────────

export function AddSiteNoteDialog({
    siteId,
    isOpen,
    onClose,
}: {
    siteId: number;
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                {isOpen && <NoteBody siteId={siteId} onClose={onClose} />}
            </DialogContent>
        </Dialog>
    );
}

function NoteBody({ siteId, onClose }: { siteId: number; onClose: () => void }) {
    const form = useForm<{ body: string }>({ body: '' });

    const handleSubmit = () => {
        form.post(`/sites/${siteId}/notes`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle>New Note</DialogTitle>
            </DialogHeader>

            <div>
                <Label htmlFor="note-body">Note</Label>
                <Textarea
                    id="note-body"
                    autoFocus
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                    placeholder="Handover note, observation, reminder…"
                    className="min-h-[120px]"
                />
                <FieldError message={form.errors.body} />
            </div>

            <DialogFooter className="gap-2">
                <Button variant="outline" onClick={onClose} disabled={form.processing}>
                    Cancel
                </Button>
                <Button
                    onClick={handleSubmit}
                    disabled={form.processing || form.data.body.trim().length === 0}
                >
                    {form.processing ? 'Saving…' : 'Add Note'}
                </Button>
            </DialogFooter>
        </>
    );
}
