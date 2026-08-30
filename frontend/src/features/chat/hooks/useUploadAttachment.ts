// Dosya eki yükleme (`POST /api/attachments`, multipart, alan adı `file`).
//
// AKIŞ: önce dosya yüklenir → dönen `Attachment.id` ile `useSendMessage` çağrılır
// (`{ attachment_id }`). Yükleme ve mesaj gönderme AYRI adımlardır; böylece büyük bir dosya
// yüklenirken kullanıcı yazmaya devam edebilir ve yükleme başarısız olursa ortada yarım bir
// mesaj kalmaz.
import { useCallback, useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import type { UseMutationResult } from '@tanstack/react-query'
import { toast } from '../../../components/ui'
import { getErrorMessage } from '../../../lib/axios'
import { uploadAttachmentRequest } from '../api'
import type { Attachment } from '../types'

export type UseUploadAttachmentResult = UseMutationResult<Attachment, Error, File> & {
  /** 0–100. Sunucu `Content-Length` vermezse 0'da kalır — UI belirsiz gösterge çizmelidir. */
  progress: number
  /** Süren yüklemeyi iptal eder (axios `AbortSignal`). */
  cancel: () => void
  /** Mutasyon durumunu VE yüzdeyi sıfırlar. */
  resetUpload: () => void
}

export function useUploadAttachment(): UseUploadAttachmentResult {
  const [progress, setProgress] = useState(0)
  const abortRef = useRef<AbortController | null>(null)

  const mutation = useMutation<Attachment, Error, File>({
    mutationFn: (file) => {
      const controller = new AbortController()
      abortRef.current = controller
      setProgress(0)
      return uploadAttachmentRequest(file, setProgress, controller.signal)
    },
    onSuccess: () => setProgress(100),
    onError: (error) => {
      setProgress(0)
      // İptal kullanıcının kendi eylemidir; hata olarak bildirilmez.
      if (abortRef.current?.signal.aborted) return
      toast.error(getErrorMessage(error))
    },
    onSettled: () => {
      abortRef.current = null
    },
  })

  const cancel = useCallback(() => {
    abortRef.current?.abort()
    setProgress(0)
  }, [])

  const { reset } = mutation
  const resetUpload = useCallback(() => {
    reset()
    setProgress(0)
  }, [reset])

  // `useMessageMutations.ts`teki `retry`/`discard` benzeri hiçbir ref'e DOKUNMUYOR, bu yüzden
  // orada `Object.assign` sorunsuzdu. Burada `cancel` `abortRef.current`ı okuyor — bir ref'e
  // dokunan bir kapanışı `Object.assign(...)` gibi bir FONKSİYON ÇAĞRISININ argümanı olarak
  // render sırasında iletmek React Compiler'ı tetikliyor (statik olarak "render sırasında ref
  // okunabilir" diye işaretliyor). Adi bir NESNE YAYILIMI (object spread) fonksiyon çağrısı
  // SAYILMADIĞINDAN bu uyarıyı tetiklemiyor ve davranış birebir aynı kalıyor (ikisi de kendi
  // numaralandırılabilir özelliklerini sığ biçimde kopyalar) — tip kontrolü de (`tsc -b`) hatasız
  // geçiyor, dolayısıyla union tipi endişesiyle `Object.assign`e gerek yoktu.
  return { ...mutation, progress, cancel, resetUpload }
}
