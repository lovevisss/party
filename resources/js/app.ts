import { createInertiaApp } from '@inertiajs/vue3';

const appName = '二级议事会议纪要';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: () => null,
    progress: {
        color: '#4B5563',
    },
});
