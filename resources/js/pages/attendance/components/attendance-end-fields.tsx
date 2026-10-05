import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Field } from '@/components/wizard/primitives';
import { attendanceTimeOptions } from './attendance-time';

export function AttendanceEndFields({
    id,
    date,
    time,
    zone,
    choice,
    onDate,
    onTime,
    onChoice,
    error,
}: {
    id: string;
    date: string;
    time: string;
    zone: string;
    choice: string;
    onDate: (value: string) => void;
    onTime: (value: string) => void;
    onChoice: (value: string) => void;
    error?: string;
}) {
    const options = attendanceTimeOptions(date + 'T' + time, zone);
    return (
        <div className="grid gap-3 sm:col-span-2">
            <p className="text-sm text-muted-foreground">
                Clock-out date and time · {zone}
            </p>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Clock-out date" required htmlFor={id + '-date'}>
                    <DatePicker
                        id={id + '-date'}
                        label="Clock-out date"
                        value={date}
                        onChange={(value) => {
                            onDate(value);
                            onChoice('');
                        }}
                        timeZone={zone}
                        invalid={!!error}
                        describedBy={error ? id + '-error' : undefined}
                    />
                </Field>
                <Field label="Clock-out time" required htmlFor={id + '-time'}>
                    <TimePicker
                        id={id + '-time'}
                        label="Clock-out time"
                        value={time}
                        onChange={(value) => {
                            onTime(value);
                            onChoice('');
                        }}
                        timezone={zone}
                        invalid={!!error}
                        describedBy={error ? id + '-error' : undefined}
                    />
                </Field>
            </div>
            {options.length > 1 && (
                <Field
                    label="Which occurrence?"
                    required
                    hint="The clocks repeat this time. An unchanged recorded time keeps its original occurrence."
                >
                    <Select value={choice} onValueChange={onChoice}>
                        <SelectTrigger
                            className="frontline-tap"
                            aria-label="Which occurrence?"
                        >
                            <SelectValue placeholder="Keep recorded time, or choose an occurrence" />
                        </SelectTrigger>
                        <SelectContent>
                            {options.map((value, index) => (
                                <SelectItem key={value} value={value}>
                                    {index === 0 ? 'First' : 'Second'}{' '}
                                    occurrence ·{' '}
                                    {new Intl.DateTimeFormat('en-NZ', {
                                        timeZone: zone,
                                        timeZoneName: 'shortOffset',
                                        hour: 'numeric',
                                        minute: '2-digit',
                                    }).format(new Date(value))}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            )}
            {error && (
                <p
                    id={id + '-error'}
                    role="alert"
                    className="text-sm text-destructive"
                >
                    {error}
                </p>
            )}
        </div>
    );
}
