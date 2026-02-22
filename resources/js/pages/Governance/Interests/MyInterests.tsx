import { Head, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Plus } from 'lucide-react';
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
}

interface Props extends PageProps {
  interests: Interest[];
  boardMember: BoardMember;
}

export default function MyInterests({ auth, interests, boardMember }: Props) {
  const [showForm, setShowForm] = useState(false);
  const { data, setData, post, processing, reset } = useForm({
    board_member_id: String(boardMember.id),
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

  return (
    <AppLayout>
      <Head title="My Interests" />
      <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="flex items-center justify-between mb-6">
          <h1 className="text-2xl font-bold text-gray-900">My Interests</h1>
          <Button onClick={() => setShowForm(!showForm)}>
            <Plus className="w-4 h-4 mr-2" /> Declare Interest
          </Button>
        </div>

        {showForm && (
          <Card className="mb-6">
            <CardHeader><CardTitle>New Declaration</CardTitle></CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="space-y-4">
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
                <div>
                  <Label>Organization</Label>
                  <Input value={data.organization_name} onChange={e => setData('organization_name', e.target.value)} />
                </div>
                <div>
                  <Label>Nature of Interest</Label>
                  <Input value={data.nature_of_interest} onChange={e => setData('nature_of_interest', e.target.value)} />
                </div>
                <div>
                  <Label>Description</Label>
                  <Textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={3} />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label>From</Label>
                    <Input type="date" value={data.date_from} onChange={e => setData('date_from', e.target.value)} />
                  </div>
                  <div>
                    <Label>To (blank = ongoing)</Label>
                    <Input type="date" value={data.date_to} onChange={e => setData('date_to', e.target.value)} />
                  </div>
                </div>
                <div className="flex justify-end gap-3">
                  <Button type="button" variant="outline" onClick={() => setShowForm(false)}>Cancel</Button>
                  <Button type="submit" disabled={processing}>Submit</Button>
                </div>
              </form>
            </CardContent>
          </Card>
        )}

        <div className="space-y-4">
          {interests.map(interest => (
            <Card key={interest.id}>
              <CardContent className="p-4">
                <div className="flex items-center justify-between">
                  <div>
                    <div className="flex items-center gap-2">
                      <Badge variant="outline">{interest.interest_type}</Badge>
                      <span className="font-medium">{interest.nature_of_interest}</span>
                    </div>
                    <p className="text-sm text-gray-600 mt-1">{interest.description}</p>
                    {interest.organization_name && <p className="text-sm text-gray-500">{interest.organization_name}</p>}
                  </div>
                  <Badge className={interest.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}>
                    {interest.is_active ? 'Active' : 'Inactive'}
                  </Badge>
                </div>
              </CardContent>
            </Card>
          ))}
          {interests.length === 0 && (
            <Card><CardContent className="p-8 text-center text-gray-500">No interests declared. Use the button above to add one.</CardContent></Card>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
