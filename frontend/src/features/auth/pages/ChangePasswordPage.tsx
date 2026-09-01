// Zorunlu şifre değişimi — AppLayout DIŞINDA tam sayfa (bkz. docs/AUTH-FLOWS.md §4).
// Geçici şifreyle giriş yapan kullanıcı, tek kaçış yolu "Çıkış Yap" olacak şekilde
// buraya hapsedilir; RequireAuth her korumalı route'tan buraya yönlendirir. Gerçek
// dayatma sunucudadır (EnsurePasswordIsChanged middleware) — bu ekran yalnızca UX'tir.
import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { useTranslation } from 'react-i18next'
import { Check, Eye, EyeOff, LogOut, X } from 'lucide-react'
import { Button, Card, CardBody, Input, toast } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { getErrorMessage, getFieldErrors } from '../../../lib/axios'
import { useOnlineOnly } from '../../../platform/useOnlineOnly'
import { PASSWORD_MIN_LENGTH, evaluatePassword } from '../../../features/users/utils/password'
import type { PasswordRuleId } from '../../../features/users/utils/password'
import { useAuth, useChangePassword } from '../hooks/useAuth'

type LocationState = { from?: { pathname: string } }

/** `users:passwordRules.*` anahtarları — bkz. `UserFormModal`/`ResetPasswordModal`'daki aynı harita. */
const RULE_LABEL_KEYS: Record<PasswordRuleId, string> = {
  length: 'users:passwordRules.length',
  upper: 'users:passwordRules.upper',
  lower: 'users:passwordRules.lower',
  digit: 'users:passwordRules.digit',
  special: 'users:passwordRules.special',
}

function getRetryAfterSeconds(error: unknown): number | null {
  if (!isAxiosError(error)) return null
  const header = error.response?.headers?.['retry-after']
  const seconds = Number(header)
  return Number.isFinite(seconds) && seconds > 0 ? seconds : null
}

export function ChangePasswordPage() {
  const { t } = useTranslation(['auth', 'common'])
  const navigate = useNavigate()
  const location = useLocation()
  const { user, status, logout } = useAuth()
  const changePassword = useChangePassword()

  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [showCurrent, setShowCurrent] = useState(false)
  const [showNew, setShowNew] = useState(false)
  const [showConfirm, setShowConfirm] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [lockoutSeconds, setLockoutSeconds] = useState(0)

  const currentPasswordRef = useRef<HTMLInputElement>(null)

  // SYNCDESKTOP §8 (O102) — "password change". `POST /api/password/change` has no offline path:
  // the server is the only holder of the credential. Declared with the other hooks, ABOVE the
  // `must_change_password` early return, because a hook after a conditional `return` breaks the
  // call order (`react-hooks/rules-of-hooks`). Web build: `offline` is always false.
  const passwordGuard = useOnlineOnly('password.change')

  useEffect(() => {
    currentPasswordRef.current?.focus()
  }, [])

  useEffect(() => {
    if (lockoutSeconds <= 0) return
    const timer = setInterval(() => {
      setLockoutSeconds((current) => (current > 0 ? current - 1 : 0))
    }, 1000)
    return () => clearInterval(timer)
  }, [lockoutSeconds])

  // Ters guard: bayrak zaten temizse bu ekranın burada işi yok.
  if (status === 'authenticated' && user && !user.must_change_password) {
    return <Navigate to="/" replace />
  }


  const evaluation = evaluatePassword(password)
  const submitDisabled = changePassword.isPending || lockoutSeconds > 0 || passwordGuard.offline

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (submitDisabled) return

    setFormError(null)
    setFieldErrors({})

    try {
      await changePassword.mutateAsync({
        current_password: currentPassword,
        password,
        password_confirmation: passwordConfirmation,
      })
      toast.success(t('auth:changePassword.success'))
      const state = location.state as LocationState | null
      navigate(state?.from?.pathname ?? '/', { replace: true })
    } catch (error) {
      const retryAfter = getRetryAfterSeconds(error)
      if (retryAfter) {
        setLockoutSeconds(retryAfter)
        toast.error(t('auth:changePassword.lockout', { seconds: retryAfter }))
        return
      }

      const fields = getFieldErrors(error)
      if (fields) {
        setFieldErrors(fields)
      }
      setFormError(getErrorMessage(error))
    }
  }

  async function handleLogout() {
    try {
      await logout()
    } finally {
      navigate('/login', { replace: true })
    }
  }

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
          <p className="text-sm text-fg-muted">{t('auth:changePassword.subtitle')}</p>
        </div>

        <Card>
          <CardBody className="flex flex-col gap-5">
            <p className="text-sm text-fg-muted">{t('auth:changePassword.description')}</p>

            <form onSubmit={handleSubmit} className="flex flex-col gap-4" noValidate>
              <div aria-live="polite">
                {formError && (
                  <div className="rounded-md border border-danger bg-danger-tint px-3 py-2 text-sm text-danger">
                    {formError}
                  </div>
                )}
              </div>

              <Input
                ref={currentPasswordRef}
                type={showCurrent ? 'text' : 'password'}
                label={t('auth:changePassword.currentPasswordLabel')}
                name="current_password"
                autoComplete="current-password"
                value={currentPassword}
                onChange={(event) => setCurrentPassword(event.target.value)}
                rightIcon={
                  <button
                    type="button"
                    onClick={() => setShowCurrent((current) => !current)}
                    className="pointer-events-auto text-fg-muted hover:text-fg"
                    aria-label={showCurrent ? t('auth:login.hidePassword') : t('auth:login.showPassword')}
                  >
                    {showCurrent ? <EyeOff className="size-4" aria-hidden="true" /> : <Eye className="size-4" aria-hidden="true" />}
                  </button>
                }
                error={fieldErrors.current_password?.[0]}
                required
              />

              <Input
                type={showNew ? 'text' : 'password'}
                label={t('auth:changePassword.newPasswordLabel')}
                name="password"
                autoComplete="new-password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                rightIcon={
                  <button
                    type="button"
                    onClick={() => setShowNew((current) => !current)}
                    className="pointer-events-auto text-fg-muted hover:text-fg"
                    aria-label={showNew ? t('auth:login.hidePassword') : t('auth:login.showPassword')}
                  >
                    {showNew ? <EyeOff className="size-4" aria-hidden="true" /> : <Eye className="size-4" aria-hidden="true" />}
                  </button>
                }
                error={fieldErrors.password?.[0]}
                required
              />

              <ul className="flex flex-col gap-1" aria-live="polite">
                {evaluation.rules.map((rule) => (
                  <li
                    key={rule.id}
                    className={cn('flex items-center gap-1.5 text-xs', rule.met ? 'text-success' : 'text-fg-muted')}
                  >
                    {rule.met ? (
                      <Check className="size-3.5 shrink-0" aria-hidden="true" />
                    ) : (
                      <X className="size-3.5 shrink-0" aria-hidden="true" />
                    )}
                    {t(RULE_LABEL_KEYS[rule.id], { count: PASSWORD_MIN_LENGTH })}
                  </li>
                ))}
              </ul>

              <Input
                type={showConfirm ? 'text' : 'password'}
                label={t('auth:changePassword.confirmPasswordLabel')}
                name="password_confirmation"
                autoComplete="new-password"
                value={passwordConfirmation}
                onChange={(event) => setPasswordConfirmation(event.target.value)}
                rightIcon={
                  <button
                    type="button"
                    onClick={() => setShowConfirm((current) => !current)}
                    className="pointer-events-auto text-fg-muted hover:text-fg"
                    aria-label={showConfirm ? t('auth:login.hidePassword') : t('auth:login.showPassword')}
                  >
                    {showConfirm ? <EyeOff className="size-4" aria-hidden="true" /> : <Eye className="size-4" aria-hidden="true" />}
                  </button>
                }
                error={fieldErrors.password_confirmation?.[0]}
                required
              />

              <Button
                type="submit"
                fullWidth
                loading={changePassword.isPending}
                disabled={submitDisabled}
                title={passwordGuard.title}
              >
                {lockoutSeconds > 0
                  ? t('auth:login.retryIn', { seconds: lockoutSeconds })
                  : t('auth:changePassword.submit')}
              </Button>
            </form>

            <Button variant="ghost" fullWidth leftIcon={<LogOut className="size-4" aria-hidden="true" />} onClick={handleLogout}>
              {t('common:layout.logout')}
            </Button>
          </CardBody>
        </Card>
      </div>
    </main>
  )
}
