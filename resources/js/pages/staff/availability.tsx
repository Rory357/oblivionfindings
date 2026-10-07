import {
    PageHeader,
    PageHeaderGlassButton,
} from '@/components/page/page-header';
import {
    EditAvailabilityDialog,
    continuingAvailability,
} from '@/components/rostering/edit-availability-dialog';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import { WORKER_TIMEZONE } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { CalendarCheck, Plus } from 'lucide-react';
import { useState } from 'react';

type Staff = { id: number; name: string; email: string };
type Availability = {
    id: number;
    day_of_week: number;
    starts_at: string;
    ends_at: string;
    ends_next_day?: boolean;
};
type Props = {
    user: Staff;
    availability: Availability[];
    canManage: boolean;
    workerTimezone?: string;
    staffOptions?: Staff[];
};
const DAYS = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];
export default function StaffAvailability({
    user,
    availability,
    canManage,
    workerTimezone = WORKER_TIMEZONE,
    staffOptions = [],
}: Props) {
    const [editing, setEditing] = useState(false);
    const blocks = availability.map((block) => ({
        ...block,
        start_time: block.starts_at,
        end_time: block.ends_at,
    }));
    const [pickerOpen, setPickerOpen] = useState(false);
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Availability', href: '/operations/availability' },
            ]}
        >
            <Head title={`Availability: ${user.name}`} />
            <div className="space-y-4">
                <PageHeader
                    icon={CalendarCheck}
                    title="Availability"
                    subline={`${user.name} · Weekly working times · ${workerTimezone}`}
                    actions={
                        canManage ? (
                            <PageHeaderGlassButton
                                icon={Plus}
                                onClick={() => setEditing(true)}
                            >
                                Edit weekly availability
                            </PageHeaderGlassButton>
                        ) : undefined
                    }
                />
                {staffOptions.length > 1 && (
                    <div className="max-w-md space-y-2">
                        <Label>Staff member</Label>
                        <Popover open={pickerOpen} onOpenChange={setPickerOpen}>
                            <PopoverTrigger asChild>
                                <Button
                                    variant="outline"
                                    className="w-full justify-start"
                                    role="combobox"
                                    aria-expanded={pickerOpen}
                                    aria-label="Staff member"
                                >
                                    {user.name}
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent className="p-0">
                                <Command>
                                    <CommandInput placeholder="Find a staff member" />
                                    <CommandList>
                                        <CommandEmpty>
                                            No matching staff
                                        </CommandEmpty>
                                        {staffOptions.map((staff) => (
                                            <CommandItem
                                                key={staff.id}
                                                value={`${staff.name} ${staff.id}`}
                                                onSelect={() => {
                                                    setPickerOpen(false);
                                                    router.get(
                                                        '/operations/availability',
                                                        { staff_id: staff.id },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }}
                                            >
                                                {staff.name}
                                            </CommandItem>
                                        ))}
                                    </CommandList>
                                </Command>
                            </PopoverContent>
                        </Popover>
                    </div>
                )}
                <p className="text-subtle">
                    Availability is a weekly preference. Approved leave, other
                    duties and eligibility are checked separately. Overnight
                    blocks show the day they end.
                </p>
                <div className="grid gap-4 md:grid-cols-2">
                    {[1, 2, 3, 4, 5, 6, 0].map((day) => (
                        <section
                            key={day}
                            className="rounded-lg border bg-card"
                        >
                            <h2 className="text-section-title border-b p-4">
                                {DAYS[day]}
                            </h2>
                            <div className="space-y-2 p-4">
                                {continuingAvailability(blocks, day).map(
                                    (block) => (
                                        <p
                                            key={`continued-${block.id}`}
                                            className="text-subtle"
                                        >
                                            Continues from{' '}
                                            {DAYS[block.day_of_week]} until{' '}
                                            {block.end_time}
                                        </p>
                                    ),
                                )}
                                {availability.filter(
                                    (block) => block.day_of_week === day,
                                ).length ? (
                                    availability
                                        .filter(
                                            (block) =>
                                                block.day_of_week === day,
                                        )
                                        .map((block) => (
                                            <p
                                                key={block.id}
                                                className="text-sm"
                                            >
                                                {block.starts_at}–
                                                {block.ends_at}
                                                {block.ends_next_day
                                                    ? ` · ends ${DAYS[(day + 1) % 7]}`
                                                    : ''}
                                            </p>
                                        ))
                                ) : continuingAvailability(blocks, day)
                                      .length ? null : (
                                    <p className="text-subtle">
                                        No times supplied
                                    </p>
                                )}
                            </div>
                        </section>
                    ))}
                </div>
                {canManage && (
                    <Button onClick={() => setEditing(true)}>
                        Review or change availability
                    </Button>
                )}
                <EditAvailabilityDialog
                    key={user.id}
                    open={editing}
                    onOpenChange={setEditing}
                    staff={user}
                    canManage={canManage}
                    blocks={availability.map((block) => ({
                        ...block,
                        start_time: block.starts_at,
                        end_time: block.ends_at,
                    }))}
                    workerTimezone={workerTimezone}
                    reloadKeys={['availability']}
                />
            </div>
        </AppLayout>
    );
}
