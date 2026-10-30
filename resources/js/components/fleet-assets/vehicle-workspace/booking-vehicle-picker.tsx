import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Check, ChevronsUpDown } from 'lucide-react';
import { useId, useState } from 'react';
import type { VehicleProfile } from './types';
import { fieldProps } from './wizard-kit';

export type BookingVehicle = Pick<
    VehicleProfile,
    'id' | 'name' | 'asset_tag' | 'registration_number' | 'site'
>;

export function BookingVehiclePicker({
    vehicles,
    value,
    onChange,
    error,
}: {
    vehicles: BookingVehicle[];
    value: number | null;
    onChange: (id: number) => void;
    error?: string;
}) {
    const [open, setOpen] = useState(false);
    const listId = useId();
    const selected = vehicles.find((vehicle) => vehicle.id === value);
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    {...fieldProps('request_vehicle', error)}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-controls={open ? listId : undefined}
                    className="h-auto min-h-11 w-full justify-between text-left"
                >
                    <span className="min-w-0 truncate">
                        {selected
                            ? `${selected.name} · ${selected.registration_number ?? selected.asset_tag ?? 'Registration not recorded'}`
                            : 'Choose a vehicle'}
                    </span>
                    <ChevronsUpDown className="ml-2 size-4 shrink-0" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-[var(--radix-popover-trigger-width)] p-0"
            >
                <Command>
                    <CommandInput
                        aria-label="Search vehicles"
                        placeholder="Search name, registration or site…"
                    />
                    <CommandList id={listId}>
                        <CommandEmpty>
                            No matching vehicles at your approved sites.
                        </CommandEmpty>
                        {vehicles.map((vehicle) => (
                            <CommandItem
                                key={vehicle.id}
                                value={`${vehicle.id} ${vehicle.name} ${vehicle.registration_number ?? ''} ${vehicle.asset_tag ?? ''} ${vehicle.site?.name ?? ''}`}
                                onSelect={() => {
                                    onChange(vehicle.id);
                                    setOpen(false);
                                }}
                                className="min-h-11 gap-2"
                            >
                                <Check
                                    className={`size-4 shrink-0 ${value === vehicle.id ? 'opacity-100' : 'opacity-0'}`}
                                />
                                <span>
                                    <strong className="block">
                                        {vehicle.name}
                                    </strong>
                                    <span className="text-caption">
                                        {[
                                            vehicle.registration_number ??
                                                vehicle.asset_tag,
                                            vehicle.site?.name,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </span>
                            </CommandItem>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
