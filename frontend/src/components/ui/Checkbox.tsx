// Onay kutusu bileşeni — belirsiz (indeterminate) durum desteğiyle. To-Do listesindeki kutucuklar için kullanılır.
import { forwardRef, useEffect, useId, useRef } from 'react'
import type { InputHTMLAttributes, ReactNode } from 'react'
import { Check, Minus } from 'lucide-react'
import { cn } from '../../lib/cn'

export type CheckboxProps = {
  label?: ReactNode
  error?: string
  indeterminate?: boolean
} & Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>

export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(
  ({ className, label, error, indeterminate = false, id, disabled, checked, ...props }, ref) => {
    const autoId = useId()
    const checkboxId = id ?? autoId
    const errorId = `${checkboxId}-error`
    const innerRef = useRef<HTMLInputElement | null>(null)

    useEffect(() => {
      if (innerRef.current) {
        innerRef.current.indeterminate = indeterminate
      }
    }, [indeterminate])

    return (
      <div className="flex flex-col gap-1">
        <label
          htmlFor={checkboxId}
          className={cn(
            'inline-flex items-center gap-2 text-sm text-fg',
            disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'
          )}
        >
          <span className="relative inline-flex">
            <input
              ref={(node) => {
                innerRef.current = node
                if (typeof ref === 'function') ref(node)
                else if (ref) (ref as { current: HTMLInputElement | null }).current = node
              }}
              id={checkboxId}
              type="checkbox"
              disabled={disabled}
              checked={checked}
              aria-invalid={!!error || undefined}
              aria-describedby={error ? errorId : undefined}
              className={cn(
                'peer size-4 appearance-none rounded-sm border border-border-strong bg-surface-2',
                'checked:border-primary checked:bg-primary indeterminate:border-primary indeterminate:bg-primary',
                'transition-colors duration-150 motion-reduce:transition-none',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1',
                'disabled:cursor-not-allowed',
                error && 'border-danger',
                className
              )}
              {...props}
            />
            <Check
              className="pointer-events-none absolute inset-0 hidden size-4 text-primary-fg peer-checked:block peer-indeterminate:hidden"
              aria-hidden="true"
            />
            <Minus
              className="pointer-events-none absolute inset-0 hidden size-4 text-primary-fg peer-indeterminate:block"
              aria-hidden="true"
            />
          </span>
          {label}
        </label>
        {error && (
          <p id={errorId} className="text-xs text-danger">
            {error}
          </p>
        )}
      </div>
    )
  }
)

Checkbox.displayName = 'Checkbox'
