import { createBrowserRouter } from 'react-router-dom'
import { RequireAuth } from './features/auth/components/RequireAuth'
import { LoginPage } from './features/auth/pages/LoginPage'
import { ChangePasswordPage } from './features/auth/pages/ChangePasswordPage'
import { registerUnauthorizedHandler, registerPasswordChangeHandler } from './lib/axios'
import { useAuthStore } from './features/auth/store'
import { AppLayout } from './components/layout/AppLayout'
import { UsersPage } from './features/users/pages/UsersPage'
import { LogsPage } from './features/logs/pages/LogsPage'
import { LeadsPage } from './features/leads/pages/LeadsPage'
import { LeadDetailPage } from './features/leads/pages/LeadDetailPage'
import { ContactsPage } from './features/contacts/pages/ContactsPage'
import { ContactDetailPage } from './features/contacts/pages/ContactDetailPage'
import { CompaniesPage } from './features/companies/pages/CompaniesPage'
import { CompanyDetailPage } from './features/companies/pages/CompanyDetailPage'
import { DealsBoardPage } from './features/deals/pages/DealsBoardPage'
import { DealsListPage } from './features/deals/pages/DealsListPage'
import { DealDetailPage } from './features/deals/pages/DealDetailPage'
import { TasksPage } from './features/tasks/pages/TasksPage'
import { ActivitiesPage } from './features/activities/pages/ActivitiesPage'
import { TicketsListPage } from './features/tickets/pages/TicketsListPage'
import { TicketDetailPage } from './features/tickets/pages/TicketDetailPage'
import { ChatPage } from './features/chat'
import { QuotesListPage } from './features/quotes/pages/QuotesListPage'
import { QuoteDetailPage } from './features/quotes/pages/QuoteDetailPage'
import { QuoteFormPage } from './features/quotes/pages/QuoteFormPage'
import { ProductsPage } from './features/products/pages/ProductsPage'
import { PriceListsPage } from './features/price-lists/pages/PriceListsPage'
import { PriceListDetailPage } from './features/price-lists/pages/PriceListDetailPage'
import { SettingsPage } from './features/settings'
import { ReportsPage } from './features/reports'
import { NotificationsPage } from './features/notifications'
import { DashboardPage } from './pages/DashboardPage'
import { NotFoundPage } from './pages/NotFoundPage'
import Showcase from './pages/Showcase'

export const router = createBrowserRouter([
  {
    path: '/login',
    element: <LoginPage />,
  },
  {
    path: '/change-password',
    element: (
      <RequireAuth>
        <ChangePasswordPage />
      </RequireAuth>
    ),
  },
  {
    path: '/',
    element: (
      <RequireAuth>
        <AppLayout />
      </RequireAuth>
    ),
    children: [
      {
        index: true,
        element: (
          <RequireAuth permission="dashboard.view">
            <DashboardPage />
          </RequireAuth>
        ),
      },
      {
        path: 'users',
        element: (
          <RequireAuth permission="users.view">
            <UsersPage />
          </RequireAuth>
        ),
      },
      {
        path: 'logs',
        element: (
          <RequireAuth permission="logs.view">
            <LogsPage />
          </RequireAuth>
        ),
      },
      {
        path: 'leads',
        element: (
          <RequireAuth permission="leads.view">
            <LeadsPage />
          </RequireAuth>
        ),
      },
      {
        path: 'leads/:id',
        element: (
          <RequireAuth permission="leads.view">
            <LeadDetailPage />
          </RequireAuth>
        ),
      },
      {
        path: 'contacts',
        element: (
          <RequireAuth permission="contacts.view">
            <ContactsPage />
          </RequireAuth>
        ),
      },
      {
        path: 'contacts/:id',
        element: (
          <RequireAuth permission="contacts.view">
            <ContactDetailPage />
          </RequireAuth>
        ),
      },
      {
        path: 'companies',
        element: (
          <RequireAuth permission="companies.view">
            <CompaniesPage />
          </RequireAuth>
        ),
      },
      {
        path: 'companies/:id',
        element: (
          <RequireAuth permission="companies.view">
            <CompanyDetailPage />
          </RequireAuth>
        ),
      },
      // Fırsatlar. Rota sırası KASITLI: sabit `deals/list` segmenti `deals/:id`den ÖNCE
      // gelmeli, aksi hâlde "list" bir id sanılır ve liste görünümü detay sayfasına düşer.
      {
        path: 'deals',
        element: (
          <RequireAuth permission="deals.view">
            <DealsBoardPage />
          </RequireAuth>
        ),
      },
      {
        path: 'deals/list',
        element: (
          <RequireAuth permission="deals.view">
            <DealsListPage />
          </RequireAuth>
        ),
      },
      {
        path: 'deals/:id',
        element: (
          <RequireAuth permission="deals.view">
            <DealDetailPage />
          </RequireAuth>
        ),
      },
      {
        path: 'tasks',
        element: (
          <RequireAuth permission="tasks.view">
            <TasksPage />
          </RequireAuth>
        ),
      },
      {
        path: 'activities',
        element: (
          <RequireAuth permission="activities.view">
            <ActivitiesPage />
          </RequireAuth>
        ),
      },
      // Destek Talepleri (Faz 8 / D). Rota sırası KASITLI: sabit `tickets/:id` segmenti bir
      // sayı beklediği için `/tickets` (liste) ile aralarında bir belirsizlik yok (deals'taki
      // `list` segmenti gibi ayrı bir sabit alt yol GEREKMEZ).
      {
        path: 'tickets',
        element: (
          <RequireAuth permission="tickets.view">
            <TicketsListPage />
          </RequireAuth>
        ),
      },
      {
        path: 'tickets/:id',
        element: (
          <RequireAuth permission="tickets.view">
            <TicketDetailPage />
          </RequireAuth>
        ),
      },
      // Sohbet (Faz 12). Rota sırası KASITLI: sabit `chat` segmenti `chat/:conversationId`den
      // ÖNCE gelmeli, aksi hâlde konuşma listesi bir conversationId sanılıp aynı bileşene
      // (ChatPage) düşse de URL/parametre eşlemesi bozulur (deals/quotes'taki tuzakla AYNI).
      {
        path: 'chat',
        element: (
          <RequireAuth permission="chat.use">
            <ChatPage />
          </RequireAuth>
        ),
      },
      {
        path: 'chat/:conversationId',
        element: (
          <RequireAuth permission="chat.use">
            <ChatPage />
          </RequireAuth>
        ),
      },
      // Ürün kataloğu ve fiyat listeleri (Faz 9 / D). `price-lists` ve `price-lists/:id`
      // arasında `deals/list` vs `deals/:id` tarzı bir belirsizlik YOK: ikisi de kendi
      // segment derinliğinde sabit/parametreli, aralarında çakışan bir literal yok.
      {
        path: 'products',
        element: (
          <RequireAuth permission="products.view">
            <ProductsPage />
          </RequireAuth>
        ),
      },
      {
        path: 'price-lists',
        element: (
          <RequireAuth permission="products.view">
            <PriceListsPage />
          </RequireAuth>
        ),
      },
      {
        path: 'price-lists/:id',
        element: (
          <RequireAuth permission="products.view">
            <PriceListDetailPage />
          </RequireAuth>
        ),
      },
      // Teklifler (Faz 9 / E). Rota sırası KASITLI: sabit `quotes/new` ve `quotes/:id/edit`
      // segmentleri `quotes/:id`den ÖNCE tanımlı — aksi hâlde "new" bir teklif id'si sanılıp
      // detay sayfasına düşer (deals'taki `list` ve tickets'taki `:id` ile AYNI tuzak).
      {
        path: 'quotes',
        element: (
          <RequireAuth permission="quotes.view">
            <QuotesListPage />
          </RequireAuth>
        ),
      },
      {
        path: 'quotes/new',
        element: (
          <RequireAuth permission="quotes.create">
            <QuoteFormPage />
          </RequireAuth>
        ),
      },
      {
        path: 'quotes/:id/edit',
        element: (
          <RequireAuth permission="quotes.update">
            <QuoteFormPage />
          </RequireAuth>
        ),
      },
      {
        path: 'quotes/:id',
        element: (
          <RequireAuth permission="quotes.view">
            <QuoteDetailPage />
          </RequireAuth>
        ),
      },
      {
        path: 'settings',
        element: (
          <RequireAuth permission="settings.manage">
            <SettingsPage />
          </RequireAuth>
        ),
      },
      {
        path: 'reports',
        element: (
          <RequireAuth permission="reports.view">
            <ReportsPage />
          </RequireAuth>
        ),
      },
      {
        path: 'notifications',
        element: (
          <RequireAuth permission="notifications.view">
            <NotificationsPage />
          </RequireAuth>
        ),
      },
    ],
  },
  {
    path: '/showcase',
    element: <Showcase />,
  },
  {
    path: '*',
    element: <NotFoundPage />,
  },
])

/**
 * Axios interceptor 401 (ve pasifleştirilmiş hesap 403) yanıtında bu
 * callback'i tetikler — auth store'u temizler ve `/login`'e yönlendirir.
 * `PASSWORD_CHANGE_REQUIRED` (403) için ayrı bir callback kaydedilir: oturum
 * hâlâ geçerli olduğundan store TEMİZLENMEZ, yalnızca `/change-password`'e
 * yönlendirilir (bkz. docs/AUTH-FLOWS.md §4.3). `App.tsx` içinde bir kez
 * kayıt edilir.
 */
export function registerAuthRedirect() {
  registerUnauthorizedHandler(() => {
    useAuthStore.getState().clear()
    void router.navigate('/login')
  })

  registerPasswordChangeHandler(() => {
    void router.navigate('/change-password')
  })
}
