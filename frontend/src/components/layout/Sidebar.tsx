// Uygulama kenar çubuğu — CRM modül navigasyonu.
// Menü kalıbı Figma template'inin bilgi mimarisinden DEĞİL, `PRODUCT-BRIEF.md`'deki
// modül listesinden gelir (bkz. docs/DESIGN-SYSTEM.md §8 — IA uyuşmazlığı kararı).
//
// FAZ 2 NOTU: Yalnızca "/" (Dashboard) ve "/users" gerçek bir route'a bağlı. Listedeki diğer
// linkler ileriki fazlarda eklenecek sayfalara işaret eder; şu an tıklanınca 404'e düşmeleri
// beklenen bir durumdur — route'lar bağlandıkça bu linkler otomatik çalışır hale gelecektir.
import type { ComponentType } from 'react'
import { NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  BarChart3,
  Building2,
  CheckSquare,
  FileText,
  LayoutDashboard,
  LifeBuoy,
  MessageSquare,
  Package,
  ScrollText,
  Settings,
  Target,
  UserCog,
  UserPlus,
  Users,
} from 'lucide-react'
import { cn } from '../../lib/cn'
import { usePermission } from '../../features/auth/hooks/usePermission'

type NavItem = {
  /** `common` namespace'indeki anahtar — `label` DEĞİL: metin render anında çözülür (§1.3). */
  labelKey: string
  to: string
  permission: string
  icon: ComponentType<{ className?: string }>
  end?: boolean
}

type NavSection = {
  titleKey: string
  items: NavItem[]
}

/*
 * Menü tablosu ÇEVİRİLMİŞ METİN DEĞİL, ANAHTAR taşır (Faz 14 / İz D).
 *
 * Neden: bu sabit modül seviyesinde bir kez değerlendirilir — o an `t()` çağrılsaydı metin
 * ilk yüklenen dile DONAR ve dil değiştiğinde menü Türkçe kalırdı. Anahtar taşıyıp render
 * içinde çözmek, `languageChanged` sonrası yeniden render'ın menüyü de tazelemesini sağlar.
 */
const NAV_SECTIONS: NavSection[] = [
  {
    titleKey: 'nav.sections.main',
    items: [{ labelKey: 'nav.dashboard', to: '/', permission: 'dashboard.view', icon: LayoutDashboard, end: true }],
  },
  {
    titleKey: 'nav.sections.sales',
    items: [
      { labelKey: 'nav.leads', to: '/leads', permission: 'leads.view', icon: UserPlus },
      { labelKey: 'nav.contacts', to: '/contacts', permission: 'contacts.view', icon: Users },
      { labelKey: 'nav.companies', to: '/companies', permission: 'companies.view', icon: Building2 },
      { labelKey: 'nav.deals', to: '/deals', permission: 'deals.view', icon: Target },
      { labelKey: 'nav.quotes', to: '/quotes', permission: 'quotes.view', icon: FileText },
      { labelKey: 'nav.products', to: '/products', permission: 'products.view', icon: Package },
    ],
  },
  {
    titleKey: 'nav.sections.work',
    items: [
      { labelKey: 'nav.tasks', to: '/tasks', permission: 'tasks.view', icon: CheckSquare },
      { labelKey: 'nav.tickets', to: '/tickets', permission: 'tickets.view', icon: LifeBuoy },
      { labelKey: 'nav.chat', to: '/chat', permission: 'chat.use', icon: MessageSquare },
    ],
  },
  {
    titleKey: 'nav.sections.analysis',
    items: [{ labelKey: 'nav.reports', to: '/reports', permission: 'reports.view', icon: BarChart3 }],
  },
  {
    titleKey: 'nav.sections.admin',
    items: [
      { labelKey: 'nav.users', to: '/users', permission: 'users.view', icon: UserCog },
      { labelKey: 'nav.logs', to: '/logs', permission: 'logs.view', icon: ScrollText },
      { labelKey: 'nav.settings', to: '/settings', permission: 'settings.manage', icon: Settings },
    ],
  },
]

type SidebarProps = {
  collapsed: boolean
  mobileOpen: boolean
  onCloseMobile: () => void
}

export function Sidebar({ collapsed, mobileOpen, onCloseMobile }: SidebarProps) {
  const { can } = usePermission()
  const { t } = useTranslation('common')

  // Her item izin kontrollüdür; bir bölümün tüm item'ları gizliyse bölüm başlığı da gizlenir.
  const visibleSections = NAV_SECTIONS.map((section) => ({
    ...section,
    items: section.items.filter((item) => can(item.permission)),
  })).filter((section) => section.items.length > 0)

  return (
    <>
      {mobileOpen && (
        <div className="fixed inset-0 z-40 bg-black/50 lg:hidden" onClick={onCloseMobile} aria-hidden="true" />
      )}

      <aside
        aria-label={t('nav.aria')}
        className={cn(
          'fixed inset-y-0 left-0 z-50 flex w-60 flex-col overflow-hidden border-r border-border-subtle bg-surface-1',
          'transition-[width,transform] duration-200 ease-in-out motion-reduce:transition-none',
          // Masaüstünde akışa dahil sabit panel; mobilde off-canvas overlay.
          'lg:static lg:z-auto lg:translate-x-0',
          mobileOpen ? 'translate-x-0' : '-translate-x-full',
          collapsed ? 'lg:w-16' : 'lg:w-60'
        )}
      >
        <div className="flex h-14 shrink-0 items-center gap-2 border-b border-border-subtle px-4">
          <img
            src="/logo-mark.png"
            alt=""
            aria-hidden="true"
            width={320}
            height={165}
            className="w-8 shrink-0"
          />
          <span className={cn('truncate text-base font-semibold text-fg', collapsed && 'lg:hidden')}>
            Syncra
          </span>
        </div>

        <nav className="flex-1 overflow-y-auto overflow-x-hidden px-3 py-4">
          {visibleSections.map((section) => (
            <div key={section.titleKey} className="mb-5 last:mb-0">
              <p
                className={cn(
                  'mb-2 px-2.5 text-xs font-medium uppercase tracking-wide text-fg-muted',
                  collapsed && 'lg:hidden'
                )}
              >
                {t(section.titleKey)}
              </p>
              <ul className="flex flex-col gap-1">
                {section.items.map((item) => (
                  <li key={item.to}>
                    <NavLink
                      to={item.to}
                      end={item.end}
                      onClick={onCloseMobile}
                      title={collapsed ? t(item.labelKey) : undefined}
                      className={({ isActive }) =>
                        cn(
                          'flex items-center gap-3 rounded-md px-2.5 py-2 text-base',
                          'transition-colors duration-150 motion-reduce:transition-none',
                          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1',
                          collapsed && 'lg:justify-center',
                          isActive ? 'bg-primary-tint text-primary' : 'text-fg-secondary hover:bg-surface-2'
                        )
                      }
                    >
                      <item.icon className="size-5 shrink-0" aria-hidden="true" />
                      <span className={cn('truncate', collapsed && 'lg:hidden')}>{t(item.labelKey)}</span>
                    </NavLink>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </nav>
      </aside>
    </>
  )
}
