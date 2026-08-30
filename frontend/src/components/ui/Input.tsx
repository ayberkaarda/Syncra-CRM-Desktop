// Metin girişi bileşeni — label/hata/ipucu ve ikon desteğiyle. Üst bardaki arama kutusu bu bileşeni kullanır.
import { forwardRef, useId } from 'react'
import type { InputHTMLAttributes, ReactNode } from 'react'
import { cn } from '../../lib/cn'

export type InputProps = {
  label?: string
  error?: string
  hint?: string
  leftIcon?: ReactNode
  rightIcon?: ReactNode
  inputSize?: 'sm' | 'md' | 'lg'
} & Omit<InputHTMLAttributes<HTMLInputElement>, 'size'>

const sizeClasses: Record<NonNullable<InputProps['inputSize']>, string> = {
  sm: 'h-8 text-xs',
  md: 'h-10 text-sm',
  lg: 'h-12 text-base',
}

const iconPaddingLeft: Record<NonNullable<InputProps['inputSize']>, string> = {
  sm: 'pl-8',
  md: 'pl-9',
  lg: 'pl-10',
}

const iconPaddingRight: Record<NonNullable<InputProps['inputSize']>, string> = {
  sm: 'pr-8',
  md: 'pr-9',
  lg: 'pr-10',
}

export const Input = forwardRef<HTMLInputElement, InputProps>(
  (
    {
      className,
      label,
      error,
      hint,
      leftIcon,
      rightIcon,
      inputSize = 'md',
      id,
      disabled,
      ...props
    },
    ref
  ) => {
    const autoId = useId()
    const inputId = id ?? autoId
    const errorId = `${inputId}-error`
    const hintId = `${inputId}-hint`
    const describedBy = error ? errorId : hint ? hintId : undefined

    return (
      <div className="flex flex-col gap-1.5">
        {label && (
          <label htmlFor={inputId} className="text-xs font-medium text-fg-muted">
            {label}
          </label>
        )}
        <div className="relative">
          {leftIcon && (
            <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-fg-muted">
              {leftIcon}
            </span>
          )}
          <input
            ref={ref}
            id={inputId}
            disabled={disabled}
            aria-invalid={!!error || undefined}
            aria-describedby={describedBy}
            className={cn(
              'w-full rounded-md border border-border-strong bg-surface-2 px-3 text-fg',
              'placeholder:text-fg-muted',
              'transition-colors duration-150 motion-reduce:transition-none',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1',
              'disabled:opacity-50 disabled:cursor-not-allowed',
              sizeClasses[inputSize],
              leftIcon && iconPaddingLeft[inputSize],
              rightIcon && iconPaddingRight[inputSize],
              error && 'border-danger',
              className
            )}
            {...props}
          />
          {rightIcon && (
            <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-fg-muted">
              {rightIcon}
            </span>
          )}
        </div>
        {error && (
          <p id={errorId} className="text-xs text-danger">
            {error}
          </p>
        )}
        {!error && hint && (
          <p id={hintId} className="text-xs text-fg-muted">
            {hint}
          </p>
        )}
      </div>
    )
  }
)

Input.displayName = 'Input'
