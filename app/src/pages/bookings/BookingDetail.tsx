import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { ArrowLeft, Check, Clock, ExternalLink, FileText, History, IndianRupee, LayoutTemplate, MapPin, MoreHorizontal, PackageCheck, Pencil, Plus, Receipt, Trash2, Users } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useTeam } from '@/lib/data';
import { fmtDate, fmtDateLong, fmtDateTime, fmtTime, inr, phoneDisplay, telLink, waLink } from '@/lib/format';
import { bookingTone, eventColor, payLabel, payTone, paymentStatusTone, paymentTypeTone, priorityTone, taskStatusTone } from '@/lib/tones';
import { DeliverableBadge, DeliverableModal, DeliverableTemplateModal, PaymentHistoryModal, PortalCard, ProgressBar } from '@/components/delivery';
import { Avatar, Badge, Button, Card, ErrorState, Field, Input, Menu, MenuItem, Modal, PageHeader, PageLoader, Select, Tabs, useUi, EmptyState } from '@/components/ui';
import { Documents, InfoRow, NoteBox, Timeline, WaButton } from '@/components/shared';
import { ExpenseModal, PaymentModal, TaskModal } from '@/components/forms';
import { ServicesEditor, serviceFromApi, serviceToApi, validateServices, type ServiceRow } from '@/components/services';

export default function BookingDetail() {
  const { id } = useParams();
  const nav = useNavigate();
  const qc = useQueryClient();
  const { config, can, member } = useAuth();
  const { toast, confirm } = useUi();
  const q = useQuery({ queryKey: ['booking', id], queryFn: () => api.get(`bookings/${id}`) });
  const loc = useLocation();
  const [tab, setTab] = useState<'overview' | 'services' | 'deliverables' | 'payments' | 'tasks' | 'files' | 'activity'>('overview');
  const [payOpen, setPayOpen] = useState(false);
  const [payEdit, setPayEdit] = useState<any>(null);
  const [history, setHistory] = useState<number | null>(null);
  const [svcOpen, setSvcOpen] = useState(false);
  const [dlEdit, setDlEdit] = useState<any>(null);
  const [tplOpen, setTplOpen] = useState(false);
  const [pinOnce, setPinOnce] = useState<string | null>((loc.state as any)?.portal_pin_once || null);
  useEffect(() => {
    // The one-time PIN arrives through navigation state after creating a booking; drop it from history right away.
    if ((loc.state as any)?.portal_pin_once) nav(loc.pathname, { replace: true, state: null });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  const [expense, setExpense] = useState<any>(null);
  const [task, setTask] = useState<any>(null);
  const [teamFor, setTeamFor] = useState<any>(null);
  const refresh = () => qc.invalidateQueries({ queryKey: ['booking', id] });

  if (q.error) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  if (!q.data) return <PageLoader />;
  const b = q.data;
  const pay = b.payment;
  const statuses = Object.entries(config.booking_statuses).filter(([k]) => k !== 'cancelled');
  const sIdx = statuses.findIndex(([k]) => k === b.status);
  const canEdit = can('bookings.edit');
  const canStatus = canEdit || can('bookings.assign');

  const setStatus = async (status: string) => {
    try {
      await api.post(`bookings/${id}/status`, { status });
      toast(`Status: ${config.booking_statuses[status]}`);
      refresh();
      qc.invalidateQueries({ queryKey: ['bookings'] });
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };
  const remove = async () => {
    if (!(await confirm({ title: `Delete booking ${b.number}?`, message: 'Its events, team assignments, installments, payments and receipts will be deleted. This cannot be undone.', danger: true, confirmLabel: 'Delete booking' }))) return;
    try {
      await api.del(`bookings/${id}`);
      ['bookings', 'calendar', 'dashboard', 'payments'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
      toast('Booking deleted');
      nav('/bookings');
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };
  const delPayment = async (p: any) => {
    if (!(await confirm({ title: `Delete payment ${p.receipt_no}?`, message: `${inr(p.amount)} dated ${fmtDate(p.paid_on)} will be removed and the receipt voided. The deletion is kept in the payment activity log.`, danger: true, confirmLabel: 'Delete payment' }))) return;
    try {
      await api.del(`payments/${p.id}`);
      toast('Payment deleted');
      ['booking', 'payments', 'dashboard'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };
  const delExpense = async (x: any) => {
    if (!(await confirm({ title: 'Delete this expense?', danger: true, confirmLabel: 'Delete' }))) return;
    await api.del(`expenses/${x.id}`).then(refresh, (e) => toast(e.message, 'error'));
  };
  const toggleTask = async (t: any) => {
    await api.put(`tasks/${t.id}`, { status: t.status === 'done' ? 'todo' : 'done' }).then(() => (refresh(), qc.invalidateQueries({ queryKey: ['tasks'] })), (e) => toast(e.message, 'error'));
  };
  const receipt = (p: any) => api.openPdf(`payments/${p.id}/receipt`, `${p.receipt_no}.pdf`).catch((e) => toast(e.message, 'error'));
  const delDeliverable = async (d: any) => {
    if (!(await confirm({ title: `Delete "${d.name}"?`, message: 'It will disappear from the client portal too.', danger: true, confirmLabel: 'Delete' }))) return;
    await api.del(`deliverables/${d.id}`).then(() => (refresh(), qc.invalidateQueries({ queryKey: ['deliverables'] })), (e) => toast(e.message, 'error'));
  };
  const canDl = can('deliverables.edit');
  const canDlUpdate = (d: any) => canDl || (can('deliverables.update') && d.assigned_to === member.id);
  const services: any[] = b.services || [];
  const deliverables: any[] = b.deliverables || [];
  const expTotal = (b.expenses || []).reduce((s: number, x: any) => s + Number(x.amount), 0);
  const openTasks = (b.tasks || []).filter((t: any) => t.status !== 'done').length;

  return (
    <div>
      <PageHeader
        back={<Link to="/bookings" className="mb-1 inline-flex items-center gap-1 text-xs text-muted hover:text-ink"><ArrowLeft className="h-3.5 w-3.5" /> Bookings</Link>}
        title={b.title}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            <span>{b.number}</span>
            <Badge tone={bookingTone[b.status]}>{config.booking_statuses[b.status]}</Badge>
            {pay && <Badge tone={payTone[pay.status]}>{payLabel[pay.status]}</Badge>}
            {b.quotation && can('quotes.view') && <Link to={`/quotations/${b.quotation.id}`} className="text-accent-ink">{b.quotation.number}</Link>}
            {b.lead_id && can('leads.view') && <Link to={`/leads/${b.lead_id}`} className="text-accent-ink">Lead</Link>}
          </span>
        }
        actions={
          <>
            <WaButton ctx={{ booking_id: b.id }} template="booking_confirmation" label="WhatsApp" />
            {pay && can('payments.edit') && pay.pending > 0 && <Button variant="primary" icon={<IndianRupee className="h-4 w-4" />} onClick={() => setPayOpen(true)}>Record payment</Button>}
            {canEdit && <Button icon={<Pencil className="h-4 w-4" />} onClick={() => nav(`/bookings/${id}/edit`)}>Edit</Button>}
            {(canStatus || can('bookings.delete')) && (
              <Menu trigger={() => <Button icon={<MoreHorizontal className="h-4 w-4" />} aria-label="More" />}>
                {(close) => (
                  <>
                    {canStatus && b.status !== 'cancelled' && <MenuItem onClick={() => (close(), setStatus('cancelled'))}>Mark as cancelled</MenuItem>}
                    {canStatus && b.status === 'cancelled' && <MenuItem onClick={() => (close(), setStatus('confirmed'))}>Restore booking</MenuItem>}
                    {can('bookings.delete') && <MenuItem danger icon={<Trash2 className="h-4 w-4" />} onClick={() => (close(), remove())}>Delete booking</MenuItem>}
                  </>
                )}
              </Menu>
            )}
          </>
        }
      />

      {b.status !== 'cancelled' && (
        <Card className="mb-5 overflow-x-auto p-3">
          <ol className="flex min-w-max items-center gap-1">
            {statuses.map(([k, label], i) => (
              <li key={k} className="flex items-center gap-1">
                <button
                  disabled={!canStatus}
                  onClick={() => k !== b.status && setStatus(k)}
                  className={clsx('flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium transition-colors', i < sIdx && 'text-emerald-700 dark:text-emerald-300', i === sIdx && 'bg-accent text-white dark:text-slate-950', i > sIdx && 'text-muted', canStatus && i !== sIdx && 'hover:bg-subtle')}
                >
                  {i < sIdx ? <Check className="h-3.5 w-3.5" /> : <span className={clsx('flex h-4 w-4 items-center justify-center rounded-full text-[10px]', i === sIdx ? 'bg-white/25' : 'bg-subtle')}>{i + 1}</span>}
                  {label}
                </button>
                {i < statuses.length - 1 && <span className="h-px w-4 bg-line" />}
              </li>
            ))}
          </ol>
        </Card>
      )}

      {b.portal && b.status !== 'cancelled' && (
        <div className="mb-5" id="client-portal">
          <PortalCard booking={b} initialPin={pinOnce} onPinShown={() => setPinOnce(null)} />
        </div>
      )}

      {pay && (
        <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Money label="Package" value={inr(pay.total)} />
          <Money label="Received" value={inr(pay.paid)} tone="text-emerald-700 dark:text-emerald-300" />
          <Money label="Pending" value={inr(pay.pending)} tone={pay.status === 'overdue' ? 'text-red-600' : ''} />
          <Money label="Next due" value={pay.next_due ? inr(pay.next_due.amount) : '—'} sub={pay.next_due ? `${pay.next_due.label} · ${fmtDate(pay.next_due.due_date)}` : pay.status === 'paid' ? 'Fully paid' : ''} />
        </div>
      )}

      <Card>
        <div className="px-4 pt-1">
          <Tabs
            value={tab}
            onChange={setTab}
            tabs={[
              ['overview', 'Events & team', b.events.length],
              ['services', 'Services & team', services.length],
              ...(can('deliverables.view') ? ([['deliverables', 'Deliverables', deliverables.length]] as any) : []),
              ...(pay ? ([['payments', 'Payments', b.payments.length]] as any) : []),
              ['tasks', 'Tasks', openTasks],
              ['files', 'Files', b.documents.length],
              ['activity', 'Notes & activity'],
            ]}
          />
        </div>

        {tab === 'overview' && (
          <div className="grid gap-5 p-4 lg:grid-cols-[1fr_300px]">
            <ul className="space-y-3">
              {b.events.map((e: any) => (
                <li key={e.id} className="rounded-xl border border-line p-4" style={{ borderLeft: `4px solid ${eventColor[e.event_type] || '#94a3b8'}` }}>
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                      <p className="font-medium">{e.name}</p>
                      <p className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                        <span>{fmtDateLong(e.event_date)}</span>
                        {e.start_time && <span className="inline-flex items-center gap-1"><Clock className="h-3 w-3" />{fmtTime(e.start_time)}{e.end_time ? ' – ' + fmtTime(e.end_time) : ''}</span>}
                        {(e.venue || e.city) && <span className="inline-flex items-center gap-1"><MapPin className="h-3 w-3" />{[e.venue, e.city].filter(Boolean).join(', ')}</span>}
                      </p>
                    </div>
                    <div className="flex gap-1.5">
                      <WaButton ctx={{ booking_id: b.id, event_id: e.id }} template="event_reminder" label="Reminder" size="xs" variant="ghost" />
                      {can('bookings.assign') && <Button size="xs" icon={<Users className="h-3.5 w-3.5" />} onClick={() => setTeamFor(e)}>Team</Button>}
                    </div>
                  </div>
                  <div className="mt-3 flex flex-wrap gap-2">
                    {e.team.length === 0 ? (
                      <span className="text-xs text-amber-700 dark:text-amber-300">No team assigned yet</span>
                    ) : (
                      e.team.map((t: any) => (
                        <span key={t.id} className="inline-flex items-center gap-1.5 rounded-full bg-subtle py-0.5 pl-0.5 pr-2.5 text-xs">
                          <Avatar name={t.name} color={t.color} size="sm" />
                          <span className="font-medium">{t.name}</span>
                          <span className="text-muted">{config.event_roles[t.role]}</span>
                        </span>
                      ))
                    )}
                  </div>
                  {services.some((s) => s.on_site && s.team.length) && (
                    <p className="mt-2 text-xs text-muted">Service crew: {services.filter((s) => s.on_site && s.team.length).map((s) => `${s.name} (${s.team.map((t: any) => t.name).join(', ')})`).join(' · ')}</p>
                  )}
                  {e.notes && <p className="mt-2 text-xs text-muted">{e.notes}</p>}
                </li>
              ))}
            </ul>
            <div className="space-y-4">
              {deliverables.length > 0 && (
                <button onClick={() => setTab('deliverables')} className="block w-full rounded-xl border border-line p-4 text-left hover:bg-subtle/50">
                  <p className="mb-2 flex items-center justify-between text-xs font-medium uppercase tracking-wide text-faint">Delivery progress <span className="tabular text-ink">{b.delivery_progress}%</span></p>
                  <ProgressBar value={b.delivery_progress} />
                  <p className="mt-1.5 text-xs text-muted">{deliverables.filter((d) => d.status === 'delivered').length} of {deliverables.length} delivered</p>
                </button>
              )}
              <div className="rounded-xl border border-line p-4">
                <p className="mb-1 text-xs font-medium uppercase tracking-wide text-faint">Client</p>
                <Link to={`/clients/${b.client.id}`} className="font-medium hover:text-accent-ink">{b.client.name}</Link>
                <p className="text-sm"><a href={telLink(b.client.phone)} className="text-muted hover:text-accent-ink">{phoneDisplay(b.client.phone)}</a></p>
                {b.client.email && <p className="text-sm text-muted">{b.client.email}</p>}
                {b.client.city && <p className="text-sm text-muted">{b.client.city}</p>}
              </div>
              <div className="divide-y divide-line rounded-xl border border-line px-4 py-1">
                <InfoRow label="Event type">{b.event_type ? config.event_types[b.event_type] : null}</InfoRow>
                <InfoRow label="Confirmed on">{fmtDate(b.confirmed_on)}</InfoRow>
                <InfoRow label="Delivery due">{fmtDate(b.delivery_due)}</InfoRow>
              </div>
              {b.package_details && (
                <div className="rounded-xl border border-line p-4">
                  <p className="mb-1 text-xs font-medium uppercase tracking-wide text-faint">Package</p>
                  <p className="whitespace-pre-line text-sm">{b.package_details}</p>
                </div>
              )}
              {b.special_instructions && (
                <div className="rounded-xl border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
                  <p className="mb-1 text-xs font-medium uppercase tracking-wide text-amber-700 dark:text-amber-300">Special instructions</p>
                  <p className="whitespace-pre-line text-sm">{b.special_instructions}</p>
                </div>
              )}
            </div>
          </div>
        )}

        {tab === 'services' && (
          <div className="p-4">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <p className="text-sm text-muted">Services in this booking and who works on each. On-site services count toward team availability on every event date.</p>
              {(canEdit || can('bookings.assign')) && <Button size="sm" icon={<Pencil className="h-4 w-4" />} onClick={() => setSvcOpen(true)}>{services.length ? 'Edit services & team' : 'Add services'}</Button>}
            </div>
            {services.length === 0 ? (
              <EmptyState icon={<Users className="h-5 w-5" />} title="No services added" description="Add services like Candid Photography or Drone and assign team members to each." />
            ) : (
              <ul className="divide-y divide-line rounded-xl border border-line">
                {services.map((s) => (
                  <li key={s.id} className="px-4 py-3">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="font-medium">{s.name}</p>
                      {s.category && <Badge>{config.service_categories?.[s.category] || s.category}</Badge>}
                      {!s.on_site && <Badge tone="gray">Post-production</Badge>}
                    </div>
                    <div className="mt-2 flex flex-wrap gap-2">
                      {s.team.length === 0 ? (
                        <span className="text-xs text-amber-700 dark:text-amber-300">No one assigned</span>
                      ) : (
                        s.team.map((t: any) => (
                          <Link key={t.member_id} to={`/team/${t.member_id}`} className="inline-flex items-center gap-1.5 rounded-full bg-subtle py-0.5 pl-0.5 pr-2.5 text-xs hover:bg-line">
                            <Avatar name={t.name} color={t.color} src={t.photo_url} size="sm" />
                            <span className="font-medium">{t.name}</span>
                            {t.team_role && <span className="text-muted">{config.team_roles[t.team_role] || t.team_role}</span>}
                          </Link>
                        ))
                      )}
                    </div>
                    {s.notes && <p className="mt-1.5 text-xs text-muted">{s.notes}</p>}
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}

        {tab === 'deliverables' && (
          <div className="p-4">
            <div className="mb-4 flex flex-wrap items-center gap-3">
              <div className="min-w-[200px] flex-1">
                <p className="mb-1 flex justify-between text-sm"><span className="font-medium">Overall delivery progress</span><span className="tabular">{b.delivery_progress}%</span></p>
                <ProgressBar value={b.delivery_progress} size="lg" />
              </div>
              <div className="flex flex-wrap gap-1.5">
                <WaButton ctx={{ booking_id: b.id }} template="delivery_progress" label="Send update" size="sm" />
                {canDl && <Button size="sm" icon={<LayoutTemplate className="h-4 w-4" />} onClick={() => setTplOpen(true)}>Apply template</Button>}
                {canDl && <Button size="sm" variant="primary" icon={<Plus className="h-4 w-4" />} onClick={() => setDlEdit({})}>Add deliverable</Button>}
              </div>
            </div>
            {b.client?.id && <Link to={`/deliverables/clients/${b.client.id}`} className="mb-3 inline-flex items-center gap-1 text-xs text-accent-ink hover:underline">All deliverables for {b.client.name}, across every booking <ExternalLink className="h-3 w-3" /></Link>}
            {deliverables.length === 0 ? (
              <EmptyState icon={<PackageCheck className="h-5 w-5" />} title="No deliverables yet" description={canDl ? 'Apply the Wedding Package or Pre-Wedding template, or add items one by one.' : 'The studio has not added deliverables to this booking.'} />
            ) : (
              <ul className="divide-y divide-line rounded-xl border border-line">
                {deliverables.map((d) => {
                  const late = d.status !== 'delivered' && d.expected_date && d.expected_date < new Date().toISOString().slice(0, 10);
                  return (
                    <li key={d.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                      <div className="min-w-[180px] flex-1">
                        <p className="flex flex-wrap items-center gap-2 font-medium">{d.name} <DeliverableBadge status={d.status} /></p>
                        <p className="mt-0.5 text-xs text-muted">
                          {d.assigned_name || 'Not assigned'} · {d.expected_date ? <span className={clsx(late && 'font-medium text-red-600')}>Expected {fmtDate(d.expected_date)}{late ? ' (overdue)' : ''}</span> : 'No date'}
                          {d.delivery_link && <> · <a href={d.delivery_link} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-0.5 text-accent-ink hover:underline">Link <ExternalLink className="h-3 w-3" /></a></>}
                        </p>
                        {d.admin_notes && canDl && <p className="mt-0.5 text-xs italic text-faint">Note: {d.admin_notes}</p>}
                      </div>
                      <div className="flex w-36 items-center gap-2"><ProgressBar value={d.progress} size="sm" /><span className="tabular w-9 text-right text-xs text-muted">{d.progress}%</span></div>
                      <div className="flex items-center gap-1">
                        {['ready', 'delivered'].includes(d.status) && <WaButton ctx={{ booking_id: b.id, deliverable_id: d.id }} template="deliverable_ready" label="Notify" size="xs" variant="ghost" />}
                        {canDlUpdate(d) && <button onClick={() => setDlEdit(d)} className="rounded p-1.5 text-faint hover:bg-subtle hover:text-ink" aria-label="Edit deliverable"><Pencil className="h-4 w-4" /></button>}
                        {canDl && <button onClick={() => delDeliverable(d)} className="rounded p-1.5 text-faint hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" aria-label="Delete deliverable"><Trash2 className="h-4 w-4" /></button>}
                      </div>
                    </li>
                  );
                })}
              </ul>
            )}
          </div>
        )}

        {tab === 'payments' && pay && (
          <div className="space-y-6 p-4">
            <section>
              <div className="mb-2 flex items-center justify-between">
                <h3 className="text-sm font-semibold">Installments</h3>
                <WaButton ctx={{ booking_id: b.id }} template="payment_reminder" label="Payment reminder" size="xs" />
              </div>
              {pay.installments.length === 0 ? (
                <p className="text-sm text-muted">No payment schedule. <Link to={`/bookings/${id}/edit`} className="text-accent-ink">Add installments</Link></p>
              ) : (
                <div className="overflow-x-auto rounded-xl border border-line">
                  <table className="w-full">
                    <thead className="bg-subtle/50"><tr><th className="th">Installment</th><th className="th">Due</th><th className="th text-right">Amount</th><th className="th text-right">Paid</th><th className="th">Status</th></tr></thead>
                    <tbody className="divide-y divide-line">
                      {pay.installments.map((i: any) => (
                        <tr key={i.id}>
                          <td className="td font-medium">{i.label}</td>
                          <td className={clsx('td whitespace-nowrap text-sm', i.status === 'overdue' && 'font-medium text-red-600')}>{fmtDate(i.due_date)}</td>
                          <td className="td tabular text-right">{inr(i.amount)}</td>
                          <td className="td tabular text-right text-muted">{inr(i.paid)}</td>
                          <td className="td"><Badge tone={payTone[i.status]}>{payLabel[i.status]}</Badge></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>
            <section>
              <div className="mb-2 flex items-center justify-between">
                <h3 className="text-sm font-semibold">Payment records</h3>
                {can('payments.edit') && pay.pending > 0 && <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setPayOpen(true)}>Record payment</Button>}
              </div>
              {b.payments.length === 0 ? (
                <p className="text-sm text-muted">No payments yet.</p>
              ) : (
                <ul className="divide-y divide-line rounded-xl border border-line">
                  {b.payments.map((p: any) => (
                    <li key={p.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                      <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15"><IndianRupee className="h-4 w-4" /></span>
                      <div className="min-w-0 flex-1">
                        <p className="tabular flex flex-wrap items-center gap-1.5 font-medium">{inr(p.amount)} <span className="text-xs font-normal text-muted">· {config.payment_methods[p.method]}</span>
                          <Badge tone={paymentTypeTone[p.payment_type] || 'gray'}>{config.payment_types[p.payment_type] || p.payment_type}</Badge>
                          {p.status && p.status !== 'received' && <Badge tone={paymentStatusTone[p.status] || 'gray'}>{config.payment_statuses[p.status]}</Badge>}
                        </p>
                        <p className="text-xs text-muted">{fmtDate(p.paid_on)} · {p.receipt_no}{p.transaction_ref ? ' · Ref ' + p.transaction_ref : ''}{p.notes ? ' · ' + p.notes : ''}</p>
                      </div>
                      {(!p.status || p.status === 'received') && <Button size="xs" icon={<Receipt className="h-3.5 w-3.5" />} onClick={() => receipt(p)}>Receipt</Button>}
                      <button onClick={() => setHistory(p.id)} className="rounded p-1.5 text-faint hover:bg-subtle hover:text-ink" aria-label="Payment history" title="History"><History className="h-4 w-4" /></button>
                      {can('payments.edit') && <button onClick={() => setPayEdit(p)} className="rounded p-1.5 text-faint hover:bg-subtle hover:text-ink" aria-label="Edit payment"><Pencil className="h-4 w-4" /></button>}
                      {can('payments.edit') && <button onClick={() => delPayment(p)} className="rounded p-1.5 text-faint hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" aria-label="Delete payment"><Trash2 className="h-4 w-4" /></button>}
                    </li>
                  ))}
                </ul>
              )}
            </section>
            <PaymentLinks booking={b} pay={pay} onPaid={refresh} />
            {b.expenses && (
              <section>
                <div className="mb-2 flex items-center justify-between">
                  <h3 className="text-sm font-semibold">Expenses on this booking {expTotal > 0 && <span className="font-normal text-muted">· {inr(expTotal)} · Margin {inr(pay.total - expTotal)}</span>}</h3>
                  {can('expenses.edit') && <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setExpense({})}>Add expense</Button>}
                </div>
                {b.expenses.length === 0 ? (
                  <p className="text-sm text-muted">No expenses recorded.</p>
                ) : (
                  <ul className="divide-y divide-line rounded-xl border border-line">
                    {b.expenses.map((x: any) => (
                      <li key={x.id} className="flex items-center gap-3 px-4 py-2.5 text-sm">
                        <span className="w-24 shrink-0 text-xs text-muted">{fmtDate(x.spent_on)}</span>
                        <Badge>{config.expense_categories[x.category]}</Badge>
                        <span className="min-w-0 flex-1 truncate text-muted">{x.description}</span>
                        <span className="tabular font-medium">{inr(x.amount)}</span>
                        {can('expenses.edit') && (
                          <>
                            <button onClick={() => setExpense(x)} className="rounded p-1 text-faint hover:text-ink" aria-label="Edit"><Pencil className="h-3.5 w-3.5" /></button>
                            <button onClick={() => delExpense(x)} className="rounded p-1 text-faint hover:text-red-600" aria-label="Delete"><Trash2 className="h-3.5 w-3.5" /></button>
                          </>
                        )}
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            )}
          </div>
        )}

        {tab === 'tasks' && (
          <div className="p-4">
            <div className="mb-3 flex justify-end">{can('tasks.edit') && <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setTask({})}>Add task</Button>}</div>
            {b.tasks.length === 0 ? (
              <EmptyState title="No tasks for this booking" />
            ) : (
              <ul className="divide-y divide-line rounded-xl border border-line">
                {b.tasks.map((t: any) => (
                  <li key={t.id} className="flex items-center gap-3 px-4 py-2.5">
                    <button onClick={() => can('tasks.edit') && toggleTask(t)} className={clsx('flex h-5 w-5 shrink-0 items-center justify-center rounded-md border', t.status === 'done' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-line hover:border-accent')} aria-label={t.status === 'done' ? 'Mark not done' : 'Mark done'}>
                      {t.status === 'done' && <Check className="h-3.5 w-3.5" />}
                    </button>
                    <button onClick={() => can('tasks.edit') && setTask(t)} className={clsx('min-w-0 flex-1 text-left text-sm', t.status === 'done' && 'text-muted line-through')}>
                      {t.title}
                      <span className="block text-xs text-muted no-underline">{config.task_categories[t.category]}{t.assigned_name ? ' · ' + t.assigned_name : ''}{t.due_at ? ' · Due ' + fmtDateTime(t.due_at) : ''}</span>
                    </button>
                    <Badge tone={priorityTone[t.priority]}>{config.priorities[t.priority]}</Badge>
                    <Badge tone={taskStatusTone[t.status]}>{config.task_statuses[t.status]}</Badge>
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}

        {tab === 'files' && (
          <div className="p-4">
            <Documents docs={b.documents} bookingId={b.id} clientId={b.client_id} onChange={refresh} />
          </div>
        )}

        {tab === 'activity' && (
          <div className="space-y-4 p-4">
            {canStatus && <NoteBox onSave={(m) => api.post(`bookings/${id}/notes`, { message: m }).then(refresh)} />}
            <Timeline items={b.activities} />
          </div>
        )}
      </Card>

      {b.quotation && can('quotes.view') && b.quotation.total !== undefined && (
        <p className="mt-3 flex items-center gap-1.5 text-xs text-muted">
          <FileText className="h-3.5 w-3.5" /> Based on quotation <Link to={`/quotations/${b.quotation.id}`} className="text-accent-ink">{b.quotation.number}</Link> ({inr(b.quotation.total)})
        </p>
      )}

      <PaymentModal open={payOpen} onClose={() => setPayOpen(false)} booking={pay ? b : null} />
      <PaymentModal open={!!payEdit} onClose={() => setPayEdit(null)} booking={pay ? b : null} payment={payEdit} />
      <PaymentHistoryModal paymentId={history} onClose={() => setHistory(null)} />
      <DeliverableModal open={!!dlEdit} onClose={() => setDlEdit(null)} deliverable={dlEdit?.id ? dlEdit : undefined} bookingId={b.id} onSaved={refresh} />
      <DeliverableTemplateModal open={tplOpen} onClose={() => setTplOpen(false)} bookingId={b.id} onDone={refresh} />
      <ServicesModal open={svcOpen} onClose={() => setSvcOpen(false)} booking={b} onSaved={refresh} />
      <ExpenseModal open={!!expense} onClose={() => setExpense(null)} expense={expense?.id ? expense : undefined} bookingId={b.id} />
      <TaskModal open={!!task} onClose={() => setTask(null)} task={task?.id ? task : undefined} defaults={{ booking_id: b.id }} />
      <TeamModal event={teamFor} onClose={() => setTeamFor(null)} onSaved={refresh} />
    </div>
  );
}

function ServicesModal({ open, onClose, booking: b, onSaved }: { open: boolean; onClose: () => void; booking: any; onSaved: () => void }) {
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const [rows, setRows] = useState<ServiceRow[]>([]);
  const [errors, setErrors] = useState<Record<number, string>>({});
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (open) {
      setRows((b.services || []).map(serviceFromApi));
      setErrors({});
    }
  }, [open, b.services]);
  const save = async (force = false): Promise<void> => {
    const e = validateServices(rows);
    setErrors(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    try {
      await api.put(`bookings/${b.id}/services`, { services: rows.map(serviceToApi), force });
      toast('Services & team saved');
      ['calendar', 'team', 'availability'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
      onSaved();
      onClose();
    } catch (err) {
      setBusy(false);
      if (err instanceof ApiError && err.status === 409) {
        const list = (err.data.conflicts || []).map((c: any) => `${c.member_name}: ${c.event_name} on ${fmtDate(c.event_date)} (${c.booking_number} · ${c.booking_title})`);
        const uniq = Array.from(new Set<string>(list));
        if (await confirm({ title: 'Team member already booked', message: <><ul className="mb-2 list-disc pl-4">{uniq.map((u) => <li key={u}>{u}</li>)}</ul>Assign anyway? Only an admin or production manager can do this.</>, confirmLabel: 'Assign anyway', danger: true })) return save(true);
        return;
      }
      toast((err as Error).message, 'error');
      return;
    }
    setBusy(false);
  };
  return (
    <Modal open={open} onClose={onClose} size="lg" title="Services & team" subtitle={`${b.number} · ${b.title}`} footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={() => save()}>Save</Button></>}>
      <ServicesEditor value={rows} onChange={setRows} dates={(b.events || []).map((e: any) => e.event_date)} bookingId={b.id} errors={errors} />
    </Modal>
  );
}

function Money({ label, value, sub, tone }: { label: string; value: string; sub?: string; tone?: string }) {
  return (
    <div className="card p-4">
      <p className="text-xs font-medium text-muted">{label}</p>
      <p className={clsx('tabular mt-0.5 text-lg font-semibold', tone)}>{value}</p>
      {sub && <p className="mt-0.5 truncate text-xs text-faint">{sub}</p>}
    </div>
  );
}

function TeamModal({ event, onClose, onSaved }: { event: any; onClose: () => void; onSaved: () => void }) {
  const { config } = useAuth();
  const { toast, confirm } = useUi();
  const team = useTeam();
  const [rows, setRows] = useState<{ member_id: number; role: string }[]>([]);
  const [busy, setBusy] = useState(false);
  const avail = useQuery({ queryKey: ['availability', event?.event_date], queryFn: () => api.get<any>('team/availability', { from: event.event_date, to: event.event_date }), enabled: !!event });
  useEffect(() => {
    if (event) setRows(event.team.map((t: any) => ({ member_id: t.member_id, role: t.role })));
  }, [event]);
  const crew = (team.data || []).filter((m) => m.status === 'active');
  const busyMap = new Map<number, any[]>();
  (avail.data?.members || []).forEach((m: any) => busyMap.set(m.id, (m.busy || []).filter((e: any) => e.event_id !== event?.id)));
  const toggle = (mid: number, role: string) => setRows((r) => (r.some((x) => x.member_id === mid) ? r.filter((x) => x.member_id !== mid) : [...r, { member_id: mid, role: role === 'cinematographer' ? 'cinematographer' : 'photographer' }]));
  const save = async (force = false) => {
    setBusy(true);
    try {
      await api.put(`booking-events/${event.id}/team`, { team: rows, force });
      toast('Team updated');
      onSaved();
      onClose();
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) {
        setBusy(false);
        const names = Array.from(new Set((e.data.conflicts || []).map((c: any) => c.member_name))).join(', ');
        if (await confirm({ title: 'Double booking', message: `${names} already has another event at this time. Assign anyway?`, confirmLabel: 'Assign anyway', danger: true })) return save(true);
        return;
      }
      toast((e as Error).message, 'error');
    }
    setBusy(false);
  };
  return (
    <Modal
      open={!!event}
      onClose={onClose}
      title={`Team for ${event?.name || ''}`}
      subtitle={event ? fmtDateLong(event.event_date) + (event.start_time ? ` · ${fmtTime(event.start_time)}–${fmtTime(event.end_time)}` : '') : ''}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={busy} onClick={() => save()}>Save team</Button>
        </>
      }
    >
      <ul className="divide-y divide-line rounded-xl border border-line">
        {crew.map((m) => {
          const row = rows.find((r) => r.member_id === m.id);
          const other = busyMap.get(m.id) || [];
          return (
            <li key={m.id} className="flex items-center gap-3 px-3 py-2.5">
              <input type="checkbox" checked={!!row} onChange={() => toggle(m.id, m.role)} className="h-4 w-4 rounded border-line text-accent-ink focus:ring-accent" />
              <Avatar name={m.name} color={m.color} size="sm" />
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium">{m.name}</p>
                <p className={clsx('text-xs', other.length ? 'text-amber-700 dark:text-amber-300' : 'text-muted')}>
                  {other.length ? `Busy: ${other.map((e: any) => e.name + (e.start_time ? ' ' + fmtTime(e.start_time) : '')).join(', ')}` : config.roles[m.role] + ' · free'}
                </p>
              </div>
              {row && (
                <Select value={row.role} onChange={(e) => setRows((r) => r.map((x) => (x.member_id === m.id ? { ...x, role: e.target.value } : x)))} options={config.event_roles} className="w-40 py-1 text-xs" />
              )}
            </li>
          );
        })}
      </ul>
    </Modal>
  );
}

const money = (v: any) => Number(String(v ?? '').replace(/[^0-9.]/g, '')) || 0;
const LINK_TONE: Record<string, string> = { created: 'amber', paid: 'green', recording: 'blue', cancelled: 'gray', expired: 'gray', partially_paid: 'amber' };
const LINK_LABEL: Record<string, string> = { created: 'Waiting for payment', paid: 'Paid', recording: 'Recording…', cancelled: 'Cancelled', expired: 'Expired', partially_paid: 'Partly paid' };

/** Razorpay payment links for this booking. */
function PaymentLinks({ booking: b, pay, onPaid }: { booking: any; pay: any; onPaid: () => void }) {
  const { studio, can } = useAuth();
  const { toast } = useUi();
  const enabled = !!studio.settings?.rzp_enabled;
  const q = useQuery({ queryKey: ['payment-links', b.id], queryFn: () => api.get<any[]>(`bookings/${b.id}/payment-links`) });
  const [open, setOpen] = useState(false);
  const [amount, setAmount] = useState('');
  const [inst, setInst] = useState('');
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState<string | number>('');
  const links = q.data || [];
  if (!enabled && !links.length) {
    return can('settings.edit') ? (
      <p className="rounded-lg bg-subtle px-3 py-2.5 text-sm text-muted">Want clients to pay online? <Link to="/settings?tab=payments" className="font-medium text-accent-ink">Connect Razorpay</Link> and send a payment link from here.</p>
    ) : null;
  }
  const openInst = (pay.installments || []).filter((i: any) => i.status !== 'paid');
  const start = () => {
    const next = openInst[0];
    setAmount(next ? String(next.balance) : String(pay.pending));
    setInst(next ? String(next.id) : '');
    setErr('');
    setOpen(true);
  };
  const create = async () => {
    const amt = money(amount);
    if (!(amt > 0)) return setErr('Enter an amount.');
    if (amt - pay.pending > 0.009) return setErr(`Only ${inr(pay.pending)} is pending on this booking.`);
    setBusy('new');
    try {
      await api.post(`bookings/${b.id}/payment-links`, { amount: amt, installment_id: inst || null });
      toast('Payment link created');
      setOpen(false);
      q.refetch();
    } catch (e) {
      setErr((e as Error).message);
    }
    setBusy('');
  };
  const act = async (l: any, what: 'sync' | 'cancel') => {
    setBusy(l.id);
    try {
      const r: any = await api.post(`payment-links/${l.id}/${what}`, {});
      q.refetch();
      if (r.status === 'paid') {
        toast('Payment received and recorded');
        onPaid();
      } else toast(what === 'cancel' ? 'Link cancelled' : 'Not paid yet');
    } catch (e) {
      toast((e as Error).message, 'error');
    }
    setBusy('');
  };
  const share = (l: any) => {
    const text = `Hi ${b.client?.name || ''}, here is the payment link for ${inr(l.amount)} for ${b.title} (${b.number}): ${l.short_url}\n\nThank you, ${studio.name}`;
    window.open(waLink(b.client?.phone, text), '_blank');
  };
  return (
    <section>
      <div className="mb-2 flex items-center justify-between">
        <h3 className="text-sm font-semibold">Online payment links <span className="font-normal text-muted">· Razorpay</span></h3>
        {enabled && can('payments.edit') && pay.pending > 0 && <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={start}>Payment link</Button>}
      </div>
      {links.length === 0 ? (
        <p className="text-sm text-muted">No links yet. Create one and share it with the client on WhatsApp.</p>
      ) : (
        <ul className="divide-y divide-line rounded-xl border border-line">
          {links.map((l: any) => (
            <li key={l.id} className="flex flex-wrap items-center gap-2 px-4 py-3">
              <div className="min-w-0 flex-1">
                <p className="tabular font-medium">{inr(l.amount)} <Badge className="ml-1" tone={LINK_TONE[l.status] || 'gray'}>{LINK_LABEL[l.status] || l.status}</Badge></p>
                <p className="truncate text-xs text-muted">{fmtDateTime(l.created_at)} · {l.short_url}</p>
                {l.note && <p className="text-xs text-amber-700 dark:text-amber-300">{l.note}</p>}
              </div>
              {l.status === 'created' && (
                <>
                  <Button size="xs" onClick={() => navigator.clipboard.writeText(l.short_url).then(() => toast('Link copied'))}>Copy</Button>
                  <Button size="xs" onClick={() => share(l)}>WhatsApp</Button>
                  <Button size="xs" loading={busy === l.id} onClick={() => act(l, 'sync')}>Check status</Button>
                  {can('payments.edit') && <Button size="xs" variant="ghost" onClick={() => act(l, 'cancel')}>Cancel</Button>}
                </>
              )}
            </li>
          ))}
        </ul>
      )}
      <Modal open={open} onClose={() => setOpen(false)} title="Create payment link" size="sm" footer={<><Button onClick={() => setOpen(false)}>Cancel</Button><Button variant="primary" loading={busy === 'new'} onClick={create}>Create link</Button></>}>
        <div className="space-y-4">
          {openInst.length > 0 && (
            <Field label="For installment">
              <Select value={inst} onChange={(e) => { setInst(e.target.value); const i = openInst.find((x: any) => String(x.id) === e.target.value); if (i) setAmount(String(i.balance)); }} options={{ '': 'Not linked', ...Object.fromEntries(openInst.map((i: any) => [String(i.id), `${i.label} · ${inr(i.balance)} due`])) }} />
            </Field>
          )}
          <Field label="Amount (₹)" error={err}><Input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
          <p className="text-xs text-muted">Pending on this booking: {inr(pay.pending)}. The client can pay by UPI, card, netbanking or wallet. Razorpay charges its usual fee.</p>
        </div>
      </Modal>
    </section>
  );
}
