<script setup lang="ts">
defineProps<{ status: string; overdue?: boolean | null }>();
const labels: any = { draft: '草稿', archived: '已归档', returned: '已退回' };
</script>
<template>
    <span
        class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium"
        :class="
            status === 'archived'
                ? 'bg-emerald-50 text-emerald-700'
                : status === 'returned'
                  ? 'bg-amber-50 text-amber-700'
                  : 'bg-slate-100 text-slate-600'
        "
        >{{ labels[status] || status }}</span
    ><span
        v-if="overdue"
        class="ml-1 inline-flex rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700"
        >超时</span
    >
</template>
