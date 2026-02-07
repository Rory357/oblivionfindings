import { Head, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';

interface User {
  id: number;
  name: string;
  email: string;
}

interface BoardMember {
  id: number;
  user: User;
  board_role: string;
  term_start: string;
  term_end: string;
  is_active: boolean;
}

interface Props extends PageProps {
  boardMembers: BoardMember[];
  availableUsers: User[]; // Users NOT already board members
}

export default function ManageBoardMembers({ auth, boardMembers, availableUsers }: Props) {
  const { data, setData, post, processing, reset, delete: destroy } = useForm({
    user_id: '',
    board_role: 'member',
    term_start: '',
    term_end: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/governance/admin/board-members', {
      onSuccess: () => reset(),
    });
  };

  const handleRemove = (id: number) => {
    if (!confirm('Remove this board member?')) return;
    destroy(`/governance/admin/board-members/${id}`, { preserveScroll: true });
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Board Members', href: '/governance/admin/board-members' },
      ]}
    >
      <Head title="Manage Board Members" />

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-6">Board Member Management</h1>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Add New Board Member */}
          <Card className="lg:col-span-1">
            <CardHeader>
              <CardTitle>Appoint Board Member</CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                  <Label>Select User</Label>
                  <Select value={data.user_id} onValueChange={(v) => setData('user_id', v)}>
                    <SelectTrigger>
                      <SelectValue placeholder="Choose staff member..." />
                    </SelectTrigger>
                    <SelectContent>
                      {availableUsers.map((user) => (
                        <SelectItem key={user.id} value={String(user.id)}>
                          {user.name} ({user.email})
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                <div>
                  <Label>Board Role</Label>
                  <Select value={data.board_role} onValueChange={(v) => setData('board_role', v)}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="chair">Chair</SelectItem>
                      <SelectItem value="secretary">Secretary</SelectItem>
                      <SelectItem value="treasurer">Treasurer</SelectItem>
                      <SelectItem value="member">Member</SelectItem>
                      <SelectItem value="observer">Observer</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div>
                  <Label>Term Start</Label>
                  <input
                    type="date"
                    className="w-full rounded-md border border-gray-300 px-3 py-2"
                    value={data.term_start}
                    onChange={(e) => setData('term_start', e.target.value)}
                  />
                </div>

                <div>
                  <Label>Term End</Label>
                  <input
                    type="date"
                    className="w-full rounded-md border border-gray-300 px-3 py-2"
                    value={data.term_end}
                    onChange={(e) => setData('term_end', e.target.value)}
                  />
                </div>

                <Button type="submit" disabled={processing} className="w-full">
                  Appoint to Board
                </Button>
              </form>
            </CardContent>
          </Card>

          {/* Current Board Members */}
          <Card className="lg:col-span-2">
            <CardHeader>
              <CardTitle>Current Board Members</CardTitle>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Name</TableHead>
                    <TableHead>Role</TableHead>
                    <TableHead>Term</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {boardMembers.map((member) => (
                    <TableRow key={member.id}>
                      <TableCell>
                        <div>
                          <p className="font-medium">{member.user.name}</p>
                          <p className="text-sm text-gray-500">{member.user.email}</p>
                        </div>
                      </TableCell>
                      <TableCell>
                        <Badge variant="outline" className="capitalize">
                          {member.board_role}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <span className="text-sm">
                          {member.term_start} -> {member.term_end || 'Ongoing'}
                        </span>
                      </TableCell>
                      <TableCell>
                        {member.is_active ? (
                          <Badge className="bg-green-100 text-green-800">Active</Badge>
                        ) : (
                          <Badge className="bg-gray-100 text-gray-800">Inactive</Badge>
                        )}
                      </TableCell>
                      <TableCell>
                        <div className="flex gap-2">
                          <Button
                            variant="destructive"
                            size="sm"
                            onClick={() => handleRemove(member.id)}
                            disabled={processing}
                          >
                            Remove
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}
