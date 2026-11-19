import { createContext, useContext, type ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from './api';

export type Dict = Record<string, string>;
export type Plan = { key?: string; label: string; tagline?: string; price: number; price_yearly?: number; trial_days?: number; members: number; leads_per_month: number; features?: string[]; paid?: boolean; reports: boolean; whatsapp_api: boolean };
export type BillingStatus = {
  plan: string;
  label: string;
  state: 'trial' | 'trial_ended' | 'active' | 'past_due' | 'lapsed' | 'manual';
  ends_at: string | null;
  days_left: number | null;
  read_only: boolean;
  after_expiry: 'read_only' | 'starter_limits';
  limits_plan: string;
  members: number;
  leads_per_month: number;
  features: string[];
  billing_cycle: string | null;
};
export type AppConfig = {
  stages: Dict;
  sources: Dict;
  event_types: Dict;
  call_statuses: Dict;
  priorities: Dict;
  quotation_statuses: Dict;
  service_categories: Dict;
  booking_statuses: Dict;
  payment_methods: Dict;
  expense_categories: Dict;
  task_categories: Dict;
  task_statuses: Dict;
  roles: Dict;
  event_roles: Dict;
  payment_types: Dict;
  payment_statuses: Dict;
  team_roles: Dict;
  availability: Dict;
  deliverable_statuses: Dict;
  deliverable_progress: Record<string, number>;
  deliverable_presets: string[];
  plans: Record<string, Plan>;
  crew_categories: Dict;
  crew_availability: Dict;
  crew_assignment_statuses: Dict;
  crew_payment_statuses: Dict;
  enquiry_services: Dict;
  enquiry_budgets: Dict;
  enquiry_heard_from: Dict;
  enquiry_statuses: Dict;
  quote_coverage_roles: Dict;
  quote_item_kinds: Dict;
  quote_deliverable_presets: string[];
};
export type Member = {
  id: number;
  studio_id: number;
  user_id: number | null;
  name: string;
  email?: string;
  phone?: string;
  role: string;
  status: string;
  color?: string;
  photo_url?: string | null;
  team_role?: string | null;
  specialization?: string | null;
  city?: string | null;
  availability?: string;
  joining_date?: string | null;
  notes?: string | null;
};
export type Studio = {
  id: number;
  name: string;
  email?: string;
  phone?: string;
  address?: string;
  city?: string;
  state?: string;
  gstin?: string;
  logo_url?: string;
  plan: string;
  plan_info: Plan;
  plan_expires_at?: string | null;
  trial_ends_at?: string | null;
  billing?: BillingStatus;
  status: string;
  settings: Record<string, any>;
};
export type Me = {
  user: { id: number; name: string; email: string } | null;
  is_super?: boolean;
  access_off?: boolean;
  member?: Member | null;
  permissions?: string[];
  studios?: { id: number; name: string; role: string; plan: string; status: string }[];
  studio?: Studio | null;
  config: AppConfig;
};

type Ctx = { me: Me; can: (p: string) => boolean; config: AppConfig; studio: Studio; member: Member; refresh: () => void };
const AuthCtx = createContext<Ctx | null>(null);

export function useMe() {
  return useQuery({ queryKey: ['me'], queryFn: () => api.get<Me>('me'), staleTime: 5 * 60 * 1000 });
}

export function AuthProvider({ me, children }: { me: Me; children: ReactNode }) {
  const qc = useQueryClient();
  const perms = new Set(me.permissions || []);
  const value: Ctx = {
    me,
    can: (p) => perms.has(p),
    config: me.config,
    studio: me.studio as Studio,
    member: me.member as Member,
    refresh: () => qc.invalidateQueries({ queryKey: ['me'] }),
  };
  return <AuthCtx.Provider value={value}>{children}</AuthCtx.Provider>;
}

export function useAuth() {
  const c = useContext(AuthCtx);
  if (!c) throw new Error('useAuth outside provider');
  return c;
}

/** Label lookup helper: label('stages', 'new') → "New Lead" */
export function useLabels() {
  const { config } = useAuth();
  return (group: keyof AppConfig, key?: string | null) => {
    if (!key) return '—';
    const g = config[group] as any;
    return (g && g[key]) || key;
  };
}
