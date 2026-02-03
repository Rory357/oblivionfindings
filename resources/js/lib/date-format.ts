export const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
};

export const formatTime = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d
        .toLocaleTimeString('en-NZ', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: true,
        })
        .replace(' ', '');
};

export const formatDateTime = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    const date = formatDate(value);
    const time = formatTime(value);
    return `${date} ${time}`;
};
