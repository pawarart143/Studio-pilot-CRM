import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { AuthProvider, useMe } from '@/lib/auth';
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
const Tasks = lazy(() => import('@/pages/Tasks'));
const Clients = lazy(() => import('@/pages/clients/ClientsList'));
const ClientDetail = lazy(() => import('@/pages/clients/ClientDetail'));
const Reports = lazy(() => import('@/pages/Reports'));
const Settings = lazy(() => import('@/pages/Settings'));
const Crew = lazy(() => import('@/pages/crew/Crew'));
const CrewProfile = lazy(() => import('@/pages/crew/CrewProfile'));
const Admin = lazy(() => import('@/pages/Admin'));

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
            <Route path="/payments" element={<Payments />} />
            <Route path="/calendar" element={<CalendarPage />} />
            <Route path="/team" element={<Team />} />
            <Route path="/team/:id" element={<TeamProfile />} />
            <Route path="/deliverables" element={<Deliverables />} />
            <Route path="/tasks" element={<Tasks />} />
            <Route path="/crew" element={<Crew />} />
            <Route path="/crew/members/:id" element={<CrewProfile />} />
            <Route path="/clients" element={<Clients />} />
            <Route path="/clients/:id" element={<ClientDetail />} />
            <Route path="/reports" element={<Reports />} />
            <Route path="/settings" element={<Settings />} />
            <Route path="/admin" element={<Admin />} />
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

function NotFound() {
  return (
    <div className="py-24 text-center">
      <p className="text-4xl font-semibold">404</p>
      <p className="mt-2 text-muted">This page doesn't exist.</p>
    </div>
  );
}
