<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowUpRight, FileCheck2 } from 'lucide-vue-next';
import BusinessLayout from '@/layouts/BusinessLayout.vue';
import { minuteDateTime } from '@/lib/minuteDateTime';

type Minute = {
    id: string;
    title: string | null;
    meeting_type: string;
    meeting_scope: { name: string } | null;
    meeting_year: number | null;
    sequence_no: number | null;
    current_version: number;
    archived_at: string | null;
};

defineProps<{
    minutes: {
        data: Minute[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

const typeLabels: Record<string, string> = {
    party_branch: '党总支会议纪要',
    party_government_joint: '党政联席会议纪要',
};
</script>

<template>
    <BusinessLayout title="按时归档明细" eyebrow="工作台 · 归档统计">
        <Link
            href="/dashboard"
            class="mb-5 inline-flex items-center gap-2 text-sm font-medium text-[#2f6a59] hover:underline"
        >
            <ArrowLeft :size="16" aria-hidden="true" />返回工作台
        </Link>

        <section class="overflow-hidden border border-[#ded7c9] bg-white shadow-[0_8px_24px_rgba(48,55,45,.05)]">
            <header class="flex flex-wrap items-end justify-between gap-4 border-b border-[#e8e2d7] px-6 py-6">
                <div>
                    <p class="text-xs tracking-[.18em] text-[#8b6f35]">归档统计</p>
                    <h1 class="mt-2 font-serif text-2xl font-semibold text-[#173b32]">按时归档</h1>
                    <p class="mt-2 text-sm text-[#79827d]">汇总全部会议类型的当前已归档纪要</p>
                </div>
                <p class="font-serif text-4xl font-semibold tabular-nums text-[#173b32]">
                    {{ minutes.total }}<span class="ml-2 font-sans text-sm font-normal text-[#79827d]">条</span>
                </p>
            </header>

            <div v-if="minutes.data.length" class="divide-y divide-[#eee9df]">
                <Link
                    v-for="minute in minutes.data"
                    :key="minute.id"
                    :href="`/minutes/${minute.id}`"
                    class="group flex flex-wrap items-center gap-4 px-6 py-5 transition-colors hover:bg-[#faf8f3] focus-visible:bg-[#faf8f3] focus-visible:outline-2 focus-visible:outline-offset-[-3px] focus-visible:outline-[#2f6a59]"
                >
                    <div class="grid size-10 shrink-0 place-items-center bg-[#edf4ef] text-[#2f6a59]">
                        <FileCheck2 :size="19" aria-hidden="true" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium break-words text-[#173b32] group-hover:underline">
                            {{ minute.title || '未命名纪要' }}
                        </p>
                        <p class="mt-1 text-xs text-[#79827d]">
                            {{ typeLabels[minute.meeting_type] || '会议纪要' }} ·
                            {{ minute.meeting_scope?.name || '—' }} ·
                            {{ minute.meeting_year || '—' }} 年第
                            {{ minute.sequence_no || '—' }} 次 · V{{ minute.current_version }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-4 text-sm text-[#68736e]">
                        <span class="tabular-nums">{{ minuteDateTime(minute.archived_at) }}</span>
                        <ArrowUpRight :size="17" class="text-[#2f6a59]" aria-hidden="true" />
                    </div>
                </Link>
            </div>
            <div v-else class="px-6 py-16 text-center text-sm text-[#79827d]">
                暂无按时归档的纪要
            </div>

            <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-[#e8e2d7] px-6 py-4 text-sm text-[#68736e]">
                <span>第 {{ minutes.current_page }} / {{ minutes.last_page }} 页</span>
                <nav aria-label="按时归档明细分页" class="flex gap-2">
                    <Link v-if="minutes.prev_page_url" :href="minutes.prev_page_url" class="btn-secondary">上一页</Link>
                    <span v-else class="btn-secondary opacity-40">上一页</span>
                    <Link v-if="minutes.next_page_url" :href="minutes.next_page_url" class="btn-secondary">下一页</Link>
                    <span v-else class="btn-secondary opacity-40">下一页</span>
                </nav>
            </footer>
        </section>
    </BusinessLayout>
</template>
