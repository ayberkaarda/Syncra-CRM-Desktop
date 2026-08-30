import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { useTranslation } from 'react-i18next'
import { Eye, EyeOff, Lock, Mail } from 'lucide-react'
import { Button, Card, CardBody, Checkbox, Input, Modal, toast } from '../../../components/ui'
import { getErrorMessage, getFieldErrors } from '../../../lib/axios'
import { useAuth, useForgotPassword } from '../hooks/useAuth'
import { LanguageSwitcher } from '../../../i18n/LanguageSwitcher'

type LocationState = { from?: { pathname: string } }

function getRetryAfterSeconds(error: unknown): number | null {
  if (!isAxiosError(error)) return null
  const header = error.response?.headers?.['retry-after']
  const seconds = Number(header)
  return Number.isFinite(seconds) && seconds > 0 ? seconds : null
}

export function LoginPage() {
  const navigate = useNavigate()
  const location = useLocation()
  // Giriş ekranı PRE-AUTH'tur: burada gösterilen dil localStorage'daki seçimden gelir,
  // `users.locale` daha okunmamıştır (§1.3). Bu yüzden metinler de çevrilmek ZORUNDA —
  // kullanıcı uygulamaya ilk temasında kendi dilini görmeli.
  const { t } = useTranslation('auth')
  const { login, isLoggingIn } = useAuth()
  const forgotPassword = useForgotPassword()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [lockoutSeconds, setLockoutSeconds] = useState(0)

  const [forgotOpen, setForgotOpen] = useState(false)
  const [forgotEmail, setForgotEmail] = useState('')
  const [forgotSent, setForgotSent] = useState(false)

  const emailInputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    emailInputRef.current?.focus()
  }, [])

  useEffect(() => {
    if (lockoutSeconds <= 0) return
    const timer = setInterval(() => {
      setLockoutSeconds((current) => (current > 0 ? current - 1 : 0))
    }, 1000)
    return () => clearInterval(timer)
  }, [lockoutSeconds])

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (lockoutSeconds > 0 || isLoggingIn) return

    setFormError(null)
    setFieldErrors({})

    try {
      const user = await login({ email, password, remember })
      const state = location.state as LocationState | null
      navigate(state?.from?.pathname ?? '/', { replace: true })
      toast.success(t('login.welcome', { name: user.name }))
    } catch (error) {
      const retryAfter = getRetryAfterSeconds(error)
      if (retryAfter) {
        setLockoutSeconds(retryAfter)
        setFormError(t('login.lockout', { seconds: retryAfter }))
        return
      }

      const fields = getFieldErrors(error)
      if (fields) {
        setFieldErrors(fields)
      }
      setFormError(getErrorMessage(error))
    }
  }

  async function handleForgotSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    try {
      await forgotPassword.mutateAsync({ email: forgotEmail })
      setForgotSent(true)
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  function closeForgotModal() {
    setForgotOpen(false)
    setForgotEmail('')
    setForgotSent(false)
  }

  const submitDisabled = isLoggingIn || lockoutSeconds > 0

  return (
    <main className="flex min-h-screen flex-col items-center justify-center bg-surface-0 px-4 py-12">
      <div className="flex w-full max-w-sm flex-col gap-6">
        <div className="flex flex-col items-center gap-1 text-center">
          <img
            src="/logo-mark.png"
            alt=""
            aria-hidden="true"
            width={320}
            height={165}
            className="mb-2 w-28"
          />
          <span className="text-2xl font-semibold tracking-tight text-fg">Syncra</span>
          <p className="text-sm text-fg-muted">{t('login.subtitle')}</p>
        </div>

        <Card>
          <CardBody className="flex flex-col gap-5">
            <form onSubmit={handleSubmit} className="flex flex-col gap-4" noValidate>
              <div aria-live="polite">
                {formError && (
                  <div className="rounded-md border border-danger bg-danger-tint px-3 py-2 text-sm text-danger">
                    {formError}
                  </div>
                )}
              </div>

              <Input
                ref={emailInputRef}
                type="email"
                label={t('login.email')}
                name="email"
                autoComplete="email"
                placeholder={t('login.emailPlaceholder')}
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                leftIcon={<Mail className="size-4" aria-hidden="true" />}
                error={fieldErrors.email?.[0]}
                required
              />

              <Input
                type={showPassword ? 'text' : 'password'}
                label={t('login.password')}
                name="password"
                autoComplete="current-password"
                placeholder="••••••••"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                leftIcon={<Lock className="size-4" aria-hidden="true" />}
                rightIcon={
                  <button
                    type="button"
                    onClick={() => setShowPassword((current) => !current)}
                    className="pointer-events-auto text-fg-muted hover:text-fg"
                    aria-label={showPassword ? t('login.hidePassword') : t('login.showPassword')}
                  >
                    {showPassword ? (
                      <EyeOff className="size-4" aria-hidden="true" />
                    ) : (
                      <Eye className="size-4" aria-hidden="true" />
                    )}
                  </button>
                }
                error={fieldErrors.password?.[0]}
                required
              />

              <div className="flex items-center justify-between">
                <Checkbox
                  label={t('login.remember')}
                  checked={remember}
                  onChange={(event) => setRemember(event.target.checked)}
                />
                <button
                  type="button"
                  onClick={() => setForgotOpen(true)}
                  className="text-sm text-primary hover:underline"
                >
                  {t('login.forgot')}
                </button>
              </div>

              <Button type="submit" fullWidth loading={isLoggingIn} disabled={submitDisabled}>
                {lockoutSeconds > 0 ? t('login.retryIn', { seconds: lockoutSeconds }) : t('login.submit')}
              </Button>
            </form>
          </CardBody>
        </Card>

        <p className="text-center text-xs text-fg-muted">{t('login.accountsAdminOnly')}</p>

        {/* Dil seçici giriş ekranında da ZORUNLU (§1.3): oturum açmadan önce de arayüz
            kullanıcının dilinde olmalı; seçim localStorage'a yazılır ve girişten sonra
            `users.locale`'e taşınır. */}
        <div className="flex justify-center">
          <LanguageSwitcher align="left" />
        </div>
      </div>

      <Modal
        open={forgotOpen}
        onClose={closeForgotModal}
        title={t('forgot.title')}
        description={
          forgotSent
            ? undefined
            : t('forgot.description')
        }
        size="sm"
      >
        {forgotSent ? (
          <div className="rounded-md border border-success bg-success-tint px-3 py-2 text-sm text-success">
            {t('forgot.sent')}
          </div>
        ) : (
          <form onSubmit={handleForgotSubmit} className="flex flex-col gap-4" noValidate>
            <Input
              type="email"
              label={t('login.email')}
              autoComplete="email"
              placeholder={t('login.emailPlaceholder')}
              value={forgotEmail}
              onChange={(event) => setForgotEmail(event.target.value)}
              required
            />
            <Button type="submit" fullWidth loading={forgotPassword.isPending}>
              {t('forgot.submit')}
            </Button>
          </form>
        )}
      </Modal>
    </main>
  )
}
