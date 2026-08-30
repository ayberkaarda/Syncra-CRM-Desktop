// Ayarlar sayfası — sekmeli kabuk. Sekme durumu URL'de tutulur (`/settings?tab=pipeline`) ki
// sayfa yenilemede kaybolmasın (bkz. görev tanımı).
//
// Tüm sekmeler `settings.manage` izni gerektirir — bu tek izin sayfa rotasında
// (`router.tsx` → `RequireAuth permission="settings.manage"`) zaten uygulanıyor, bu yüzden
// burada sekme bazlı ek bir yetki kontrolü YOK.
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Card, CardBody, CardHeader, Tab, TabList, TabPanel, Tabs } from '../../../components/ui'
import { AutomationRulesTab } from '../components/AutomationRulesTab'
import { CompanyProfileTab } from '../components/CompanyProfileTab'
import { CustomFieldsTab } from '../components/CustomFieldsTab'
import { EmailTemplatesTab } from '../components/EmailTemplatesTab'
import { ExchangeRatesTab } from '../components/ExchangeRatesTab'
import { PermissionMatrixTab } from '../components/PermissionMatrixTab'
import { PipelineStagesTab } from '../components/PipelineStagesTab'

const TAB_VALUES = [
  'company',
  'pipeline',
  'custom-fields',
  'email-templates',
  'permissions',
  'exchange-rates',
  'automation-rules',
] as const
type TabValue = (typeof TAB_VALUES)[number]

const DEFAULT_TAB: TabValue = 'company'

function isTabValue(value: string | null): value is TabValue {
  return !!value && (TAB_VALUES as readonly string[]).includes(value)
}

export function SettingsPage() {
  const { t } = useTranslation('settings')
  const [searchParams, setSearchParams] = useSearchParams()
  const activeTab: TabValue = isTabValue(searchParams.get('tab')) ? (searchParams.get('tab') as TabValue) : DEFAULT_TAB

  function handleTabChange(value: string) {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      next.set('tab', value)
      return next
    })
  }

  return (
    <div className="flex flex-col gap-4">
      <Card>
        <CardHeader title={t('settings:page.title')} subtitle={t('settings:page.subtitle')} />
        <CardBody>
          <Tabs value={activeTab} onValueChange={handleTabChange}>
            <TabList className="mb-5 overflow-x-auto">
              <Tab value="company">{t('settings:tabs.company')}</Tab>
              <Tab value="pipeline">{t('settings:tabs.pipeline')}</Tab>
              <Tab value="custom-fields">{t('settings:tabs.customFields')}</Tab>
              <Tab value="email-templates">{t('settings:tabs.emailTemplates')}</Tab>
              <Tab value="permissions">{t('settings:tabs.permissions')}</Tab>
              <Tab value="exchange-rates">{t('settings:tabs.exchangeRates')}</Tab>
              <Tab value="automation-rules">{t('settings:tabs.automationRules')}</Tab>
            </TabList>

            <TabPanel value="company">
              <CompanyProfileTab />
            </TabPanel>
            <TabPanel value="pipeline">
              <PipelineStagesTab />
            </TabPanel>
            <TabPanel value="custom-fields">
              <CustomFieldsTab />
            </TabPanel>
            <TabPanel value="email-templates">
              <EmailTemplatesTab />
            </TabPanel>
            <TabPanel value="permissions">
              <PermissionMatrixTab />
            </TabPanel>
            <TabPanel value="exchange-rates">
              <ExchangeRatesTab />
            </TabPanel>
            <TabPanel value="automation-rules">
              <AutomationRulesTab />
            </TabPanel>
          </Tabs>
        </CardBody>
      </Card>
    </div>
  )
}
