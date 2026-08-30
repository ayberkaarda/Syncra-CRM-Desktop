// E-posta şablonu oluşturma/düzenleme modalı — form + değişken listesi + canlı önizleme.
//
// BU FAZDA E-POSTA GÖNDERİLMİYOR (kapalı devre sistem, `MAIL_MAILER=log`) — burada bilinçli
// olarak bir "gönder" / "test et" butonu YOK (görev tanımı). Önizleme yalnızca `body_html`'in
// tarayıcıda nasıl göründüğünü gösterir, değişkenler gerçek bir değerle DEĞİŞTİRİLMEZ (backend
// henüz hangi değişkenlerin hangi bağlamda dolacağını tanımlamadı) — ham `{{değişken}}`
// biçimiyle görünür, altındaki rozet listesi hangi değişkenlerin kullanılabilir olduğunu ayrıca
// gösterir.
//
// ============================================================================
// FAZ 13 / H6 (PHASE-AUDIT §4-F5, §2-A5.3) — ÖNİZLEME ARTIK İZOLE
// ============================================================================
// ÖNCE: `<div dangerouslySetInnerHTML={{ __html: bodyHtml }} />`. Bu, reponun
// geri kalanının açıkça uyduğu "dangerouslySetInnerHTML KULLANILMAZ" çizgisinin
// (bkz. MessageBubble.tsx, chatShared.ts, ActivityDetailModal.tsx) TEK
// istisnasıydı ve yazarın kendi HTML'ini aynı origin'de çalıştırıyordu.
//
// ŞİMDİ: `<iframe sandbox="" srcDoc={...}>`. Değerlendirilen üç seçenek:
//   (a) Yalnız sunucuda sanitize edip `dangerouslySetInnerHTML`'i BIRAKMAK —
//       reddedildi: önizlemede gösterilen metin HENÜZ KAYDEDİLMEMİŞ, yani
//       sunucudan geçmemiş ham girdidir; sunucu sanitizasyonu bu ekranı hiç
//       korumaz. Üstelik kalite çizgisi ihlali aynen kalırdı.
//   (c) Önizlemeyi kaldırıp ham kaynağı `<pre>` içinde göstermek — reddedildi:
//       HTML şablonu düzenlemenin tek amacı sonucu GÖRMEK; bu, güvenlik
//       kazancını özelliği yok ederek satın almak olurdu.
//   (b) SEÇİLDİ. `sandbox=""` (BOŞ değer) tüm sandbox kısıtlarını açık bırakır:
//       script çalıştırma yok, form gönderimi yok, üst pencereye erişim yok,
//       opak (unique) origin. `allow-scripts` HİÇBİR KOŞULDA eklenmemeli;
//       `allow-scripts` + `allow-same-origin` birlikte verilirse iframe kendi
//       sandbox özniteliğini DOM'dan silip kaçabilir — yani sandbox'ı anlamsız
//       kılar. Burada ikisi de yok.
//
// Bilinen sınırlar (kabul edildi):
//   * Opak origin + script yasağı => iframe kendi yüksekliğini üst pencereye
//     bildiremez, üst pencere de `contentDocument`'ı okuyamaz. Otomatik
//     yükseklik İMKÂNSIZ; sabit yükseklik (h-80, eski `max-h-80` ile aynı) +
//     iframe'in kendi içinde kaydırma kullanılıyor.
//   * Göreli URL'ler ve harici görseller: `<img>` yüklemesi sandbox tarafından
//     engellenmez, dolayısıyla mutlak `https://` görseller görünür. Sunucu
//     sanitizasyonu zaten yalnız http/https/mailto şemalarına izin veriyor.
//     Üst belgenin CSP'si srcdoc iframe'ine MİRAS KALIR — `img-src` daraltılırsa
//     önizlemedeki harici görseller sessizce görünmez olur.
//
// 2. TUR DÜZELTME: `variables` GÖNDERİLMEZSE sunucu `body_html` içindeki `{{değişken}}` yer
// tutucularından otomatik türetiyor. Bu yüzden kullanıcı hiç değişken eklemediyse (liste boş)
// `handleSubmit` bu anahtarı payload'a HİÇ KOYMAZ — boş dizi (`[]`) göndermek "değişken yok"
// anlamına gelir ve otomatik türetmeyi geçersiz kılardı, oysa istenen davranış budur.
import { useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, X } from 'lucide-react'
import { Badge, Button, Input, Modal, Textarea } from '../../../components/ui'
import { getFieldErrors } from '../../../lib/axios'
import { useCreateEmailTemplate, useUpdateEmailTemplate } from '../hooks/useEmailTemplates'
import type { EmailTemplate } from '../types'

// Önizleme iframe'inin taban belgesi. `body_html` HAM olarak buraya gömülür ve
// KAÇIŞLANMAZ — amaç zaten HTML'i render etmek; güvenliği sağlayan şey kaçış
// değil, iframe'in `sandbox=""` kısıtlaması (bkz. PreviewFrame).
//
// Stil kasıtlı olarak uygulama temasından BAĞIMSIZ: iframe ayrı bir belgedir,
// Tailwind/tema değişkenleri oraya sızmaz ve sızmamalı. Beyaz zemin + nötr
// serif olmayan yazı tipi, şablonun gerçek bir posta istemcisinde nasıl
// görüneceğine uygulamanın koyu temasından DAHA yakın bir yaklaşımdır.
// `color-scheme: light` tarayıcının koyu modda otomatik renk çevirmesini
// engeller — çevirse önizleme yalan söylerdi.
function buildPreviewDocument(bodyHtml: string): string {
  return `<!doctype html><html lang="tr"><head><meta charset="utf-8">
<style>
  :root { color-scheme: light; }
  html, body { margin: 0; }
  body {
    padding: 12px;
    background: #ffffff;
    color: #111827;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 14px;
    line-height: 1.5;
    word-break: break-word;
  }
  img { max-width: 100%; height: auto; }
  table { border-collapse: collapse; max-width: 100%; }
</style></head><body>${bodyHtml}</body></html>`
}

export type EmailTemplateFormModalProps = {
  open: boolean
  onClose: () => void
  template?: EmailTemplate | null
}

export function EmailTemplateFormModal({ open, onClose, template }: EmailTemplateFormModalProps) {
  const { t } = useTranslation(['settings', 'common'])
  const isEdit = !!template

  const createTemplate = useCreateEmailTemplate()
  const updateTemplate = useUpdateEmailTemplate()

  const [key, setKey] = useState('')
  const [name, setName] = useState('')
  const [subject, setSubject] = useState('')
  const [bodyHtml, setBodyHtml] = useState('')
  const [variables, setVariables] = useState<string[]>([])
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  const openKey = open ? (template ? `edit-${template.id}` : 'create') : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) {
      setKey(template?.key ?? '')
      setName(template?.name ?? '')
      setSubject(template?.subject ?? '')
      setBodyHtml(template?.body_html ?? '')
      setVariables(template?.variables ?? [])
      setFieldErrors({})
    }
  }

  const isPending = createTemplate.isPending || updateTemplate.isPending

  // Her tuş vuruşunda yeni bir belge dizesi kurmak iframe'i gereksiz yere
  // yeniden yükletir (`srcDoc` değişince belge baştan ayrıştırılır).
  const previewDocument = useMemo(() => buildPreviewDocument(bodyHtml), [bodyHtml])

  function fieldError(f: string): string | undefined {
    return fieldErrors[f]?.[0]
  }

  function validate(): boolean {
    const errors: Record<string, string[]> = {}
    if (!key.trim()) errors.key = [t('settings:emailTemplateForm.errors.keyRequired')]
    if (!name.trim()) errors.name = [t('settings:emailTemplateForm.errors.nameRequired')]
    if (!subject.trim()) errors.subject = [t('settings:emailTemplateForm.errors.subjectRequired')]
    if (!bodyHtml.trim()) errors.body_html = [t('settings:emailTemplateForm.errors.bodyRequired')]
    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!validate()) return

    const cleanVariables = variables.map((v) => v.trim()).filter(Boolean)
    // Boşsa anahtarı payload'a hiç koyma — sunucu `body_html`'den otomatik türetsin (bkz. dosya
    // başı notu). `[]` göndermek bunun yerine "değişken yok" derdi.
    const payload = {
      key,
      name,
      subject,
      body_html: bodyHtml,
      ...(cleanVariables.length > 0 ? { variables: cleanVariables } : {}),
    }

    try {
      if (isEdit && template) {
        await updateTemplate.mutateAsync({ id: template.id, payload })
      } else {
        await createTemplate.mutateAsync(payload)
      }
      onClose()
    } catch (error) {
      const serverFieldErrors = getFieldErrors(error)
      if (serverFieldErrors) setFieldErrors(serverFieldErrors)
    }
  }

  function updateVariable(index: number, value: string) {
    setVariables((prev) => prev.map((v, i) => (i === index ? value : v)))
  }

  function removeVariable(index: number) {
    setVariables((prev) => prev.filter((_, i) => i !== index))
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? t('settings:emailTemplateForm.titleEdit') : t('settings:emailTemplateForm.titleCreate')}
      size="xl"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:actions.cancel')}
          </Button>
          <Button type="submit" form="email-template-form" loading={isPending}>
            {isEdit ? t('common:actions.save') : t('common:actions.create')}
          </Button>
        </div>
      }
    >
      <form id="email-template-form" onSubmit={handleSubmit} className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="flex flex-col gap-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input
              label={t('settings:emailTemplateForm.keyLabel')}
              value={key}
              onChange={(e) => setKey(e.target.value)}
              error={fieldError('key')}
              required
            />
            <Input
              label={t('settings:emailTemplateForm.nameLabel')}
              value={name}
              onChange={(e) => setName(e.target.value)}
              error={fieldError('name')}
              required
            />
          </div>

          <Input
            label={t('settings:emailTemplateForm.subjectLabel')}
            value={subject}
            onChange={(e) => setSubject(e.target.value)}
            error={fieldError('subject')}
            required
          />

          <Textarea
            label={t('settings:emailTemplateForm.bodyLabel')}
            value={bodyHtml}
            onChange={(e) => setBodyHtml(e.target.value)}
            error={fieldError('body_html')}
            className="font-mono text-xs"
            rows={10}
            required
          />

          <div className="flex flex-col gap-2">
            <span className="text-xs font-medium text-fg-muted">{t('settings:emailTemplateForm.variablesLabel')}</span>
            <p className="text-xs text-fg-muted">
              {t('settings:emailTemplateForm.variablesHint', { placeholder: '{{variable}}' })}
            </p>
            {variables.map((variable, index) => (
              <div key={index} className="flex items-center gap-2">
                <div className="flex-1">
                  <Input
                    value={variable}
                    onChange={(e) => updateVariable(index, e.target.value)}
                    placeholder={t('settings:emailTemplateForm.variablePlaceholderExample')}
                  />
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => removeVariable(index)}
                  aria-label={t('settings:emailTemplateForm.removeVariable')}
                >
                  <X className="size-4" aria-hidden="true" />
                </Button>
              </div>
            ))}
            <Button
              type="button"
              variant="secondary"
              size="sm"
              leftIcon={<Plus className="size-3.5" aria-hidden="true" />}
              onClick={() => setVariables((prev) => [...prev, ''])}
            >
              {t('settings:emailTemplateForm.addVariable')}
            </Button>
          </div>
        </div>

        <div className="flex flex-col gap-2">
          <span className="text-xs font-medium text-fg-muted">{t('settings:emailTemplateForm.previewLabel')}</span>
          <div className="flex flex-col gap-3 rounded-lg border border-border-subtle bg-surface-2 p-4">
            <div className="border-b border-border-subtle pb-2">
              <p className="text-xs text-fg-muted">{t('settings:emailTemplateForm.previewSubjectLabel')}</p>
              <p className="text-sm font-medium text-fg">{subject || t('settings:emailTemplateForm.previewEmptySubject')}</p>
            </div>
            {bodyHtml ? (
              <iframe
                title={t('settings:emailTemplateForm.previewIframeTitle')}
                sandbox=""
                srcDoc={previewDocument}
                referrerPolicy="no-referrer"
                className="h-80 w-full rounded-md border-0 bg-white"
              />
            ) : (
              <div className="rounded-md bg-surface-1 p-3 text-sm">
                <p className="text-fg-muted">{t('settings:emailTemplateForm.previewPlaceholder')}</p>
              </div>
            )}
            <p className="text-xs text-fg-muted">{t('settings:emailTemplateForm.previewNote')}</p>
            {variables.filter((v) => v.trim()).length > 0 && (
              <div className="flex flex-wrap gap-1.5">
                {variables
                  .filter((v) => v.trim())
                  .map((variable, index) => (
                    <Badge key={index} variant="neutral" size="sm">
                      {`{{${variable.trim()}}}`}
                    </Badge>
                  ))}
              </div>
            )}
          </div>
        </div>
      </form>
    </Modal>
  )
}
