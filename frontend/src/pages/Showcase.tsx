// Bileşen vitrini — tüm UI primitive'lerinin varyant/durumlarını iki temada görsel denetim için listeler.
// Faz 1 / Dalga 2 çıktısı; kalıcı bir ürün sayfası değildir.
import { useState } from 'react'
import {
  AtSign,
  Bell,
  Inbox,
  Info,
  Laptop,
  Mail,
  Moon,
  Plus,
  Search,
  Sun,
  TriangleAlert,
} from 'lucide-react'
import { useTheme } from '../hooks/useTheme'
import type { Theme } from '../stores/themeStore'
import {
  Avatar,
  AvatarGroup,
  Badge,
  Button,
  Card,
  CardBody,
  CardFooter,
  CardHeader,
  Checkbox,
  EmptyState,
  Input,
  Modal,
  Pagination,
  Select,
  Skeleton,
  Tab,
  TabList,
  Tabs,
  Table,
  TBody,
  Td,
  Textarea,
  Th,
  THead,
  toast,
  Tr,
} from '../components/ui'

const BUTTON_VARIANTS = ['primary', 'secondary', 'ghost', 'danger', 'link'] as const
const BUTTON_SIZES = ['sm', 'md', 'lg'] as const
const BADGE_VARIANTS = ['primary', 'success', 'danger', 'warning', 'neutral'] as const
const BADGE_SIZES = ['sm', 'md'] as const
const AVATAR_SIZES = ['xs', 'sm', 'md', 'lg'] as const

type Employee = {
  id: number
  name: string
  role: string
  status: 'Aktif' | 'Beklemede' | 'Pasif'
  statusVariant: 'success' | 'warning' | 'neutral'
}

const EMPLOYEES: Employee[] = [
  { id: 1, name: 'Elif Demir', role: 'Ürün Tasarımcısı', status: 'Aktif', statusVariant: 'success' },
  { id: 2, name: 'Ahmet Yıldız', role: 'Backend Mühendisi', status: 'Beklemede', statusVariant: 'warning' },
  { id: 3, name: 'Zeynep Kaya', role: 'Proje Yöneticisi', status: 'Aktif', statusVariant: 'success' },
  { id: 4, name: 'Mert Şahin', role: 'QA Mühendisi', status: 'Pasif', statusVariant: 'neutral' },
  { id: 5, name: 'Selin Arslan', role: 'Frontend Mühendisi', status: 'Aktif', statusVariant: 'success' },
]

function SectionCard({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader title={title} />
      <CardBody className="flex flex-col gap-6">{children}</CardBody>
    </Card>
  )
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-2">
      <p className="text-xs font-medium text-fg-muted">{label}</p>
      <div className="flex flex-wrap items-center gap-3">{children}</div>
    </div>
  )
}

function ThemeSwitcher() {
  const { theme, setTheme } = useTheme()

  const options: Array<{ value: Theme; label: string; icon: React.ReactNode }> = [
    { value: 'light', label: 'Açık', icon: <Sun className="size-3.5" aria-hidden="true" /> },
    { value: 'dark', label: 'Koyu', icon: <Moon className="size-3.5" aria-hidden="true" /> },
    { value: 'system', label: 'Sistem', icon: <Laptop className="size-3.5" aria-hidden="true" /> },
  ]

  return (
    <Tabs value={theme} onValueChange={(value) => setTheme(value as Theme)} variant="segment">
      <TabList aria-label="Tema seçimi">
        {options.map((option) => (
          <Tab key={option.value} value={option.value}>
            {option.icon}
            {option.label}
          </Tab>
        ))}
      </TabList>
    </Tabs>
  )
}

export default function Showcase() {
  const [modalOpen, setModalOpen] = useState(false)
  const [checkedDemo, setCheckedDemo] = useState(true)
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('asc')
  const [currentPage, setCurrentPage] = useState(1)
  const [underlineTab, setUnderlineTab] = useState('notes')
  const [segmentTab, setSegmentTab] = useState('week')

  const pageSize = 5
  const totalItems = 42

  const sortedEmployees = [...EMPLOYEES].sort((a, b) =>
    sortDirection === 'desc' ? b.name.localeCompare(a.name) : a.name.localeCompare(b.name)
  )

  return (
    <div className="min-h-screen bg-surface-0 pb-24">
      <header className="sticky top-0 z-10 border-b border-border-subtle bg-surface-0/90 backdrop-blur-sm">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-5">
          <div>
            <h1 className="text-2xl font-semibold text-fg">Bileşen Vitrini</h1>
            <p className="text-sm text-fg-muted">Faz 1 / Dalga 2 — layout ve geri bildirim primitive'leri</p>
          </div>
          <ThemeSwitcher />
        </div>
      </header>

      <main className="mx-auto flex max-w-6xl flex-col gap-8 px-6 py-8">
        <SectionCard title="Button">
          <Row label="Varyant × Boyut">
            <div className="flex flex-col gap-3">
              {BUTTON_VARIANTS.map((variant) => (
                <div key={variant} className="flex flex-wrap items-center gap-3">
                  {BUTTON_SIZES.map((size) => (
                    <Button key={size} variant={variant} size={size}>
                      {variant} / {size}
                    </Button>
                  ))}
                </div>
              ))}
            </div>
          </Row>
          <Row label="Durumlar">
            <Button loading>Yükleniyor</Button>
            <Button disabled>Devre dışı</Button>
            <Button leftIcon={<Plus className="size-4" aria-hidden="true" />}>Sol ikon</Button>
            <Button variant="secondary" rightIcon={<Mail className="size-4" aria-hidden="true" />}>
              Sağ ikon
            </Button>
          </Row>
        </SectionCard>

        <SectionCard title="Input / Select / Textarea">
          <Row label="Input">
            <Input placeholder="Normal" />
            <Input placeholder="Hata" error="Bu alan zorunlu" />
            <Input placeholder="İpucu" hint="En az 3 karakter girin" />
            <Input placeholder="Devre dışı" disabled />
            <Input placeholder="Arama" leftIcon={<Search className="size-4" aria-hidden="true" />} />
          </Row>
          <Row label="Select">
            <Select
              placeholder="Seçiniz"
              options={[
                { value: 'a', label: 'Seçenek A' },
                { value: 'b', label: 'Seçenek B' },
              ]}
            />
            <Select
              error="Geçerli bir seçim yapın"
              options={[{ value: 'a', label: 'Seçenek A' }]}
            />
            <Select
              hint="Varsayılan değer önceden seçili"
              options={[{ value: 'a', label: 'Seçenek A' }]}
            />
            <Select disabled options={[{ value: 'a', label: 'Devre dışı' }]} />
          </Row>
          <Row label="Textarea">
            <Textarea placeholder="Normal" className="min-w-64" />
            <Textarea placeholder="Hata" error="Açıklama gerekli" className="min-w-64" />
            <Textarea placeholder="İpucu" hint="Maksimum 500 karakter" className="min-w-64" />
            <Textarea placeholder="Devre dışı" disabled className="min-w-64" />
          </Row>
        </SectionCard>

        <SectionCard title="Checkbox">
          <Row label="Durumlar">
            <Checkbox label="Normal" />
            <Checkbox label="İşaretli" checked={checkedDemo} onChange={(e) => setCheckedDemo(e.target.checked)} />
            <Checkbox label="Belirsiz (indeterminate)" indeterminate />
            <Checkbox label="Hatalı" error="Bu kutu işaretlenmeli" />
            <Checkbox label="Devre dışı" disabled />
          </Row>
        </SectionCard>

        <SectionCard title="Badge">
          <Row label="Varyant × Boyut">
            {BADGE_VARIANTS.map((variant) => (
              <div key={variant} className="flex items-center gap-2">
                {BADGE_SIZES.map((size) => (
                  <Badge key={size} variant={variant} size={size}>
                    {variant}
                  </Badge>
                ))}
              </div>
            ))}
          </Row>
          <Row label="Nokta ile">
            {BADGE_VARIANTS.map((variant) => (
              <Badge key={variant} variant={variant} dot>
                {variant}
              </Badge>
            ))}
          </Row>
        </SectionCard>

        <SectionCard title="Avatar">
          <Row label="Boyutlar">
            {AVATAR_SIZES.map((size) => (
              <Avatar key={size} size={size} name="Elif Demir" />
            ))}
          </Row>
          <Row label="Görselli / Baş harfli">
            <Avatar name="Selin Arslan" src="https://i.pravatar.cc/80?img=5" />
            <Avatar name="Mert Şahin" />
            <Avatar name="Ahmet Yıldız" src="https://broken-url.invalid/x.png" />
          </Row>
          <Row label="Durum noktası">
            <Avatar name="Elif Demir" status="online" />
            <Avatar name="Ahmet Yıldız" status="busy" />
            <Avatar name="Zeynep Kaya" status="offline" />
          </Row>
          <Row label="AvatarGroup (max=3)">
            <AvatarGroup max={3}>
              <Avatar name="Elif Demir" />
              <Avatar name="Ahmet Yıldız" />
              <Avatar name="Zeynep Kaya" />
              <Avatar name="Mert Şahin" />
              <Avatar name="Selin Arslan" />
            </AvatarGroup>
          </Row>
        </SectionCard>

        <SectionCard title="Card">
          <Card>
            <CardHeader
              title="Aktif Projeler"
              subtitle="Bu ay güncellenen projeler"
              action={
                <Button variant="link" size="sm">
                  Tümünü Gör
                </Button>
              }
            />
            <CardBody>
              <p className="text-sm text-fg-secondary">
                Gövde içeriği burada yer alır — herhangi bir bileşen gömülebilir.
              </p>
            </CardBody>
            <CardFooter className="flex justify-end">
              <Button size="sm">Rapor Al</Button>
            </CardFooter>
          </Card>
        </SectionCard>

        <SectionCard title="Table + Pagination">
          <Table>
            <THead>
              <Tr>
                <Th
                  sortable
                  sortDirection={sortDirection}
                  onSort={() => setSortDirection((prev) => (prev === 'asc' ? 'desc' : 'asc'))}
                >
                  Ad Soyad
                </Th>
                <Th>Rol</Th>
                <Th align="right">Durum</Th>
              </Tr>
            </THead>
            <TBody>
              {sortedEmployees.map((employee) => (
                <Tr key={employee.id}>
                  <Td>
                    <div className="flex items-center gap-3">
                      <Avatar name={employee.name} size="sm" />
                      <span className="font-medium">{employee.name}</span>
                    </div>
                  </Td>
                  <Td className="text-fg-secondary">{employee.role}</Td>
                  <Td align="right">
                    <Badge variant={employee.statusVariant}>{employee.status}</Badge>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
          <Pagination
            currentPage={currentPage}
            totalItems={totalItems}
            pageSize={pageSize}
            onPageChange={setCurrentPage}
          />
        </SectionCard>

        <SectionCard title="Modal">
          <Button onClick={() => setModalOpen(true)}>Modalı Aç</Button>
          <Modal
            open={modalOpen}
            onClose={() => setModalOpen(false)}
            title="Çalışan Ekle"
            description="Yeni çalışan bilgilerini girin"
            footer={
              <div className="flex justify-end gap-3">
                <Button variant="secondary" onClick={() => setModalOpen(false)}>
                  Vazgeç
                </Button>
                <Button onClick={() => setModalOpen(false)}>Kaydet</Button>
              </div>
            }
          >
            <div className="flex flex-col gap-4">
              <Input label="Ad Soyad" placeholder="Örn. Elif Demir" />
              <Input label="E-posta" placeholder="ornek@syncra.com" leftIcon={<AtSign className="size-4" aria-hidden="true" />} />
            </div>
          </Modal>
        </SectionCard>

        <SectionCard title="Toast">
          <Row label="Varyantlar">
            <Button
              variant="secondary"
              onClick={() => toast.success('Kayıt başarıyla oluşturuldu', { description: 'Değişiklikler kaydedildi.' })}
            >
              Başarı
            </Button>
            <Button
              variant="secondary"
              onClick={() => toast.error('İşlem başarısız oldu', { description: 'Lütfen tekrar deneyin.' })}
            >
              Hata
            </Button>
            <Button
              variant="secondary"
              onClick={() => toast.warning('Depolama alanı azalıyor', { description: '%90 doluluk oranına ulaşıldı.' })}
            >
              Uyarı
            </Button>
            <Button
              variant="secondary"
              onClick={() => toast.info('Yeni bir güncelleme mevcut', { description: 'Sayfayı yenileyin.' })}
            >
              Bilgi
            </Button>
          </Row>
        </SectionCard>

        <SectionCard title="Skeleton">
          <Row label="Text (çok satır)">
            <div className="w-64">
              <Skeleton variant="text" lines={3} />
            </div>
          </Row>
          <Row label="Circle / Rect">
            <Skeleton variant="circle" width={48} height={48} />
            <Skeleton variant="rect" width={160} height={90} />
          </Row>
        </SectionCard>

        <SectionCard title="EmptyState">
          <div className="rounded-lg border border-dashed border-border">
            <EmptyState
              icon={<Inbox className="size-6" aria-hidden="true" />}
              title="Henüz kayıt yok"
              description="Yeni bir kayıt oluşturduğunuzda burada listelenecek."
              action={<Button size="sm">Yeni Kayıt Oluştur</Button>}
            />
          </div>
        </SectionCard>

        <SectionCard title="Tabs">
          <Row label="underline">
            <Tabs value={underlineTab} onValueChange={setUnderlineTab} variant="underline">
              <TabList aria-label="Bildirim türleri" className="w-full">
                <Tab value="notes">
                  <Bell className="size-3.5" aria-hidden="true" />
                  NOTLAR
                </Tab>
                <Tab value="alerts">
                  <TriangleAlert className="size-3.5" aria-hidden="true" />
                  UYARILAR
                </Tab>
                <Tab value="chat">
                  <Info className="size-3.5" aria-hidden="true" />
                  SOHBET
                </Tab>
              </TabList>
            </Tabs>
            <p className="w-full text-sm text-fg-muted">
              Seçili sekme: <span className="font-medium text-fg">{underlineTab}</span>
            </p>
          </Row>
          <Row label="segment">
            <Tabs value={segmentTab} onValueChange={setSegmentTab} variant="segment">
              <TabList aria-label="Zaman aralığı">
                <Tab value="week">Hafta</Tab>
                <Tab value="month">Ay</Tab>
                <Tab value="year">Yıl</Tab>
                <Tab value="all">Tümü</Tab>
              </TabList>
            </Tabs>
            <p className="w-full text-sm text-fg-muted">
              Seçili sekme: <span className="font-medium text-fg">{segmentTab}</span>
            </p>
          </Row>
        </SectionCard>
      </main>
    </div>
  )
}
