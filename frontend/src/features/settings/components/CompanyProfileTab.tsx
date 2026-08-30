// Şirket Profili sekmesi — `group='company'` ayarları için tek formlu düzenleme.
//
// 2. TUR DÜZELTME: `Setting.value` artık ham `string | null` DEĞİL — `type`'a göre backend'de
// zaten cast edilmiş gerçek değer (`SettingValue`, bkz. `types.ts`). Bu yüzden burada
// `JSON.parse` / sayı-string çevrimi bir "yeniden yorumlama" değil, yalnızca form
// input'larının metin tabanlı olması nedeniyle GEÇİCİ bir düzenleme tamponudur — kaydederken
// tamponun içeriği `type`'a uygun gerçek tipe (number/boolean/nesne) geri çevrilip öyle
// gönderilir. `json` tipinde tampon `JSON.stringify(value, null, 2)` ile doldurulur, kaydetmeden
// önce `JSON.parse` edilip NESNE olarak yollanır (ham metin olarak DEĞİL).
//
// Yalnızca DOKUNULMUŞ (touched) anahtarlar `PATCH /api/settings`'e gönderilir. Yanıt `GET` ile
// aynı şekli (tüm liste + `meta.groups`) döndüğü için hook doğrudan cache'e yazıyor (bkz.
// `hooks/useSettings.ts`).
//
// Backend henüz hangi `company.*` anahtarlarını döneceğini netleştirmediği için burada sabit
// bir alan listesi VARSAYILMAZ — gelen her `Setting` dinamik olarak render edilir. Bilinen
// yaygın anahtarlar için Türkçe etiket sözlüğü var, bilinmeyen bir anahtar anahtar adından
// türetilmiş bir etiketle (bkz. `titleizeKey`) yine de doğru render edilir.
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { Save, Undo2 } from 'lucide-react'
import { Button, Checkbox, Input, Skeleton, Textarea, toast } from '../../../components/ui'
import { useSettings, useUpdateSettings } from '../hooks/useSettings'
import type { Setting, SettingValue } from '../types'

// `setting.key` (ör. `company.tax_number`) -> `settings:company.fields` altındaki camelCase
// anahtar (ör. `taxNumber`). Yalnızca BİLİNEN alanlar için çeviri var; bilinmeyen bir anahtar
// `titleizeKey` ile anahtar adından türetilmiş bir etikete düşer (bkz. dosya başı notu).
const FIELD_LABEL_SUFFIX: Record<string, string> = {
  'company.name': 'name',
  'company.email': 'email',
  'company.phone': 'phone',
  'company.address': 'address',
  'company.website': 'website',
  'company.tax_number': 'taxNumber',
  'company.tax_office': 'taxOffice',
  'company.logo_url': 'logoUrl',
  'company.currency': 'currency',
}

// `settings:keys.company.<snake>.description` çevirisi bulunan alanlar (§1.5 — ayar
// açıklamaları çevrilir, DB `description` yalnızca çevirisi olmayan anahtarlar için fallback'tir).
const DESCRIBABLE_KEYS = new Set(['name', 'email', 'phone', 'address', 'tax_number'])

const MULTILINE_HINTS = ['address', 'description', 'note']

function titleizeKey(key: string): string {
  const short = key.includes('.') ? key.split('.').slice(1).join(' ') : key
  const words = short.replace(/_/g, ' ').trim()
  return words.replace(/\b\w/g, (c) => c.toUpperCase()) || key
}

function labelFor(setting: Setting, t: TFunction): string {
  const suffix = FIELD_LABEL_SUFFIX[setting.key]
  return suffix ? t(`settings:company.fields.${suffix}`) : titleizeKey(setting.key)
}

/** Çevirisi olan bir açıklama varsa onu, yoksa sunucudan gelen `description`'ı (seed-metadata
 *  fallback, §1.5) döner. */
function descriptionFor(setting: Setting, t: TFunction): string | undefined {
  const snake = setting.key.split('.').pop() ?? ''
  if (DESCRIBABLE_KEYS.has(snake)) return t(`settings:keys.company.${snake}.description`)
  return setting.description ?? undefined
}

function isMultiline(key: string): boolean {
  return MULTILINE_HINTS.some((hint) => key.includes(hint))
}

/** Sunucudan gelen gerçek `SettingValue`'yu metin tabanlı düzenleme tamponuna çevirir
 *  (boolean hariç — o `Checkbox`'a doğrudan bağlanır). */
function toEditableText(setting: Setting): string {
  if (setting.type === 'json') return JSON.stringify(setting.value ?? null, null, 2)
  if (setting.value === null || setting.value === undefined) return ''
  return String(setting.value)
}

export function CompanyProfileTab() {
  const { t } = useTranslation(['settings', 'common'])
  const { data, isLoading, isError, refetch } = useSettings()
  const updateSettings = useUpdateSettings()

  const companySettings = (data?.data ?? []).filter((setting) => setting.group === 'company')

  // Metin/JSON alanları için tampon, boolean alanlar için ayrı tampon (checkbox `checked` boolean bekler).
  const [textValues, setTextValues] = useState<Record<string, string>>({})
  const [boolValues, setBoolValues] = useState<Record<string, boolean>>({})
  const [touched, setTouched] = useState<Set<string>>(new Set())
  const [jsonErrors, setJsonErrors] = useState<Record<string, string>>({})

  function seedFromServer() {
    const nextText: Record<string, string> = {}
    const nextBool: Record<string, boolean> = {}
    for (const setting of companySettings) {
      if (setting.type === 'boolean') nextBool[setting.key] = setting.value === true
      else nextText[setting.key] = toEditableText(setting)
    }
    setTextValues(nextText)
    setBoolValues(nextBool)
    setTouched(new Set())
    setJsonErrors({})
  }

  // Sunucudan yeni veri geldiğinde (ilk yükleme VEYA başarılı kayıt sonrası cache güncellemesi)
  // yeniden tohumla — ama kullanıcının kaydedilmemiş değişikliği varsa (touched dolu) ÜZERİNE
  // YAZMA.
  useEffect(() => {
    if (touched.size > 0) return
    if (companySettings.length === 0) return
    seedFromServer()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data])

  function markTouched(key: string) {
    setTouched((prev) => new Set(prev).add(key))
  }

  function handleTextChange(key: string, next: string) {
    setTextValues((prev) => ({ ...prev, [key]: next }))
    markTouched(key)
  }

  function handleBoolChange(key: string, next: boolean) {
    setBoolValues((prev) => ({ ...prev, [key]: next }))
    markTouched(key)
  }

  const isDirty = touched.size > 0

  async function handleSave() {
    const errors: Record<string, string> = {}
    const patch: Record<string, SettingValue> = {}

    for (const key of touched) {
      const setting = companySettings.find((s) => s.key === key)
      if (!setting) continue

      if (setting.type === 'boolean') {
        patch[key] = boolValues[key] ?? false
        continue
      }

      const raw = (textValues[key] ?? '').trim()

      if (setting.type === 'integer') {
        if (raw === '') {
          patch[key] = null
          continue
        }
        const num = Number(raw)
        if (Number.isNaN(num)) {
          errors[key] = t('settings:company.errors.invalidNumber')
          continue
        }
        patch[key] = num
        continue
      }

      if (setting.type === 'json') {
        if (raw === '') {
          patch[key] = null
          continue
        }
        try {
          patch[key] = JSON.parse(raw)
        } catch {
          errors[key] = t('settings:company.errors.invalidJson')
        }
        continue
      }

      patch[key] = raw === '' ? null : raw
    }

    setJsonErrors(errors)
    if (Object.keys(errors).length > 0) {
      toast.error(t('settings:company.errors.fixBeforeSave'))
      return
    }
    if (Object.keys(patch).length === 0) return

    await updateSettings.mutateAsync(patch)
  }

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} variant="rect" height={40} />
        ))}
      </div>
    )
  }

  if (isError) {
    return (
      <div className="flex flex-col items-center gap-3 py-12 text-center">
        <p className="text-sm text-fg-muted">{t('settings:company.loadError')}</p>
        <Button variant="secondary" onClick={() => refetch()}>
          {t('common:actions.retry')}
        </Button>
      </div>
    )
  }

  if (companySettings.length === 0) {
    return <p className="py-8 text-center text-sm text-fg-muted">{t('settings:company.empty')}</p>
  }

  return (
    <div className="flex flex-col gap-5">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {companySettings.map((setting) => {
          const label = labelFor(setting, t)

          if (setting.type === 'boolean') {
            return (
              <Checkbox
                key={setting.key}
                label={label}
                checked={boolValues[setting.key] ?? false}
                onChange={(e) => handleBoolChange(setting.key, e.target.checked)}
              />
            )
          }

          const value = textValues[setting.key] ?? ''

          if (setting.type === 'json') {
            return (
              <div key={setting.key} className="sm:col-span-2">
                <Textarea
                  label={label}
                  value={value}
                  onChange={(e) => handleTextChange(setting.key, e.target.value)}
                  hint={jsonErrors[setting.key] ? undefined : descriptionFor(setting, t) ?? t('settings:company.jsonHint')}
                  error={jsonErrors[setting.key]}
                  className="font-mono text-xs"
                  rows={5}
                />
              </div>
            )
          }

          if (isMultiline(setting.key)) {
            return (
              <div key={setting.key} className="sm:col-span-2">
                <Textarea
                  label={label}
                  value={value}
                  onChange={(e) => handleTextChange(setting.key, e.target.value)}
                  hint={descriptionFor(setting, t)}
                />
              </div>
            )
          }

          return (
            <Input
              key={setting.key}
              label={label}
              type={setting.type === 'integer' ? 'number' : 'text'}
              value={value}
              onChange={(e) => handleTextChange(setting.key, e.target.value)}
              hint={descriptionFor(setting, t)}
            />
          )
        })}
      </div>

      <div className="flex items-center justify-end gap-2 border-t border-border-subtle pt-4">
        <Button
          type="button"
          variant="secondary"
          leftIcon={<Undo2 className="size-4" aria-hidden="true" />}
          onClick={seedFromServer}
          disabled={!isDirty || updateSettings.isPending}
        >
          {t('settings:company.resetChanges')}
        </Button>
        <Button
          type="button"
          leftIcon={<Save className="size-4" aria-hidden="true" />}
          onClick={handleSave}
          disabled={!isDirty}
          loading={updateSettings.isPending}
        >
          {t('common:actions.save')}
        </Button>
      </div>
    </div>
  )
}
