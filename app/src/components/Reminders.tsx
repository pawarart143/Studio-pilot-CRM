import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { dayjs } from '@/lib/format';
import { useUi } from './ui';

/**
 * Follow-up reminders: polls today's follow-ups and alerts (toast + browser
 * notification when allowed) 10 minutes before each one is due.
 */
export function Reminders() {
  const { can, member } = useAuth();
  const { toast } = useUi();
  const q = useQuery({ queryKey: ['followups', 'reminders'], queryFn: () => api.get<any[]>('followups', { view: 'today', mine: 1 }), enabled: can('leads.view'), refetchInterval: 5 * 60_000 });
  useEffect(() => {
    if (!q.data?.length) return;
    const check = () => {
      let seen: string[] = [];
      try {
        seen = JSON.parse(sessionStorage.getItem('pcrm-reminded') || '[]');
      } catch {
        seen = [];
      }
      const now = dayjs();
      q.data!.forEach((f) => {
        const due = dayjs(f.due_at);
        const key = `${f.id}@${f.due_at}`;
        if (seen.includes(key) || due.diff(now, 'minute') > 10 || due.isBefore(now.subtract(5, 'minute'))) return;
        seen.push(key);
        const msg = `Follow-up with ${f.lead_name} at ${due.format('hh:mm A')}${f.note ? ' — ' + f.note : ''}`;
        toast(msg, 'info');
        if ('Notification' in window && Notification.permission === 'granted') {
          const n = new Notification('Follow-up reminder', { body: msg, tag: key });
          n.onclick = () => {
            window.focus();
            window.location.href = `${window.PCRM.base}/leads/${f.lead_id}`;
          };
        }
      });
      try {
        sessionStorage.setItem('pcrm-reminded', JSON.stringify(seen.slice(-200)));
      } catch {
        /* storage blocked */
      }
    };
    check();
    const t = setInterval(check, 60_000);
    return () => clearInterval(t);
  }, [q.data, toast, member.id]);
  return null;
}
