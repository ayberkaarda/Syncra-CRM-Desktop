// E-posta şablonları için TanStack Query hook'ları. Bu fazda e-posta GÖNDERİLMİYOR
// (MAIL_MAILER=log, kapalı devre) — buradaki hiçbir hook bir gönderim/test ucu çağırmaz.
//
// `useDeleteEmailTemplate` GERÇEK silmedir (backend 204 döner) — özel alanların/aşamaların
// aksine burada pasifleştirme semantiği YOK. Şablonun `is_active` alanı `useUpdateEmailTemplate`
// ile ayrıca açılıp kapatılabilir; ikisi birbirinden bağımsız işlemlerdir.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from '../../../components/ui'
import { getErrorMessage } from '../../../lib/axios'
import i18n from '../../../i18n'
import {
  createEmailTemplateRequest,
  deleteEmailTemplateRequest,
  fetchEmailTemplates,
  settingsKeys,
  updateEmailTemplateRequest,
} from '../api'
import type { EmailTemplatePayload } from '../types'

export function useEmailTemplates() {
  return useQuery({
    queryKey: settingsKeys.emailTemplates,
    queryFn: fetchEmailTemplates,
  })
}

export function useCreateEmailTemplate() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: EmailTemplatePayload) => createEmailTemplateRequest(payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: settingsKeys.emailTemplates })
      toast.success(i18n.t('settings:toast.templateCreated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useUpdateEmailTemplate() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: EmailTemplatePayload }) =>
      updateEmailTemplateRequest(id, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: settingsKeys.emailTemplates })
      toast.success(i18n.t('settings:toast.templateUpdated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

/** GERÇEK silme (204) — geri alınamaz. Çağıran taraf bir onay modalı ile korumalı (bkz.
 *  `EmailTemplatesTab`). */
export function useDeleteEmailTemplate() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => deleteEmailTemplateRequest(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: settingsKeys.emailTemplates })
      toast.success(i18n.t('settings:toast.templateDeleted'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}
