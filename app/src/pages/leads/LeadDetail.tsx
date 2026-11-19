import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Briefcase, CalendarClock, Check, FileText, Mail, MapPin, MoreHorizontal, Pencil, Phone, PhoneCall, Trash2, AlertTriangle, Inbox } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { fmtDate, fmtDateTime, inr, phoneDisplay, telLink, dayjs, fromNow } from '@/lib/format';
import { callTone, priorityTone, quoteTone, stageTone } from '@/lib/tones';
import { Badge, Button, Card, CardHeader, ErrorState, Menu, MenuItem, PageLoader, Tabs, useUi, Input, Modal, Field } from '@/components/ui';
import { FollowupModal, InfoRow, LogCallModal, NoteBox, Timeline, WaButton } from '@/components/shared';
import { LeadFormModal } from './LeadForm';
import { SubmissionModal } from '@/pages/enquiry/EnquirySettings';

export default function LeadDetail() {
  const { id } = useParams();
  const { config, can } = useAuth();
  const { toast, confirm } = useUi();
  const nav = useNavigate();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['lead', id], queryFn: () => api.get(`leads/${id}`) });
  const [tab, setTab] = useState<'timeline' | 'calls' | 'followups' | 'quotes'>('timeline');
  const [edit, setEdit] = useState(false);
  const [call, setCall] = useState(false);
  const [fu, setFu] = useState<{ open: boolean; item?: any }>({ open: false });
  const [lost, setLost] = useState(false);
  const [lostReason, setLostReason] = useState('');
  const [enquiry, setEnquiry] = useState<number | null>(null);
  const l = q.data;

  const refresh = () => ['lead', 'leads', 'pipeline', 'dashboard', 'calling-summary'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
  const move = useMutation({
    mutationFn: ({ stage, lost_reason }: { stage: string; lost_reason?: string }) => api.post(`leads/${id}/move`, { stage, lost_reason }),
    onSuccess: () => (refresh(), toast('Stage updated')),
    onError: (e: any) => toast(e.message, 'error'),
  });
  const completeFu = useMutation({
    mutationFn: (fid: number) => api.post(`followups/${fid}/complete`, {}),
    onSuccess: () => (refresh(), toast('Follow-up completed')),
  });

  if (q.isLoading) return <PageLoader />;
  if (q.error || !l) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;

  const del = async () => {
    if (!(await confirm({ title: 'Delete this lead?', message: 'All calls, follow-ups and timeline entries for this lead will be deleted.', danger: true, confirmLabel: 'Delete lead' }))) return;
    try {
      await api.del(`leads/${id}`);
      toast('Lead deleted');
      qc.invalidateQueries({ queryKey: ['leads'] });
      nav('/leads');
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };
  const stages = Object.keys(config.stages);
  const idx = stages.indexOf(l.stage);
  const pendingFu = l.followups.filter((f: any) => f.status === 'pending');
  let services: string[] = [];
  try {
    services = Array.isArray(l.services) ? l.services : l.services ? JSON.parse(l.services) : [];
  } catch {
    services = [];
  }

  return (
    <div>
      <Link to="/leads" className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><ArrowLeft className="h-4 w-4" /> Leads</Link>
      <div className="mb-5 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{l.name}</h1>
            <Badge tone={stageTone[l.stage]} dot>{config.stages[l.stage]}</Badge>
            <Badge tone={priorityTone[l.priority]}>{config.priorities[l.priority]} priority</Badge>
            {l.enquiry_submission_id && <Badge tone="accent"><Inbox className="h-3 w-3" />Enquiry form</Badge>}
          </div>
          <p className="mt-1 text-sm text-muted">
            {l.event_type ? config.event_types[l.event_type] : 'Event'} · {fmtDate(l.event_date)}{l.event_end_date && l.event_end_date !== l.event_date ? ' to ' + fmtDate(l.event_end_date) : ''} · {l.city || 'City not set'} · Added {fromNow(l.created_at)}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {can('leads.edit') && <Button variant="primary" icon={<PhoneCall className="h-4 w-4" />} onClick={() => setCall(true)}>Log call</Button>}
          <WaButton ctx={{ lead_id: l.id }} template={l.stage === 'new' ? 'first_contact' : 'followup'} size="md" />
          {can('quotes.edit') && <Button icon={<FileText className="h-4 w-4" />} onClick={() => nav(`/quotations/new?lead_id=${l.id}`)}>Quotation</Button>}
          {can('bookings.edit') && l.stage !== 'booked' && <Button icon={<Briefcase className="h-4 w-4" />} onClick={() => nav(`/bookings/new?lead_id=${l.id}`)}>Convert to booking</Button>}
          <Menu trigger={() => <Button aria-label="More"><MoreHorizontal className="h-4 w-4" /></Button>}>
            {(close) => (
              <>
                {can('leads.edit') && <MenuItem icon={<Pencil />} onClick={() => (close(), setEdit(true))}>Edit lead</MenuItem>}
                {can('leads.edit') && <MenuItem icon={<CalendarClock />} onClick={() => (close(), setFu({ open: true }))}>Schedule follow-up</MenuItem>}
                {can('leads.edit') && l.stage !== 'lost' && <MenuItem icon={<AlertTriangle />} onClick={() => (close(), setLost(true))}>Mark as lost</MenuItem>}
                {can('leads.delete') && <MenuItem icon={<Trash2 />} danger onClick={() => (close(), del())}>Delete lead</MenuItem>}
              </>
            )}
          </Menu>
        </div>
      </div>

      {/* Stage stepper */}
      <Card className="mb-5 overflow-x-auto p-1.5">
        <div className="flex min-w-[760px] gap-1">
          {stages.filter((s) => s !== 'lost').map((s, i) => {
            const done = l.stage !== 'lost' && i <= idx;
            return (
              <button
                key={s}
                disabled={!can('leads.edit') || move.isPending}
                onClick={() => (s === 'booked' ? nav(`/bookings/new?lead_id=${l.id}`) : move.mutate({ stage: s }))}
                className={'flex flex-1 items-center justify-center gap-1 rounded-lg px-2 py-2 text-xs font-medium transition-colors ' + (s === l.stage ? 'bg-accent text-white dark:text-slate-950' : done ? 'bg-accent-soft text-accent-ink' : 'text-muted hover:bg-subtle')}
              >
                {done && s !== l.stage && <Check className="h-3 w-3" />}
                {config.stages[s]}
              </button>
            );
          })}
        </div>
      </Card>

      {l.duplicates?.length > 0 && (
        <div className="mb-5 flex flex-wrap items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
          <AlertTriangle className="h-4 w-4" /> Same phone or email as:
          {l.duplicates.map((d: any) => <Link key={d.id} to={`/leads/${d.id}`} className="font-medium underline">{d.name} ({config.stages[d.stage]})</Link>)}
        </div>
      )}

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div className="space-y-5">
          <Card>
            <CardHeader title="Contact" />
            <div className="divide-y divide-line px-5 py-1">
              <InfoRow label="Phone"><a href={telLink(l.phone)} className="inline-flex items-center gap-1 hover:text-accent-ink"><Phone className="h-3.5 w-3.5" />{phoneDisplay(l.phone)}</a></InfoRow>
              <InfoRow label="WhatsApp">{phoneDisplay(l.whatsapp)}</InfoRow>
              <InfoRow label="Email">{l.email ? <a href={`mailto:${l.email}`} className="inline-flex items-center gap-1 hover:text-accent-ink"><Mail className="h-3.5 w-3.5" />{l.email}</a> : null}</InfoRow>
              <InfoRow label="City">{l.city}</InfoRow>
              {l.client_id && <InfoRow label="Client profile"><Link to={`/clients/${l.client_id}`} className="text-accent-ink">Open</Link></InfoRow>}
            </div>
          </Card>
          <Card>
            <CardHeader title="Event" />
            <div className="divide-y divide-line px-5 py-1">
              <InfoRow label="Type">{l.event_type ? config.event_types[l.event_type] : null}</InfoRow>
              <InfoRow label="Date">{fmtDate(l.event_date)}</InfoRow>
              <InfoRow label="End date">{l.event_end_date ? fmtDate(l.event_end_date) : null}</InfoRow>
              <InfoRow label="Duration">{l.event_duration}</InfoRow>
              <InfoRow label="Venue">{l.venue ? <span className="inline-flex items-center gap-1"><MapPin className="h-3.5 w-3.5" />{l.venue}</span> : null}</InfoRow>
            </div>
          </Card>
          <Card>
            <CardHeader title="Sales" />
            <div className="divide-y divide-line px-5 py-1">
              <InfoRow label="Source">{l.source ? config.sources[l.source] : null}</InfoRow>
              <InfoRow label="Assigned to">{l.assigned_name}</InfoRow>
              <InfoRow label="Estimated budget">{l.budget ? inr(l.budget) : null}</InfoRow>
              <InfoRow label="Call status"><Badge tone={callTone[l.call_status]}>{config.call_statuses[l.call_status]}</Badge></InfoRow>
              <InfoRow label="Last call">{l.last_call_at ? fmtDateTime(l.last_call_at) : null}</InfoRow>
              <InfoRow label="Next follow-up"><span className={l.next_followup_at && dayjs(l.next_followup_at).isBefore(dayjs()) ? 'text-red-600' : ''}>{l.next_followup_at ? fmtDateTime(l.next_followup_at) : null}</span></InfoRow>
              {l.stage === 'lost' && <InfoRow label="Lost reason">{l.lost_reason}</InfoRow>}
              <InfoRow label="Created">{fmtDateTime(l.created_at)}</InfoRow>
            </div>
            {l.notes && <p className="whitespace-pre-line border-t border-line px-5 py-3 text-sm text-muted">{l.notes}</p>}
          </Card>
          {(l.enquiry_submission_id || services.length > 0 || l.utm_source) && (
            <Card data-testid="lead-enquiry">
              <CardHeader title="Enquiry" actions={l.enquiry_submission_id && <Button size="xs" onClick={() => setEnquiry(l.enquiry_submission_id)}>View original enquiry</Button>} />
              <div className="divide-y divide-line px-5 py-1">
                {services.length > 0 && (
                  <InfoRow label="Services">
                    <span className="flex flex-wrap justify-end gap-1">{services.map((x) => <Badge key={x}>{config.enquiry_services[x] || x}</Badge>)}</span>
                  </InfoRow>
                )}
                {l.enquiry_submission_id && l.source === 'enquiry_form' && <InfoRow label="Submitted">{fmtDateTime(l.created_at)}</InfoRow>}
                {l.form_url && <InfoRow label="Form"><a href={l.form_url} target="_blank" rel="noreferrer" className="break-all text-accent-ink hover:underline">{l.form_url.replace(/^https?:\/\//, '').replace(/\?.*$/, '')}</a></InfoRow>}
                {l.utm_source && <InfoRow label="UTM source">{l.utm_source}</InfoRow>}
                {l.utm_medium && <InfoRow label="UTM medium">{l.utm_medium}</InfoRow>}
                {l.utm_campaign && <InfoRow label="UTM campaign">{l.utm_campaign}</InfoRow>}
              </div>
            </Card>
          )}
        </div>

        <div className="space-y-5 lg:col-span-2">
          {pendingFu.length > 0 && (
            <Card className="border-amber-200 dark:border-amber-500/30">
              <div className="flex flex-col gap-2 px-5 py-3.5 sm:flex-row sm:items-center">
                <CalendarClock className="h-5 w-5 text-amber-600" />
                <div className="flex-1">
                  <p className="text-sm font-medium">Next follow-up {dayjs(pendingFu[pendingFu.length - 1].due_at).isBefore(dayjs()) ? <span className="text-red-600">(overdue)</span> : ''}</p>
                  <p className="text-sm text-muted">{fmtDateTime(pendingFu[pendingFu.length - 1].due_at)}{pendingFu[pendingFu.length - 1].note ? ' · ' + pendingFu[pendingFu.length - 1].note : ''}</p>
                </div>
                {can('leads.edit') && (
                  <div className="flex gap-2">
                    <Button size="sm" onClick={() => setFu({ open: true, item: pendingFu[pendingFu.length - 1] })}>Reschedule</Button>
                    <Button size="sm" variant="primary" onClick={() => completeFu.mutate(pendingFu[pendingFu.length - 1].id)}>Mark done</Button>
                  </div>
                )}
              </div>
            </Card>
          )}
          <Card>
            <div className="px-5 pt-2">
              <Tabs value={tab} onChange={setTab} tabs={[['timeline', 'Activity'], ['calls', 'Calls', l.calls.length], ['followups', 'Follow-ups', l.followups.length], ['quotes', 'Quotations & bookings', l.quotations.length + l.bookings.length]]} />
            </div>
            <div className="p-5">
              {tab === 'timeline' && (
                <div className="space-y-5">
                  {can('leads.edit') && <NoteBox onSave={(m) => api.post(`leads/${id}/notes`, { message: m }).then(refresh)} />}
                  <Timeline items={l.activities} />
                </div>
              )}
              {tab === 'calls' && (
                l.calls.length === 0 ? <p className="py-6 text-center text-sm text-muted">No calls logged yet.</p> : (
                  <ul className="divide-y divide-line">
                    {l.calls.map((c: any) => (
                      <li key={c.id} className="py-3">
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge tone={callTone[c.status]}>{config.call_statuses[c.status]}</Badge>
                          <span className="text-xs text-muted">{fmtDateTime(c.called_at)}{c.member_name ? ' · ' + c.member_name : ''}</span>
                        </div>
                        {c.notes && <p className="mt-1 text-sm">{c.notes}</p>}
                        {c.next_call_at && <p className="mt-1 text-xs text-muted">Next call: {fmtDateTime(c.next_call_at)}</p>}
                      </li>
                    ))}
                  </ul>
                )
              )}
              {tab === 'followups' && (
                <div>
                  {can('leads.edit') && <Button size="sm" className="mb-3" icon={<CalendarClock className="h-4 w-4" />} onClick={() => setFu({ open: true })}>Schedule follow-up</Button>}
                  {l.followups.length === 0 ? <p className="py-6 text-center text-sm text-muted">No follow-ups yet.</p> : (
                    <ul className="divide-y divide-line">
                      {l.followups.map((f: any) => (
                        <li key={f.id} className="flex items-center gap-3 py-3">
                          <div className="flex-1">
                            <p className="text-sm font-medium">{fmtDateTime(f.due_at)}</p>
                            <p className="text-xs text-muted">{f.note || 'Follow-up'}{f.assigned_name ? ' · ' + f.assigned_name : ''}</p>
                          </div>
                          <Badge tone={f.status === 'done' ? 'green' : f.status === 'pending' && dayjs(f.due_at).isBefore(dayjs()) ? 'red' : f.status === 'pending' ? 'amber' : 'gray'}>
                            {f.status === 'pending' && dayjs(f.due_at).isBefore(dayjs()) ? 'Overdue' : f.status === 'done' ? 'Done' : f.status === 'pending' ? 'Pending' : 'Cancelled'}
                          </Badge>
                          {f.status === 'pending' && can('leads.edit') && <Button size="xs" onClick={() => completeFu.mutate(f.id)}>Done</Button>}
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              )}
              {tab === 'quotes' && (
                <div className="space-y-4">
                  {l.quotations.length === 0 && l.bookings.length === 0 && <p className="py-6 text-center text-sm text-muted">No quotations yet.</p>}
                  {l.quotations.map((qt: any) => (
                    <Link key={qt.id} to={`/quotations/${qt.id}`} className="flex items-center gap-3 rounded-lg border border-line p-3 hover:bg-subtle">
                      <FileText className="h-4 w-4 text-muted" />
                      <div className="flex-1"><p className="text-sm font-medium">{qt.number} · {qt.title}</p><p className="text-xs text-muted">{fmtDate(qt.created_at)}</p></div>
                      <span className="tabular text-sm font-medium">{inr(qt.total)}</span>
                      <Badge tone={quoteTone[qt.status]}>{config.quotation_statuses[qt.status]}</Badge>
                    </Link>
                  ))}
                  {l.bookings.map((b: any) => (
                    <Link key={b.id} to={`/bookings/${b.id}`} className="flex items-center gap-3 rounded-lg border border-line p-3 hover:bg-subtle">
                      <Briefcase className="h-4 w-4 text-muted" />
                      <div className="flex-1"><p className="text-sm font-medium">{b.number} · {b.title}</p></div>
                      <Badge tone="green">{config.booking_statuses[b.status]}</Badge>
                    </Link>
                  ))}
                </div>
              )}
            </div>
          </Card>
        </div>
      </div>

      <LeadFormModal open={edit} onClose={() => setEdit(false)} lead={l} />
      <SubmissionModal id={enquiry} onClose={() => setEnquiry(null)} />
      <LogCallModal open={call} onClose={() => setCall(false)} lead={l} />
      <FollowupModal open={fu.open} onClose={() => setFu({ open: false })} leadId={l.id} followup={fu.item} />
      <Modal open={lost} onClose={() => setLost(false)} title="Mark lead as lost" size="sm" footer={<><Button onClick={() => setLost(false)}>Cancel</Button><Button variant="danger" onClick={() => (move.mutate({ stage: 'lost', lost_reason: lostReason }), setLost(false))}>Mark lost</Button></>}>
        <Field label="Why was this lead lost?">
          <Input value={lostReason} onChange={(e) => setLostReason(e.target.value)} placeholder="e.g. Budget too high" list="lost-reasons" autoFocus />
          <datalist id="lost-reasons">{['Budget too high', 'Booked another photographer', 'Date not available', 'Event cancelled', 'No response after follow-ups'].map((r) => <option key={r} value={r} />)}</datalist>
        </Field>
      </Modal>
    </div>
  );
}
