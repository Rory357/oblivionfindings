import { Head, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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

export default function InterestsIndex({ auth, interestsByMember, boardMembers }: Props) {
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
      onSuccess: () => { reset(); setShowForm(false); },
    });
  };

  const getMemberName = (memberId: string) => {
    const member = boardMembers.find(m => String(m.id) === memberId);
    return member?.user?.name ?? 'Unknown';
  };

  return (
    <AppLayout>
      <Head title="Interests Register" />
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="flex items-center justify-between mb-6">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Board Interests Register</h1>
            <p className="text-gray-500 mt-1">Declarations of interests for all board members</p>
          </div>
          <Button onClick={() => setShowForm(!showForm)}>
            <Plus className="w-4 h-4 mr-2" /> Declare Interest
          </Button>
        </div>

        {showForm && (
          <Card className="mb-6">
            <CardHeader><CardTitle>Declare New Interest</CardTitle></CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label>Board Member</Label>
                    <Select value={data.board_member_id || undefined} onValueChange={val => setData('board_member_id', val)}>
                      <SelectTrigger><SelectValue placeholder="Select member..." /></SelectTrigger>
                      <SelectContent>
                        {boardMembers.map(m => (
                          <SelectItem key={m.id} value={String(m.id)}>{m.user.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <div>
                    <Label>Interest Type</Label>
                    <Select value={data.interest_type} onValueChange={val => setData('interest_type', val)}>
                      <SelectTrigger><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="financial">Financial</SelectItem>
                        <SelectItem value="personal">Personal</SelectItem>
                        <SelectItem value="professional">Professional</SelectItem>
                        <SelectItem value="family">Family</SelectItem>
                        <SelectItem value="other">Other</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>
                <div>
                  <Label>Organization Name</Label>
                  <Input value={data.organization_name} onChange={e => setData('organization_name', e.target.value)} />
                </div>
                <div>
                  <Label>Nature of Interest</Label>
                  <Input value={data.nature_of_interest} onChange={e => setData('nature_of_interest', e.target.value)} />
                  {errors.nature_of_interest && <p className="text-red-500 text-sm mt-1">{errors.nature_of_interest}</p>}
                </div>
                <div>
                  <Label>Description</Label>
                  <Textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={3} />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label>Date From</Label>
                    <Input type="date" value={data.date_from} onChange={e => setData('date_from', e.target.value)} />
                  </div>
                  <div>
                    <Label>Date To (leave blank if ongoing)</Label>
                    <Input type="date" value={data.date_to} onChange={e => setData('date_to', e.target.value)} />
                  </div>
                </div>
                <div className="flex justify-end gap-3">
                  <Button type="button" variant="outline" onClick={() => setShowForm(false)}>Cancel</Button>
                  <Button type="submit" disabled={processing}>Submit Declaration</Button>
                </div>
              </form>
            </CardContent>
          </Card>
        )}

        {Object.keys(interestsByMember).length === 0 ? (
          <Card><CardContent className="p-8 text-center text-gray-500">No interests declared yet.</CardContent></Card>
        ) : (
          Object.entries(interestsByMember).map(([memberId, interests]) => (
            <Card key={memberId} className="mb-4">
              <CardHeader>
                <div className="flex items-center gap-2">
                  <User className="w-5 h-5 text-gray-400" />
                  <CardTitle className="text-lg">{getMemberName(memberId)}</CardTitle>
                  <Badge variant="outline">{interests.length} interest(s)</Badge>
                </div>
              </CardHeader>
              <CardContent>
                <div className="space-y-3">
                  {interests.map(interest => (
                    <div key={interest.id} className="border rounded-lg p-3">
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                          <Badge variant="outline">{interest.interest_type}</Badge>
                          <span className="font-medium">{interest.nature_of_interest}</span>
                          {interest.organization_name && <span className="text-gray-500">({interest.organization_name})</span>}
                        </div>
                        <Badge className={interest.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}>
                          {interest.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                      </div>
                      <p className="text-sm text-gray-600 mt-1">{interest.description}</p>
                      <p className="text-xs text-gray-400 mt-1">
                        From {new Date(interest.date_from).toLocaleDateString('en-NZ')}
                        {interest.date_to ? ` to ${new Date(interest.date_to).toLocaleDateString('en-NZ')}` : ' (ongoing)'}
                      </p>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          ))
        )}
      </div>
    </AppLayout>
  );
}
