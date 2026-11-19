import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, CalendarCheck, RefreshCw } from 'lucide-react';
import { api } from '@/lib/api';
import { useUrlState } from '@/lib/hooks';
import { fmtDateTime, fromNow } from '@/lib/format';
import { Badge, Button, Checkbox, ErrorState, Field, Modal, Select, Skeleton, useUi } from '@/components/ui';

type Kind = 'bookings' | 'tasks' | 'followups' | 'payments';
const kindInfo: [Kind, string, string][] = [
  ['bookings', 'Bookings', 'Every event date and venue of a booking, with the assigned team. Multi-day weddings get one event per day.'],
  ['tasks', 'Tasks', 'Tasks that have a deadline, at their due time.'],
  ['followups', 'Calls & follow-ups', 'Scheduled follow-up calls, including the "next call" you set when logging a call.'],
  ['payments', 'Payment reminders', 'Installment due dates that still have a balance. Removed once paid.'],
];
const reminderOptions: Record<string, string> = { '0': 'At the time', '10': '10 minutes before', '15': '15 minutes before', '30': '30 minutes before', '60': '1 hour before', '120': '2 hours before', '1440': '1 day before' };
const syncedKey: Record<Kind, string> = { bookings: 'booking', tasks: 'task', followups: 'followup', payments: 'payment' };
const resultText: Record<string, [string, 'success' | 'error']> = {
  connected: ['Google Calendar connected. Your CRM items are being added now.', 'success'],
  cancelled: ['Google connection was cancelled.', 'error'],
  state: ['That Google sign-in link expired or was opened in another account. Please try again.', 'error'],
  token: ['Google did not accept the sign-in. Please try again.', 'error'],
  scope: ['Please tick "See, edit, share and delete all events" on the Google screen so ShootPilot can add events.', 'error'],
  no_refresh: ['Google did not give ShootPilot offline access. Remove ShootPilot from your Google account permissions and connect again.', 'error'],
  forbidden: ['Only studio owners and admins can connect Google Calendar.', 'error'],
  not_configured: ['Google Calendar is not set up on this site yet.', 'error'],
};

export default function GoogleCalendarTab() {
  const { toast } = useUi();
  const qc = useQueryClient();
  const [f, setF] = useUrlState({ gcal: '' });
  const q = useQuery({ queryKey: ['gcal'], queryFn: () => api.get<any>('gcal'), refetchInterval: (d: any) => (d?.state?.data?.stats?.pending ? 5000 : false) });
  const d = q.data;
  const live = d?.connected && d.status === 'connected';
  const cals = useQuery({ queryKey: ['gcal-calendars'], queryFn: () => api.get<any[]>('gcal/calendars'), enabled: !!live, retry: false });
  const [busy, setBusy] = useState('');
  const [disc, setDisc] = useState(false);
  const [removeEvents, setRemoveEvents] = useState(false);

  useEffect(() => {
    if (!f.gcal) return;
    const r = resultText[f.gcal];
    if (r) toast(r[0], r[1]);
    setF({ gcal: '' });
  }, [f.gcal]); // eslint-disable-line react-hooks/exhaustive-deps

  const run = async (name: string, fn: () => Promise<any>, ok?: string) => {
    setBusy(name);
    try {
      const r = await fn();
      if (r && typeof r === 'object' && 'connected' in r) qc.setQueryData(['gcal'], r);
      else await q.refetch();
      if (ok) toast(ok);
      return r;
    } catch (e: any) {
      toast(e.message, 'error');
    } finally {
      setBusy('');
    }
  };
  const connect = () => run('connect', async () => { const r = await api.post<{ url: string }>('gcal/connect'); window.location.href = r.url; return null; });
  const save = (body: any, ok = 'Saved') => run('save', () => api.put('gcal', body), ok);

  if (q.isLoading) return <div className="max-w-3xl space-y-3"><Skeleton className="h-6 w-56" /><Skeleton className="h-24 w-full" /></div>;
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;

  return (
    <div className="grid max-w-5xl gap-8 lg:grid-cols-[1.3fr_1fr]">
      <section>
        <div className="flex flex-wrap items-center gap-2">
          <h3 className="text-sm font-semibold">Google Calendar</h3>
          {!d.connected ? <Badge tone="gray">Not connected</Badge> : live ? <Badge tone="green" dot>Connected</Badge> : <Badge tone="red" dot>Reconnect needed</Badge>}
        </div>
        <p className="mt-1 text-sm text-muted">ShootPilot adds your bookings, tasks, follow-up calls and payment due dates to your Google Calendar automatically, and keeps them updated when you edit them here. Changes made in Google are not copied back. ShootPilot only edits events it created.</p>

        {!d.configured ? (
          <div className="mt-4 rounded-xl border border-line bg-subtle/50 p-4 text-sm">
            <p className="font-medium">Not available yet</p>
            <p className="mt-1 text-muted">{d.super_admin ? 'Add a Google OAuth client in Super Admin → Google sign-in first (it is shared with Google Calendar). The card there shows the redirect URI to add.' : 'The ShootPilot platform admin needs to switch on Google for this site first.'}</p>
          </div>
        ) : !d.connected ? (
          <div className="mt-5">
            <Button variant="primary" icon={<CalendarCheck className="h-4 w-4" />} loading={busy === 'connect'} onClick={connect}>Connect Google Calendar</Button>
            <p className="mt-2 text-xs text-muted">You'll sign in with Google and allow ShootPilot to add events to your calendar.</p>
          </div>
        ) : (
          <div className="mt-5 space-y-5">
            {!live && (
              <div className="flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-200">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                <div className="min-w-0">
                  <p className="font-medium">Google stopped accepting ShootPilot's sign-in</p>
                  <p className="mt-0.5">{d.last_error || 'Reconnect to resume syncing.'} Your CRM keeps working and nothing is lost: {d.stats?.pending || 0} pending change(s) will be sent after you reconnect.</p>
                  <Button className="mt-3" variant="primary" size="sm" loading={busy === 'connect'} onClick={connect}>Reconnect Google</Button>
                </div>
              </div>
            )}
            <div className="text-sm">
              <span className="text-muted">Google account: </span><b>{d.google_email || '—'}</b>
            </div>
            <Field label="Add events to" hint={cals.isError ? 'Could not load your calendars from Google.' : 'Tip: create a separate "Shoots" calendar in Google to keep studio events apart.'}>
              <Select
                value={d.calendar_id}
                disabled={!live || cals.isLoading || busy === 'save'}
                onChange={(e) => save({ calendar_id: e.target.value }, 'Calendar changed. Events are moving to the new calendar.')}
                options={cals.data ? cals.data.map((c: any) => [c.id, c.primary ? `${c.name} (main calendar)` : c.name] as [string, string]) : [[d.calendar_id, d.calendar_name || (d.calendar_id === 'primary' ? 'Main calendar' : d.calendar_id)]]}
              />
            </Field>
            <div>
              <p className="mb-2 text-sm font-medium">What to add</p>
              <div className="space-y-3">
                {kindInfo.map(([k, label, help]) => (
                  <div key={k} className="flex items-start justify-between gap-3">
                    <Checkbox checked={!!d.kinds?.[k]} onChange={(v) => save({ kinds: { [k]: v } }, v ? `${label} will be added` : `${label} will be removed from Google`)} label={<span><span className="font-medium text-ink">{label}</span><span className="block text-xs text-muted">{help}</span></span>} />
                    <span className="shrink-0 text-xs tabular text-muted">{d.stats?.synced?.[syncedKey[k]] || 0} in Google</span>
                  </div>
                ))}
              </div>
            </div>
            <Field label="Reminder for timed events" hint="Bookings also get a reminder 1 day before. All-day items remind you at 9 AM the day before.">
              <Select value={String(d.reminder_minutes)} onChange={(e) => save({ reminder_minutes: Number(e.target.value) })} options={reminderOptions} />
            </Field>
            <div className="flex flex-wrap gap-2 border-t border-line pt-4">
              <Button icon={<RefreshCw className="h-4 w-4" />} loading={busy === 'sync'} disabled={!live} onClick={() => run('sync', () => api.post('gcal/sync'), 'Sync finished')}>Sync now</Button>
              <Button variant="ghost" onClick={() => setDisc(true)}>Disconnect</Button>
            </div>
          </div>
        )}
      </section>

      {d.connected && (
        <section className="text-sm">
          <h3 className="font-semibold">Sync status</h3>
          <dl className="mt-2 space-y-1.5 text-muted">
            <div className="flex justify-between gap-3"><dt>Last sync</dt><dd className="text-ink">{d.last_sync_at ? `${fmtDateTime(d.last_sync_at)} (${fromNow(d.last_sync_at)})` : 'Not yet'}</dd></div>
            <div className="flex justify-between gap-3"><dt>Waiting to send</dt><dd className="tabular text-ink">{d.stats?.pending || 0}</dd></div>
            <div className="flex justify-between gap-3"><dt>Failed</dt><dd className="tabular text-ink">{d.stats?.failed || 0}</dd></div>
          </dl>
          {d.stats?.errors?.length > 0 && (
            <div className="mt-4">
              <div className="flex items-center justify-between">
                <p className="font-medium">Recent problems</p>
                {d.stats.failed > 0 && live && <Button size="xs" loading={busy === 'retry'} onClick={() => run('retry', () => api.post('gcal/retry'), 'Retried')}>Retry all</Button>}
              </div>
              <ul className="mt-2 divide-y divide-line rounded-xl border border-line">
                {d.stats.errors.map((e: any) => (
                  <li key={e.entity_key} className="px-3 py-2">
                    <div className="flex items-center justify-between gap-2"><span className="truncate text-ink">{e.label}</span><Badge tone={e.status === 'failed' ? 'red' : 'amber'}>{e.status === 'failed' ? 'Failed' : `Retrying (${e.attempts})`}</Badge></div>
                    <p className="mt-0.5 truncate text-xs text-muted" title={e.last_error}>{e.last_error}</p>
                  </li>
                ))}
              </ul>
            </div>
          )}
          <p className="mt-4 text-xs text-muted">Changes usually reach Google within a minute. If Google is down, ShootPilot keeps trying for up to a day. Items older than 30 days are left as they are.{d.cron_disabled ? ' WP-Cron is turned off on this site, so make sure a server cron job runs wp-cron.php every few minutes.' : ''}</p>
        </section>
      )}

      <Modal
        open={disc}
        onClose={() => setDisc(false)}
        title="Disconnect Google Calendar?"
        size="sm"
        footer={<><Button onClick={() => setDisc(false)}>Cancel</Button><Button variant="danger" loading={busy === 'disc'} onClick={async () => { const r = await run('disc', () => api.post('gcal/disconnect', { remove_events: removeEvents })); setDisc(false); if (r) toast(removeEvents ? `Disconnected. ${r.result?.removed || 0} ShootPilot events removed from Google.` : 'Disconnected. Events already in Google were kept.'); }}>Disconnect</Button></>}
      >
        <p className="text-sm text-muted">ShootPilot will stop updating your Google Calendar. Your CRM data is not affected.</p>
        <Checkbox className="mt-4" checked={removeEvents} onChange={setRemoveEvents} label="Also delete the events ShootPilot added (your other Google events are never touched)" />
      </Modal>
    </div>
  );
}
