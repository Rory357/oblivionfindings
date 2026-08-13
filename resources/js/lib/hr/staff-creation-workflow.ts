export const STAFF_CREATION_INTENT = 'staff' as const;

export type EmployeeCreationRequiredFields = {
    name: string;
    email: string;
    role: string;
    primarySiteId: string;
};

/** One client-side completion contract for the canonical HR People intake. */
export function employeeCreationIsComplete({
    name,
    email,
    role,
    primarySiteId,
}: EmployeeCreationRequiredFields): boolean {
    return (
        name.trim() !== '' &&
        email.trim() !== '' &&
        role.trim() !== '' &&
        primarySiteId !== ''
    );
}
