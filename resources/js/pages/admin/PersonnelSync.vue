<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Database, RefreshCw } from 'lucide-vue-next';
import BusinessLayout from '@/layouts/BusinessLayout.vue';
defineProps<{ runs: any }>();
</script>
<template>
    <BusinessLayout title="人员同步" eyebrow="权威数据接入"
        ><div
            class="mb-5 flex items-center justify-between border border-[#ded7c9] bg-white p-5"
        >
            <div class="flex items-center gap-4">
                <div
                    class="grid size-12 place-items-center bg-[#edf3f0] text-[#2f6a59]"
                >
                    <Database />
                </div>
                <div>
                    <h2 class="font-serif text-lg font-semibold">
                        中间库全量同步
                    </h2>
                    <p class="text-sm text-[#75807b]">
                        每天 02:00 自动执行；不使用 rylx 字段筛选
                    </p>
                </div>
            </div>
            <button
                class="btn-primary"
                @click="router.post('/admin/personnel-sync')"
            >
                <RefreshCw :size="16" />立即同步
            </button>
        </div>
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>批次</th>
                        <th>状态</th>
                        <th>源数据</th>
                        <th>新增</th>
                        <th>更新</th>
                        <th>停用</th>
                        <th>开始时间</th>
                        <th>说明</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in runs.data" :key="r.id">
                        <td>#{{ r.id }}</td>
                        <td>
                            <span
                                :class="
                                    r.status === 'completed'
                                        ? 'text-emerald-700'
                                        : r.status === 'failed'
                                          ? 'text-red-700'
                                          : 'text-amber-700'
                                "
                                >{{ r.status }}</span
                            >
                        </td>
                        <td>{{ r.source_count }}</td>
                        <td>{{ r.created_count }}</td>
                        <td>{{ r.updated_count }}</td>
                        <td>{{ r.deactivated_count }}</td>
                        <td>{{ r.started_at?.slice(0, 16) }}</td>
                        <td class="max-w-xs truncate text-xs text-red-700">
                            {{ r.error_message }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div></BusinessLayout
    >
</template>
