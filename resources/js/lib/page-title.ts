/**
 * Browser tab titles. App pages read "<page> - <app name>". The public
 * website is the client's own site (Stephan, 20 Oct 2026), so its pages title
 * themselves in full ("About us · Oblivion Living") and must not gain our app
 * name: they mark their title with `ownTitle()`.
 */
const OWN_TITLE = '⁠'; // word joiner: invisible, never typed by hand

/** A title that is complete as it is: no app-name suffix. */
export function ownTitle(title: string): string {
    return `${title}${OWN_TITLE}`;
}

/** Inertia's `title` callback (app.tsx and ssr.tsx). */
export function formatPageTitle(title: string, appName: string): string {
    if (!title) return appName;
    if (title.endsWith(OWN_TITLE)) return title.slice(0, -OWN_TITLE.length);

    return `${title} - ${appName}`;
}
