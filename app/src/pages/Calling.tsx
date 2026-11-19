import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Bell, CalendarClock, CheckCircle2, MessageCircle, Phone, PhoneCall, PhoneForwarded, PhoneMissed, PhoneOff } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { dayjs, fmtDate, fmtDateTime, fromNow, phoneDisplay, telLink } from '@/lib/format';
import { callTone, stageTone } from '@/lib/tones';
import { Badge, Button, Card, EmptyState, ErrorState, PageHeader, StatCard, TableSkeleton, Tabs, Toggle, useUi, Pagination } from '@/components/ui';
import { FollowupModal, LogCallModal, WhatsAppModal } from '@/components/shared';

type View = 'today' | 'overdue' | 'not_called' | 'callbacks' | 'upcoming' | 'followups' | 'history';

export default function Calling() {
  const { config } = useAuth();
  const { toast } = useUi();
  const qc = useQueryClient();
  const [view, setView] = useState<View>('today');
  const [mine, setMine] = useState(false);
  const [page, setPage] = useState(1);
  const [callLead, setCallLead] = useState<any>(null);
  const [waLead, setWaLead] = useState<any>(null);
  const [fu, setFu] = useState<{ leadId: number; item?: any } | null>(null);
  const summary = useQuery({ queryKey: ['calling-summary'], queryFn: () => api.get('calling/summary') });
  const listView = !['followups', 'history'].includes(view);
  const list = useQuery({ queryKey: ['calling', view, mine], queryFn: () => api.get<any[]>('calling/list', { view, mine: mine ? 1 : '' }), enabled: listView });
  const fups = useQuery({ queryKey: ['followups', 'pending-all', mine], queryFn: () => api.get<any[]>('followups', { view: 'all', mine: mine ? 1 : '' }), enabled: view === 'followups' });
  const hist = useQuery({ queryKey: ['calls', page], queryFn: () => api.get('calls', { page, per_page: 30 }), enabled: view === 'history' });
  const s = summary.data;
  const done = useMutation({
    mutationFn: (id: number) => api.post(`followups/${id}/complete`, {}),
    onSuccess: () => (['followups', 'calling', 'calling-summary', 'dashboard'].forEach((k) => qc.invalidateQueries({ queryKey: [k] })), toast('Follow-up completed')),
  });

  const enableNotif = async () => {
    if (!('Notification' in window)) return toast('This browser does not support notifications.', 'error');
    const p = await Notification.requestPermission();
    toast(p === 'granted' ? 'Reminders on. Keep this tab open to get follow-up alerts.' : 'Notifications were blocked in the browser.', p === 'granted' ? 'success' : 'error');
  };

  const rows = list.data || [];
  return (
    <div>
      <PageHeader
        title="Calling & follow-ups"
        subtitle="Your daily calling list. Log every call so nothing slips."
        actions={
          <>
            <Toggle checked={mine} onChange={setMine} label="Only my leads" />
            {'Notification' in window && Notification.permission !== 'granted' && <Button icon={<Bell className="h-4 w-4" />} onClick={enableNotif}>Enable reminders</Button>}
          </>
        }
      />
      <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <StatCard label="Today's calls" value={s?.today ?? '…'} icon={<PhoneCall className="h-4 w-4" />} tone="indigo" onClick={() => setView('today')} />
        <StatCard label="Overdue" value={s?.overdue ?? '…'} icon={<PhoneMissed className="h-4 w-4" />} tone="red" onClick={() => setView('overdue')} />
        <StatCard label="Never called" value={s?.not_called ?? '…'} icon={<PhoneOff className="h-4 w-4" />} tone="amber" onClick={() => setView('not_called')} />
        <StatCard label="Call-backs" value={s?.callbacks ?? '…'} icon={<PhoneForwarded className="h-4 w-4" />} tone="violet" onClick={() => setView('callbacks')} />
        <StatCard label="Calls made today" value={s?.calls_today ?? '…'} icon={<Phone className="h-4 w-4" />} tone="cyan" onClick={() => setView('history')} />
        <StatCard label="Connected today" value={s?.connected_today ?? '…'} icon={<CheckCircle2 className="h-4 w-4" />} tone="green" />
      </div>
      <Card>
        <div className="px-4 pt-1">
          <Tabs
            value={view}
            onChange={(v) => (setView(v), setPage(1))}
            tabs={[
              ['today', "Today's list", s?.today],
              ['overdue', 'Overdue', s?.overdue],
              ['not_called', 'Not called', s?.not_called],
              ['callbacks', 'Call-backs', s?.callbacks],
              ['upcoming', 'Upcoming'],
              ['followups', 'All follow-ups'],
              ['history', 'Call history'],
            ]}
          />
        </div>
        {listView &&
          (list.error ? (
            <ErrorState error={list.error} onRetry={() => list.refetch()} />
          ) : list.isLoading ? (
            <TableSkeleton />
          ) : rows.length === 0 ? (
            <EmptyState icon={<CheckCircle2 className="h-5 w-5" />} title={view === 'today' ? 'No calls scheduled for today' : 'Nothing here'} description="Great job staying on top of your leads." />
          ) : (
            <ul className="divide-y divide-line">
              {rows.map((l) => {
                const overdue = l.next_followup_at && dayjs(l.next_followup_at).isBefore(dayjs());
                return (
                  <li key={l.id} className="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center">
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <Link to={`/leads/${l.id}`} className="font-medium hover:text-accent-ink">{l.name}</Link>
                        <Badge tone={stageTone[l.stage]}>{config.stages[l.stage]}</Badge>
                        <Badge tone={callTone[l.call_status]}>{config.call_statuses[l.call_status]}</Badge>
                      </div>
                      <p className="mt-0.5 text-xs text-muted">
                        <a href={telLink(l.phone)} className="font-medium text-ink hover:text-accent-ink">{phoneDisplay(l.phone)}</a> · {l.event_type ? config.event_types[l.event_type] : 'Event'} {fmtDate(l.event_date)} · {l.city || '—'}
                        {l.assigned_name ? ' · ' + l.assigned_name : ''}
                      </p>
                      <p className="mt-0.5 text-xs">
                        {l.next_followup_at ? <span className={overdue ? 'font-medium text-red-600' : 'text-muted'}>Follow-up {fmtDateTime(l.next_followup_at)} ({fromNow(l.next_followup_at)})</span> : <span className="text-faint">No follow-up set</span>}
                        {l.last_call_at && <span className="text-faint"> · Last call {fromNow(l.last_call_at)}</span>}
                      </p>
                    </div>
                    <div className="flex shrink-0 flex-wrap gap-1.5">
                      <a href={telLink(l.phone)} className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line px-3 text-xs font-medium hover:bg-subtle"><Phone className="h-3.5 w-3.5 text-accent-ink" /> Call</a>
                      <Button size="sm" variant="primary" onClick={() => setCallLead(l)}>Log call</Button>
                      <Button size="sm" onClick={() => setWaLead(l)} icon={<MessageCircle className="h-3.5 w-3.5 text-green-600" />}>WhatsApp</Button>
                      <Button size="sm" variant="ghost" onClick={() => setFu({ leadId: l.id })} icon={<CalendarClock className="h-3.5 w-3.5" />}>Follow-up</Button>
                    </div>
                  </li>
                );
              })}
            </ul>
          ))}
        {view === 'followups' &&
          (fups.isLoading ? (
            <TableSkeleton />
          ) : !fups.data?.length ? (
            <EmptyState title="No follow-ups yet" />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead className="border-b border-line bg-subtle/50"><tr><th className="th">Due</th><th className="th">Lead</th><th className="th">Note</th><th className="th">Assigned</th><th className="th">Status</th><th className="th" /></tr></thead>
                <tbody className="divide-y divide-line">
                  {fups.data.map((f) => {
                    const od = f.status === 'pending' && dayjs(f.due_at).isBefore(dayjs());
                    return (
                      <tr key={f.id}>
                        <td className={clsx('td whitespace-nowrap text-sm', od && 'font-medium text-red-600')}>{fmtDateTime(f.due_at)}</td>
                        <td className="td"><Link to={`/leads/${f.lead_id}`} className="font-medium hover:text-accent-ink">{f.lead_name}</Link><p className="text-xs text-muted">{phoneDisplay(f.lead_phone)}</p></td>
                        <td className="td max-w-xs truncate text-muted">{f.note || '—'}</td>
                        <td className="td whitespace-nowrap text-muted">{f.assigned_name || '—'}</td>
                        <td className="td"><Badge tone={f.status === 'done' ? 'green' : od ? 'red' : f.status === 'pending' ? 'amber' : 'gray'}>{f.status === 'done' ? 'Done' : od ? 'Overdue' : f.status === 'pending' ? 'Pending' : 'Cancelled'}</Badge></td>
                        <td className="td whitespace-nowrap text-right">
                          {f.status === 'pending' && (
                            <div className="flex justify-end gap-1.5">
                              <Button size="xs" onClick={() => setFu({ leadId: f.lead_id, item: f })}>Reschedule</Button>
                              <Button size="xs" variant="primary" onClick={() => done.mutate(f.id)}>Done</Button>
                            </div>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          ))}
        {view === 'history' &&
          (hist.isLoading ? (
            <TableSkeleton />
          ) : !hist.data?.items.length ? (
            <EmptyState title="No calls logged yet" />
          ) : (
            <>
              <ul className="divide-y divide-line">
                {hist.data.items.map((c: any) => (
                  <li key={c.id} className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-center sm:gap-4">
                    <span className="w-40 shrink-0 text-xs text-muted">{fmtDateTime(c.called_at)}</span>
                    <Link to={`/leads/${c.lead_id}`} className="w-52 shrink-0 truncate text-sm font-medium hover:text-accent-ink">{c.lead_name}</Link>
                    <Badge tone={callTone[c.status]}>{config.call_statuses[c.status]}</Badge>
                    <span className="flex-1 truncate text-sm text-muted">{c.notes}</span>
                    <span className="text-xs text-faint">{c.member_name}</span>
                  </li>
                ))}
              </ul>
              <Pagination page={page} perPage={30} total={hist.data.total} onPage={setPage} />
            </>
          ))}
      </Card>
      <LogCallModal open={!!callLead} onClose={() => setCallLead(null)} lead={callLead} />
      <WhatsAppModal open={!!waLead} onClose={() => setWaLead(null)} ctx={{ lead_id: waLead?.id }} template={waLead?.call_status === 'not_called' ? 'first_contact' : 'followup'} />
      {fu && <FollowupModal open onClose={() => setFu(null)} leadId={fu.leadId} followup={fu.item} />}
    </div>
  );
}
