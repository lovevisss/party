<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Plus, Search } from 'lucide-vue-next';
import BusinessLayout from '@/layouts/BusinessLayout.vue';
import MinuteStatus from '@/components/MinuteStatus.vue';

const props = defineProps<{
    minutes: any;
    organizations: any[];
    filters: any;
    canCreate: boolean;
}>();

const filter: any = { ...props.filters };
const apply = () => router.get('/minutes', filter, { preserveState: true, replace: true });
</script>

<template>
    <BusinessLayout title="会议纪要" eyebrow="归档台账">
        <div class="mb-5 flex flex-col gap-4 border border-[#ded7c9] bg-white p-4 md:flex-row md:items-end">
            <label class="field">
                <span>年度</span>
                <input v-model="filter.year" type="number" placeholder="全部年度">
            </label>
            <label class="field">
                <span>状态</span>
                <select v-model="filter.status">
                    <option value="">全部状态</option>
                    <option value="draft">草稿</option>
                    <option value="archived">已归档</option>
                    <option value="returned">已退回</option>
                </select>
            </label>
            <label class="field">
                <span>学院</span>
                <select v-model="filter.organization_id">
                    <option value="">全部学院</option>
                    <option v-for="organization in organizations" :key="organization.id" :value="organization.id">
                        {{ organization.name }}
                    </option>
                </select>
            </label>
            <button class="btn-secondary" @click="apply">
                <Search :size="16" />查询
            </button>
            <Link v-if="canCreate" href="/minutes/create" class="btn-primary md:ml-auto">
                <Plus :size="16" />新建纪要
            </Link>
        </div>

        <div class="overflow-x-auto border border-[#ded7c9] bg-white">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead class="bg-[#f1ede4] text-xs uppercase tracking-wider text-[#66716c]">
                    <tr>
                        <th>会议名称</th>
                        <th>学院</th>
                        <th>会议时间</th>
                        <th>序号</th>
                        <th>状态</th>
                        <th>归档时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#eee9df]">
                    <tr v-for="minute in minutes.data" :key="minute.id" class="hover:bg-[#fbfaf7]">
                        <td>
                            <p class="font-medium">{{ minute.title || '未命名草稿' }}</p>
                            <p class="mt-1 text-xs text-[#87908b]">版本 {{ minute.current_version }}</p>
                        </td>
                        <td>{{ organizations.find((organization) => organization.id === minute.organization_id)?.name || '—' }}</td>
                        <td>{{ minute.meeting_start_at?.slice(0, 16) || '待补充' }}</td>
                        <td>{{ minute.meeting_year || '—' }} / {{ minute.sequence_no || '—' }}</td>
                        <td><MinuteStatus :status="minute.status" :overdue="minute.is_overdue" /></td>
                        <td>{{ minute.archived_at?.slice(0, 16) || '—' }}</td>
                        <td>
                            <Link
                                :href="minute.can_edit ? `/minutes/${minute.id}/edit` : `/minutes/${minute.id}`"
                                class="font-medium text-[#2f6a59]"
                            >
                                {{ minute.can_edit ? '编辑' : '查看' }}
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="!minutes.data.length">
                        <td colspan="7" class="py-16 text-center text-[#7f8883]">当前筛选条件下暂无纪要</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex justify-end gap-2 print:hidden">
            <Link
                v-for="link in minutes.links"
                :key="link.label"
                :href="link.url || '#'"
                class="border px-3 py-1.5 text-sm"
                :class="link.active ? 'border-[#2f6a59] bg-[#2f6a59] text-white' : 'border-[#d8d2c5] bg-white'"
                v-html="link.label"
            />
        </div>
    </BusinessLayout>
</template>
