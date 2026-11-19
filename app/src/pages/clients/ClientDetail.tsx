import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CalendarCheck, FileText, Mail, MapPin, MoreHorizontal, Pencil, Phone, Trash2 } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { fmtDate, fmtDateLong, inr, phoneDisplay, telLink, dayjs } from '@/lib/format';
import { bookingTone, eventColor, quoteTone, stageTone } from '@/lib/tones';
import { Avatar, Badge, Button, Card, CardHeader, ErrorState, Menu, MenuItem, PageLoader, Tabs, useUi } from '@/components/ui';
import { Documents, NoteBox, Timeline, WaButton } from '@/components/shared';
import { ClientFormModal } from './ClientForm';

export default function ClientDetail() {
  const { id } = useParams();
  const nav = useNavigate();
  const qc = useQueryClient();
  const { config, can } = useAuth();
  const { toast, confirm } = useUi();
  const q = useQuery({ queryKey: ['client', id], queryFn: () => api.get(`clients/${id}`) });
  const [tab, setTab] = useState('overview');
  const [edit, setEdit] = useState(false);
  const refresh = () => qc.invalidateQueries({ queryKey: ['client', id] });
  if (q.error) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  if (!q.data) return <PageLoader />;
  const c = q.data;
  const s = c.summary;
  const upcoming = c.events.filter((e: any) => e.event_date >= dayjs().format('YYYY-MM-DD'));

  const remove = async () => {
    if (!(await confirm({ title: `Delete ${c.name}?`, message: 'The client profile, notes and files will be deleted. Clients with bookings cannot be deleted.', danger: true, confirmLabel: 'Delete' }))) return;
    try {
      await api.del(`clients/${id}`);
      qc.invalidateQueries({ queryKey: ['clients'] });
      toast('Client deleted');
      nav('/clients');
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };

  return (
    <div>
      <Link to="/clients" className="mb-2 inline-flex items-center gap-1 text-xs text-muted hover:text-ink"><ArrowLeft className="h-3.5 w-3.5" /> Clients</Link>
      <Card className="mb-5 p-4 sm:p-5">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
          <Avatar name={c.name} size="lg" />
          <div className="min-w-0 flex-1">
            <h1 className="text-xl font-semibold tracking-tight">{c.name}</h1>
            <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted">
              <a href={telLink(c.phone)} className="inline-flex items-center gap-1.5 hover:text-accent-ink"><Phone className="h-3.5 w-3.5" />{phoneDisplay(c.phone)}</a>
              {c.email && <a href={`mailto:${c.email}`} className="inline-flex items-center gap-1.5 hover:text-accent-ink"><Mail className="h-3.5 w-3.5" />{c.email}</a>}
              {(c.city || c.address) && <span className="inline-flex items-center gap-1.5"><MapPin className="h-3.5 w-3.5" />{[c.address, c.city].filter(Boolean).join(', ')}</span>}
            </div>
            {c.notes && <p className="mt-2 text-sm text-muted">{c.notes}</p>}
          </div>
          <div className="flex flex-wrap gap-2">
            <WaButton ctx={{ client_id: c.id }} template="followup" />
            {can('quotes.edit') && <Button icon={<FileText className="h-4 w-4" />} onClick={() => nav(`/quotations/new?client_id=${c.id}`)}>Quotation</Button>}
            {can('bookings.edit') && <Button variant="primary" icon={<CalendarCheck className="h-4 w-4" />} onClick={() => nav(`/bookings/new?client_id=${c.id}`)}>New booking</Button>}
            {(can('clients.edit') || can('clients.delete')) && (
              <Menu trigger={() => <Button icon={<MoreHorizontal className="h-4 w-4" />} aria-label="More" />}>
                {(close) => (
                  <>
                    {can('clients.edit') && <MenuItem icon={<Pencil className="h-4 w-4" />} onClick={() => (close(), setEdit(true))}>Edit details</MenuItem>}
                    {can('clients.delete') && <MenuItem danger icon={<Trash2 className="h-4 w-4" />} onClick={() => (close(), remove())}>Delete client</MenuItem>}
                  </>
                )}
              </Menu>
            )}
          </div>
        </div>
        {s && (
          <div className="mt-5 grid grid-cols-3 gap-3 border-t border-line pt-4">
            <div><p className="text-xs text-muted">Total business</p><p className="tabular text-lg font-semibold">{inr(s.total)}</p></div>
            <div><p className="text-xs text-muted">Received</p><p className="tabular text-lg font-semibold text-emerald-700 dark:text-emerald-300">{inr(s.paid)}</p></div>
            <div><p className="text-xs text-muted">Pending</p><p className="tabular text-lg font-semibold">{inr(s.pending)}</p></div>
          </div>
        )}
      </Card>

      <Card>
        <div className="px-4 pt-1">
          <Tabs value={tab} onChange={setTab} tabs={[['overview', 'Overview'], ['bookings', 'Bookings', c.bookings.length], ['enquiries', 'Enquiries', c.enquiries.length], ...(c.payments ? ([['payments', 'Payments', c.payments.length]] as any) : []), ['files', 'Files', c.documents.length], ['activity', 'Notes & history']]} />
        </div>
        {tab === 'overview' && (
          <div className="grid gap-5 p-4 lg:grid-cols-2">
            <div>
              <CardHeader title="Upcoming events" className="px-0 pt-0" />
              {upcoming.length === 0 ? <p className="text-sm text-muted">No upcoming events.</p> : (
                <ul className="space-y-2">
                  {upcoming.map((e: any) => (
                    <li key={e.id} className="rounded-lg border border-line p-3" style={{ borderLeft: `3px solid ${eventColor[e.event_type] || eventColor.other}` }}>
                      <Link to={`/bookings/${e.booking_id}`} className="text-sm font-medium hover:text-accent-ink">{e.name}</Link>
                      <p className="text-xs text-muted">{fmtDateLong(e.event_date)}{e.venue ? ' · ' + e.venue : ''}{e.city ? ', ' + e.city : ''}</p>
                    </li>
                  ))}
                </ul>
              )}
            </div>
            <div>
              <CardHeader title="Quotations" className="px-0 pt-0" />
              {c.quotations.length === 0 ? <p className="text-sm text-muted">No quotations.</p> : (
                <ul className="divide-y divide-line rounded-lg border border-line">
                  {c.quotations.map((x: any) => (
                    <li key={x.id} className="flex items-center gap-2 px-3 py-2 text-sm">
                      <Link to={`/quotations/${x.id}`} className="font-medium hover:text-accent-ink">{x.number}</Link>
                      <span className="min-w-0 flex-1 truncate text-muted">{x.title}</span>
                      {x.total !== undefined && <span className="tabular">{inr(x.total)}</span>}
                      <Badge tone={quoteTone[x.status]}>{config.quotation_statuses[x.status]}</Badge>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        )}
        {tab === 'bookings' && (
          <ul className="divide-y divide-line">
            {c.bookings.length === 0 && <li className="px-4 py-8 text-center text-sm text-muted">No bookings yet.</li>}
            {c.bookings.map((b: any) => (
              <li key={b.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                <div className="min-w-0 flex-1">
                  <Link to={`/bookings/${b.id}`} className="font-medium hover:text-accent-ink">{b.title}</Link>
                  <p className="text-xs text-muted">{b.number} · Confirmed {fmtDate(b.confirmed_on)}</p>
                </div>
                {b.total_amount !== undefined && <span className="tabular text-sm">{inr(b.total_amount)} <span className="text-xs text-muted">({inr(b.paid)} paid)</span></span>}
                <Badge tone={bookingTone[b.status]}>{config.booking_statuses[b.status]}</Badge>
              </li>
            ))}
          </ul>
        )}
        {tab === 'enquiries' && (
          <ul className="divide-y divide-line">
            {c.enquiries.length === 0 && <li className="px-4 py-8 text-center text-sm text-muted">No enquiries linked.</li>}
            {c.enquiries.map((l: any) => (
              <li key={l.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                <div className="min-w-0 flex-1">
                  {can('leads.view') ? <Link to={`/leads/${l.id}`} className="font-medium hover:text-accent-ink">{l.event_type ? config.event_types[l.event_type] : 'Enquiry'} · {fmtDate(l.event_date)}</Link> : <span className="font-medium">{l.event_type ? config.event_types[l.event_type] : 'Enquiry'} · {fmtDate(l.event_date)}</span>}
                  <p className="text-xs text-muted">Received {fmtDate(l.created_at)}{l.source ? ' via ' + config.sources[l.source] : ''}</p>
                </div>
                <Badge tone={stageTone[l.stage]}>{config.stages[l.stage]}</Badge>
              </li>
            ))}
          </ul>
        )}
        {tab === 'payments' && c.payments && (
          <ul className="divide-y divide-line">
            {c.payments.length === 0 && <li className="px-4 py-8 text-center text-sm text-muted">No payments yet.</li>}
            {c.payments.map((p: any) => (
              <li key={p.id} className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                <span className="w-24 text-xs text-muted">{fmtDate(p.paid_on)}</span>
                <span className="flex-1">{p.receipt_no} · <Link to={`/bookings/${p.booking_id}`} className="text-muted hover:text-accent-ink">{p.booking_number}</Link></span>
                <Badge>{config.payment_methods[p.method]}</Badge>
                <span className="tabular font-medium">{inr(p.amount)}</span>
                <Button size="xs" onClick={() => api.openPdf(`payments/${p.id}/receipt`, `${p.receipt_no}.pdf`).catch((e) => toast(e.message, 'error'))}>Receipt</Button>
              </li>
            ))}
          </ul>
        )}
        {tab === 'files' && <div className="p-4"><Documents docs={c.documents} clientId={c.id} onChange={refresh} /></div>}
        {tab === 'activity' && (
          <div className="space-y-4 p-4">
            {can('clients.edit') && <NoteBox onSave={(m) => api.post(`clients/${id}/notes`, { message: m }).then(refresh)} />}
            <Timeline items={c.activities} />
          </div>
        )}
      </Card>
      <ClientFormModal open={edit} onClose={() => setEdit(false)} client={c} />
    </div>
  );
}
