import { useMemo, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { DndContext, KeyboardSensor, PointerSensor, TouchSensor, closestCenter, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { SortableContext, arrayMove, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { ArrowDown, ArrowUp, BarChart3, EyeOff, GripVertical, LayoutList, Plus, RotateCcw, SquareStack } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { Badge, Button, Drawer, IconButton, Select, useUi } from '@/components/ui';
import { LAYOUT_KEY, type LayoutItem, type LayoutResponse, type WidgetMeta } from './layout';

const kindIcon = (m: WidgetMeta) => (m.kind === 'stat' ? <SquareStack className="h-4 w-4" /> : m.id.startsWith('chart') ? <BarChart3 className="h-4 w-4" /> : <LayoutList className="h-4 w-4" />);

/**
 * Edit mode. The dashboard behind the drawer shows the draft live; nothing is
 * saved until Save. Reset removes this user's layout so the shipped default
 * comes back exactly.
 */
export function CustomizeDrawer({ widgets, isDefault, draft, setDraft, onClose }: { widgets: WidgetMeta[]; isDefault: boolean; draft: LayoutItem[]; setDraft: (d: LayoutItem[]) => void; onClose: () => void }) {
  const qc = useQueryClient();
  const { toast, confirm } = useUi();
  const { studio } = useAuth();
  const [busy, setBusy] = useState<'save' | 'reset' | null>(null);
  const meta = useMemo(() => Object.fromEntries(widgets.map((w) => [w.id, w])), [widgets]);
  const visible = draft.filter((it) => it.visible && meta[it.id]);
  const hidden = widgets.filter((w) => !visible.some((v) => v.id === w.id));
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }), useSensor(TouchSensor, { activationConstraint: { delay: 150, tolerance: 6 } }), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));

  // Keeps visible widgets first in their chosen order; hidden ones keep their place after.
  const commit = (vis: LayoutItem[]) => {
    const ids = new Set(vis.map((v) => v.id));
    setDraft([...vis, ...draft.filter((d) => !ids.has(d.id)).map((d) => ({ ...d, visible: false }))]);
  };
  const move = (from: number, to: number) => to >= 0 && to < visible.length && commit(arrayMove(visible, from, to));
  const onEnd = (e: DragEndEvent) => {
    if (!e.over || e.active.id === e.over.id) return;
    move(visible.findIndex((v) => v.id === e.active.id), visible.findIndex((v) => v.id === e.over!.id));
  };
  const hide = (id: string) => commit(visible.filter((v) => v.id !== id));
  const add = (w: WidgetMeta) => {
    const prev = draft.find((d) => d.id === w.id);
    commit([...visible, { id: w.id, visible: true, range: prev?.range ?? w.default_range }]);
  };
  const setRange = (id: string, range: string) => commit(visible.map((v) => (v.id === id ? { ...v, range } : v)));

  const save = async () => {
    setBusy('save');
    try {
      const res = await api.put<LayoutResponse>('dashboard/layout', { items: draft });
      qc.setQueryData(LAYOUT_KEY, res);
      toast('Dashboard saved');
      onClose();
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy(null);
  };
  const reset = async () => {
    const ok = await confirm({ title: 'Reset to default?', message: 'Your dashboard goes back to the standard ShootPilot layout. Your custom layout will be removed.', confirmLabel: 'Reset' });
    if (!ok) return;
    setBusy('reset');
    try {
      const res = await api.del<LayoutResponse>('dashboard/layout');
      qc.setQueryData(LAYOUT_KEY, res);
      toast('Dashboard reset to default');
      onClose();
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy(null);
  };

  return (
    <Drawer
      open
      onClose={onClose}
      width="max-w-md"
      title="Customize dashboard"
      footer={
        <div className="flex w-full flex-wrap items-center gap-2">
          <Button variant="ghost" size="sm" icon={<RotateCcw className="h-4 w-4" />} onClick={reset} loading={busy === 'reset'} disabled={!!busy}>
            Reset to default
          </Button>
          <span className="flex-1" />
          <Button size="sm" onClick={onClose} disabled={!!busy}>
            Cancel
          </Button>
          <Button variant="primary" size="sm" onClick={save} loading={busy === 'save'} disabled={!!busy || visible.length === 0}>
            Save
          </Button>
        </div>
      }
    >
      <div className="space-y-6 p-5">
        <p className="text-sm text-muted">
          Only your own dashboard in {studio.name} changes. Drag to reorder, or use the arrows. {isDefault ? <Badge className="ml-1">Default layout</Badge> : <Badge tone="accent" className="ml-1">Custom layout</Badge>}
        </p>

        <section>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">On your dashboard ({visible.length})</h3>
          {visible.length === 0 ? (
            <p className="rounded-lg border border-dashed border-line p-4 text-center text-sm text-muted">Add at least one widget from below.</p>
          ) : (
            <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onEnd}>
              <SortableContext items={visible.map((v) => v.id)} strategy={verticalListSortingStrategy}>
                <ul className="space-y-2">
                  {visible.map((it, i) => (
                    <Row key={it.id} m={meta[it.id]} it={it} first={i === 0} last={i === visible.length - 1} onUp={() => move(i, i - 1)} onDown={() => move(i, i + 1)} onHide={() => hide(it.id)} onRange={(r) => setRange(it.id, r)} />
                  ))}
                </ul>
              </SortableContext>
            </DndContext>
          )}
        </section>

        <section>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">More widgets ({hidden.length})</h3>
          {hidden.length === 0 ? (
            <p className="text-sm text-muted">Every widget you can use is on your dashboard.</p>
          ) : (
            <ul className="space-y-2">
              {hidden.map((w) => (
                <li key={w.id} className="flex items-center gap-3 rounded-lg border border-line bg-surface p-3">
                  <span className="text-muted">{kindIcon(w)}</span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{w.title}</p>
                    <p className="text-xs text-muted">{w.description}</p>
                  </div>
                  <Button size="xs" icon={<Plus className="h-3.5 w-3.5" />} onClick={() => add(w)} aria-label={`Add ${w.title}`}>
                    Add
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </Drawer>
  );
}

function Row({ m, it, first, last, onUp, onDown, onHide, onRange }: { m: WidgetMeta; it: LayoutItem; first: boolean; last: boolean; onUp: () => void; onDown: () => void; onHide: () => void; onRange: (r: string) => void }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: it.id });
  return (
    <li ref={setNodeRef} style={{ transform: CSS.Transform.toString(transform), transition }} className={'rounded-lg border border-line bg-surface p-3 ' + (isDragging ? 'relative z-10 shadow-pop' : '')} data-widget={it.id}>
      <div className="flex items-center gap-2">
        <button type="button" className="cursor-grab touch-none rounded p-1 text-faint hover:bg-subtle hover:text-ink active:cursor-grabbing" aria-label={`Drag ${m.title}`} {...attributes} {...listeners}>
          <GripVertical className="h-4 w-4" />
        </button>
        <span className="text-muted">{kindIcon(m)}</span>
        <p className="min-w-0 flex-1 truncate text-sm font-medium">{m.title}</p>
        <IconButton label="Move up" onClick={onUp} disabled={first}>
          <ArrowUp className="h-4 w-4" />
        </IconButton>
        <IconButton label="Move down" onClick={onDown} disabled={last}>
          <ArrowDown className="h-4 w-4" />
        </IconButton>
        <IconButton label={`Hide ${m.title}`} onClick={onHide}>
          <EyeOff className="h-4 w-4" />
        </IconButton>
      </div>
      {m.ranges.length > 0 && (
        <div className="mt-2 pl-8">
          <Select aria-label={`Date range for ${m.title}`} className="h-8 py-1 text-xs" value={it.range || m.default_range || ''} onChange={(e) => onRange(e.target.value)} options={m.ranges.map((r) => [r.key, r.label + (r.key === m.default_range ? ' (default)' : '')] as [string, string])} />
        </div>
      )}
    </li>
  );
}
