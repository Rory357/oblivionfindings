import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"

import { cn } from "@/lib/utils"

const alertVariants = cva(
  "relative w-full rounded-lg border px-4 py-3 text-sm grid has-[>svg]:grid-cols-[calc(var(--spacing)*4)_1fr] grid-cols-[0_1fr] has-[>svg]:gap-x-3 gap-y-0.5 items-start [&>svg]:size-4 [&>svg]:translate-y-0.5 [&>svg]:text-current",
  {
    variants: {
      variant: {
        default: "bg-background text-foreground",
        // The verified critical status pair, not --destructive-foreground:
        // that token is text for a solid bg-destructive fill (white in light
        // mode), so on a card it rendered white on white (DESIGN.md
        // "Fill foregrounds off their fill").
        destructive:
          "border-status-critical/40 bg-status-critical-bg text-status-critical-foreground [&>svg]:text-status-critical *:data-[slot=alert-description]:text-status-critical-foreground",
        // Borderless toned notices on the same verified status pairs
        // (sign-in screens): -foreground text, status-colour icon.
        critical:
          "border-transparent bg-status-critical-bg text-status-critical-foreground [&>svg]:text-status-critical *:data-[slot=alert-description]:text-status-critical-foreground",
        warning:
          "border-transparent bg-status-warning-bg text-status-warning-foreground [&>svg]:text-status-warning *:data-[slot=alert-description]:text-status-warning-foreground",
        info:
          "border-transparent bg-status-info-bg text-status-info-foreground [&>svg]:text-status-info *:data-[slot=alert-description]:text-status-info-foreground",
        success:
          "border-transparent bg-status-success-bg text-status-success-foreground [&>svg]:text-status-success *:data-[slot=alert-description]:text-status-success-foreground",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Alert({
  className,
  variant,
  ...props
}: React.ComponentProps<"div"> & VariantProps<typeof alertVariants>) {
  return (
    <div
      data-slot="alert"
      role="alert"
      className={cn(alertVariants({ variant }), className)}
      {...props}
    />
  )
}

function AlertTitle({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-title"
      className={cn(
        "col-start-2 line-clamp-1 min-h-4 font-medium tracking-tight",
        className
      )}
      {...props}
    />
  )
}

function AlertDescription({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-description"
      className={cn(
        "text-muted-foreground col-start-2 grid justify-items-start gap-1 text-sm [&_p]:leading-relaxed",
        className
      )}
      {...props}
    />
  )
}

export { Alert, AlertTitle, AlertDescription }
