// Native <select> tabanlı seçim bileşeni — Input ile aynı label/hata/ipucu deseni.
import { forwardRef, useId } from 'react'
import type { SelectHTMLAttributes } from 'react'
import { ChevronDown } from 'lucide-react'
import { cn } from '../../lib/cn'

export type SelectProps = {
  label?: string
  error?: string
  hint?: string
  options?: Array<{ value: string; label: string; disabled?: boolean }>
  placeholder?: string
} & Omit<SelectHTMLAttributes<HTMLSelectElement>, 'size'>

export const Select = forwardRef<HTMLSelectElement, SelectProps>(
  (
    {
      className,
      label,
      error,
      hint,
      options,
      placeholder,
      id,
      disabled,
      children,
      defaultValue,
      value,
      ...props
    },
    ref
  ) => {
    const autoId = useId()
    const selectId = id ?? autoId
    const errorId = `${selectId}-error`
    const hintId = `${selectId}-hint`
    const describedBy = error ? errorId : hint ? hintId : undefined
    // React'e "controlled" (value verilmiş) mi yoksa "uncontrolled" (yalnızca defaultValue/hiçbiri
    // verilmiş) mi olduğumuzu SADECE BİRİNİ geçerek söylüyoruz — ikisini birden vermek native
    // <select>'i controlled/uncontrolled uyarısına düşürüyordu. `placeholder`in "seçilmemiş
    // durumda boş seçenek göster" davranışı iki modda da farklı yollarla korunur:
    //  - controlled (value verildi): caller zaten '' (veya eşdeğeri) değerini `value` olarak
    //    geçiriyor; aşağıdaki disabled `<option value="">` bu boş değerle eşleşip seçili görünür.
    //    defaultValue'ya HİÇ dokunmuyoruz.
    //  - uncontrolled (value verilmedi): eskisi gibi defaultValue`'yu placeholder varsa '' olacak
    //    şekilde hesaplıyoruz ki ilk render'da placeholder seçili gelsin.
    const isControlled = value !== undefined

    return (
      <div className="flex flex-col gap-1.5">
        {label && (
          <label htmlFor={selectId} className="text-xs font-medium text-fg-muted">
            {label}
          </label>
        )}
        <div className="relative">
          <select
            ref={ref}
            id={selectId}
            disabled={disabled}
            aria-invalid={!!error || undefined}
            aria-describedby={describedBy}
            value={value}
            defaultValue={isControlled ? undefined : (defaultValue ?? (placeholder ? '' : undefined))}
            className={cn(
              'w-full appearance-none rounded-md border border-border-strong bg-surface-2 px-3 pr-9 text-sm text-fg',
              'h-10',
              'transition-colors duration-150 motion-reduce:transition-none',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1',
              'disabled:opacity-50 disabled:cursor-not-allowed',
              error && 'border-danger',
              className
            )}
            {...props}
          >
            {placeholder && (
              <option value="" disabled>
                {placeholder}
              </option>
            )}
            {options
              ? options.map((opt) => (
                  <option key={opt.value} value={opt.value} disabled={opt.disabled}>
                    {opt.label}
                  </option>
                ))
              : children}
          </select>
          <ChevronDown
            className="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-fg-muted"
            aria-hidden="true"
          />
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

Select.displayName = 'Select'
