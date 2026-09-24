<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    Eye,
    FileText,
    Pencil,
    Plus,
    Search,
} from 'lucide-vue-next';
import { computed, reactive, watch } from 'vue';
import BusinessLayout from '@/layouts/BusinessLayout.vue';
import MinuteStatus from '@/components/MinuteStatus.vue';
import { minuteDateParts } from '@/lib/minuteDateTime';

type Filters = {
    year?: string | number;
    status?: string;
    meeting_scope_id?: string | number;
    overdue?: string | boolean;
    per_page?: string | number;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Minute = {
    id: string;
    title: string | null;
    current_version: number;
    meeting_scope: { name: string } | null;
    meeting_start_at: string | null;
    archived_at: string | null;
    meeting_year: number | null;
    sequence_no: number | null;
    status: string;
    is_overdue: boolean;
    can_edit: boolean;
};
const props = defineProps<{
    minutes: { data: Minute[]; links: PageLink[]; total: number };
    organizations: { id: string; name: string }[];
    filters: Filters;
    canCreate: boolean;
    meetingType: {
        value: string;
        slug: string;
        label: string;
        scope_label: string;
    };
}>();

const normalizeFilters = (filters: Filters) => ({
    ...filters,
    year: filters.year ?? '',
    status: filters.status ?? '',
    meeting_scope_id: filters.meeting_scope_id ?? '',
    overdue: filters.overdue == null ? '' : String(filters.overdue),
});
const filter = reactive(normalizeFilters(props.filters));
watch(
    () => props.filters,
    (value) => {
        Object.keys(filter).forEach(
            (key) => delete filter[key as keyof Filters],
        );
        Object.assign(filter, normalizeFilters(value));
    },
);
const apply = () =>
    router.get(
        `/minutes/${props.meetingType.slug}`,
        { ...filter },
        {
            preserveState: true,
            replace: true,
        },
    );

const rows = computed(() =>
    props.minutes.data.map((minute) => ({
        ...minute,
        meetingTime: minuteDateParts(minute.meeting_start_at),
        archiveTime: minuteDateParts(minute.archived_at),
    })),
);
const pagination = computed(() =>
    props.minutes.links.map((link, index, links) => {
        const direction =
            index === 0
                ? 'previous'
                : index === links.length - 1
                  ? 'next'
                  : null;
        const page = /^\d+$/.test(link.label) ? link.label : null;
        return {
            ...link,
            direction,
            page,
            label:
                direction === 'previous'
                    ? '上一页'
                    : direction === 'next'
                      ? '下一页'
                      : page
                        ? `第 ${page} 页`
                        : '更多页码',
            navigable:
                Boolean(link.url) && !link.active && Boolean(direction || page),
        };
    }),
);
</script>

<template>
    <BusinessLayout :title="meetingType.label" eyebrow="归档台账">
        <form
            class="mb-6 grid gap-4 rounded-sm border border-[#ded7c9] bg-white p-5 sm:grid-cols-2 lg:grid-cols-[150px_160px_minmax(180px,1fr)] 2xl:grid-cols-[140px_150px_minmax(180px,1fr)_170px_auto_auto] 2xl:items-end"
            @submit.prevent="apply"
        >
            <label class="field">
                <span>年度</span>
                <input
                    v-model="filter.year"
                    type="number"
                    placeholder="全部年度"
                />
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
                <span>{{ meetingType.scope_label }}</span>
                <select v-model="filter.meeting_scope_id">
                    <option value="">全部{{ meetingType.scope_label }}</option>
                    <option
                        v-for="organization in organizations"
                        :key="organization.id"
                        :value="organization.id"
                    >
                        {{ organization.name }}
                    </option>
                </select>
            </label>
            <label class="field">
                <span>归档结论</span>
                <select v-model="filter.overdue">
                    <option value="">全部结论</option>
                    <option value="0">按时归档</option>
                    <option value="1">超时归档</option>
                </select>
            </label>
            <button type="submit" class="btn-secondary self-end rounded-sm">
                <Search :size="16" aria-hidden="true" />查询
            </button>
            <Link
                v-if="canCreate"
                :href="`/minutes/${meetingType.slug}/create`"
                class="btn-primary self-end rounded-sm lg:justify-self-end 2xl:ml-4"
            >
                <Plus :size="16" aria-hidden="true" />新建纪要
            </Link>
        </form>

        <section
            class="min-w-0 overflow-hidden rounded-sm border border-[#ded7c9] bg-white shadow-[0_4px_20px_rgba(48,55,45,.03)]"
            aria-label="会议纪要列表"
        >
            <div v-if="rows.length" class="overflow-x-auto">
                <table
                    class="minute-table w-full min-w-[900px] table-fixed text-left text-sm"
                >
                    <colgroup>
                        <col class="w-[24%]" />
                        <col class="w-[16%]" />
                        <col class="w-[14%]" />
                        <col class="w-[9%]" />
                        <col class="w-[10%]" />
                        <col class="w-[14%]" />
                        <col class="w-[13%]" />
                    </colgroup>
                    <thead
                        class="bg-[#f1ede4] text-xs tracking-wide text-[#66716c]"
                    >
                        <tr>
                            <th scope="col">会议名称</th>
                            <th scope="col">{{ meetingType.scope_label }}</th>
                            <th scope="col">会议时间</th>
                            <th scope="col">序号</th>
                            <th scope="col">状态</th>
                            <th scope="col">归档时间</th>
                            <th scope="col">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="minute in rows"
                            :key="minute.id"
                            class="transition-colors hover:bg-[#fbfaf7]"
                        >
                            <td>
                                <p
                                    class="leading-6 font-medium break-words text-[#173b32]"
                                >
                                    {{ minute.title || '未命名草稿' }}
                                </p>
                                <p class="mt-1 text-xs text-[#87908b]">
                                    版本 {{ minute.current_version }}
                                </p>
                            </td>
                            <td class="leading-6 break-words text-[#52625a]">
                                {{ minute.meeting_scope?.name || '—' }}
                            </td>
                            <td class="tabular-nums">
                                <template v-if="minute.meetingTime"
                                    ><p>{{ minute.meetingTime.date }}</p>
                                    <p class="mt-1 text-xs text-[#87908b]">
                                        {{ minute.meetingTime.time }}
                                    </p></template
                                ><span v-else class="text-[#87908b]"
                                    >待补充</span
                                >
                            </td>
                            <td class="tabular-nums">
                                <span>{{ minute.meeting_year || '—' }}</span
                                ><span class="mx-1 text-[#b3bab5]">/</span
                                ><span>{{ minute.sequence_no || '—' }}</span>
                            </td>
                            <td>
                                <div
                                    class="flex flex-wrap items-center gap-y-1"
                                >
                                    <MinuteStatus
                                        :status="minute.status"
                                        :overdue="
                                            minute.status === 'archived' &&
                                            minute.is_overdue
                                        "
                                    />
                                </div>
                            </td>
                            <td class="tabular-nums">
                                <template v-if="minute.archiveTime"
                                    ><p>{{ minute.archiveTime.date }}</p>
                                    <p class="mt-1 text-xs text-[#87908b]">
                                        {{ minute.archiveTime.time }}
                                    </p></template
                                ><span v-else class="text-[#87908b]">—</span>
                            </td>
                            <td>
                                <Link
                                    :href="
                                        minute.can_edit
                                            ? `/minutes/${minute.id}/edit`
                                            : `/minutes/${minute.id}`
                                    "
                                    class="inline-flex min-h-9 items-center gap-1.5 rounded-sm px-2 font-medium whitespace-nowrap text-[#2f6a59] transition hover:bg-[#edf4ef] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#2f6a59]"
                                    ><component
                                        :is="minute.can_edit ? Pencil : Eye"
                                        :size="15"
                                        aria-hidden="true"
                                    />{{
                                        minute.can_edit ? '编辑' : '查看'
                                    }}</Link
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-else
                class="flex min-h-64 flex-col items-center justify-center gap-3 px-5 py-16 text-center"
            >
                <div class="rounded-full bg-[#f4f1ea] p-4 text-[#8b987f]">
                    <FileText
                        :size="28"
                        :stroke-width="1.5"
                        aria-hidden="true"
                    />
                </div>
                <p class="text-sm font-medium text-[#52625a]">
                    当前筛选条件下暂无纪要
                </p>
                <p class="text-xs text-[#87908b]">
                    请尝试调整年度、状态或{{ meetingType.scope_label }}筛选条件
                </p>
            </div>
            <footer
                class="flex flex-wrap items-center justify-between gap-4 border-t border-[#e9e4d9] px-5 py-4 print:hidden"
            >
                <p class="text-xs text-[#7b8580]">
                    共
                    <span
                        class="mx-1 text-sm font-medium text-[#263c32] tabular-nums"
                        >{{ minutes.total }}</span
                    >
                    条
                </p>
                <nav
                    class="flex flex-wrap items-center gap-1.5"
                    aria-label="会议纪要分页"
                >
                    <component
                        :is="link.navigable ? Link : 'button'"
                        v-for="(link, index) in pagination"
                        :key="index"
                        :href="link.navigable ? link.url! : undefined"
                        :type="link.navigable ? undefined : 'button'"
                        :disabled="!link.navigable"
                        :aria-label="link.label"
                        :title="link.label"
                        :aria-current="link.active ? 'page' : undefined"
                        class="inline-flex h-9 min-w-9 items-center justify-center rounded-sm border px-2 text-sm tabular-nums transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#2f6a59]"
                        :class="
                            link.active
                                ? 'border-[#2f6a59] bg-[#2f6a59] text-white'
                                : link.navigable
                                  ? 'border-[#ded7c9] bg-white text-[#52625a] hover:border-[#2f6a59] hover:bg-[#f1f6f2] hover:text-[#2f6a59]'
                                  : 'cursor-default border-transparent text-[#bac1bc]'
                        "
                    >
                        <ChevronLeft
                            v-if="link.direction === 'previous'"
                            :size="17"
                            aria-hidden="true"
                        /><ChevronRight
                            v-else-if="link.direction === 'next'"
                            :size="17"
                            aria-hidden="true"
                        /><span v-else>{{ link.page || '…' }}</span>
                    </component>
                </nav>
            </footer>
        </section>
    </BusinessLayout>
</template>

<style scoped>
.minute-table th {
    height: 44px;
    padding: 12px 16px;
    font-weight: 600;
}
.minute-table td {
    height: 76px;
    border-top: 1px solid #eee9df;
    padding: 14px 16px;
    vertical-align: middle;
}
.minute-table th:first-child,
.minute-table td:first-child {
    padding-left: 24px;
}
.minute-table th:last-child,
.minute-table td:last-child {
    padding-right: 24px;
}
</style>
