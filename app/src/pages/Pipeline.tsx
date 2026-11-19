import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { DndContext, DragOverlay, PointerSensor, TouchSensor, useDroppable, useSensor, useSensors, closestCorners, type DragEndEvent, type DragStartEvent } from '@dnd-kit/core';
import { SortableContext, useSortable, verticalListSortingStrategy, arrayMove } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import clsx from 'clsx';
import { CalendarClock, MapPin, Phone, Plus } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import type { Lead } from '@/lib/data';
import { teamOptions, useTeam } from '@/lib/data';
import { useDebounce } from '@/lib/hooks';
import { dayjs, fmtDate, fmtDateShort, inrShort, phoneDisplay } from '@/lib/format';
import { stageColor } from '@/lib/tones';
import { Avatar, Button, ErrorState, Input, Modal, PageHeader, PageLoader, SearchInput, Select, useUi, Field } from '@/components/ui';
import { LeadFormModal } from './leads/LeadForm';

type Cols = Record<string, Lead[]>;

export default function Pipeline() {
  const { config, can } = useAuth();
  const { toast } = useUi();
  const qc = useQueryClient();
  const nav = useNavigate();
  const team = useTeam();
  const [search, setSearch] = useState('');
  const dq = useDebounce(search, 300);
  const [assigned, setAssigned] = useState('');
  const [source, setSource] = useState('');
  const params = { q: dq, assigned_to: assigned, source };
  const q = useQuery({ queryKey: ['pipeline', params], queryFn: () => api.get<Cols>('leads/pipeline', params) });
  const [local, setLocal] = useState<Cols | null>(null);
  const cols = local || q.data;
  const [active, setActive] = useState<Lead | null>(null);
  const [lost, setLost] = useState<{ lead: Lead; order: number[] } | null>(null);
  const [lostReason, setLostReason] = useState('');
  const [newIn, setNewIn] = useState<string | null>(null);
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }), useSensor(TouchSensor, { activationConstraint: { delay: 200, tolerance: 6 } }));

  const findCol = (id: number | string, c: Cols) => {
    if (typeof id === 'string' && id.startsWith('col:')) return id.slice(4);
    return Object.keys(c).find((k) => c[k].some((l) => l.id === id));
  };

  const persist = async (lead: Lead, stage: string, order: number[], lost_reason?: string) => {
    try {
      await api.post(`leads/${lead.id}/move`, { stage, order, lost_reason });
      if (stage !== lead.stage) toast(`${lead.name} moved to ${config.stages[stage]}`);
      ['leads', 'dashboard', 'lead'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
      await qc.invalidateQueries({ queryKey: ['pipeline'] });
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setLocal(null);
  };

  const onStart = (e: DragStartEvent) => {
    const c = cols!;
    const col = findCol(e.active.id as number, c);
    setActive(col ? c[col].find((l) => l.id === e.active.id) || null : null);
    setLocal(JSON.parse(JSON.stringify(c)));
  };

  const onOver = (e: any) => {
    if (!e.over || !local) return;
    const from = findCol(e.active.id, local);
    const to = findCol(e.over.id, local);
    if (!from || !to || from === to) return;
    const item = local[from].find((l) => l.id === e.active.id)!;
    const next = { ...local, [from]: local[from].filter((l) => l.id !== item.id), [to]: [...local[to]] };
    const overIdx = next[to].findIndex((l) => l.id === e.over.id);
    next[to].splice(overIdx >= 0 ? overIdx : next[to].length, 0, item);
    setLocal(next);
  };

  const onEnd = (e: DragEndEvent) => {
    const lead = active;
    setActive(null);
    if (!lead || !local) return setLocal(null);
    const to = e.over ? findCol(e.over.id as any, local) : undefined;
    if (!to) return setLocal(null);
    let list = local[to];
    const oldIdx = list.findIndex((l) => l.id === lead.id);
    const newIdx = list.findIndex((l) => l.id === e.over!.id);
    if (oldIdx >= 0 && newIdx >= 0 && oldIdx !== newIdx) list = arrayMove(list, oldIdx, newIdx);
    setLocal({ ...local, [to]: list });
    const order = list.map((l) => l.id);
    if (to === lead.stage && JSON.stringify(order) === JSON.stringify((q.data?.[to] || []).map((l) => l.id))) return setLocal(null);
    if (to === 'lost' && lead.stage !== 'lost') {
      setLostReason('');
      return setLost({ lead, order });
    }
    if (to === 'booked' && lead.stage !== 'booked') {
      setLocal(null);
      return nav(`/bookings/new?lead_id=${lead.id}`);
    }
    persist(lead, to, order);
  };

  const totals = useMemo(() => {
    const t: Record<string, number> = {};
    Object.entries(cols || {}).forEach(([k, v]) => (t[k] = v.reduce((s, l) => s + (l.budget || 0), 0)));
    return t;
  }, [cols]);

  return (
    <div className="flex h-[calc(100vh-8.5rem)] flex-col lg:h-[calc(100vh-6.5rem)]">
      <PageHeader
        title="Sales pipeline"
        subtitle="Drag cards between stages. Dropping on Booking Confirmed opens the booking form."
        actions={
          <>
            <SearchInput value={search} onChange={setSearch} placeholder="Search leads…" className="w-full sm:w-56" />
            <Select value={assigned} onChange={(e) => setAssigned(e.target.value)} options={teamOptions(team.data, ['owner', 'admin', 'sales'])} placeholder="Everyone" className="w-auto" />
            <Select value={source} onChange={(e) => setSource(e.target.value)} options={config.sources} placeholder="All sources" className="w-auto" />
          </>
        }
      />
      {q.error ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : !cols ? (
        <PageLoader />
      ) : (
        <DndContext sensors={sensors} collisionDetection={closestCorners} onDragStart={onStart} onDragOver={onOver} onDragEnd={onEnd} onDragCancel={() => (setActive(null), setLocal(null))}>
          <div className="scrollbar-thin -mx-3 flex flex-1 gap-3 overflow-x-auto px-3 pb-3 sm:-mx-5 sm:px-5 lg:-mx-7 lg:px-7">
            {Object.entries(config.stages).map(([key, label]) => (
              <Column key={key} id={key} label={label} leads={cols[key] || []} total={totals[key]} onAdd={can('leads.edit') && key !== 'booked' && key !== 'lost' ? () => setNewIn(key) : undefined} />
            ))}
          </div>
          <DragOverlay>{active ? <LeadCard lead={active} overlay /> : null}</DragOverlay>
        </DndContext>
      )}
      <Modal
        open={!!lost}
        onClose={() => (setLost(null), setLocal(null))}
        title={`Mark ${lost?.lead.name} as lost`}
        size="sm"
        footer={
          <>
            <Button onClick={() => (setLost(null), setLocal(null))}>Cancel</Button>
            <Button variant="danger" onClick={() => (persist(lost!.lead, 'lost', lost!.order, lostReason), setLost(null))}>Mark lost</Button>
          </>
        }
      >
        <Field label="Reason (helps your lost-leads report)">
          <Input value={lostReason} onChange={(e) => setLostReason(e.target.value)} list="pl-lost" autoFocus placeholder="e.g. Budget too high" />
          <datalist id="pl-lost">{['Budget too high', 'Booked another photographer', 'Date not available', 'Event cancelled', 'No response after follow-ups'].map((r) => <option key={r} value={r} />)}</datalist>
        </Field>
      </Modal>
      <LeadFormModal open={!!newIn} onClose={() => setNewIn(null)} defaults={{ stage: newIn || 'new' }} />
    </div>
  );
}

function Column({ id, label, leads, total, onAdd }: { id: string; label: string; leads: Lead[]; total: number; onAdd?: () => void }) {
  const { setNodeRef, isOver } = useDroppable({ id: 'col:' + id });
  return (
    <div className="flex w-[272px] shrink-0 flex-col rounded-xl bg-subtle/70">
      <div className="flex items-center gap-2 px-3 pb-2 pt-3">
        <span className="h-2 w-2 rounded-full" style={{ background: stageColor[id] }} />
        <span className="truncate text-sm font-semibold">{label}</span>
        <span className="rounded-full bg-surface px-1.5 text-2xs font-medium text-muted">{leads.length}</span>
        <span className="tabular ml-auto text-2xs text-faint">{total ? inrShort(total) : ''}</span>
        {onAdd && <button onClick={onAdd} className="rounded p-0.5 text-faint hover:bg-surface hover:text-ink" aria-label={`Add lead to ${label}`}><Plus className="h-3.5 w-3.5" /></button>}
      </div>
      <div ref={setNodeRef} className={clsx('scrollbar-thin flex-1 space-y-2 overflow-y-auto px-2 pb-2 transition-colors', isOver && 'bg-accent-soft/50')}>
        <SortableContext id={id} items={leads.map((l) => l.id)} strategy={verticalListSortingStrategy}>
          {leads.map((l) => <SortableCard key={l.id} lead={l} />)}
        </SortableContext>
        {leads.length === 0 && <div className="rounded-lg border border-dashed border-line py-8 text-center text-xs text-faint">Drop leads here</div>}
      </div>
    </div>
  );
}

function SortableCard({ lead }: { lead: Lead }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: lead.id });
  return (
    <div ref={setNodeRef} style={{ transform: CSS.Transform.toString(transform), transition }} {...attributes} {...listeners} className={clsx(isDragging && 'opacity-40')}>
      <LeadCard lead={lead} />
    </div>
  );
}

function LeadCard({ lead, overlay }: { lead: Lead; overlay?: boolean }) {
  const { config } = useAuth();
  const overdue = lead.next_followup_at && dayjs(lead.next_followup_at).isBefore(dayjs()) && !['booked', 'lost'].includes(lead.stage);
  return (
    <div className={clsx('cursor-grab touch-manipulation rounded-lg border border-line bg-surface p-3 shadow-card active:cursor-grabbing', overlay && 'rotate-2 shadow-pop')}>
      <div className="flex items-start justify-between gap-2">
        <Link to={`/leads/${lead.id}`} className="line-clamp-2 text-sm font-medium leading-snug hover:text-accent-ink" onPointerDown={(e) => e.stopPropagation()}>
          {lead.name}
        </Link>
        {lead.priority === 'hot' && <span className="rounded bg-red-50 px-1 text-2xs font-semibold text-red-600 dark:bg-red-500/15">HOT</span>}
      </div>
      <div className="mt-2 space-y-1 text-xs text-muted">
        <p className="flex items-center gap-1.5"><CalendarClock className="h-3 w-3 shrink-0" />{lead.event_type ? config.event_types[lead.event_type] : 'Event'} · {fmtDate(lead.event_date)}</p>
        {lead.city && <p className="flex items-center gap-1.5"><MapPin className="h-3 w-3 shrink-0" />{lead.city}</p>}
        <p className="flex items-center gap-1.5"><Phone className="h-3 w-3 shrink-0" />{phoneDisplay(lead.phone)}</p>
      </div>
      <div className="mt-2.5 flex items-center gap-2 border-t border-line pt-2">
        {lead.source && <span className="rounded bg-subtle px-1.5 py-0.5 text-2xs text-muted">{config.sources[lead.source]}</span>}
        {lead.next_followup_at && <span className={clsx('text-2xs', overdue ? 'font-medium text-red-600' : 'text-muted')}>↻ {fmtDateShort(lead.next_followup_at)}</span>}
        <span className="ml-auto">{lead.assigned_name ? <Avatar name={lead.assigned_name} size="xs" /> : <span className="text-2xs text-faint">Unassigned</span>}</span>
      </div>
    </div>
  );
}
