import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus } from 'lucide-react';
import { useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

type Staff = {
    id: number;
    name: string;
    email: string;
};

type SessionType = {
    value: string;
    label: string;
};

type SupervisionNote = {
    id: number;
    employee_user_id: number;
    session_date: string;
    session_type: string;
    duration_minutes: number | null;
    topics_discussed: string | null;
    actions_agreed: string[] | null;
    next_session_date: string | null;
    is_visible_to_employee: boolean;
    employee: {
        id: number;
        name: string;
    };
};

type Props = {
    note: SupervisionNote;
    staff: Staff[];
    sessionTypes: SessionType[];
};

export default function EditSupervision({ note, staff, sessionTypes }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Performance & Supervision', href: '/hr/performance' },
        { title: note.employee.name, href: `/hr/performance/supervision/${note.id}` },
        { title: 'Edit', href: `/hr/performance/supervision/${note.id}/edit` },
    ];

    const initialActions = note.actions_agreed?.length ? note.actions_agreed : [''];
    const [actions, setActions] = useState<string[]>(initialActions);

    const { data, setData, put, processing, errors } = useForm({
        session_date: note.session_date.split('T')[0],
        session_type: note.session_type,
        duration_minutes: note.duration_minutes?.toString() || '',
        topics_discussed: note.topics_discussed || '',
        actions_agreed: note.actions_agreed || [],
        next_session_date: note.next_session_date?.split('T')[0] || '',
        is_visible_to_employee: note.is_visible_to_employee,
    });

    const addAction = () => {
        setActions([...actions, '']);
    };

    const updateAction = (index: number, value: string) => {
        const newActions = [...actions];
        newActions[index] = value;
        setActions(newActions);
        setData('actions_agreed', newActions.filter(a => a.trim() !== ''));
    };

    const removeAction = (index: number) => {
        const newActions = actions.filter((_, i) => i !== index);
        setActions(newActions);
        setData('actions_agreed', newActions.filter(a => a.trim() !== ''));
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/hr/performance/supervision/${note.id}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit Supervision Note" />

            <div className="space-y-6 max-w-4xl">
                <div className="flex items-center gap-4">
                    <Link href={`/hr/performance/supervision/${note.id}`}>
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-2xl font-bold">Edit Supervision Note</h1>
                        <p className="text-muted-foreground">Update supervision session for {note.employee.name}</p>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Session Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="employee_user_id">Staff Member</Label>
                                    <Input 
                                        value={note.employee.name} 
                                        disabled 
                                        className="bg-muted"
                                    />
                                    <p className="text-xs text-muted-foreground">Employee cannot be changed</p>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="session_type">
                                        Session Type <span className="text-red-500">*</span>
                                    </Label>
                                    <Select
                                        value={data.session_type}
                                        onValueChange={(value) => setData('session_type', value)}
                                    >
                                        <SelectTrigger id="session_type" className={errors.session_type ? 'border-red-500' : ''}>
                                            <SelectValue placeholder="Select session type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {sessionTypes.map((type) => (
                                                <SelectItem key={type.value} value={type.value}>
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.session_type && (
                                        <p className="text-sm text-red-500">{errors.session_type}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="session_date">
                                        Session Date <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        id="session_date"
                                        type="date"
                                        value={data.session_date}
                                        onChange={(e) => setData('session_date', e.target.value)}
                                        className={errors.session_date ? 'border-red-500' : ''}
                                    />
                                    {errors.session_date && (
                                        <p className="text-sm text-red-500">{errors.session_date}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="duration_minutes">Duration (minutes)</Label>
                                    <Input
                                        id="duration_minutes"
                                        type="number"
                                        min="1"
                                        placeholder="e.g., 30"
                                        value={data.duration_minutes}
                                        onChange={(e) => setData('duration_minutes', e.target.value)}
                                        className={errors.duration_minutes ? 'border-red-500' : ''}
                                    />
                                    {errors.duration_minutes && (
                                        <p className="text-sm text-red-500">{errors.duration_minutes}</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="topics_discussed">Topics Discussed</Label>
                                <Textarea
                                    id="topics_discussed"
                                    placeholder="What was discussed in this session..."
                                    rows={5}
                                    value={data.topics_discussed}
                                    onChange={(e) => setData('topics_discussed', e.target.value)}
                                    className={errors.topics_discussed ? 'border-red-500' : ''}
                                />
                                {errors.topics_discussed && (
                                    <p className="text-sm text-red-500">{errors.topics_discussed}</p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Actions Agreed</CardTitle>
                            <Button type="button" variant="outline" size="sm" onClick={addAction}>
                                <Plus className="mr-2 h-4 w-4" />
                                Add Action
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {actions.map((action, index) => (
                                <div key={index} className="flex gap-2">
                                    <Input
                                        placeholder={`Action item ${index + 1}`}
                                        value={action}
                                        onChange={(e) => updateAction(index, e.target.value)}
                                    />
                                    {actions.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => removeAction(index)}
                                            className="text-red-500 hover:text-red-600"
                                        >
                                            Remove
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Follow-up</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="next_session_date">Next Session Date</Label>
                                    <Input
                                        id="next_session_date"
                                        type="date"
                                        value={data.next_session_date}
                                        onChange={(e) => setData('next_session_date', e.target.value)}
                                        className={errors.next_session_date ? 'border-red-500' : ''}
                                    />
                                    {errors.next_session_date && (
                                        <p className="text-sm text-red-500">{errors.next_session_date}</p>
                                    )}
                                </div>
                            </div>

                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="is_visible_to_employee"
                                    checked={data.is_visible_to_employee}
                                    onCheckedChange={(checked) => setData('is_visible_to_employee', checked as boolean)}
                                />
                                <Label htmlFor="is_visible_to_employee" className="text-sm font-normal">
                                    Make this note visible to the employee
                                </Label>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-4">
                        <Link href={`/hr/performance/supervision/${note.id}`}>
                            <Button type="button" variant="outline">Cancel</Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Update Supervision Note'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
