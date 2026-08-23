import * as React from "react"

type OverlayFocusReturnState = {
  target: HTMLElement | null
  owner: HTMLElement | null
}

type OverlayFocusReturnRef = { current: OverlayFocusReturnState }

const FOCUS_OWNER_SELECTOR = [
  "[data-focus-return-owner]",
  '[role="toolbar"]',
  "header",
  "nav",
  "main",
  '[role="main"]',
  '[role="navigation"]',
  '[role="region"]',
  "form",
].join(",")

const OverlayFocusReturnContext =
  React.createContext<OverlayFocusReturnRef | null>(null)

function activeFocusReturnTarget(): HTMLElement | null {
  if (typeof document === "undefined") return null

  const active = document.activeElement
  return active instanceof HTMLElement && active !== document.body
    ? active
    : null
}

function canRestoreOverlayFocus(
  target: HTMLElement | null
): target is HTMLElement {
  if (!target?.isConnected || typeof window === "undefined") return false
  if (target.matches("[disabled], [aria-disabled='true'], [hidden]")) return false
  if (target.closest("[hidden], [inert], [aria-hidden='true']")) return false

  let element: HTMLElement | null = target
  while (element) {
    const style = window.getComputedStyle(element)
    if (style.display === "none" || style.visibility === "hidden") return false
    element = element.parentElement
  }

  return true
}

function focusOwnerFor(target: HTMLElement | null): HTMLElement | null {
  const nearest = target?.closest<HTMLElement>(FOCUS_OWNER_SELECTOR) ?? null
  if (nearest?.isConnected) return nearest

  if (typeof document === "undefined") return null
  return (
    Array.from(
      document.querySelectorAll<HTMLElement>('main, [role="main"], header')
    ).find(canRestoreOverlayFocus) ?? null
  )
}

function restoreOwnerFocus(owner: HTMLElement | null): boolean {
  const safeOwner = canRestoreOverlayFocus(owner)
    ? owner
    : focusOwnerFor(null)
  if (!canRestoreOverlayFocus(safeOwner)) return false

  const hadTabIndex = safeOwner.hasAttribute("tabindex")
  if (!hadTabIndex) safeOwner.setAttribute("tabindex", "-1")
  safeOwner.focus({ preventScroll: true })

  if (!hadTabIndex) {
    safeOwner.addEventListener(
      "blur",
      () => safeOwner.removeAttribute("tabindex"),
      { once: true }
    )
  }

  return document.activeElement === safeOwner
}

function OverlayFocusReturnProvider({
  children,
}: {
  children: React.ReactNode
}) {
  const returnFocusRef = React.useRef<OverlayFocusReturnState>({
    target: null,
    owner: null,
  })

  return (
    <OverlayFocusReturnContext.Provider value={returnFocusRef}>
      {children}
    </OverlayFocusReturnContext.Provider>
  )
}

function useOverlayFocusReturn() {
  return React.useContext(OverlayFocusReturnContext)
}

function rememberOverlayTrigger(
  returnFocusRef: OverlayFocusReturnRef | null,
  target: EventTarget | null
) {
  if (returnFocusRef && target instanceof HTMLElement) {
    returnFocusRef.current = {
      target,
      owner: focusOwnerFor(target),
    }
  }
}

function rememberOverlayActiveElement(
  returnFocusRef: OverlayFocusReturnRef | null
) {
  if (returnFocusRef && returnFocusRef.current.target === null) {
    const target = activeFocusReturnTarget()
    returnFocusRef.current = {
      target,
      owner: focusOwnerFor(target),
    }
  }
}

function clearOverlayFocusReturn(
  returnFocusRef: OverlayFocusReturnRef | null
) {
  if (returnFocusRef) {
    returnFocusRef.current = { target: null, owner: null }
  }
}

function restoreOverlayFocus(
  returnFocusRef: OverlayFocusReturnRef | null
): boolean {
  const target = returnFocusRef?.current.target ?? null
  const owner = returnFocusRef?.current.owner ?? null
  clearOverlayFocusReturn(returnFocusRef)
  if (!canRestoreOverlayFocus(target)) return restoreOwnerFocus(owner)

  target.focus({ preventScroll: true })
  return document.activeElement === target
}

export {
  clearOverlayFocusReturn,
  OverlayFocusReturnProvider,
  rememberOverlayActiveElement,
  rememberOverlayTrigger,
  restoreOverlayFocus,
  useOverlayFocusReturn,
}
