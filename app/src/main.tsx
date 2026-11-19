import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { UiProvider } from '@/components/ui';
import App from './App';
import './index.css';

const qc = new QueryClient({
  defaultOptions: {
    queries: { retry: (n, e: any) => n < 1 && !(e?.status >= 400 && e?.status < 500), refetchOnWindowFocus: false, staleTime: 20_000 },
  },
});

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={qc}>
      <BrowserRouter basename={window.PCRM.base}>
        <UiProvider>
          <App />
        </UiProvider>
      </BrowserRouter>
    </QueryClientProvider>
  </React.StrictMode>,
);
