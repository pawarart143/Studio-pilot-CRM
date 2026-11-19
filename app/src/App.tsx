import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import type { ReactNode } from 'react';
import { AuthProvider, useAuth, useMe } from '@/lib/auth';
import { api } from '@/lib/api';
import { ErrorState, PageLoader } from '@/components/ui';
import AppLayout from '@/components/AppLayout';
import { Login, Register, Forgot, Reset, CreateStudio } from '@/pages/Auth';

const Dashboard = lazy(() => import('@/pages/Dashboard'));
const Leads = lazy(() => import('@/pages/leads/LeadsList'));
const LeadDetail = lazy(() => import('@/pages/leads/LeadDetail'));
const Pipeline = lazy(() => import('@/pages/Pipeline'));
const Calling = lazy(() => import('@/pages/Calling'));
const Quotations = lazy(() => import('@/pages/quotations/QuotationsList'));
const QuotationBuilder = lazy(() => import('@/pages/quotations/QuotationBuilder'));
const Bookings = lazy(() => import('@/pages/bookings/BookingsList'));
const BookingForm = lazy(() => import('@/pages/bookings/BookingForm'));
const BookingDetail = lazy(() => import('@/pages/bookings/BookingDetail'));
const Payments = lazy(() => import('@/pages/Payments'));
const CalendarPage = lazy(() => import('@/pages/Calendar'));
const Team = lazy(() => import('@/pages/Team'));
const TeamProfile = lazy(() => import('@/pages/TeamProfile'));
const Deliverables = lazy(() => import('@/pages/Deliverables'));
const DeliverablesClient = lazy(() => import('@/pages/DeliverablesClient'));
const Tasks = lazy(() => import('@/pages/Tasks'));
const Clients = lazy(() => import('@/pages/clients/ClientsList'));
const ClientDetail = lazy(() => import('@/pages/clients/ClientDetail'));
const Reports = lazy(() => import('@/pages/Reports'));
const Settings = lazy(() => import('@/pages/Settings'));
const Crew = lazy(() => import('@/pages/crew/Crew'));
const CrewProfile = lazy(() => import('@/pages/crew/CrewProfile'));
const Admin = lazy(() => import('@/pages/Admin'));
const Billing = lazy(() => import('@/pages/Billing'));

const publicPaths = ['/login', '/register', '/forgot', '/reset'];

export default function App() {
  const { data: me, isLoading, error, refetch } = useMe();
  const loc = useLocation();

  if (isLoading) return <FullLoader />;
  if (error || !me) return <ErrorState error={error} onRetry={() => refetch()} />;

  if (!me.user) {
    return (
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="/forgot" element={<Forgot />} />
        <Route path="/reset" element={<Reset />} />
        <Route path="*" element={<Navigate to={`/login${loc.pathname !== '/' ? '?next=' + encodeURIComponent(window.PCRM.base + loc.pathname + loc.search) : ''}`} replace />} />
      </Routes>
    );
  }

  if (publicPaths.includes(loc.pathname) && loc.pathname !== '/reset') return <Navigate to="/" replace />;

  if (!me.member || !me.studio) {
    if (me.is_super && loc.pathname.startsWith('/admin')) {
      return (
        <Suspense fallback={<FullLoader />}>
          <Admin standalone />
        </Suspense>
      );
    }
    if (me.access_off) return <AccessOff email={me.user.email} />;
    return <CreateStudio me={me} />;
  }

  return (
    <AuthProvider me={me}>
      <AppLayout>
        <Suspense fallback={<PageLoader />}>
          <Routes>
            <Route path="/" element={<Dashboard />} />
            <Route path="/leads" element={<Leads />} />
            <Route path="/leads/:id" element={<LeadDetail />} />
            <Route path="/pipeline" element={<Pipeline />} />
            <Route path="/calling" element={<Calling />} />
            <Route path="/quotations" element={<Quotations />} />
            <Route path="/quotations/new" element={<QuotationBuilder />} />
            <Route path="/quotations/:id" element={<QuotationBuilder />} />
            <Route path="/bookings" element={<Bookings />} />
            <Route path="/bookings/new" element={<BookingForm />} />
            <Route path="/bookings/:id" element={<BookingDetail />} />
            <Route path="/bookings/:id/edit" element={<BookingForm />} />
            <Route path="/payments" element={<Guard perm="payments.view"><Payments /></Guard>} />
            <Route path="/calendar" element={<CalendarPage />} />
            <Route path="/team" element={<Guard perm="team.view"><Team /></Guard>} />
            <Route path="/team/:id" element={<Guard perm="team.view"><TeamProfile /></Guard>} />
            <Route path="/deliverables" element={<Deliverables />} />
            <Route path="/deliverables/clients/:id" element={<DeliverablesClient />} />
            <Route path="/tasks" element={<Tasks />} />
            <Route path="/crew" element={<Crew />} />
            <Route path="/crew/members/:id" element={<CrewProfile />} />
            <Route path="/clients" element={<Clients />} />
            <Route path="/clients/:id" element={<ClientDetail />} />
            <Route path="/reports" element={<Guard perm="reports.view"><Reports /></Guard>} />
            <Route path="/settings" element={<Settings />} />
            <Route path="/admin" element={<Admin />} />
            <Route path="/billing" element={<Billing />} />
            <Route path="/reset" element={<Reset />} />
            <Route path="*" element={<NotFound />} />
          </Routes>
        </Suspense>
      </AppLayout>
    </AuthProvider>
  );
}

function FullLoader() {
  return (
    <div className="flex h-screen items-center justify-center">
      <PageLoader />
    </div>
  );
}

/** Pages owner/admin only. The server refuses the data too; this keeps the page clean. */
function Guard({ perm, children }: { perm: string; children: ReactNode }) {
  const { can } = useAuth();
  if (can(perm)) return <>{children}</>;
  return (
    <div className="py-24 text-center">
      <p className="text-lg font-semibold">You don't have access to this page</p>
      <p className="mt-2 text-sm text-muted">Payments, reports, team and business settings are only for the studio owner and admins.</p>
    </div>
  );
}

function AccessOff({ email }: { email: string }) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-app p-4">
      <div className="max-w-sm rounded-xl border border-line bg-surface p-6 text-center shadow-sm">
        <p className="text-lg font-semibold">Your team access is turned off</p>
        <p className="mt-2 text-sm text-muted">You are signed in as {email}, but your studio has deactivated your access. Please ask your studio owner to activate you again.</p>
        <button className="mt-4 text-sm font-medium text-accent-ink" onClick={async () => (await api.post('auth/logout'), (window.location.href = window.PCRM.base + '/login'))}>Sign out</button>
      </div>
    </div>
  );
}

function NotFound() {
  return (
    <div className="py-24 text-center">
      <p className="text-4xl font-semibold">404</p>
      <p className="mt-2 text-muted">This page doesn't exist.</p>
    </div>
  );
}
