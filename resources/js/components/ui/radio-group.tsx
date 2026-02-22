import * as React from "react"
import { cn } from "@/lib/utils"

interface RadioGroupProps extends React.HTMLAttributes<HTMLDivElement> {
  value?: string
  defaultValue?: string
  onValueChange?: (value: string) => void
  disabled?: boolean
}

const RadioGroup = React.forwardRef<HTMLDivElement, RadioGroupProps>(
  ({ className, value, defaultValue, onValueChange, disabled, children, ...props }, ref) => {
    const [selectedValue, setSelectedValue] = React.useState(defaultValue || value)

    React.useEffect(() => {
      if (value !== undefined) {
        setSelectedValue(value)
      }
    }, [value])

    const handleSelect = (newValue: string) => {
      if (disabled) return
      setSelectedValue(newValue)
      onValueChange?.(newValue)
    }

    return (
      <div
        ref={ref}
        className={cn("grid gap-2", className)}
        role="radiogroup"
        {...props}
      >
        {React.Children.map(children, (child) => {
          if (React.isValidElement(child) && child.type === RadioGroupItem) {
            const item = child as React.ReactElement<RadioGroupItemProps>
            return React.cloneElement(child as React.ReactElement<RadioGroupItemProps>, {
              selectedValue,
              onItemSelect: handleSelect,
              disabled: disabled || item.props.disabled,
            })
          }
          return child
        })}
      </div>
    )
  }
)
RadioGroup.displayName = "RadioGroup"

interface RadioGroupItemProps extends React.HTMLAttributes<HTMLDivElement> {
  value: string
  id?: string
  selectedValue?: string
  onItemSelect?: (value: string) => void
  disabled?: boolean
}

const RadioGroupItem = React.forwardRef<HTMLDivElement, RadioGroupItemProps>(
  ({ className, value, id, selectedValue, onItemSelect, disabled, children, ...props }, ref) => {
    const isSelected = selectedValue === value
    const itemId = id || value

    return (
      <div
        ref={ref}
        className={cn(
          "flex items-center space-x-2 rounded-md border p-3 cursor-pointer transition-colors",
          isSelected && "border-primary bg-primary/5",
          disabled && "opacity-50 cursor-not-allowed",
          !isSelected && !disabled && "hover:bg-muted",
          className
        )}
        onClick={() => onItemSelect?.(value)}
        role="radio"
        aria-checked={isSelected}
        {...props}
      >
        <div
          className={cn(
            "h-4 w-4 rounded-full border flex items-center justify-center",
            isSelected ? "border-primary" : "border-muted-foreground"
          )}
        >
          {isSelected && <div className="h-2 w-2 rounded-full bg-primary" />}
        </div>
        <label
          htmlFor={itemId}
          className="flex-1 cursor-pointer text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
        >
          {children || value}
        </label>
      </div>
    )
  }
)
RadioGroupItem.displayName = "RadioGroupItem"

export { RadioGroup, RadioGroupItem }
