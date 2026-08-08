import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { ClipboardList, Plus, User } from 'lucide-react';
import { useState } from 'react';

interface Interest {
    id: number;
    interest_type: string;
    description: string;
    organization_name: string | null;
    nature_of_interest: string;
    date_from: string;
    date_to: string | null;
    is_active: boolean;
    declared_at: string;
}

interface BoardMember {
    id: number;
    user: { id: number; name: string };
}

interface Props extends PageProps {
    interestsByMember: Record<string, Interest[]>;
    boardMembers: BoardMember[];
}

export default function InterestsIndex({
    auth,
    interestsByMember,
    boardMembers,
}: Props) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, reset, errors } = useForm({
        board_member_id: '',
        interest_type: 'professional',
        description: '',
        organization_name: '',
        nature_of_interest: '',
        date_from: new Date().toISOString().split('T')[0],
        date_to: '',
        is_active: true,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/governance/interests', {
            onSuccess: () => {
                reset();
                setShowForm(false);
            },
        });
    };

    const getMemberName = (memberId: string) => {
        const member = boardMembers.find((m) => String(m.id) === memberId);
        return member?.user?.name ?? 'Unknown';
    };

    const totalInterests = Object.values(interestsByMember).reduce(
        (sum, arr) => sum + arr.length,
        0,
    );
    const activeInterests = Object.values(interestsByMember).reduce(
        (sum, arr) => sum + arr.filter((i) => i.is_active).length,
        0,
    );

    return (
        <AppLayout>
            <Head title="Interests Register" />
            <PageLayout
                hero={
                    <PageHero
                        icon={ClipboardList}
                        title="Board Interests Register"
                        description="Declarations of interests for all board members"
                        stats={[
                            { label: 'Members', value: boardMembers.length },
                            {
                                label: 'Total declarations',
                                value: totalInterests,
                            },
                            { label: 'Active', value: activeInterests },
                        ]}
                        actions={
                            <Button
                                size="sm"
                                onClick={() => setShowForm(!showForm)}
                            >
                                <Plus className="mr-2 h-4 w-4" /> Declare
                                Interest
                            </Button>
                        }
                    />
                }
            >
                {showForm && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Declare New Interest</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <Label>Board Member</Label>
                                        <Select
                                            value={
                                                data.board_member_id ||
                                                undefined
                                            }
                                            onValueChange={(val) =>
                                                setData('board_member_id', val)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select member..." />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {boardMembers.map((m) => (
                                                    <SelectItem
                                                        key={m.id}
                                                        value={String(m.id)}
                                                    >
                                                        {m.user.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Interest Type</Label>
                                        <Select
                                            value={data.interest_type}
                                            onValueChange={(val) =>
                                                setData('interest_type', val)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="financial">
                                                    Financial
                                                </SelectItem>
                                                <SelectItem value="personal">
                                                    Personal
                                                </SelectItem>
                                                <SelectItem value="professional">
                                                    Professional
                                                </SelectItem>
                                                <SelectItem value="family">
                                                    Family
                                                </SelectItem>
                                                <SelectItem value="other">
                                                    Other
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                <div>
                                    <Label>Organization Name</Label>
                                    <Input
                                        value={data.organization_name}
                                        onChange={(e) =>
                                            setData(
                                                'organization_name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div>
                                    <Label>Nature of Interest</Label>
                                    <Input
                                        value={data.nature_of_interest}
                                        onChange={(e) =>
                                            setData(
                                                'nature_of_interest',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {errors.nature_of_interest && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {errors.nature_of_interest}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label>Description</Label>
                                    <Textarea
                                        value={data.description}
                                        onChange={(e) =>
                                            setData(
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                        rows={3}
                                    />
                                </div>
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <Label>Date From</Label>
                                        <Input
                                            type="date"
                                            value={data.date_from}
                                            onChange={(e) =>
                                                setData(
                                                    'date_from',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>
                                            Date To (leave blank if ongoing)
                                        </Label>
                                        <Input
                                            type="date"
                                            value={data.date_to}
                                            onChange={(e) =>
                                                setData(
                                                    'date_to',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                                <div className="flex justify-end gap-3">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setShowForm(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        Submit Declaration
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {Object.keys(interestsByMember).length === 0 ? (
                    <Card>
                        <CardContent className="p-8 text-center text-muted-foreground">
                            No interests declared yet.
                        </CardContent>
                    </Card>
                ) : (
                    Object.entries(interestsByMember).map(
                        ([memberId, interests]) => (
                            <Card key={memberId} className="mb-4">
                                <CardHeader>
                                    <div className="flex items-center gap-2">
                                        <User className="h-5 w-5 text-muted-foreground" />
                                        <CardTitle className="text-lg">
                                            {getMemberName(memberId)}
                                        </CardTitle>
                                        <Badge variant="outline">
                                            {interests.length} interest(s)
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-3">
                                        {interests.map((interest) => (
                                            <div
                                                key={interest.id}
                                                className="rounded-lg border p-3"
                                            >
                                                <div className="flex items-center justify-between">
                                                    <div className="flex items-center gap-2">
                                                        <Badge variant="outline">
                                                            {
                                                                interest.interest_type
                                                            }
                                                        </Badge>
                                                        <span className="font-medium">
                                                            {
                                                                interest.nature_of_interest
                                                            }
                                                        </span>
                                                        {interest.organization_name && (
                                                            <span className="text-muted-foreground">
                                                                (
                                                                {
                                                                    interest.organization_name
                                                                }
                                                                )
                                                            </span>
                                                        )}
                                                    </div>
                                                    <Badge
                                                        className={
                                                            interest.is_active
                                                                ? 'bg-status-success-bg text-status-success'
                                                                : 'bg-muted text-foreground'
                                                        }
                                                    >
                                                        {interest.is_active
                                                            ? 'Active'
                                                            : 'Inactive'}
                                                    </Badge>
                                                </div>
                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    {interest.description}
                                                </p>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    From{' '}
                                                    {new Date(
                                                        interest.date_from,
                                                    ).toLocaleDateString(
                                                        'en-NZ',
                                                    )}
                                                    {interest.date_to
                                                        ? ` to ${new Date(interest.date_to).toLocaleDateString('en-NZ')}`
                                                        : ' (ongoing)'}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        ),
                    )
                )}
            </PageLayout>
        </AppLayout>
    );
}
