import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { AlertTriangle, ExternalLink, PackageCheck, Pencil } from 'lucide-react';
import { api, type Paged } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { teamOptions, useTeam } from '@/lib/data';
import { useDebounce, useUrlState } from '@/lib/hooks';
import { dayjs, fmtDate } from '@/lib/format';
import { Avatar, Card, EmptyState, ErrorState, PageHeader, Pagination, SearchInput, Select, TableSkeleton, useUi } from '@/components/ui';
import { DeliverableBadge, DeliverableModal, ProgressBar } from '@/components/delivery';
import { WaButton } from '@/components/shared';

const defaults = { q: '', status: 'open', assigned_to: '', overdue: '', page: '1' };

export default function Deliverables() {
  const { config, can, member } = useAuth();
  const { toast } = useUi();
  const qc = useQueryClient();
  const team = useTeam();
  const [f, setF] = useUrlState(defaults);
  const [search, setSearch] = useState(f.q);
  const dq = useDebounce(search, 300);
  useEffect(() => {
    if (dq !== f.q) setF({ q: dq, page: '1' });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dq]);
  const [edit, setEdit] = useState<any>(null);
  const params = { q: f.q, status: f.status === 'all' ? '' : f.status, assigned_to: f.assigned_to, overdue: f.overdue, page: f.page, per_page: 40 };
  const list = useQuery({ queryKey: ['deliverables', params], queryFn: () => api.get<Paged<any>>('deliverables', params), placeholderData: keepPreviousData });
  const counts: Record<string, number> = list.data?.counts || {};
  const total = Object.values(counts).reduce((a, b) => a + b, 0);
  const full = can('deliverables.edit');
  const canUpdate = (d: any) => full || (can('deliverables.update') && d.assigned_to === member.id);

  const quickStatus = async (d: any, status: string) => {
    try {
      await api.put(`deliverables/${d.id}`, { status });
      toast(`${d.name}: ${config.deliverable_statuses[status]}`);
      qc.invalidateQueries({ queryKey: ['deliverables'] });
      qc.invalidateQueries({ queryKey: ['booking'] });
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };

  const chips: [string, string, number][] = [
    ['open', 'Open', total - (counts.delivered || 0)],
    ...Object.entries(config.deliverable_statuses).map(([k, l]) => [k, l, counts[k] || 0] as [string, string, number]),
    ['all', 'All', total],
  ];

  return (
    <div>
      <PageHeader
        title="Deliverables"
        subtitle={can('deliverables.view_all') ? 'Every photo, film and album you owe clients, across all bookings. Status changes show on the client portal right away.' : 'Deliverables assigned to you. Update the status as you work; the client portal updates automatically.'}
      />
      <Card>
        <div className="scrollbar-thin flex gap-1.5 overflow-x-auto border-b border-line px-3 py-2.5">
          {chips.map(([k, l, n]) => (
            <button key={k} onClick={() => setF({ status: k, overdue: '', page: '1' })} className={clsx('whitespace-nowrap rounded-full border px-3 py-1 text-xs font-medium transition-colors', f.status === k && !f.overdue ? 'border-accent bg-accent-soft text-accent-ink' : 'border-line text-muted hover:bg-subtle hover:text-ink')}>
              {l} <span className="ml-0.5 tabular opacity-70">{n}</span>
            </button>
          ))}
          {!!list.data?.overdue && (
            <button onClick={() => setF({ overdue: f.overdue ? '' : '1', status: 'all', page: '1' })} className={clsx('flex items-center gap-1 whitespace-nowrap rounded-full border px-3 py-1 text-xs font-medium', f.overdue ? 'border-red-400 bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'border-red-200 text-red-600 hover:bg-red-50 dark:border-red-500/30 dark:hover:bg-red-500/10')}>
              <AlertTriangle className="h-3.5 w-3.5" /> Overdue {list.data.overdue}
            </button>
          )}
        </div>
        <div className="flex flex-col gap-2 border-b border-line p-3 sm:flex-row sm:items-center">
          <SearchInput value={search} onChange={setSearch} placeholder="Deliverable, client, booking…" className="w-full sm:w-72" />
          {can('deliverables.view_all') && (
            <Select value={f.assigned_to} onChange={(e) => setF({ assigned_to: e.target.value, page: '1' })} options={[['unassigned', 'Not assigned'], ...teamOptions(team.data)]} placeholder="Everyone" className="w-auto py-1.5 sm:ml-auto" />
          )}
        </div>
        {list.error ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.isLoading ? (
          <TableSkeleton />
        ) : !list.data?.items.length ? (
          <EmptyState icon={<PackageCheck className="h-5 w-5" />} title="No deliverables here" description={full ? 'Add deliverables from a booking, or pick a template when you create one.' : 'Nothing matches this filter.'} />
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[900px]">
                <thead className="border-b border-line bg-subtle/50">
                  <tr><th className="th">Deliverable</th><th className="th">Client · Booking</th><th className="th">Assigned</th><th className="th">Expected</th><th className="th">Status</th><th className="th w-40">Progress</th><th className="th" /></tr>
                </thead>
                <tbody className="divide-y divide-line">
                  {list.data.items.map((d) => {
                    const late = d.status !== 'delivered' && d.expected_date && d.expected_date < dayjs().format('YYYY-MM-DD');
                    return (
                      <tr key={d.id}>
                        <td className="td"><p className="font-medium">{d.name}</p>{d.delivery_link && <a href={d.delivery_link} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs text-accent-ink hover:underline">Delivery link <ExternalLink className="h-3 w-3" /></a>}</td>
                        <td className="td"><Link to={`/bookings/${d.booking_id}`} className="text-sm hover:text-accent-ink">{d.client_name}</Link><p className="text-xs text-muted">{d.booking_number}</p></td>
                        <td className="td">{d.assigned_name ? <span className="flex items-center gap-1.5 text-sm"><Avatar name={d.assigned_name} color={d.assigned_color} size="xs" />{d.assigned_name}</span> : <span className="text-xs text-amber-700 dark:text-amber-300">Not assigned</span>}</td>
                        <td className={clsx('td whitespace-nowrap text-sm', late && 'font-medium text-red-600')}>{d.expected_date ? fmtDate(d.expected_date) : '—'}{late && <span className="block text-2xs font-normal">Overdue</span>}</td>
                        <td className="td">
                          {canUpdate(d) ? (
                            <select value={d.status} onChange={(e) => quickStatus(d, e.target.value)} className="input w-auto py-1 text-xs" aria-label="Status">
                              {Object.entries(config.deliverable_statuses).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                            </select>
                          ) : (
                            <DeliverableBadge status={d.status} />
                          )}
                        </td>
                        <td className="td"><div className="flex items-center gap-2"><ProgressBar value={d.progress} size="sm" /><span className="tabular w-9 text-right text-xs text-muted">{d.progress}%</span></div></td>
                        <td className="td whitespace-nowrap text-right">
                          <div className="flex items-center justify-end gap-1">
                            {['ready', 'delivered'].includes(d.status) && <WaButton ctx={{ deliverable_id: d.id }} template="deliverable_ready" label="Notify" size="xs" variant="ghost" />}
                            {canUpdate(d) && <button onClick={() => setEdit(d)} className="rounded p-1.5 text-faint hover:bg-subtle hover:text-ink" aria-label="Edit"><Pencil className="h-4 w-4" /></button>}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            <Pagination page={Number(f.page)} perPage={40} total={list.data.total} onPage={(p) => setF({ page: String(p) })} />
          </>
        )}
      </Card>
      <DeliverableModal open={!!edit} onClose={() => setEdit(null)} deliverable={edit} bookingId={edit?.booking_id} />
    </div>
  );
}
