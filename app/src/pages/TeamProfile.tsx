import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CalendarDays, Mail, MapPin, PackageCheck, Pencil, Phone, UserCheck, UserX } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { availabilityTone, bookingTone } from '@/lib/tones';
import { fmtDate, fmtDateLong, fmtTime, phoneDisplay, telLink } from '@/lib/format';
import { Avatar, Badge, Button, Card, CardHeader, EmptyState, ErrorState, PageHeader, PageLoader, useUi } from '@/components/ui';
import { DeliverableBadge, ProgressBar } from '@/components/delivery';
import { MemberModal } from '@/pages/Team';

export default function TeamProfile() {
  const { id } = useParams();
  const { config, can, member: me } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const [edit, setEdit] = useState<any>(null);
  const q = useQuery({ queryKey: ['team', 'profile', id], queryFn: () => api.get<any>(`team/${id}`) });
  if (q.error) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  if (q.isLoading || !q.data) return <PageLoader />;
  const m = q.data;
  const bookings: any[] = m.bookings || [];
  const upcoming = bookings.filter((b) => b.upcoming).sort((a, b) => String(a.first_date || '').localeCompare(String(b.first_date || '')));
  const past = bookings.filter((b) => !b.upcoming);
  const deliverables: any[] = m.deliverables || [];
  const openDl = deliverables.filter((d) => d.status !== 'delivered');
  const editable = can('team.edit');

  const setActive = async (on: boolean) => {
    if (!on && !(await confirm({ title: `Deactivate ${m.name}?`, message: 'They stay in past bookings but cannot be assigned to new work' + (m.user_id ? ' and cannot sign in to this studio.' : '.'), confirmLabel: 'Deactivate', danger: true }))) return;
    try {
      await api.put(`team/${m.id}`, { status: on ? 'active' : 'inactive' });
      toast(on ? `${m.name} is active again` : `${m.name} deactivated`);
      qc.invalidateQueries({ queryKey: ['team'] });
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };

  const BookingRow = ({ b }: { b: any }) => (
    <li className="px-4 py-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <Link to={`/bookings/${b.id}`} className="font-medium hover:text-accent-ink">{b.title}</Link>
          <p className="text-xs text-muted">{b.number} · {b.client_name}</p>
        </div>
        <div className="flex items-center gap-2">
          <span className="text-xs text-muted">{b.first_date ? (b.first_date === b.last_date ? fmtDate(b.first_date) : `${fmtDate(b.first_date)} – ${fmtDate(b.last_date)}`) : 'No date'}</span>
          <Badge tone={bookingTone[b.status]}>{config.booking_statuses[b.status] || b.status}</Badge>
        </div>
      </div>
      {(b.services?.length > 0 || b.events?.length > 0) && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {b.services?.map((s: string) => <Badge key={'s' + s} tone="accent">{s}</Badge>)}
          {b.events?.map((e: any, i: number) => (
            <span key={i} className="rounded-md border border-line px-2 py-0.5 text-2xs text-muted">
              {e.name} · {fmtDate(e.event_date)}{e.start_time ? ' ' + fmtTime(e.start_time) : ''}{e.role ? ` · ${config.event_roles[e.role] || e.role}` : ''}
            </span>
          ))}
        </div>
      )}
    </li>
  );

  return (
    <div>
      <PageHeader
        back={<Link to="/team" className="mb-1 inline-flex items-center gap-1 text-xs text-muted hover:text-ink"><ArrowLeft className="h-3.5 w-3.5" /> Team</Link>}
        title={m.name}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            <span>{m.team_role ? config.team_roles[m.team_role] || m.team_role : config.roles[m.role]}</span>
            <Badge tone={availabilityTone[m.availability] || 'gray'}>{config.availability[m.availability] || m.availability}</Badge>
            {m.status !== 'active' && <Badge tone="gray">Inactive</Badge>}
          </span>
        }
        actions={
          editable && (
            <>
              <Button onClick={() => setEdit(m)}><Pencil className="h-4 w-4" /> Edit</Button>
              {m.role !== 'owner' && m.id !== me.id && (m.status === 'active' ? <Button variant="ghost" onClick={() => setActive(false)}><UserX className="h-4 w-4" /> Deactivate</Button> : <Button variant="ghost" onClick={() => setActive(true)}><UserCheck className="h-4 w-4" /> Activate</Button>)}
            </>
          )
        }
      />
      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-5">
          <Card className="p-5">
            <div className="flex items-center gap-4">
              <Avatar name={m.name} color={m.color} src={m.photo_url} size="xl" />
              <div className="min-w-0">
                <p className="text-lg font-semibold">{m.name}</p>
                <p className="text-sm text-muted">{config.roles[m.role]} access</p>
              </div>
            </div>
            <dl className="mt-5 space-y-2.5 text-sm">
              {m.phone && <div className="flex items-center gap-2"><Phone className="h-4 w-4 text-faint" /><a href={telLink(m.phone)} className="hover:text-accent-ink">{phoneDisplay(m.phone)}</a></div>}
              {m.email && <div className="flex items-center gap-2"><Mail className="h-4 w-4 text-faint" /><a href={`mailto:${m.email}`} className="truncate hover:text-accent-ink">{m.email}</a></div>}
              {m.city && <div className="flex items-center gap-2"><MapPin className="h-4 w-4 text-faint" />{m.city}</div>}
              {m.joining_date && <div className="flex items-center gap-2"><CalendarDays className="h-4 w-4 text-faint" />Joined {fmtDateLong(m.joining_date)}</div>}
            </dl>
            {m.specialization && (
              <div className="mt-4">
                <p className="text-xs font-medium uppercase tracking-wide text-faint">Specialization</p>
                <p className="mt-1 text-sm">{m.specialization}</p>
              </div>
            )}
            {m.notes && (
              <div className="mt-4">
                <p className="text-xs font-medium uppercase tracking-wide text-faint">Notes (team only)</p>
                <p className="mt-1 whitespace-pre-wrap text-sm">{m.notes}</p>
              </div>
            )}
          </Card>
          <Card>
            <CardHeader title="Deliverables" subtitle={`${openDl.length} open`} />
            {!deliverables.length ? (
              <EmptyState icon={<PackageCheck className="h-5 w-5" />} title="No deliverables assigned" />
            ) : (
              <ul className="divide-y divide-line">
                {deliverables.slice(0, 20).map((d) => (
                  <li key={d.id} className="px-4 py-3">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium">{d.name}</p>
                        <Link to={`/bookings/${d.booking_id}`} className="text-xs text-muted hover:text-accent-ink">{d.booking_number}</Link>
                        {d.expected_date && <span className="text-xs text-muted"> · due {fmtDate(d.expected_date)}</span>}
                      </div>
                      <DeliverableBadge status={d.status} />
                    </div>
                    <div className="mt-2"><ProgressBar value={d.progress} size="sm" /></div>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
        <div className="space-y-5 lg:col-span-2">
          <Card>
            <CardHeader title="Upcoming assignments" subtitle={`${upcoming.length} booking${upcoming.length === 1 ? '' : 's'}`} />
            {!upcoming.length ? <EmptyState icon={<CalendarDays className="h-5 w-5" />} title="Nothing upcoming" description="Assign this member to a service or event on a booking." /> : <ul className="divide-y divide-line">{upcoming.map((b) => <BookingRow key={b.id} b={b} />)}</ul>}
          </Card>
          <Card>
            <CardHeader title="Past assigned bookings" subtitle={`${past.length}`} />
            {!past.length ? <EmptyState title="No past bookings" /> : <ul className="divide-y divide-line">{past.map((b) => <BookingRow key={b.id} b={b} />)}</ul>}
          </Card>
        </div>
      </div>
      <MemberModal member={edit} onClose={() => setEdit(null)} />
    </div>
  );
}
