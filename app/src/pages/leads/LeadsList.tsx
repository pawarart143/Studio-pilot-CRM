import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { ArrowDown, ArrowUp, Download, Filter, MessageCircle, Phone, Plus, Trash2, Upload, UserRound, X, Inbox } from 'lucide-react';
import { api, type Paged } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { teamOptions, useTeam, type Lead } from '@/lib/data';
import { useDebounce, useUrlState } from '@/lib/hooks';
import { fmtDate, fmtDateTime, inr, telLink, waLink, dayjs, phoneDisplay } from '@/lib/format';
import { callTone, priorityTone, stageTone } from '@/lib/tones';
import { Badge, Button, Card, Checkbox, EmptyState, ErrorState, Input, PageHeader, Pagination, SearchInput, Select, TableSkeleton, useUi, Menu, MenuItem } from '@/components/ui';
import { LeadFormModal } from './LeadForm';
import { ImportModal } from './ImportModal';
import { Submissions } from '@/pages/enquiry/EnquirySettings';
import { Modal } from '@/components/ui';

const defaults = { q: '', stage: '', source: '', event_type: '', priority: '', assigned_to: '', followup: '', event_from: '', event_to: '', call_status: '', sort: 'created_at', dir: 'desc', page: '1' };

export default function LeadsList() {
  const { config, can } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const nav = useNavigate();
  const team = useTeam();
  const [f, setF] = useUrlState(defaults);
  const [search, setSearch] = useState(f.q);
  const dq = useDebounce(search, 300);
  useEffect(() => {
    if (dq !== f.q) setF({ q: dq });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dq]);
  const [showFilters, setShowFilters] = useState(false);
  const [sel, setSel] = useState<number[]>([]);
  const [formOpen, setFormOpen] = useState(false);
  const [importOpen, setImportOpen] = useState(false);
  const [exporting, setExporting] = useState(false);

  const params = { ...f, per_page: 25 };
  const q = useQuery({ queryKey: ['leads', params], queryFn: () => api.get<Paged<Lead>>('leads', params), placeholderData: keepPreviousData });
  const review = useQuery({ queryKey: ['enquiry-submissions', 'review-count'], queryFn: () => api.get<Paged<any>>('enquiry-submissions', { status: 'review', per_page: 1 }), staleTime: 60_000 });
  const [reviewOpen, setReviewOpen] = useState(false);
  const items = q.data?.items || [];
  const activeFilters = ['stage', 'source', 'event_type', 'priority', 'assigned_to', 'followup', 'event_from', 'event_to', 'call_status'].filter((k) => (f as any)[k]);

  const sortBy = (col: string) => setF({ sort: col, dir: f.sort === col && f.dir === 'desc' ? 'asc' : 'desc' });
  const SortHead = ({ col, children, className }: { col: string; children: React.ReactNode; className?: string }) => (
    <th className={clsx('th cursor-pointer select-none hover:text-ink', className)} onClick={() => sortBy(col)}>
      <span className="inline-flex items-center gap-1">
        {children}
        {f.sort === col && (f.dir === 'asc' ? <ArrowUp className="h-3 w-3" /> : <ArrowDown className="h-3 w-3" />)}
      </span>
    </th>
  );

  const bulk = async (action: string, value?: string) => {
    if (action === 'delete' && !(await confirm({ title: `Delete ${sel.length} lead${sel.length > 1 ? 's' : ''}?`, message: 'Their calls, follow-ups and timeline will be deleted too. This cannot be undone.', danger: true, confirmLabel: 'Delete' }))) return;
    try {
      const r = await api.post('leads/bulk', { ids: sel, action, value });
      toast(`${r.updated} lead${r.updated === 1 ? '' : 's'} updated`);
      setSel([]);
      ['leads', 'pipeline', 'dashboard'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };

  const doExport = async () => {
    setExporting(true);
    try {
      const { page, sort, dir, ...rest } = f;
      await api.download('leads/export', rest, `leads-${dayjs().format('DD-MM-YYYY')}.csv`);
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setExporting(false);
  };

  const allSel = items.length > 0 && items.every((l) => sel.includes(l.id));

  return (
    <div>
      <PageHeader
        title="Leads"
        subtitle={q.data ? `${q.data.total} enquiries${activeFilters.length || f.q ? ' match your filters' : ''}` : 'All enquiries'}
        actions={
          <>
            {can('leads.import') && <Button icon={<Upload className="h-4 w-4" />} onClick={() => setImportOpen(true)}>Import</Button>}
            <Button icon={<Download className="h-4 w-4" />} loading={exporting} onClick={doExport}>Export</Button>
            {can('leads.edit') && <Button variant="primary" icon={<Plus className="h-4 w-4" />} onClick={() => setFormOpen(true)}>Add lead</Button>}
          </>
        }
      />

      {(review.data?.total || 0) > 0 && (
        <div className="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" data-testid="enquiry-review-banner">
          <Inbox className="h-4 w-4" />
          <span className="flex-1">{review.data!.total} enquiry form {review.data!.total === 1 ? 'submission needs' : 'submissions need'} review (same phone as an existing lead, or over the plan limit).</span>
          <Button size="sm" onClick={() => setReviewOpen(true)}>Review</Button>
        </div>
      )}
      <Modal open={reviewOpen} onClose={() => (setReviewOpen(false), review.refetch())} title="Enquiries to review" size="xl">
        <Submissions initialStatus="review" />
      </Modal>

      <Card>
        <div className="flex flex-col gap-3 border-b border-line p-3 sm:flex-row sm:items-center">
          <SearchInput value={search} onChange={setSearch} placeholder="Search name, phone, email, city, venue…" className="sm:max-w-sm sm:flex-1" />
          <div className="flex flex-wrap items-center gap-2">
            <Select value={f.stage.includes(',') ? '' : f.stage} onChange={(e) => setF({ stage: e.target.value })} options={config.stages} placeholder="All stages" className="h-9 w-auto py-1.5" />
            <Select value={f.followup} onChange={(e) => setF({ followup: e.target.value })} options={{ today: 'Follow-up today', overdue: 'Follow-up overdue', upcoming: 'Upcoming follow-up' }} placeholder="Any follow-up" className="hidden h-9 w-auto py-1.5 md:block" />
            <Button onClick={() => setShowFilters(!showFilters)} icon={<Filter className="h-4 w-4" />} className={clsx(activeFilters.length && 'border-accent text-accent-ink')}>
              Filters{activeFilters.length ? ` (${activeFilters.length})` : ''}
            </Button>
            {(activeFilters.length > 0 || f.q) && (
              <Button variant="ghost" onClick={() => (setSearch(''), setF({ ...defaults }))} icon={<X className="h-4 w-4" />}>Clear</Button>
            )}
          </div>
        </div>
        {showFilters && (
          <div className="grid grid-cols-2 gap-3 border-b border-line bg-subtle/50 p-3 md:grid-cols-4 xl:grid-cols-8">
            <Select value={f.source} onChange={(e) => setF({ source: e.target.value })} options={config.sources} placeholder="All sources" />
            <Select value={f.event_type} onChange={(e) => setF({ event_type: e.target.value })} options={config.event_types} placeholder="All event types" />
            <Select value={f.priority} onChange={(e) => setF({ priority: e.target.value })} options={config.priorities} placeholder="Any priority" />
            <Select value={f.call_status} onChange={(e) => setF({ call_status: e.target.value })} options={config.call_statuses} placeholder="Any call status" />
            <Select value={f.assigned_to} onChange={(e) => setF({ assigned_to: e.target.value })} options={[['none', 'Unassigned'], ...teamOptions(team.data, ['owner', 'admin', 'sales'])]} placeholder="Anyone" />
            <Select value={f.followup} onChange={(e) => setF({ followup: e.target.value })} options={{ today: 'Follow-up today', overdue: 'Follow-up overdue', upcoming: 'Upcoming follow-up' }} placeholder="Any follow-up" />
            <Input type="date" value={f.event_from} onChange={(e) => setF({ event_from: e.target.value })} title="Event date from" />
            <Input type="date" value={f.event_to} onChange={(e) => setF({ event_to: e.target.value })} title="Event date to" />
          </div>
        )}
        {sel.length > 0 && (
          <div className="flex flex-wrap items-center gap-2 border-b border-line bg-accent-soft/60 px-4 py-2 text-sm">
            <span className="font-medium">{sel.length} selected</span>
            <Menu trigger={() => <Button size="sm">Move to stage</Button>} align="left">
              {(close) => Object.entries(config.stages).map(([k, v]) => <MenuItem key={k} onClick={() => (close(), bulk('stage', k))}>{v}</MenuItem>)}
            </Menu>
            <Menu trigger={() => <Button size="sm">Assign</Button>} align="left">
              {(close) => teamOptions(team.data, ['owner', 'admin', 'sales']).map(([k, v]) => <MenuItem key={k} onClick={() => (close(), bulk('assign', k))}>{v}</MenuItem>)}
            </Menu>
            {can('leads.delete') && <Button size="sm" variant="ghost" className="text-red-600" icon={<Trash2 className="h-4 w-4" />} onClick={() => bulk('delete')}>Delete</Button>}
            <button className="ml-auto text-xs text-muted hover:text-ink" onClick={() => setSel([])}>Clear selection</button>
          </div>
        )}

        {q.error ? (
          <ErrorState error={q.error} onRetry={() => q.refetch()} />
        ) : q.isLoading ? (
          <TableSkeleton />
        ) : items.length === 0 ? (
          <EmptyState
            icon={<UserRound className="h-5 w-5" />}
            title={activeFilters.length || f.q ? 'No leads match these filters' : 'No leads yet'}
            description={activeFilters.length || f.q ? 'Try clearing a filter or searching for something else.' : 'Add your first enquiry or import your existing leads from Excel.'}
            action={!activeFilters.length && !f.q && can('leads.edit') ? <Button variant="primary" onClick={() => setFormOpen(true)}>Add lead</Button> : undefined}
          />
        ) : (
          <>
            {/* Desktop table */}
            <div className={clsx('hidden overflow-x-auto md:block', q.isFetching && 'opacity-70')}>
              <table className="w-full">
                <thead className="border-b border-line bg-subtle/50">
                  <tr>
                    <th className="w-10 px-4"><Checkbox checked={allSel} indeterminate={!allSel && sel.length > 0} onChange={(v) => setSel(v ? items.map((l) => l.id) : [])} /></th>
                    <SortHead col="name">Client</SortHead>
                    <SortHead col="event_date">Event</SortHead>
                    <th className="th">Source</th>
                    <SortHead col="stage">Status</SortHead>
                    <SortHead col="budget" className="text-right">Budget</SortHead>
                    <SortHead col="next_followup_at">Next follow-up</SortHead>
                    <th className="th">Assigned</th>
                    <SortHead col="created_at">Created</SortHead>
                    <th className="th w-20" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-line">
                  {items.map((l) => {
                    const overdue = l.next_followup_at && dayjs(l.next_followup_at).isBefore(dayjs()) && !['booked', 'lost'].includes(l.stage);
                    return (
                      <tr key={l.id} className="group cursor-pointer hover:bg-subtle/60" onClick={() => nav(`/leads/${l.id}`)}>
                        <td className="td" onClick={(e) => e.stopPropagation()}>
                          <Checkbox checked={sel.includes(l.id)} onChange={(v) => setSel(v ? [...sel, l.id] : sel.filter((x) => x !== l.id))} />
                        </td>
                        <td className="td max-w-[240px]">
                          <div className="flex items-center gap-2">
                            <Link to={`/leads/${l.id}`} className="truncate font-medium hover:text-accent-ink" onClick={(e) => e.stopPropagation()}>{l.name}</Link>
                            {l.priority !== 'medium' && <Badge tone={priorityTone[l.priority]}>{config.priorities[l.priority]}</Badge>}
                          </div>
                          <p className="truncate text-xs text-muted">{phoneDisplay(l.phone)}{l.city ? ' · ' + l.city : ''}</p>
                        </td>
                        <td className="td whitespace-nowrap">
                          <p>{l.event_type ? config.event_types[l.event_type] : '—'}</p>
                          <p className="text-xs text-muted">{fmtDate(l.event_date)}{l.event_end_date && l.event_end_date !== l.event_date ? ' → ' + fmtDate(l.event_end_date) : ''}</p>
                        </td>
                        <td className="td whitespace-nowrap text-muted">
                          {l.source ? config.sources[l.source] : '—'}
                          {l.enquiry_submission_id ? <Badge tone="accent" className="ml-1.5" ><Inbox className="h-3 w-3" />Enquiry</Badge> : null}
                        </td>
                        <td className="td">
                          <div className="flex flex-col items-start gap-1">
                            <Badge tone={stageTone[l.stage]} dot>{config.stages[l.stage]}</Badge>
                            {l.call_status !== 'not_called' && <Badge tone={callTone[l.call_status]} className="opacity-80">{config.call_statuses[l.call_status]}</Badge>}
                          </div>
                        </td>
                        <td className="td tabular whitespace-nowrap text-right">{l.budget ? inr(l.budget) : '—'}</td>
                        <td className={clsx('td whitespace-nowrap text-xs', overdue ? 'font-medium text-red-600' : 'text-muted')}>{l.next_followup_at ? fmtDateTime(l.next_followup_at) : '—'}</td>
                        <td className="td whitespace-nowrap text-muted">{l.assigned_name || '—'}</td>
                        <td className="td whitespace-nowrap text-xs text-muted">{fmtDate(l.created_at)}</td>
                        <td className="td" onClick={(e) => e.stopPropagation()}>
                          <div className="flex justify-end gap-0.5 opacity-60 group-hover:opacity-100">
                            <a href={telLink(l.phone)} className="rounded-md p-1.5 hover:bg-surface hover:text-accent-ink" aria-label="Call"><Phone className="h-4 w-4" /></a>
                            <a href={waLink(l.whatsapp || l.phone)} target="_blank" rel="noreferrer" className="rounded-md p-1.5 hover:bg-surface hover:text-green-600" aria-label="WhatsApp"><MessageCircle className="h-4 w-4" /></a>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            {/* Mobile cards */}
            <ul className="divide-y divide-line md:hidden">
              {items.map((l) => (
                <li key={l.id} className="flex items-start gap-3 px-4 py-3">
                  <Link to={`/leads/${l.id}`} className="min-w-0 flex-1">
                    <p className="truncate font-medium">{l.name}</p>
                    <p className="truncate text-xs text-muted">{l.event_type ? config.event_types[l.event_type] : 'Event'} · {fmtDate(l.event_date)} · {l.city || '—'}</p>
                    <div className="mt-1.5 flex flex-wrap gap-1">
                      <Badge tone={stageTone[l.stage]} dot>{config.stages[l.stage]}</Badge>
                      {l.budget ? <Badge>{inr(l.budget)}</Badge> : null}
                    </div>
                  </Link>
                  <a href={telLink(l.phone)} className="rounded-lg border border-line p-2 text-accent-ink" aria-label="Call"><Phone className="h-4 w-4" /></a>
                  <a href={waLink(l.whatsapp || l.phone)} target="_blank" rel="noreferrer" className="rounded-lg border border-line p-2 text-green-600" aria-label="WhatsApp"><MessageCircle className="h-4 w-4" /></a>
                </li>
              ))}
            </ul>
            <Pagination page={Number(f.page)} perPage={25} total={q.data?.total || 0} onPage={(p) => setF({ page: String(p) })} />
          </>
        )}
      </Card>
      <LeadFormModal open={formOpen} onClose={() => setFormOpen(false)} />
      <ImportModal open={importOpen} onClose={() => setImportOpen(false)} />
    </div>
  );
}
