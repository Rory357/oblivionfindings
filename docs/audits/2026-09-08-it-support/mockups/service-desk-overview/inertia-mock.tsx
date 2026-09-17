import React from 'react';
export const router = { visit: (href: string) => window.dispatchEvent(new CustomEvent('mock-navigate', {detail: href})), get: (href: string) => window.dispatchEvent(new CustomEvent('mock-navigate', {detail: href})) };
export const Link = React.forwardRef<HTMLAnchorElement, any>(({href, children, onClick, preserveState, preserveScroll, prefetch, ...props}, ref) => <a {...props} ref={ref} href={href} onClick={e => {e.preventDefault();onClick?.(e);router.visit(href);}}>{children}</a>);
