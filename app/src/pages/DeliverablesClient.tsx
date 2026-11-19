import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { AlertTriangle, ArrowLeft, CalendarDays, ExternalLink, LayoutTemplate, PackageCheck, Pencil, Phone, Plus, Trash2, UserRound } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useTeam } from '@/lib/data';
import { fmtDate, phoneDisplay, telLink, today } from '@/lib/format';
import { bookingTone } from '@/lib/tones';
import { Avatar, Badge, Button, Card, EmptyState, ErrorState, PageHeader, PageLoader, Toggle, useUi } from '@/components/ui';
import { DeliverableBadge, DeliverableModal, DeliverableTemplateModal, ProgressBar } from '@/components/delivery';
import { WaButton } from '@/components/shared';

/**
 * Client delivery workspace: every deliverable of one client, grouped by
 * booking, with the same actions as the booking's Deliverables tab.
 */
export default function DeliverablesClient() {
  const { id } = useParams();
  const { config, can, member } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const team = useTeam();
  const q = useQuery({ queryKey: ['deliverables', 'client', id], queryFn: () => api.get<any>(`deliverables/clients/${id}`) });
  const [edit, setEdit] = useState<{ d?: any; bookingId: number } | null>(null);
  const [tplFor, setTplFor] = useState<number | null>(null);
  const [hideDone, setHideDone] = useState(false);
  const [busy, setBusy] = useState<number | null>(null);
  const full = can('deliverables.edit');
  const canUpdate = (d: any) => full || (can('deliverables.update') && d.assigned_to === member.id);

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['deliverables'] });
    qc.invalidateQueries({ queryKey: ['booking'] });
    qc.invalidateQueries({ queryKey: ['team'] });
  };
  const patch = async (d: any, body: Record<string, any>, msg: string) => {
    setBusy(d.id);
    try {
      await api.put(`deliverables/${d.id}`, body);
      toast(msg);
      refresh();
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy(null);
  };
  const remove = async (d: any) => {
    if (!(await confirm({ title: `Delete "${d.name}"?`, message: 'It will disappear from the client portal too.', danger: true, confirmLabel: 'Delete' }))) return;
    await api.del(`deliverables/${d.id}`).then(() => (toast('Deliverable deleted'), refresh()), (e) => toast(e.message, 'error'));
  };

  const back = <Link to="/deliverables" className="mb-1 inline-flex items-center gap-1 text-xs text-muted hover:text-ink"><ArrowLeft className="h-3.5 w-3.5" /> Deliverables</Link>;
  if (q.error) return <div>{back}<Card className="mt-3"><ErrorState error={q.error} onRetry={() => q.refetch()} /></Card></div>;
  if (!q.data) return <PageLoader />;
  const data = q.data;
  const c = data.client;
  const crew = (team.data || []).filter((m: any) => m.status === 'active');
  const todayStr = today();

  return (
    <div>
      <PageHeader
        back={back}
        title={c.name}
        subtitle={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
            {c.phone && <a href={telLink(c.phone)} className="inline-flex items-center gap-1 hover:text-accent-ink"><Phone className="h-3.5 w-3.5" />{phoneDisplay(c.phone)}</a>}
            {c.city && <span>{c.city}</span>}
            <span>{data.bookings.length} booking{data.bookings.length === 1 ? '' : 's'}</span>
            {can('clients.view') && <Link to={`/clients/${c.id}`} className="inline-flex items-center gap-1 text-accent-ink"><UserRound className="h-3.5 w-3.5" />Client profile</Link>}
          </span>
        }
        actions={<WaButton ctx={{ client_id: c.id }} label="WhatsApp" />}
      />

      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Delivered" value={`${data.delivered} of ${data.total}`} />
        <Stat label="Overall progress" value={`${data.progress}%`}><ProgressBar value={data.progress} size="sm" className="mt-2" /></Stat>
        <Stat label="Overdue" value={String(data.overdue)} tone={data.overdue ? 'text-red-600' : undefined} />
        <Stat label={full ? 'Not assigned' : 'Next due'} value={full ? String(data.unassigned) : data.next_due ? fmtDate(data.next_due) : '—'} tone={full && data.unassigned ? 'text-amber-700 dark:text-amber-300' : undefined} />
      </div>

      {data.total > 0 && data.delivered > 0 && data.delivered < data.total && (
        <div className="mb-3 flex justify-end"><Toggle checked={hideDone} onChange={setHideDone} label={<span className="text-sm text-muted">Hide delivered</span>} /></div>
      )}

      {!data.bookings.length ? (
        <Card><EmptyState icon={<PackageCheck className="h-5 w-5" />} title="No deliverables for this client" description={full ? 'This client has no bookings yet. Deliverables are added to a booking.' : 'Nothing is assigned to you for this client.'} /></Card>
      ) : (
        <div className="space-y-4">
          {data.bookings.map((b: any) => {
            const rows = (b.deliverables as any[]).filter((d) => !hideDone || d.status !== 'delivered');
            return (
              <Card key={b.id} data-testid="client-booking">
                <div className="flex flex-col gap-3 border-b border-line p-4 lg:flex-row lg:items-center">
                  <div className="min-w-0 flex-1">
                    <p className="flex flex-wrap items-center gap-2">
                      <Link to={`/bookings/${b.id}`} className="font-semibold hover:text-accent-ink">{b.number}</Link>
                      <span className="truncate text-sm text-muted">{b.title}</span>
                      <Badge tone={bookingTone[b.status]}>{config.booking_statuses[b.status] || b.status}</Badge>
                    </p>
                    <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted">
                      {b.first_event && <span className="inline-flex items-center gap-1"><CalendarDays className="h-3.5 w-3.5" />{fmtDate(b.first_event)}{b.last_event && b.last_event !== b.first_event ? ` to ${fmtDate(b.last_event)}` : ''}</span>}
                      <span className="tabular">{b.delivered} of {b.total} delivered</span>
                      {b.overdue > 0 && <span className="font-medium text-red-600">{b.overdue} overdue</span>}
                    </p>
                  </div>
                  {b.total > 0 && (
                    <div className="flex w-full items-center gap-2 lg:w-44">
                      <ProgressBar value={b.progress} size="md" /><span className="tabular w-9 text-right text-xs text-muted">{b.progress}%</span>
                    </div>
                  )}
                  <div className="flex flex-wrap gap-1.5">
                    {b.total > 0 && <WaButton ctx={{ booking_id: b.id }} template="delivery_progress" label="Send update" size="xs" />}
                    {full && <Button size="xs" icon={<LayoutTemplate className="h-3.5 w-3.5" />} onClick={() => setTplFor(b.id)}>Apply template</Button>}
                    {full && <Button size="xs" variant="primary" icon={<Plus className="h-3.5 w-3.5" />} onClick={() => setEdit({ bookingId: b.id })}>Add deliverable</Button>}
                    <Link to={`/bookings/${b.id}`} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-muted hover:bg-subtle hover:text-ink">Open booking <ExternalLink className="h-3 w-3" /></Link>
                  </div>
                </div>
                {!b.total ? (
                  <p className="px-4 py-6 text-center text-sm text-muted">No deliverables on this booking yet.</p>
                ) : !rows.length ? (
                  <p className="px-4 py-6 text-center text-sm text-muted">Everything on this booking is delivered.</p>
                ) : (
                  <ul className="divide-y divide-line">
                    {rows.map((d) => {
                      const late = d.status !== 'delivered' && d.expected_date && d.expected_date < todayStr;
                      const editable = canUpdate(d);
                      return (
                        <li key={d.id} className={clsx('flex flex-col gap-3 px-4 py-3 md:flex-row md:items-center', busy === d.id && 'opacity-60')} data-testid="deliverable-row">
                          <div className="min-w-0 flex-1">
                            <p className="font-medium">{d.name}</p>
                            {d.description && <p className="truncate text-xs text-muted">{d.description}</p>}
                            <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted">
                              {d.expected_date ? <span className={clsx(late && 'font-medium text-red-600')}>{late && <AlertTriangle className="mr-0.5 inline h-3 w-3" />}Expected {fmtDate(d.expected_date)}{late ? ' (overdue)' : ''}</span> : <span>No date</span>}
                              {d.delivery_link && <a href={d.delivery_link} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-0.5 text-accent-ink hover:underline">Delivery link <ExternalLink className="h-3 w-3" /></a>}
                              {d.updated_by_name && <span className="text-faint">· updated by {d.updated_by_name}</span>}
                            </p>
                            {full && d.admin_notes && <p className="mt-0.5 text-xs italic text-faint">Note: {d.admin_notes}</p>}
                          </div>
                          <div className="grid grid-cols-2 items-center gap-2 sm:flex sm:flex-wrap md:flex-nowrap">
                            {full ? (
                              <select value={d.assigned_to ? String(d.assigned_to) : ''} disabled={busy === d.id} onChange={(e) => patch(d, { assigned_to: e.target.value ? Number(e.target.value) : null }, e.target.value ? `${d.name} assigned` : `${d.name} unassigned`)} className="input w-full py-1 text-xs sm:w-40" aria-label={`Assign ${d.name}`}>
                                <option value="">Not assigned</option>
                                {[...crew, ...(d.assigned_to && !crew.some((m: any) => m.id === d.assigned_to) ? [{ id: d.assigned_to, name: d.assigned_name }] : [])].map((m: any) => <option key={m.id} value={String(m.id)}>{m.name}</option>)}
                              </select>
                            ) : (
                              <span className="flex items-center gap-1.5 text-xs">{d.assigned_name ? <><Avatar name={d.assigned_name} color={d.assigned_color} size="xs" />{d.assigned_name}</> : <span className="text-amber-700 dark:text-amber-300">Not assigned</span>}</span>
                            )}
                            {editable ? (
                              <select value={d.status} disabled={busy === d.id} onChange={(e) => patch(d, { status: e.target.value }, `${d.name}: ${config.deliverable_statuses[e.target.value]}`)} className="input w-full py-1 text-xs sm:w-44" aria-label={`Status of ${d.name}`}>
                                {Object.entries(config.deliverable_statuses).map(([k, l]) => <option key={k} value={k}>{l as string}</option>)}
                              </select>
                            ) : (
                              <DeliverableBadge status={d.status} />
                            )}
                            <div className="col-span-2 flex items-center gap-2 sm:w-32"><ProgressBar value={d.progress} size="sm" /><span className="tabular w-9 text-right text-xs text-muted">{d.progress}%</span></div>
                            <div className="col-span-2 flex items-center justify-end gap-1 sm:col-span-1">
                              {['ready', 'delivered'].includes(d.status) && <WaButton ctx={{ booking_id: b.id, deliverable_id: d.id }} template="deliverable_ready" label="Notify" size="xs" variant="ghost" />}
                              {editable && <button onClick={() => setEdit({ d, bookingId: b.id })} className="rounded p-1.5 text-faint hover:bg-subtle hover:text-ink" aria-label={`Edit ${d.name}`}><Pencil className="h-4 w-4" /></button>}
                              {full && <button onClick={() => remove(d)} className="rounded p-1.5 text-faint hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" aria-label={`Delete ${d.name}`}><Trash2 className="h-4 w-4" /></button>}
                            </div>
                          </div>
                        </li>
                      );
                    })}
                  </ul>
                )}
              </Card>
            );
          })}
        </div>
      )}
      <DeliverableModal open={!!edit} onClose={() => setEdit(null)} deliverable={edit?.d} bookingId={edit?.bookingId} onSaved={refresh} />
      <DeliverableTemplateModal open={!!tplFor} onClose={() => setTplFor(null)} bookingId={tplFor || 0} onDone={refresh} />
    </div>
  );
}

function Stat({ label, value, tone, children }: { label: string; value: string; tone?: string; children?: React.ReactNode }) {
  return (
    <div className="card p-4">
      <p className="text-xs font-medium text-muted">{label}</p>
      <p className={clsx('tabular mt-0.5 text-lg font-semibold', tone)}>{value}</p>
      {children}
    </div>
  );
}
