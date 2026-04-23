import { Head, Link, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { show as showResolution, vote as voteResolution, open as openResolution, close as closeResolution } from '@/routes/governance/resolutions';
import { declare as declareConflictRoute } from '@/routes/governance/resolutions/conflict';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { CheckCircle, XCircle, MinusCircle, Users, AlertTriangle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useState } from 'react';
import axios from 'axios';

interface Vote {
  id: number;
  board_member: { user: { name: string } };
  vote: string;
  conflict_declared: boolean;
  voted_at: string;
}

interface Conflict {
  id: number;
  board_member: { user: { name: string } };
  declaration_type: string;
  withdrew_from_voting: boolean;
}

type ResultMember =
  | string
  | { user?: { name?: string | null } | null }
  | null
  | undefined;

interface ResultVote {
  board_member: ResultMember;
  vote: string;
  conflict_declared: boolean;
  voted_at: string;
}

interface ResultConflict {
  board_member: ResultMember;
  type: string;
  description: string;
  withdrew: boolean;
}

interface Resolution {
  id: number;
  resolution_reference: string;
  title: string;
  context: string;
  options: Array<{ label: string; description?: string }>;
  recommendation: string | null;
  voting_threshold: string;
  status: string;
  deadline: string | null;
  outcome: string | null;
  outcome_notes?: string | null;
  vote_summary: {
    for: number;
    against: number;
    abstain: number;
    total_votes: number;
  } | null;
  proposed_by: { name: string };
  meeting: { title: string } | null;
  votes: Vote[];
  conflict_declarations: Conflict[];
}

interface Props extends PageProps {
  resolution: Resolution;
  results: {
    summary: { for: number; against: number; abstain: number };
    percentages: { for: number; against: number };
    outcome: string;
    quorum_met: boolean;
    individual_votes: ResultVote[];
    conflicts: ResultConflict[];
  } | null;
  my_vote: Vote | null;
  can_vote: boolean;
  quorum: { present: number; required: number; met: boolean } | null;
}

export default function ResolutionShow({ auth, resolution, results, my_vote, can_vote, quorum }: Props) {
  const [selectedVote, setSelectedVote] = useState<string>('');
  const [conflictNote, setConflictNote] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [declaringConflict, setDeclaringConflict] = useState(false);
  const [finalNotes, setFinalNotes] = useState('');
  const [finalizing, setFinalizing] = useState(false);

  const permissions = auth?.can as { governance?: { resolutions?: { manage?: boolean } } } | undefined;
  const canManage = permissions?.governance?.resolutions?.manage;
  const options = resolution.options ?? [];
  const isOpen = resolution.status === 'open';
  const isClosed = ['closed', 'implemented', 'archived'].includes(resolution.status);
  const isFinalized = ['implemented', 'archived'].includes(resolution.status);

  const resolveMemberName = (member: ResultMember) => {
    if (!member) return 'Unknown';
    if (typeof member === 'string') return member;
    return member.user?.name ?? 'Unknown';
  };

  const submitVote = async () => {
    if (!selectedVote) return;
    setSubmitting(true);
    try {
      await axios.post(voteResolution.url({ resolution: resolution.id }), {
        vote: selectedVote,
        conflict_note: conflictNote || undefined,
      });
      router.reload();
    } catch (error) {
      console.error('Vote failed:', error);
    } finally {
      setSubmitting(false);
    }
  };

  const handleDeclareConflict = async () => {
    setDeclaringConflict(true);
    try {
      await axios.post(declareConflictRoute.url({ resolution: resolution.id }), {
        type: 'material',
        description: conflictNote,
        withdraw_from_voting: true,
      });
      router.reload();
    } catch (error) {
      console.error('Conflict declaration failed:', error);
    } finally {
      setDeclaringConflict(false);
    }
  };

  const openVoting = async () => {
    try {
      await axios.post(openResolution.url({ resolution: resolution.id }));
      router.reload();
    } catch (error) {
      console.error('Failed to open voting:', error);
    }
  };

  const closeVoting = async () => {
    try {
      await axios.post(closeResolution.url({ resolution: resolution.id }));
      router.reload();
    } catch (error) {
      console.error('Failed to close voting:', error);
    }
  };

  const finalizeResolution = async (status: 'implemented' | 'archived') => {
    setFinalizing(true);
    try {
      await axios.post(`/governance/resolutions/${resolution.id}/finalize`, {
        status,
        notes: finalNotes || undefined,
      });
      router.reload();
    } catch (error) {
      console.error('Failed to finalize resolution:', error);
    } finally {
      setFinalizing(false);
    }
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Resolutions', href: '/governance/resolutions' },
        { title: 'Resolution', href: `/governance/resolutions/${resolution.id}` },
      ]}
    >
      <Head title={resolution.title} />

      <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Header */}
          <div className="mb-6">
            <div className="flex items-center gap-3 mb-2">
              <h1 className="text-3xl font-bold text-foreground">{resolution.title}</h1>
              <Badge className={cn(
                resolution.status === 'open' && 'bg-green-100 text-green-800',
                resolution.status === 'closed' && 'bg-primary/10 text-primary',
                resolution.status === 'implemented' && 'bg-green-100 text-green-800',
                resolution.status === 'archived' && 'bg-muted text-foreground',
                resolution.status === 'draft' && 'bg-muted text-foreground',
              )}>
                {resolution.status}
              </Badge>
              {resolution.outcome && (
                <Badge className={cn(
                  resolution.outcome === 'carried' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                )}>
                  {resolution.outcome}
                </Badge>
              )}
            </div>
            <p className="text-muted-foreground">{resolution.resolution_reference}</p>
          </div>

          {/* Context */}
          <Card className="mb-6">
            <CardHeader>
              <CardTitle>Context</CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-foreground whitespace-pre-wrap">{resolution.context}</p>
              
              {resolution.recommendation && (
                <div className="mt-4 p-4 bg-blue-50 rounded-lg border border-blue-200">
                  <p className="font-medium text-blue-900">Management Recommendation:</p>
                  <p className="text-blue-800">{resolution.recommendation}</p>
                </div>
              )}
            </CardContent>
          </Card>

          {/* Options */}
          <Card className="mb-6">
            <CardHeader>
              <CardTitle>Options</CardTitle>
            </CardHeader>
            <CardContent>
                <div className="space-y-3">
                {options.map((option, index) => (
                  <div key={index} className="p-4 border rounded-lg">
                    <p className="font-medium">{option.label}</p>
                    {option.description && (
                      <p className="text-sm text-muted-foreground mt-1">{option.description}</p>
                    )}
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>

          {/* Voting Section */}
          {isOpen && can_vote && !my_vote && (
            <Card className="mb-6 border-blue-200">
              <CardHeader>
                <CardTitle>Cast Your Vote</CardTitle>
                <CardDescription>
                  {resolution.deadline && (
                    <span>Voting closes: {new Date(resolution.deadline).toLocaleString()}</span>
                  )}
                </CardDescription>
              </CardHeader>
              <CardContent>
                <RadioGroup value={selectedVote} onValueChange={setSelectedVote} className="space-y-3">
                  <label className="flex items-center gap-3 rounded-md border p-3 cursor-pointer transition-colors [&:has([data-state=checked])]:border-primary [&:has([data-state=checked])]:bg-primary/5 hover:bg-muted">
                    <RadioGroupItem value="for" />
                    <span className="flex items-center gap-2">
                      <CheckCircle className="w-5 h-5 text-green-500" />
                      For
                    </span>
                  </label>
                  <label className="flex items-center gap-3 rounded-md border p-3 cursor-pointer transition-colors [&:has([data-state=checked])]:border-primary [&:has([data-state=checked])]:bg-primary/5 hover:bg-muted">
                    <RadioGroupItem value="against" />
                    <span className="flex items-center gap-2">
                      <XCircle className="w-5 h-5 text-red-500" />
                      Against
                    </span>
                  </label>
                  <label className="flex items-center gap-3 rounded-md border p-3 cursor-pointer transition-colors [&:has([data-state=checked])]:border-primary [&:has([data-state=checked])]:bg-primary/5 hover:bg-muted">
                    <RadioGroupItem value="abstain" />
                    <span className="flex items-center gap-2">
                      <MinusCircle className="w-5 h-5 text-muted-foreground" />
                      Abstain
                    </span>
                  </label>
                </RadioGroup>

                <div className="mt-4">
                  <label htmlFor="conflict" className="text-sm font-medium text-foreground">
                    Conflict Declaration (optional)
                  </label>
                  <Textarea
                    id="conflict"
                    placeholder="Describe any conflict of interest..."
                    value={conflictNote}
                    onChange={(e) => setConflictNote(e.target.value)}
                    className="mt-1"
                  />
                </div>

                <div className="flex gap-2 mt-4">
                  <Button 
                    onClick={submitVote} 
                    disabled={!selectedVote || submitting}
                  >
                    {submitting ? 'Submitting...' : 'Submit Vote'}
                  </Button>
                  <Button 
                    variant="outline" 
                    onClick={handleDeclareConflict}
                    disabled={declaringConflict}
                  >
                    Declare Conflict & Abstain
                  </Button>
                </div>
              </CardContent>
            </Card>
          )}

          {/* My Vote */}
          {my_vote && (
            <Card className="mb-6">
              <CardHeader>
                <CardTitle>Your Vote</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="flex items-center gap-2">
                  <Badge className={cn(
                    my_vote.vote === 'for' && 'bg-green-100 text-green-800',
                    my_vote.vote === 'against' && 'bg-red-100 text-red-800',
                    my_vote.vote === 'abstain' && 'bg-muted text-foreground',
                  )}>
                    {my_vote.vote.toUpperCase()}
                  </Badge>
                  <span className="text-sm text-muted-foreground">
                    Voted {new Date(my_vote.voted_at).toLocaleString()}
                  </span>
                  {my_vote.conflict_declared && (
                    <Badge variant="outline" className="text-orange-600">
                      <AlertTriangle className="w-3 h-3 mr-1" />
                      Conflict Declared
                    </Badge>
                  )}
                </div>
              </CardContent>
            </Card>
          )}

          {/* Results */}
          {isClosed && results && (
            <Card className="mb-6">
              <CardHeader>
                <CardTitle>Voting Results</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="grid grid-cols-3 gap-4 mb-6">
                  <div className="text-center p-4 bg-green-50 rounded-lg">
                    <p className="text-3xl font-bold text-green-600">{results.summary.for}</p>
                    <p className="text-sm text-green-800">For ({results.percentages.for}%)</p>
                  </div>
                  <div className="text-center p-4 bg-red-50 rounded-lg">
                    <p className="text-3xl font-bold text-red-600">{results.summary.against}</p>
                    <p className="text-sm text-red-800">Against ({results.percentages.against}%)</p>
                  </div>
                  <div className="text-center p-4 bg-muted rounded-lg">
                    <p className="text-3xl font-bold text-muted-foreground">{results.summary.abstain}</p>
                    <p className="text-sm text-foreground">Abstain</p>
                  </div>
                </div>

                <h4 className="font-medium mb-2">Individual Votes</h4>
                <div className="space-y-2">
                  {results.individual_votes.map((vote, index) => (
                    <div key={`${resolveMemberName(vote.board_member)}-${vote.voted_at}-${index}`} className="flex items-center justify-between p-2 border rounded">
                      <span>{resolveMemberName(vote.board_member)}</span>
                      <Badge className={cn(
                        vote.vote === 'for' && 'bg-green-100 text-green-800',
                        vote.vote === 'against' && 'bg-red-100 text-red-800',
                        vote.vote === 'abstain' && 'bg-muted text-foreground',
                      )}>
                        {vote.vote}
                      </Badge>
                    </div>
                  ))}
                </div>

                {results.conflicts.length > 0 && (
                  <div className="mt-4">
                    <h4 className="font-medium mb-2">Conflict Declarations</h4>
                    {results.conflicts.map((conflict, i) => (
                      <p key={i} className="text-sm text-orange-600">
                        {resolveMemberName(conflict.board_member)} - {conflict.type}
                      </p>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>
          )}

          {isClosed && (
            <Card className="mb-6">
              <CardHeader>
                <CardTitle>Decision Summary</CardTitle>
                <CardDescription>
                  Finalize the resolution once the outcome has been actioned.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <div className="space-y-3">
                  <Textarea
                    placeholder="Outcome notes or implementation summary..."
                    value={finalNotes}
                    onChange={(e) => setFinalNotes(e.target.value)}
                  />
                  {resolution.outcome_notes && (
                    <p className="text-sm text-muted-foreground">
                      Previous notes: {resolution.outcome_notes}
                    </p>
                  )}
                  {canManage && resolution.status === 'closed' && (
                    <div className="flex gap-2">
                      <Button
                        onClick={() => finalizeResolution('implemented')}
                        disabled={finalizing}
                      >
                        Mark Implemented
                      </Button>
                      <Button
                        variant="outline"
                        onClick={() => finalizeResolution('archived')}
                        disabled={finalizing}
                      >
                        Archive
                      </Button>
                    </div>
                  )}
                  {isFinalized && (
                    <p className="text-sm text-muted-foreground">
                      This resolution is {resolution.status}.
                    </p>
                  )}
                </div>
              </CardContent>
            </Card>
          )}

          {/* Admin Actions */}
          {canManage && (
            <Card>
              <CardHeader>
                <CardTitle>Admin Actions</CardTitle>
              </CardHeader>
              <CardContent className="flex gap-2">
                {resolution.status === 'draft' && (
                  <Button onClick={openVoting}>Open Voting</Button>
                )}
                {resolution.status === 'open' && (
                  <Button onClick={closeVoting} variant="destructive">Close Voting</Button>
                )}
              </CardContent>
            </Card>
          )}
      </div>
    </AppLayout>
  );
}
