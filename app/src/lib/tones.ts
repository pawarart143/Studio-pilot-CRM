import type { Tone } from '@/components/ui';

export const stageTone: Record<string, Tone> = {
  new: 'blue',
  first_call_pending: 'cyan',
  contacted: 'indigo',
  interested: 'violet',
  followup_required: 'amber',
  quotation_sent: 'orange',
  negotiation: 'pink',
  booked: 'green',
  lost: 'gray',
};

export const stageColor: Record<string, string> = {
  new: '#3b82f6',
  first_call_pending: '#06b6d4',
  contacted: '#6366f1',
  interested: '#8b5cf6',
  followup_required: '#f59e0b',
  quotation_sent: '#f97316',
  negotiation: '#ec4899',
  booked: '#10b981',
  lost: '#94a3b8',
};

export const priorityTone: Record<string, Tone> = { low: 'gray', medium: 'blue', high: 'amber', hot: 'red' };

export const callTone: Record<string, Tone> = {
  not_called: 'gray',
  attempted: 'cyan',
  connected: 'blue',
  not_answered: 'amber',
  call_back: 'violet',
  followup: 'orange',
  interested: 'green',
  not_interested: 'red',
  switched_off: 'gray',
  wrong_number: 'red',
};

export const quoteTone: Record<string, Tone> = { draft: 'gray', sent: 'blue', viewed: 'violet', accepted: 'green', rejected: 'red', expired: 'amber' };

export const bookingTone: Record<string, Tone> = { confirmed: 'indigo', shoot_done: 'cyan', editing: 'violet', album_design: 'pink', delivered: 'blue', completed: 'green', cancelled: 'gray' };

export const payTone: Record<string, Tone> = { pending: 'gray', partial: 'amber', paid: 'green', overdue: 'red', cancelled: 'gray' };
export const payLabel: Record<string, string> = { pending: 'Pending', partial: 'Partially Paid', paid: 'Paid', overdue: 'Overdue', cancelled: 'Cancelled' };

export const taskStatusTone: Record<string, Tone> = { todo: 'gray', in_progress: 'blue', done: 'green' };

/** Calendar colours per event type. */
export const eventColor: Record<string, string> = {
  wedding: '#e11d48',
  pre_wedding: '#db2777',
  engagement: '#9333ea',
  haldi_mehndi: '#f59e0b',
  reception: '#7c3aed',
  birthday: '#0891b2',
  baby_shoot: '#14b8a6',
  corporate: '#2563eb',
  commercial: '#4f46e5',
  fashion: '#c026d3',
  product: '#0d9488',
  other: '#64748b',
};

/** Validated categorical palette (fixed order, light/dark steps). */
const SERIES_LIGHT = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
const SERIES_DARK = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#0ca30c', '#9085e9', '#e66767'];
export const series = (i: number, dark: boolean) => (dark ? SERIES_DARK : SERIES_LIGHT)[i % 8];

/** Sources keep a fixed slot so colour follows the entity, not its rank. */
export const SOURCE_ORDER = ['instagram', 'referral', 'google', 'facebook', 'website', 'whatsapp', 'walkin', 'other', 'enquiry_form'];
export const sourceColor = (src: string, dark: boolean) => series(Math.max(0, SOURCE_ORDER.indexOf(src)), dark);

export const deliverableTone: Record<string, Tone> = {
  not_started: 'gray',
  in_progress: 'blue',
  editing: 'violet',
  awaiting_selection: 'amber',
  under_review: 'cyan',
  ready: 'indigo',
  delivered: 'green',
  revision_requested: 'red',
};

export const paymentStatusTone: Record<string, Tone> = { received: 'green', pending: 'amber', failed: 'red', refunded: 'gray' };
export const paymentTypeTone: Record<string, Tone> = { advance: 'accent', installment: 'blue', final: 'green' };
export const availabilityTone: Record<string, Tone> = { available: 'green', busy: 'amber', on_leave: 'violet', unavailable: 'gray' };
