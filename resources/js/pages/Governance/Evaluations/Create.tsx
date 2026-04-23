import { Head, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Plus, Trash2 } from 'lucide-react';

interface Question {
  text: string;
  type: 'rating' | 'text' | 'yes_no';
}

export default function EvaluationCreate({ auth }: PageProps) {
  const { data, setData, post, processing, errors } = useForm({
    title: '',
    evaluation_type: 'board',
    period_start: '',
    period_end: '',
    due_date: '',
    questions: [{ text: '', type: 'rating' }] as Question[],
  });

  const addQuestion = () => setData('questions', [...data.questions, { text: '', type: 'rating' }]);
  const removeQuestion = (i: number) => setData('questions', data.questions.filter((_, idx) => idx !== i));
  const updateQuestion = (i: number, field: keyof Question, value: string) => {
    const updated = [...data.questions];
    updated[i] = { ...updated[i], [field]: value };
    setData('questions', updated);
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/governance/evaluations');
  };

  return (
    <AppLayout>
      <Head title="Create Evaluation" />
      <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <h1 className="text-2xl font-bold text-foreground mb-6">Create Board Evaluation</h1>
        <form onSubmit={handleSubmit}>
          <Card className="mb-6">
            <CardContent className="p-6 space-y-4">
              <div>
                <Label>Title</Label>
                <Input value={data.title} onChange={e => setData('title', e.target.value)} />
              </div>
              <div>
                <Label>Type</Label>
                <Select value={data.evaluation_type} onValueChange={val => setData('evaluation_type', val)}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="board">Full Board</SelectItem>
                    <SelectItem value="committee">Committee</SelectItem>
                    <SelectItem value="chair">Chair</SelectItem>
                    <SelectItem value="individual">Individual</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div><Label>Period Start</Label><Input type="date" value={data.period_start} onChange={e => setData('period_start', e.target.value)} /></div>
                <div><Label>Period End</Label><Input type="date" value={data.period_end} onChange={e => setData('period_end', e.target.value)} /></div>
                <div><Label>Due Date</Label><Input type="date" value={data.due_date} onChange={e => setData('due_date', e.target.value)} /></div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle>Questions</CardTitle>
                <Button type="button" variant="outline" size="sm" onClick={addQuestion}><Plus className="w-4 h-4 mr-1" /> Add</Button>
              </div>
            </CardHeader>
            <CardContent className="space-y-4">
              {data.questions.map((q, i) => (
                <div key={i} className="flex gap-3 items-start border rounded-lg p-3">
                  <div className="flex-1 space-y-2">
                    <Input placeholder={`Question ${i + 1}`} value={q.text} onChange={e => updateQuestion(i, 'text', e.target.value)} />
                    <Select value={q.type} onValueChange={val => updateQuestion(i, 'type', val)}>
                      <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="rating">Rating (1-5)</SelectItem>
                        <SelectItem value="text">Free Text</SelectItem>
                        <SelectItem value="yes_no">Yes/No</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  {data.questions.length > 1 && (
                    <Button type="button" variant="ghost" size="sm" onClick={() => removeQuestion(i)}>
                      <Trash2 className="w-4 h-4 text-red-500" />
                    </Button>
                  )}
                </div>
              ))}
              <div className="flex justify-end gap-3 pt-4">
                <Button type="button" variant="outline" onClick={() => window.history.back()}>Cancel</Button>
                <Button type="submit" disabled={processing}>Create Evaluation</Button>
              </div>
            </CardContent>
          </Card>
        </form>
      </div>
    </AppLayout>
  );
}
