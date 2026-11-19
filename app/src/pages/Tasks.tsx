import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Check, CheckSquare, Plus, Trash2 } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { teamOptions, useTeam } from '@/lib/data';
import { useDebounce, useUrlState } from '@/lib/hooks';
import { dayjs, fmtDateTime, fromNow } from '@/lib/format';
import { priorityTone, taskStatusTone } from '@/lib/tones';
import { Avatar, Badge, Button, Card, EmptyState, ErrorState, PageHeader, SearchInput, Segmented, Select, TableSkeleton, Tabs, Toggle, useUi } from '@/components/ui';
import { TaskModal } from '@/components/forms';

const defaults = { view: 'open', layout: 'list', mine: '', assigned_to: '', category: '', priority: '', q: '', task: '' };

export default function Tasks() {
  const { config, can } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const team = useTeam();
  const [f, setF] = useUrlState(defaults);
  const [search, setSearch] = useState(f.q);
  const dq = useDebounce(search, 300);
  useEffect(() => {
    if (dq !== f.q) setF({ q: dq });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dq]);
  const [edit, setEdit] = useState<any>(null);
  // Links from reminder emails and alerts open the task directly: /tasks?task=ID
  useEffect(() => {
    if (!f.task) return;
    api.get(`tasks/${f.task}`).then(
      (t) => setEdit(t),
      (e) => toast(e.message, 'error'),
    );
    setF({ task: '' });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [f.task]);
  const board = f.layout === 'board';
  const viewParams: Record<string, any> = board ? {} : f.view === 'done' ? { status: 'done' } : f.view === 'open' ? { status: 'open' } : { due: f.view };
  const params = { ...viewParams, mine: f.mine, assigned_to: f.assigned_to, category: f.category, priority: f.priority, q: f.q };
  const q = useQuery({ queryKey: ['tasks', params], queryFn: () => api.get<any[]>('tasks', params), placeholderData: keepPreviousData });
  const counts = useQuery({ queryKey: ['tasks', 'counts', f.mine], queryFn: async () => {
    const [today, overdue] = await Promise.all([api.get<any[]>('tasks', { due: 'today', status: 'open', mine: f.mine }), api.get<any[]>('tasks', { due: 'overdue', mine: f.mine })]);
    return { today: today.length, overdue: overdue.length };
  } });
  const rows = q.data || [];
  const invalidate = () => ['tasks', 'dashboard', 'booking', 'lead', 'team'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));

  const setStatus = async (t: any, status: string) => {
    try {
      await api.put(`tasks/${t.id}`, { status });
      if (status === 'done') toast('Task done');
      invalidate();
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };
  const remove = async (t: any) => {
    if (!(await confirm({ title: 'Delete this task?', message: t.title, danger: true, confirmLabel: 'Delete' }))) return;
    await api.del(`tasks/${t.id}`).then(invalidate, (e) => toast(e.message, 'error'));
  };

  const Row = ({ t }: { t: any }) => {
    const od = t.status !== 'done' && t.due_at && dayjs(t.due_at).isBefore(dayjs());
    return (
      <li className="flex items-start gap-3 px-4 py-3">
        <button onClick={() => can('tasks.edit') && setStatus(t, t.status === 'done' ? 'todo' : 'done')} className={clsx('mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md border transition-colors', t.status === 'done' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-line hover:border-accent')} aria-label={t.status === 'done' ? 'Mark as not done' : 'Mark as done'}>
          {t.status === 'done' && <Check className="h-3.5 w-3.5" />}
        </button>
        <div className="min-w-0 flex-1">
          <button onClick={() => can('tasks.edit') && setEdit(t)} className={clsx('text-left text-sm font-medium', t.status === 'done' && 'text-muted line-through')}>{t.title}</button>
          <p className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted">
            <span>{config.task_categories[t.category]}</span>
            {t.due_at && <span className={clsx(od && 'font-medium text-red-600')}>Due {fmtDateTime(t.due_at)}{od ? ` (${fromNow(t.due_at)})` : ''}</span>}
            {t.booking_id && <Link to={`/bookings/${t.booking_id}`} className="hover:text-accent-ink">{t.booking_number} · {t.booking_title}</Link>}
            {t.lead_id && <Link to={`/leads/${t.lead_id}`} className="hover:text-accent-ink">Lead: {t.lead_name}</Link>}
          </p>
          {t.description && <p className="mt-1 line-clamp-2 text-xs text-faint">{t.description}</p>}
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1.5 sm:flex-row sm:items-center">
          <Badge tone={priorityTone[t.priority]}>{config.priorities[t.priority]}</Badge>
          {t.status === 'in_progress' && <Badge tone="blue">In Progress</Badge>}
          {t.assigned_name ? <Avatar name={t.assigned_name} color={t.assigned_color} size="sm" /> : <span className="text-2xs text-faint">Unassigned</span>}
          {can('tasks.edit') && <button onClick={() => remove(t)} className="hidden rounded p-1 text-faint hover:text-red-600 sm:block" aria-label="Delete task"><Trash2 className="h-3.5 w-3.5" /></button>}
        </div>
      </li>
    );
  };

  return (
    <div>
      <PageHeader
        title="Tasks"
        subtitle="Calls, edits, albums and deliveries. Booking tasks are created automatically."
        actions={
          <>
            <Toggle checked={!!f.mine} onChange={(v) => setF({ mine: v ? '1' : '', assigned_to: '' })} label="Only mine" />
            <Segmented value={f.layout} onChange={(v) => setF({ layout: v })} options={[['list', 'List'], ['board', 'Board']]} />
            {can('tasks.edit') && <Button variant="primary" icon={<Plus className="h-4 w-4" />} onClick={() => setEdit({})}>New task</Button>}
          </>
        }
      />
      <Card>
        {!board && (
          <div className="px-4 pt-1">
            <Tabs value={f.view} onChange={(v) => setF({ view: v })} tabs={[['open', 'All open'], ['today', 'Due today', counts.data?.today], ['overdue', 'Overdue', counts.data?.overdue], ['week', 'Next 7 days'], ['done', 'Completed']]} />
          </div>
        )}
        <div className="flex flex-col gap-2 border-b border-line p-3 sm:flex-row sm:items-center">
          <SearchInput value={search} onChange={setSearch} placeholder="Search tasks…" className="w-full sm:w-60" />
          <div className="flex flex-wrap gap-2 sm:ml-auto">
            {!f.mine && can('tasks.view_all') && <Select value={f.assigned_to} onChange={(e) => setF({ assigned_to: e.target.value })} options={teamOptions(team.data)} placeholder="Everyone" className="w-auto py-1.5" />}
            <Select value={f.category} onChange={(e) => setF({ category: e.target.value })} options={config.task_categories} placeholder="All categories" className="w-auto py-1.5" />
            <Select value={f.priority} onChange={(e) => setF({ priority: e.target.value })} options={config.priorities} placeholder="Any priority" className="w-auto py-1.5" />
          </div>
        </div>
        {q.error ? (
          <ErrorState error={q.error} onRetry={() => q.refetch()} />
        ) : q.isLoading ? (
          <TableSkeleton />
        ) : board ? (
          <div className="grid gap-3 p-3 md:grid-cols-3">
            {Object.entries(config.task_statuses).map(([st, label]) => {
              const col = rows.filter((t) => t.status === st);
              return (
                <div key={st} className="rounded-xl bg-subtle/60 p-2">
                  <div className="mb-2 flex items-center gap-2 px-1"><Badge tone={taskStatusTone[st]}>{label}</Badge><span className="text-xs text-muted">{col.length}</span></div>
                  <div className="space-y-2">
                    {col.slice(0, 100).map((t) => (
                      <div key={t.id} className="rounded-lg border border-line bg-surface p-2.5 shadow-card">
                        <button onClick={() => can('tasks.edit') && setEdit(t)} className="text-left text-sm font-medium">{t.title}</button>
                        <p className="mt-0.5 text-xs text-muted">{config.task_categories[t.category]}{t.due_at ? ' · ' + fmtDateTime(t.due_at) : ''}</p>
                        <div className="mt-2 flex items-center gap-1.5">
                          {t.assigned_name && <Avatar name={t.assigned_name} color={t.assigned_color} size="xs" />}
                          <Badge tone={priorityTone[t.priority]}>{config.priorities[t.priority]}</Badge>
                          {can('tasks.edit') && (
                            <select value={t.status} onChange={(e) => setStatus(t, e.target.value)} className="ml-auto rounded border-line bg-transparent py-0 pl-1.5 pr-6 text-2xs" aria-label="Move to">
                              {Object.entries(config.task_statuses).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                            </select>
                          )}
                        </div>
                      </div>
                    ))}
                    {col.length === 0 && <p className="py-6 text-center text-xs text-faint">Nothing here</p>}
                  </div>
                </div>
              );
            })}
          </div>
        ) : rows.length === 0 ? (
          <EmptyState icon={<CheckSquare className="h-5 w-5" />} title={f.view === 'overdue' ? 'Nothing overdue' : f.view === 'today' ? 'Nothing due today' : 'No tasks here'} description="Tasks you add, and the ones created from bookings, show up here." />
        ) : (
          <ul className="divide-y divide-line">{rows.map((t) => <Row key={t.id} t={t} />)}</ul>
        )}
      </Card>
      <TaskModal open={!!edit} onClose={() => setEdit(null)} task={edit?.id ? edit : undefined} defaults={f.assigned_to ? { assigned_to: Number(f.assigned_to) } : undefined} />
    </div>
  );
}
