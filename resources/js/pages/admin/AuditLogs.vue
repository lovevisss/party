<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Search,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import BusinessLayout from '@/layouts/BusinessLayout.vue';

type Log = {
    id: number;
    date: string | null;
    time: string | null;
    event: string;
    event_label: string;
    actor: string;
    user_id: number | null;
    subject_type: string | null;
    subject_id: string | null;
    subject_label: string;
    subject_name: string;
    subject_missing: boolean;
    request_id: string | null;
    ip_address: string | null;
    user_agent: string | null;
    metadata: Record<string, unknown>;
};
type PageLink = { url: string | null; label: string; active: boolean };
const props = defineProps<{
    logs: { data: Log[]; links: PageLink[]; total: number };
    filters: { category: string };
    categories: Record<string, string>;
}>();

const category = ref(props.filters.category || '');
const expanded = ref<number | null>(null);
watch(
    () => props.filters.category,
    (value) => {
        category.value = value || '';
        expanded.value = null;
    },
);
const search = () =>
    router.get(
        '/admin/audit-logs',
        { category: category.value },
        { preserveState: true, replace: true },
    );
const pagination = computed(() =>
    props.logs.links.map((link, index, links) => {
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
const metadataText = (metadata: Log['metadata']) =>
    JSON.stringify(metadata, null, 2);
</script>

<template>
    <BusinessLayout title="审计日志" eyebrow="全程操作留痕">
        <div
            class="mb-6 border-l-4 border-[#b29456] bg-white px-5 py-4 shadow-[0_6px_22px_rgba(48,55,45,.04)]"
        >
            <p class="font-serif text-lg font-semibold text-[#173b32]">
                操作记录
            </p>
            <p class="mt-1 text-sm text-[#718079]">
                按时间查看系统操作；展开记录可核对请求信息与原始数据。
            </p>
        </div>

        <form
            class="mb-5 flex flex-wrap items-end gap-3 border border-[#ded7c9] bg-white p-4"
            @submit.prevent="search"
        >
            <label class="field min-w-[220px] flex-1 sm:max-w-xs">
                <span>事件类别</span>
                <select v-model="category">
                    <option value="">全部事件</option>
                    <option
                        v-for="(label, key) in categories"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select>
            </label>
            <button type="submit" class="btn-secondary">
                <Search :size="16" aria-hidden="true" />查询
            </button>
        </form>

        <section
            class="border border-[#ded7c9] bg-white shadow-[0_8px_24px_rgba(48,55,45,.04)]"
        >
            <div class="overflow-x-auto">
                <table class="w-full min-w-[780px] text-left text-sm">
                    <thead class="bg-[#f1ede4] text-xs text-[#66716c]">
                        <tr>
                            <th class="w-40 px-5 py-3 font-semibold">
                                时间 <span class="font-normal">(北京时间)</span>
                            </th>
                            <th class="w-52 px-5 py-3 font-semibold">事件</th>
                            <th class="w-36 px-5 py-3 font-semibold">操作人</th>
                            <th class="px-5 py-3 font-semibold">对象</th>
                            <th class="w-32 px-5 py-3 font-semibold">详情</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="log in logs.data" :key="log.id">
                            <tr
                                class="border-t border-[#eee9df] transition-colors hover:bg-[#f8faf6]"
                            >
                                <td class="px-5 py-4 tabular-nums">
                                    <span
                                        class="block font-medium text-[#263c32]"
                                        >{{ log.date || '—' }}</span
                                    >
                                    <span
                                        class="mt-0.5 block text-xs text-[#77857e]"
                                        >{{ log.time || '—' }}</span
                                    >
                                </td>
                                <td
                                    class="px-5 py-4 font-medium text-[#173b32]"
                                >
                                    {{ log.event_label }}
                                </td>
                                <td class="px-5 py-4 text-[#34453d]">
                                    {{ log.actor }}
                                </td>
                                <td class="px-5 py-4">
                                    <span
                                        class="block text-xs text-[#87918b]"
                                        >{{ log.subject_label }}</span
                                    >
                                    <span
                                        class="mt-1 block break-words text-[#263c32]"
                                        >{{ log.subject_name
                                        }}<span
                                            v-if="
                                                log.subject_missing &&
                                                log.subject_id
                                            "
                                            class="ml-1 text-xs text-[#87918b]"
                                            >#{{ log.subject_id }}</span
                                        ></span
                                    >
                                </td>
                                <td class="px-5 py-4">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-sm px-2 py-1.5 text-sm font-medium text-[#2f6a59] hover:bg-[#ecf4ed] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#2f6a59]"
                                        :aria-expanded="expanded === log.id"
                                        :aria-controls="`audit-detail-${log.id}`"
                                        @click="
                                            expanded =
                                                expanded === log.id
                                                    ? null
                                                    : log.id
                                        "
                                    >
                                        查看详情
                                        <ChevronDown
                                            :size="15"
                                            aria-hidden="true"
                                            class="transition-transform"
                                            :class="{
                                                'rotate-180':
                                                    expanded === log.id,
                                            }"
                                        />
                                    </button>
                                </td>
                            </tr>
                            <tr
                                v-if="expanded === log.id"
                                :id="`audit-detail-${log.id}`"
                                class="border-t border-[#e3e8df] bg-[#f8faf6]"
                            >
                                <td colspan="5" class="px-5 py-5">
                                    <dl
                                        class="grid gap-x-8 gap-y-4 text-sm md:grid-cols-2 xl:grid-cols-3"
                                    >
                                        <div>
                                            <dt class="text-xs text-[#78857d]">
                                                原始事件代码
                                            </dt>
                                            <dd
                                                class="mt-1 font-mono text-xs break-all"
                                            >
                                                {{ log.event }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs text-[#78857d]">
                                                操作人 ID
                                            </dt>
                                            <dd class="mt-1 font-mono text-xs">
                                                {{ log.user_id ?? '—' }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs text-[#78857d]">
                                                请求 ID
                                            </dt>
                                            <dd
                                                class="mt-1 font-mono text-xs break-all"
                                            >
                                                {{ log.request_id || '—' }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs text-[#78857d]">
                                                原始对象类型
                                            </dt>
                                            <dd
                                                class="mt-1 font-mono text-xs break-all"
                                            >
                                                {{ log.subject_type || '—' }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs text-[#78857d]">
                                                对象 ID
                                            </dt>
                                            <dd
                                                class="mt-1 font-mono text-xs break-all"
                                            >
                                                {{ log.subject_id || '—' }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs text-[#78857d]">
                                                IP 地址
                                            </dt>
                                            <dd
                                                class="mt-1 font-mono text-xs break-all"
                                            >
                                                {{ log.ip_address || '—' }}
                                            </dd>
                                        </div>
                                        <div
                                            class="md:col-span-2 xl:col-span-3"
                                        >
                                            <dt class="text-xs text-[#78857d]">
                                                浏览器信息
                                            </dt>
                                            <dd
                                                class="mt-1 font-mono text-xs break-all"
                                            >
                                                {{ log.user_agent || '—' }}
                                            </dd>
                                        </div>
                                        <div
                                            class="md:col-span-2 xl:col-span-3"
                                        >
                                            <dt class="text-xs text-[#78857d]">
                                                原始附加数据
                                            </dt>
                                            <dd class="mt-1">
                                                <pre
                                                    class="overflow-x-auto rounded-sm border border-[#e6e4dc] bg-white p-3 font-mono text-xs break-all whitespace-pre-wrap"
                                                    >{{
                                                        metadataText(
                                                            log.metadata,
                                                        )
                                                    }}</pre>
                                            </dd>
                                        </div>
                                    </dl>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div v-if="!logs.data.length" class="px-5 py-16 text-center">
                    <p class="font-serif text-lg text-[#263c32]">
                        暂无审计记录
                    </p>
                    <p class="mt-1 text-sm text-[#87918b]">
                        请尝试切换事件类别。
                    </p>
                </div>
            </div>
            <footer
                class="flex flex-wrap items-center justify-between gap-4 border-t border-[#e9e4d9] px-5 py-4"
            >
                <p class="text-xs text-[#7b8580]">
                    共
                    <span
                        class="mx-1 text-sm font-medium text-[#263c32] tabular-nums"
                        >{{ logs.total }}</span
                    >
                    条
                </p>
                <nav
                    class="flex flex-wrap items-center gap-1.5"
                    aria-label="审计日志分页"
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
