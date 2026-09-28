import { createInertiaApp } from '@inertiajs/vue3';

const appName = '会议纪要管理系统';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: () => null,
    progress: {
        color: '#4B5563',
    },
});
