// Çok satırlı metin girişi — Input ile aynı label/hata/ipucu deseni, dikey yeniden boyutlanır.
import { forwardRef, useId } from 'react'
import type { TextareaHTMLAttributes } from 'react'
import { cn } from '../../lib/cn'

export type TextareaProps = {
  label?: string
  error?: string
  hint?: string
} & TextareaHTMLAttributes<HTMLTextAreaElement>

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(
  ({ className, label, error, hint, id, disabled, rows = 4, ...props }, ref) => {
    const autoId = useId()
    const textareaId = id ?? autoId
    const errorId = `${textareaId}-error`
    const hintId = `${textareaId}-hint`
    const describedBy = error ? errorId : hint ? hintId : undefined

    return (
      <div className="flex flex-col gap-1.5">
        {label && (
          <label htmlFor={textareaId} className="text-xs font-medium text-fg-muted">
            {label}
          </label>
        )}
        <textarea
          ref={ref}
          id={textareaId}
          disabled={disabled}
          rows={rows}
          aria-invalid={!!error || undefined}
          aria-describedby={describedBy}
          className={cn(
            'w-full resize-y rounded-md border border-border-strong bg-surface-2 px-3 py-2 text-sm text-fg',
            'placeholder:text-fg-muted',
            'transition-colors duration-150 motion-reduce:transition-none',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1',
            'disabled:opacity-50 disabled:cursor-not-allowed',
            error && 'border-danger',
            className
          )}
          {...props}
        />
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

Textarea.displayName = 'Textarea'
