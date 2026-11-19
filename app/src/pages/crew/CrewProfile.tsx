import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CalendarDays, Mail, MapPin, Pencil, Phone, Trash2 } from 'lucide-react';
import { api, ApiError, errMsg } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { fmtDate, fmtDateTime, inr, phoneDisplay, telLink, today } from '@/lib/format';
import { Avatar, Badge, Button, Card, CardHeader, EmptyState, ErrorState, PageHeader, PageLoader, StatCard, Tabs, useUi } from '@/components/ui';
import { crewAvailTone, crewStatusTone, crewWa, CrewModal, timeRange, WaIcon } from './shared';
import ScheduleDetail from './ScheduleDetail';
import ScheduleForm from './ScheduleForm';
import CrewPayments from './CrewPayments';

export default function CrewProfile() {
  const { id } = useParams();
  const nav = useNavigate();
  const { config, can } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['crew', 'member', id], queryFn: () => api.get(`crew/${id}`) });
  const [tab, setTab] = useState<'upcoming' | 'history' | 'payments' | 'activity'>('upcoming');
  const [edit, setEdit] = useState<any>(null);
  const [sel, setSel] = useState<number | null>(null);
  const [form, setForm] = useState<any>(null);
  if (q.isLoading) return <PageLoader />;
  if (q.error || !q.data) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  const c = q.data;
  const t = today();
  const upcoming = (c.history || []).filter((h: any) => h.event_date >= t).reverse();
  const past = (c.history || []).filter((h: any) => h.event_date < t);
  const money = can('crew.payments');

  const remove = async (cancel = false) => {
    if (!cancel && !(await confirm({ title: `Remove ${c.name}?`, message: 'Past assignments and payments stay in your records.', confirmLabel: 'Remove', danger: true }))) return;
    try {
      await api.del(`crew/${c.id}${cancel ? '?cancel_upcoming=1' : ''}`);
      qc.invalidateQueries({ queryKey: ['crew'] });
      toast(`${c.name} removed`);
      nav('/crew?tab=directory');
    } catch (e) {
      if (e instanceof ApiError && e.code === 'has_upcoming') {
        if (await confirm({ title: 'Cancel upcoming assignments?', message: e.message, confirmLabel: 'Cancel them and remove', danger: true })) remove(true);
      } else toast(errMsg(e), 'error');
    }
  };

  const tabs: [typeof tab, string, number | null][] = [['upcoming', 'Upcoming', upcoming.length], ['history', 'Past events', past.length]];
  if (money) tabs.push(['payments', 'Payments', c.payments?.length ?? 0]);
  tabs.push(['activity', 'Activity', null]);

  return (
    <div>
      <PageHeader
        back={<Link to="/crew?tab=directory" className="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><ArrowLeft className="h-4 w-4" />Crew directory</Link>}
        title={
          <span className="flex items-center gap-3">
            <Avatar name={c.name} src={c.photo_url} size="xl" />
            <span>
              {c.name}
              {c.deleted_at && <Badge className="ml-2">Removed</Badge>}
              <span className="mt-1 flex flex-wrap items-center gap-1.5 text-sm font-normal text-muted">
                {config.crew_categories[c.category]}
                <Badge tone={crewAvailTone[c.availability]} dot>{config.crew_availability[c.availability]}</Badge>
              </span>
            </span>
          </span>
        }
        actions={
          <>
            <WaIcon href={crewWa(c)} />
            {can('crew.edit') && !c.deleted_at && (
              <>
                <Button size="sm" icon={<CalendarDays className="h-4 w-4" />} onClick={() => setForm({ assignments: [{ crew_member_id: c.id, role: c.category, rate: c.daily_rate, status: 'scheduled' }], copy: true })}>Schedule</Button>
                <Button size="sm" icon={<Pencil className="h-4 w-4" />} onClick={() => setEdit(c)}>Edit</Button>
                <Button size="sm" variant="ghost" icon={<Trash2 className="h-4 w-4" />} onClick={() => remove()}>Remove</Button>
              </>
            )}
          </>
        }
      />
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4">
          <Card>
            <CardHeader title="Contact" />
            <div className="space-y-2 px-4 pb-4 text-sm">
              <a href={telLink(c.phone)} className="flex items-center gap-2 hover:text-accent-ink"><Phone className="h-4 w-4 text-muted" />{phoneDisplay(c.phone)}</a>
              {c.whatsapp && c.whatsapp !== c.phone && <p className="flex items-center gap-2 text-muted">WhatsApp: {phoneDisplay(c.whatsapp)}</p>}
              {c.email && <a href={`mailto:${c.email}`} className="flex items-center gap-2 hover:text-accent-ink"><Mail className="h-4 w-4 text-muted" />{c.email}</a>}
              {c.city && <p className="flex items-center gap-2"><MapPin className="h-4 w-4 text-muted" />{c.city}</p>}
              {c.specialization && <p><span className="text-muted">Specialization: </span>{c.specialization}</p>}
              {money && c.daily_rate != null && <p><span className="text-muted">Daily rate: </span>{inr(c.daily_rate)}</p>}
              {c.notes && <p className="whitespace-pre-line text-muted">{c.notes}</p>}
            </div>
          </Card>
          <div className="grid grid-cols-3 gap-2 lg:grid-cols-1">
            <StatCard label="Events" value={c.stats.total} />
            <StatCard label="Upcoming" value={c.stats.upcoming} tone="blue" />
            <StatCard label="Completed" value={c.stats.completed} tone="green" />
          </div>
          {money && c.payment_summary && (
            <Card className="p-4 text-sm">
              <p className="flex justify-between"><span className="text-muted">Agreed</span>{inr(c.payment_summary.agreed)}</p>
              <p className="flex justify-between"><span className="text-muted">Paid</span>{inr(c.payment_summary.paid)}</p>
              <p className="flex justify-between font-medium"><span className="text-muted">Remaining</span>{inr(c.payment_summary.remaining)}</p>
            </Card>
          )}
        </div>
        <div className="lg:col-span-2">
          <Tabs value={tab} onChange={setTab} tabs={tabs} className="mb-3" />
          {(tab === 'upcoming' || tab === 'history') && (
            <Card>
              {!(tab === 'upcoming' ? upcoming : past).length ? (
                <EmptyState icon={<CalendarDays className="h-5 w-5" />} title={tab === 'upcoming' ? 'No upcoming events' : 'No past events yet'} />
              ) : (
                <ul className="divide-y divide-line">
                  {(tab === 'upcoming' ? upcoming : past).map((h: any) => (
                    <li key={h.id}>
                      <button className="flex w-full flex-wrap items-start gap-3 px-4 py-3 text-left hover:bg-subtle/50" onClick={() => setSel(h.schedule_id)}>
                        <div className="w-16 shrink-0 text-center">
                          <p className="text-lg font-semibold leading-none">{fmtDate(h.event_date).slice(0, 2)}</p>
                          <p className="text-2xs uppercase text-muted">{new Date(h.event_date).toLocaleString('en-IN', { month: 'short', year: '2-digit' })}</p>
                        </div>
                        <div className="min-w-0 flex-1">
                          <p className="font-medium">{h.event_name}{h.client_name ? ` – ${h.client_name}` : ''}</p>
                          <p className="text-xs text-muted">{config.crew_categories[h.role]}{h.service ? ` · ${h.service}` : ''} · {timeRange(h)}</p>
                          {(h.venue || h.city) && <p className="text-xs text-faint">{[h.venue, h.city].filter(Boolean).join(', ')}</p>}
                        </div>
                        <div className="text-right">
                          <Badge tone={crewStatusTone[h.status]}>{config.crew_assignment_statuses[h.status]}</Badge>
                          {money && h.rate != null && <p className="mt-1 text-xs text-muted">{inr(h.rate)}</p>}
                        </div>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          )}
          {tab === 'payments' && money && <CrewPayments crewMemberId={c.id} />}
          {tab === 'activity' && (
            <Card className="p-4">
              {!c.audit?.length ? (
                <p className="text-sm text-muted">No changes recorded yet.</p>
              ) : (
                <ul className="space-y-2 text-sm">
                  {c.audit.map((l: any) => (
                    <li key={l.id}>
                      {l.summary}
                      <span className="block text-xs text-muted">{l.user_name || 'System'} · {fmtDateTime(l.created_at)}</span>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          )}
        </div>
      </div>
      <CrewModal member={edit} onClose={() => setEdit(null)} onSaved={() => qc.invalidateQueries({ queryKey: ['crew', 'member', id] })} />
      <ScheduleDetail id={sel} onClose={() => setSel(null)} onEdit={(s) => (setSel(null), setForm(s))} onCopy={(s) => (setSel(null), setForm({ ...s, copy: true }))} />
      <ScheduleForm initial={form} onClose={() => setForm(null)} onSaved={() => qc.invalidateQueries({ queryKey: ['crew', 'member', id] })} />
    </div>
  );
}
