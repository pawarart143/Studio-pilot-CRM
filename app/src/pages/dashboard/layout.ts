import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export type WidgetMeta = {
  id: string;
  title: string;
  description: string;
  kind: 'stat' | 'card';
  strip: string | null;
  span: number;
  default: boolean;
  source: 'dashboard' | 'widget';
  ranges: { key: string; label: string }[];
  default_range: string | null;
};
export type LayoutItem = { id: string; visible: boolean; range: string | null };
export type LayoutResponse = { widgets: WidgetMeta[]; items: LayoutItem[]; is_default: boolean };

export const LAYOUT_KEY = ['dashboard-layout'];

export function useLayout() {
  return useQuery({ queryKey: LAYOUT_KEY, queryFn: () => api.get<LayoutResponse>('dashboard/layout'), staleTime: 5 * 60_000, retry: 1 });
}

/**
 * The shipped default dashboard, used only if the layout request fails, so the
 * dashboard still shows exactly what it always did. Visibility still follows the
 * same permissions, and the server only sends data the role may see.
 */
const D = (id: string, kind: 'stat' | 'card', perm: string, extra: Partial<WidgetMeta> = {}) => ({ perm, meta: { id, title: id, description: '', kind, strip: null, span: 1, default: true, source: 'dashboard', ranges: [], default_range: null, ...extra } as WidgetMeta });
export const FALLBACK = [
  ...['stat_total_leads', 'stat_new_leads', 'stat_contacted', 'stat_followups_pending', 'stat_interested', 'stat_quotation_sent', 'stat_negotiation', 'stat_booked', 'stat_lost', 'stat_followups_today'].map((id) => D(id, 'stat', 'leads.view', { strip: 'pipeline' })),
  D('stat_revenue', 'stat', 'payments.view', { strip: 'business' }),
  D('stat_pending_payments', 'stat', 'payments.view', { strip: 'business' }),
  D('stat_upcoming_events', 'stat', 'calendar.view', { strip: 'business', default_range: '30d' }),
  D('chart_revenue', 'card', 'payments.view', { span: 2, default_range: '12m' }),
  D('chart_sources', 'card', 'leads.view', { default_range: 'all' }),
  D('chart_conversion', 'card', 'leads.view', { span: 2, default_range: '12m' }),
  D('list_followups', 'card', 'leads.view'),
  D('list_upcoming_events', 'card', 'calendar.view', { span: 2, default_range: '60d' }),
  D('list_tasks', 'card', 'tasks.view'),
  D('list_activity', 'card', 'leads.view'),
];

/** True when the widget can use the shared GET /dashboard data. */
export const usesMain = (m: WidgetMeta, it: LayoutItem) => m.source === 'dashboard' && (it.range ?? null) === (m.default_range ?? null);

export function useWidgetData<T = any>(m: WidgetMeta, it: LayoutItem) {
  const own = !usesMain(m, it);
  const q = useQuery({
    // Under ['dashboard'] so anything that refreshes the dashboard refreshes widgets too.
    queryKey: ['dashboard', 'widget', m.id, it.range],
    queryFn: () => api.get<{ data: T }>(`dashboard/widgets/${m.id}`, it.range ? { range: it.range } : undefined),
    enabled: own,
  });
  return { own, data: q.data?.data, loading: own && q.isLoading, error: own ? q.error : null, refetch: q.refetch };
}

export const rangeLabel = (m: WidgetMeta, key: string | null) => m.ranges.find((r) => r.key === key)?.label || '';
