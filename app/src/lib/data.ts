import { useQuery } from '@tanstack/react-query';
import { api } from './api';
import type { Member } from './auth';

export type TeamMember = Member & { has_login?: boolean; open_tasks?: number; upcoming_events?: number; open_deliverables?: number };

export function useTeam(active = true) {
  return useQuery({ queryKey: ['team', active], queryFn: () => api.get<TeamMember[]>('team', active ? { active: 1 } : {}), staleTime: 60_000 });
}

export function teamOptions(team: TeamMember[] | undefined, roles?: string[]) {
  return (team || []).filter((m) => !roles || roles.includes(m.role)).map((m) => [String(m.id), m.name] as [string, string]);
}

export type Lead = {
  id: number;
  client_id: number | null;
  name: string;
  phone: string | null;
  whatsapp: string | null;
  email: string | null;
  event_type: string | null;
  event_date: string | null;
  event_end_date: string | null;
  event_duration: string | null;
  venue: string | null;
  city: string | null;
  source: string | null;
  assigned_to: number | null;
  assigned_name?: string | null;
  budget: number | null;
  stage: string;
  priority: string;
  call_status: string;
  last_call_at: string | null;
  next_followup_at: string | null;
  lost_reason: string | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
  enquiry_submission_id?: number | null;
  services?: string | null;
};

export type Activity = { id: number; type: string; message: string; created_at: string; user_name?: string | null; lead_id?: number; client_id?: number; booking_id?: number; lead_name?: string; client_name?: string; booking_title?: string };
