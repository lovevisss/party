const formatter = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Shanghai',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
});

export const minuteDateParts = (value: string | null | undefined) => {
    if (!value) return null;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return null;
    const parts = Object.fromEntries(
        formatter.formatToParts(date).map(({ type, value }) => [type, value]),
    );
    return {
        date: `${parts.year}-${parts.month}-${parts.day}`,
        time: `${parts.hour}:${parts.minute}`,
        second: parts.second,
    };
};

export const minuteDateTime = (
    value: string | null | undefined,
    showSeconds = false,
) => {
    const parts = minuteDateParts(value);
    return parts
        ? `${parts.date} ${parts.time}${showSeconds ? `:${parts.second}` : ''}`
        : '—';
};

export const minuteDateTimeInput = (value: string | null | undefined) => {
    const parts = minuteDateParts(value);
    return parts ? `${parts.date}T${parts.time}` : '';
};

export const minuteMeetingRange = (
    start: string | null | undefined,
    end: string | null | undefined,
) => {
    const startParts = minuteDateParts(start);
    const endParts = minuteDateParts(end);
    if (!startParts || !endParts) return '待补充';
    if (startParts.date === endParts.date) {
        return `${startParts.date} ${startParts.time}—${endParts.time}`;
    }
    return `${startParts.date} ${startParts.time}—${endParts.date} ${endParts.time}`;
};
