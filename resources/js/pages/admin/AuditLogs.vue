<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import BusinessLayout from '@/layouts/BusinessLayout.vue';
const props = defineProps<{ logs: any; filters: any }>();
let event = props.filters.event || '';
const search = () =>
    router.get('/admin/audit-logs', { event }, { preserveState: true });
</script>
<template>
    <BusinessLayout title="审计日志" eyebrow="全程操作留痕"
        ><div class="mb-5 flex gap-3 border border-[#ded7c9] bg-white p-4">
            <input
                v-model="event"
                class="control max-w-sm"
                placeholder="按事件前缀筛选"
            /><button class="btn-secondary" @click="search">查询</button>
        </div>
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>时间</th>
                        <th>事件</th>
                        <th>用户</th>
                        <th>对象</th>
                        <th>请求 ID</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in logs.data" :key="l.id">
                        <td>{{ l.created_at?.slice(0, 19) }}</td>
                        <td class="font-medium">{{ l.event }}</td>
                        <td>{{ l.user_id || '系统' }}</td>
                        <td>{{ l.subject_type }} #{{ l.subject_id }}</td>
                        <td class="font-mono text-xs">{{ l.request_id }}</td>
                        <td>{{ l.ip_address }}</td>
                    </tr>
                </tbody>
            </table>
        </div></BusinessLayout
    >
</template>
