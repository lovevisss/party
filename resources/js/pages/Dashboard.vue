<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Archive,
    Clock3,
    FilePenLine,
    RotateCcw,
    FileCheck2,
} from 'lucide-vue-next';
import { computed } from 'vue';
import BusinessLayout from '@/layouts/BusinessLayout.vue';
import MinuteStatus from '@/components/MinuteStatus.vue';
const props = defineProps<{
    stats: Record<string, number>;
    recent: any[];
    isSystemAdmin: boolean;
}>();
const typeLabels: Record<string, string> = {
    party_branch: '党总支会议纪要',
    party_government_joint: '党政联席会议纪要',
};
const regularCards = [
    { label: '全部纪要', key: 'total', icon: Archive },
    { label: '待完善草稿', key: 'draft', icon: FilePenLine },
    { label: '退回修改', key: 'returned', icon: RotateCcw },
    { label: '逾期归档', key: 'overdue', icon: Clock3 },
];
const adminCards = [
    { label: '终稿总数', key: 'total', icon: Archive },
    { label: '按时归档', key: 'on_time', icon: FileCheck2 },
    { label: '逾期归档', key: 'overdue', icon: Clock3 },
];
const cards = computed(() => (props.isSystemAdmin ? adminCards : regularCards));
</script>
<template>
    <BusinessLayout title="工作台" eyebrow="今日概览"
        ><section
            class="grid gap-4 sm:grid-cols-2"
            :class="isSystemAdmin ? 'xl:grid-cols-3' : 'xl:grid-cols-4'"
        >
            <div
                v-for="(card, i) in cards"
                :key="card.key"
                class="relative overflow-hidden border border-[#ded7c9] bg-white p-5 shadow-[0_8px_24px_rgba(48,55,45,.05)]"
                :class="
                    isSystemAdmin && card.key === 'on_time'
                        ? 'cursor-pointer transition hover:border-[#2f6a59] hover:shadow-[0_12px_30px_rgba(48,55,45,.1)] focus-within:border-[#2f6a59]'
                        : ''
                "
            >
                <Link
                    v-if="isSystemAdmin && card.key === 'on_time'"
                    href="/dashboard/on-time"
                    class="absolute inset-0 z-10 focus-visible:outline-2 focus-visible:outline-offset-[-4px] focus-visible:outline-[#2f6a59]"
                    aria-label="查看按时归档纪要明细"
                />
                <div class="mb-7 flex items-center justify-between">
                    <span class="text-sm text-[#68736e]">{{ card.label }}</span
                    ><component
                        :is="card.icon"
                        :size="18"
                        class="text-[#8b6f35]"
                    />
                </div>
                <p class="font-serif text-4xl font-semibold">
                    {{ stats[card.key] }}
                </p>
                <span
                    v-if="isSystemAdmin && card.key === 'on_time'"
                    class="absolute right-5 bottom-5 text-xs font-medium text-[#2f6a59]"
                    aria-hidden="true"
                    >查看明细 →</span
                >
                <div
                    class="absolute bottom-0 left-0 h-1 bg-[#2f6a59]"
                    :style="{ width: `${34 + i * 12}%` }"
                />
            </div>
        </section>
        <section class="mt-7 border border-[#ded7c9] bg-white">
            <div
                class="flex items-center justify-between border-b border-[#e8e2d7] px-5 py-4"
            >
                <div>
                    <h2 class="font-serif text-lg font-semibold">最近更新</h2>
                    <p class="text-xs text-[#79827d]">
                        {{
                            isSystemAdmin
                                ? '仅显示当前终稿，按最后修改时间排列'
                                : '按最后修改时间排列'
                        }}
                    </p>
                </div>
                <Link href="/minutes" class="text-sm font-medium text-[#2f6a59]"
                    >查看全部 →</Link
                >
            </div>
            <div v-if="recent.length" class="divide-y divide-[#eee9df]">
                <Link
                    v-for="item in recent"
                    :key="item.id"
                    :href="`/minutes/${item.id}`"
                    class="grid gap-2 px-5 py-4 transition hover:bg-[#faf8f3] md:grid-cols-[1fr_150px_120px]"
                    ><div>
                        <p class="font-medium">
                            {{ item.title || '未命名草稿' }}
                        </p>
                        <p class="mt-1 text-xs text-[#7c8580]">
                            {{ typeLabels[item.meeting_type] }} ·
                            {{ item.meeting_scope?.name || '—' }} ·
                            {{ item.meeting_year || '—' }} 年 · 第
                            {{ item.sequence_no || '—' }} 次
                        </p>
                    </div>
                    <p class="self-center text-sm text-[#68736e]">
                        {{ item.meeting_start_at?.slice(0, 16) || '时间待定' }}
                    </p>
                    <div class="self-center">
                        <MinuteStatus
                            :status="item.status"
                            :overdue="item.is_overdue"
                        /></div
                ></Link>
            </div>
            <div v-else class="p-12 text-center text-sm text-[#79827d]">
                {{ isSystemAdmin ? '暂无已归档终稿' : '暂无会议纪要' }}
            </div>
        </section></BusinessLayout
    >
</template>
