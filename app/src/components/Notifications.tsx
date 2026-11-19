import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { AlarmClock, Bell, BellRing, CalendarClock, CheckCheck, CheckSquare, Mail, Phone, Volume2, VolumeX, X } from 'lucide-react';
import { api } from '@/lib/api';
import { dayjs, fmtDateTime } from '@/lib/format';
import { armAudio, audioReady, ring } from '@/lib/ring';
import { Button, EmptyState, Spinner, Tabs, Toggle, useUi } from './ui';

export type Notice = {
  id: number;
  kind: 'task' | 'followup';
  item_id: number;
  event: 'assigned' | 'reminder' | 'due';
  title: string;
  body?: string | null;
  due_at?: string | null;
  path: string;
  created_at: string;
  read: boolean;
  alerted: boolean;
  email_status: string;
};
export type DueItem = { kind: 'task' | 'followup'; id: number; title: string; note?: string; due_at: string; path: string };
export type Prefs = { email_assigned: boolean; email_reminder: boolean; popup: boolean; sound: boolean };
type Feed = { items: Notice[]; unread: number; due: DueItem[]; prefs: Prefs; lead_minutes: number; now: string };

const KEY = ['notifications'];
/** Alerts older than this only go to the notification centre (no pop-up, no ring). */
const FRESH_MINUTES = 120;

/** Server clock offset, so "due in 5 min" is right even if the device clock is off. */
function useServerNow(feed?: Feed) {
  const offset = useRef(0);
  useEffect(() => {
    if (feed?.now) offset.current = dayjs(feed.now).diff(dayjs());
  }, [feed?.now]);
  return useCallback(() => dayjs().add(offset.current, 'millisecond'), []);
}

export function useNotifications() {
  return useQuery<Feed>({
    queryKey: KEY,
    queryFn: () => api.get<Feed>('notifications'),
    refetchInterval: 60_000,
    refetchIntervalInBackground: true,
    refetchOnWindowFocus: true,
    staleTime: 20_000,
  });
}

export function dueLabel(due: string | null | undefined, now: dayjs.Dayjs) {
  if (!due) return 'No deadline';
  const d = dayjs(due);
  const mins = d.diff(now, 'minute');
  if (mins > 0 && mins < 60) return `Due in ${mins} min · ${d.format('hh:mm A')}`;
  if (mins >= 60 && d.isSame(now, 'day')) return `Due today · ${d.format('hh:mm A')}`;
  if (mins > -1 && mins <= 0) return `Due now · ${d.format('hh:mm A')}`;
  if (mins < 0) return `Overdue · ${fmtDateTime(due)}`;
  return `Due ${fmtDateTime(due)}`;
}

const eventLabel: Record<Notice['event'], string> = { assigned: 'Scheduled for you', reminder: 'Reminder', due: 'Due now' };

function KindIcon({ kind, className }: { kind: string; className?: string }) {
  return kind === 'task' ? <CheckSquare className={className} /> : <Phone className={className} />;
}

/* ---------------------------------------------------------------------- */
/* Pop-up alerts + ring                                                      */
/* ---------------------------------------------------------------------- */

/**
 * Watches the feed and shows each new reminder once: the first tab to claim
 * it on the server shows it (and rings), other tabs and devices stay quiet.
 */
export function AlertWatcher() {
  const q = useNotifications();
  const qc = useQueryClient();
  const nav = useNavigate();
  const now = useServerNow(q.data);
  const [shown, setShown] = useState<(Notice & { rang: boolean; muted: boolean })[]>([]);
  const tried = useRef(new Set<number>());

  useEffect(() => armAudio(), []);

  useEffect(() => {
    const feed = q.data;
    if (!feed || !feed.prefs.popup) return;
    const fresh = feed.items.filter((n) => !n.alerted && !n.read && !tried.current.has(n.id) && now().diff(dayjs(n.created_at), 'minute') <= FRESH_MINUTES);
    fresh.forEach(async (n) => {
      tried.current.add(n.id);
      let claimed = false;
      try {
        claimed = (await api.post<{ claimed: boolean }>(`notifications/${n.id}/alert`)).claimed;
      } catch {
        claimed = false;
      }
      if (!claimed) return;
      // Ring for reminders and due items only, never for stale ones, and only
      // when the person turned sound on and the browser allows audio.
      const stale = n.due_at ? now().diff(dayjs(n.due_at), 'minute') > 15 : false;
      const wantSound = feed.prefs.sound && n.event !== 'assigned' && !stale;
      const rang = wantSound ? ring() : false;
      setShown((s) => [...s.filter((x) => x.id !== n.id), { ...n, rang, muted: wantSound && !rang }].slice(-3));
      if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
        try {
          const dn = new Notification(eventLabel[n.event] + ': ' + n.title, { body: dueLabel(n.due_at, now()), tag: 'pcrm-' + n.id });
          dn.onclick = () => {
            window.focus();
            nav(n.path);
            dn.close();
          };
        } catch {
          /* desktop notifications unavailable */
        }
      }
      if (n.event === 'assigned') setTimeout(() => setShown((s) => s.filter((x) => x.id !== n.id)), 12_000);
    });
  }, [q.data, now, nav]);

  const markRead = async (ids: number[]) => {
    try {
      await api.post('notifications/read', { ids });
    } finally {
      qc.invalidateQueries({ queryKey: KEY });
    }
  };
  const close = (n: Notice) => {
    setShown((s) => s.filter((x) => x.id !== n.id));
    markRead([n.id]);
  };
  const open = (n: Notice) => {
    close(n);
    nav(n.path);
  };

  if (!shown.length) return null;
  return createPortal(
    <div className="pointer-events-none fixed right-3 top-16 z-[70] flex w-[min(94vw,360px)] flex-col gap-2 sm:right-5" role="region" aria-label="Reminders">
      {shown.map((n) => (
        <div key={n.id} role="alert" className="pointer-events-auto overflow-hidden rounded-xl border border-line bg-surface shadow-pop">
          <div className={clsx('h-1', n.event === 'due' ? 'bg-red-500' : n.event === 'reminder' ? 'bg-amber-500' : 'bg-accent')} />
          <div className="flex items-start gap-3 p-3.5">
            <span className={clsx('mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', n.event === 'due' ? 'bg-red-50 text-red-600 dark:bg-red-500/10' : n.event === 'reminder' ? 'bg-amber-50 text-amber-600 dark:bg-amber-500/10' : 'bg-accent-soft text-accent-ink')}>
              {n.event === 'assigned' ? <CalendarClock className="h-4 w-4" /> : <BellRing className="h-4 w-4" />}
            </span>
            <div className="min-w-0 flex-1">
              <p className="text-2xs font-semibold uppercase tracking-wider text-muted">{eventLabel[n.event]} · {n.kind === 'task' ? 'Task' : 'Follow-up'}</p>
              <p className="mt-0.5 text-sm font-semibold leading-snug">{n.title}</p>
              <p className={clsx('mt-0.5 text-xs', n.event === 'due' ? 'font-medium text-red-600' : 'text-muted')}>{dueLabel(n.due_at, now())}</p>
              {n.body && <p className="mt-1 line-clamp-2 text-xs text-faint">{n.body}</p>}
              {n.muted && <p className="mt-1.5 flex items-center gap-1 text-2xs text-faint"><VolumeX className="h-3 w-3" /> Sound is blocked until you click anywhere on the page.</p>}
              <div className="mt-2.5 flex gap-2">
                <Button size="xs" variant="primary" onClick={() => open(n)}>Open</Button>
                <Button size="xs" onClick={() => close(n)}>Dismiss</Button>
              </div>
            </div>
            <button onClick={() => close(n)} className="rounded p-0.5 text-faint hover:text-ink" aria-label="Close reminder"><X className="h-4 w-4" /></button>
          </div>
        </div>
      ))}
    </div>,
    document.body,
  );
}

/* ---------------------------------------------------------------------- */
/* Bell + notification centre                                                */
/* ---------------------------------------------------------------------- */

export function NotificationBell() {
  const q = useNotifications();
  const qc = useQueryClient();
  const nav = useNavigate();
  const now = useServerNow(q.data);
  const [open, setOpen] = useState(false);
  const [tab, setTab] = useState<'due' | 'alerts' | 'prefs'>('due');
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const h = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false);
    const k = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('mousedown', h);
    document.addEventListener('keydown', k);
    return () => {
      document.removeEventListener('mousedown', h);
      document.removeEventListener('keydown', k);
    };
  }, [open]);

  const feed = q.data;
  const overdue = useMemo(() => (feed?.due || []).filter((d) => dayjs(d.due_at).isBefore(now())).length, [feed, now]);
  const badge = (feed?.unread || 0) + overdue;

  const go = (path: string, ids?: number[]) => {
    setOpen(false);
    if (ids?.length) api.post('notifications/read', { ids }).finally(() => qc.invalidateQueries({ queryKey: KEY }));
    nav(path);
  };
  const readAll = async () => {
    await api.post('notifications/read', { all: true });
    qc.invalidateQueries({ queryKey: KEY });
  };

  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen((o) => !o)} className="relative rounded-lg p-2 text-muted hover:bg-subtle hover:text-ink" aria-label={`Notifications${badge ? ` (${badge})` : ''}`} title="Reminders and notifications">
        <Bell className="h-[18px] w-[18px]" />
        {badge > 0 && <span className="absolute right-1 top-1 min-w-[16px] rounded-full bg-red-500 px-1 text-center text-[10px] font-semibold leading-4 text-white">{badge > 99 ? '99+' : badge}</span>}
      </button>
      {open && (
        <div className="fixed inset-x-2 top-14 z-40 rounded-xl border border-line bg-surface shadow-pop sm:absolute sm:inset-x-auto sm:right-0 sm:top-auto sm:mt-1 sm:w-[400px]">
          <div className="flex items-center justify-between px-3 pt-2">
            <p className="text-sm font-semibold">Reminders</p>
            <div className="flex items-center gap-1">
              {tab === 'alerts' && !!feed?.unread && <Button size="xs" variant="ghost" icon={<CheckCheck className="h-3.5 w-3.5" />} onClick={readAll}>Mark all read</Button>}
              <button className="rounded p-1 text-faint hover:text-ink" onClick={() => setOpen(false)} aria-label="Close"><X className="h-4 w-4" /></button>
            </div>
          </div>
          <div className="px-3"><Tabs value={tab} onChange={setTab} tabs={[['due', 'Due', feed?.due.length || null], ['alerts', 'Alerts', feed?.unread || null], ['prefs', 'Preferences']]} /></div>
          <div className="scrollbar-thin max-h-[min(70vh,460px)] overflow-y-auto">
            {q.isLoading ? (
              <div className="flex justify-center py-10"><Spinner /></div>
            ) : q.isError ? (
              <p className="p-4 text-sm text-red-600">Could not load reminders. <button className="underline" onClick={() => q.refetch()}>Retry</button></p>
            ) : tab === 'due' ? (
              <DueList items={feed!.due} now={now()} onOpen={(d) => go(d.path)} />
            ) : tab === 'alerts' ? (
              <AlertList items={feed!.items} now={now()} onOpen={(n) => go(n.path, n.read ? [] : [n.id])} />
            ) : (
              <div className="p-3"><NotificationPrefs compact /></div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function DueList({ items, now, onOpen }: { items: DueItem[]; now: dayjs.Dayjs; onOpen: (d: DueItem) => void }) {
  if (!items.length) return <EmptyState icon={<AlarmClock className="h-5 w-5" />} title="Nothing due" description="Your tasks and follow-ups due today and anything overdue show up here." className="py-8" />;
  return (
    <ul className="divide-y divide-line">
      {items.map((d) => {
        const od = dayjs(d.due_at).isBefore(now);
        return (
          <li key={d.kind + d.id}>
            <button onClick={() => onOpen(d)} className="flex w-full items-start gap-3 px-3 py-2.5 text-left hover:bg-subtle">
              <KindIcon kind={d.kind} className={clsx('mt-0.5 h-4 w-4 shrink-0', od ? 'text-red-600' : 'text-muted')} />
              <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium">{d.title}</span>
                <span className={clsx('block text-xs', od ? 'font-medium text-red-600' : 'text-muted')}>{dueLabel(d.due_at, now)}</span>
                {d.note && <span className="block truncate text-xs text-faint">{d.note}</span>}
              </span>
            </button>
          </li>
        );
      })}
    </ul>
  );
}

function AlertList({ items, now, onOpen }: { items: Notice[]; now: dayjs.Dayjs; onOpen: (n: Notice) => void }) {
  if (!items.length) return <EmptyState icon={<Bell className="h-5 w-5" />} title="No notifications yet" description="When a task or follow-up is scheduled for you, or is coming up, it appears here." className="py-8" />;
  return (
    <ul className="divide-y divide-line">
      {items.map((n) => (
        <li key={n.id}>
          <button onClick={() => onOpen(n)} className={clsx('flex w-full items-start gap-3 px-3 py-2.5 text-left hover:bg-subtle', !n.read && 'bg-accent-soft/40')}>
            <KindIcon kind={n.kind} className="mt-0.5 h-4 w-4 shrink-0 text-muted" />
            <span className="min-w-0 flex-1">
              <span className="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wider text-muted">
                {eventLabel[n.event]}
                {n.email_status === 'sent' && <Mail className="h-3 w-3" aria-label="Emailed" />}
              </span>
              <span className={clsx('block truncate text-sm', !n.read ? 'font-semibold' : 'font-medium')}>{n.title}</span>
              <span className="block text-xs text-muted">{dueLabel(n.due_at, now)}</span>
              <span className="block text-2xs text-faint">{dayjs(n.created_at).from(now)}</span>
            </span>
            {!n.read && <span className="mt-2 h-2 w-2 shrink-0 rounded-full bg-accent" aria-label="Unread" />}
          </button>
        </li>
      ))}
    </ul>
  );
}

/* ---------------------------------------------------------------------- */
/* Preferences (bell panel and Settings → My profile)                        */
/* ---------------------------------------------------------------------- */

export function NotificationPrefs({ compact }: { compact?: boolean }) {
  const q = useNotifications();
  const qc = useQueryClient();
  const { toast } = useUi();
  const prefs = q.data?.prefs;
  const [busy, setBusy] = useState(false);
  const [perm, setPerm] = useState(typeof Notification !== 'undefined' ? Notification.permission : 'unsupported');

  const set = async (k: keyof Prefs, v: boolean) => {
    if (!q.data) return;
    qc.setQueryData<Feed>(KEY, { ...q.data, prefs: { ...q.data.prefs, [k]: v } });
    try {
      await api.put('notifications/prefs', { [k]: v });
      if (k === 'sound' && v) ring();
    } catch (e: any) {
      toast(e.message, 'error');
      qc.invalidateQueries({ queryKey: KEY });
    }
  };
  const testSound = () => {
    if (!ring()) toast('Your browser is blocking sound. Click anywhere on the page once, then try again.', 'info');
  };
  const testEmail = async () => {
    setBusy(true);
    try {
      const r = await api.post<{ to: string }>('notifications/test-email');
      toast(`Test email sent to ${r.to}. Check your inbox and spam folder.`);
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy(false);
  };
  const askDesktop = async () => {
    if (typeof Notification === 'undefined') return;
    setPerm(await Notification.requestPermission());
  };

  if (!prefs) return q.isLoading ? <Spinner /> : null;
  const row = 'flex items-start justify-between gap-4 py-2.5';
  return (
    <div className={clsx('divide-y divide-line', !compact && 'max-w-xl')}>
      <div className={row}>
        <div><p className="text-sm font-medium">Email when something is scheduled for me</p><p className="text-xs text-muted">A task or follow-up is assigned to you, or rescheduled.</p></div>
        <Toggle checked={prefs.email_assigned} onChange={(v) => set('email_assigned', v)} />
      </div>
      <div className={row}>
        <div><p className="text-sm font-medium">Email reminder before it's due</p><p className="text-xs text-muted">Sent about {q.data!.lead_minutes} minutes ahead (set by your studio).</p></div>
        <Toggle checked={prefs.email_reminder} onChange={(v) => set('email_reminder', v)} />
      </div>
      <div className={row}>
        <div><p className="text-sm font-medium">Pop-up alerts in ShootPilot</p><p className="text-xs text-muted">Missed alerts always stay under the bell.</p></div>
        <Toggle checked={prefs.popup} onChange={(v) => set('popup', v)} />
      </div>
      <div className={row}>
        <div>
          <p className="flex items-center gap-1.5 text-sm font-medium">{prefs.sound ? <Volume2 className="h-4 w-4" /> : <VolumeX className="h-4 w-4" />} Ring sound</p>
          <p className="text-xs text-muted">Rings once per reminder, only after you've clicked on the page. {!audioReady() && prefs.sound ? 'Click anywhere to allow sound.' : ''}</p>
          <button className="mt-1 text-xs font-medium text-accent-ink hover:underline" onClick={testSound}>Play test sound</button>
        </div>
        <Toggle checked={prefs.sound} onChange={(v) => set('sound', v)} />
      </div>
      {perm !== 'unsupported' && (
        <div className={row}>
          <div><p className="text-sm font-medium">Desktop notifications</p><p className="text-xs text-muted">{perm === 'granted' ? 'On. You see reminders even when ShootPilot is in another tab.' : perm === 'denied' ? 'Blocked in your browser settings for this site.' : 'Show reminders when ShootPilot is in a background tab.'}</p></div>
          {perm === 'default' && <Button size="xs" onClick={askDesktop}>Allow</Button>}
        </div>
      )}
      <div className={row}>
        <div><p className="text-sm font-medium">Check email delivery</p><p className="text-xs text-muted">Sends a sample reminder to your login email.</p></div>
        <Button size="xs" loading={busy} onClick={testEmail}>Send test</Button>
      </div>
    </div>
  );
}
