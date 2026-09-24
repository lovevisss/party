<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Download, Printer, RotateCcw } from 'lucide-vue-next';
import BusinessLayout from '@/layouts/BusinessLayout.vue';
import MinuteStatus from '@/components/MinuteStatus.vue';
import { minuteDateTime, minuteMeetingRange } from '@/lib/minuteDateTime';

const props = defineProps<{
    minute: any;
    meetingType: { value: string; label: string; scope_label: string };
}>();
const page = usePage<any>();
const canReturn =
    page.props.auth.roles.some(
        (item: any) =>
            item.role === 'system_admin' ||
            (item.role === 'minute_manager' &&
                item.meeting_type === props.meetingType.value),
    ) && props.minute.status === 'archived';
const sendBack = () => {
    const reason = prompt('请输入退回原因（5～500字）');
    if (reason) router.post(`/minutes/${props.minute.id}/return`, { reason });
};
const printPage = () => window.print();
const role: Record<string, string> = {
    chair: '主持人',
    recorder: '记录人',
    attendee: '参会人员',
    absent: '缺席人员',
    observer: '列席人员',
};
</script>

<template>
    <BusinessLayout :title="`${meetingType.label}详情`" eyebrow="正式档案">
        <article
            class="mx-auto max-w-5xl border border-[#cfc6b5] bg-white px-7 py-9 shadow-sm md:px-14 md:py-12 print:border-0 print:shadow-none"
        >
            <header class="border-b-2 border-[#173b32] pb-6 text-center">
                <p class="text-sm tracking-[.3em] text-[#8b6f35]">
                    {{ meetingType.label }}
                </p>
                <h1 class="mt-3 font-serif text-3xl font-semibold">
                    {{ minute.title }}
                </h1>
                <div class="mt-4">
                    <MinuteStatus
                        :status="minute.status"
                        :overdue="
                            minute.status === 'archived' && minute.is_overdue
                        "
                    />
                </div>
            </header>
            <dl class="mt-8 grid gap-px bg-[#dcd5c8] text-sm sm:grid-cols-2">
                <div class="detail">
                    <dt>会议编号</dt>
                    <dd>
                        {{ minute.meeting_year }} 年第
                        {{ minute.sequence_no }} 次
                    </dd>
                </div>
                <div class="detail">
                    <dt>会议时间</dt>
                    <dd>
                        {{
                            minuteMeetingRange(
                                minute.meeting_start_at,
                                minute.meeting_end_at,
                            )
                        }}
                    </dd>
                </div>
                <div class="detail sm:col-span-2">
                    <dt>{{ meetingType.scope_label }}</dt>
                    <dd>{{ minute.meeting_scope?.name || '—' }}</dd>
                </div>
                <div
                    v-for="(label, key) in role"
                    :key="key"
                    class="detail sm:col-span-2"
                >
                    <dt>{{ label }}</dt>
                    <dd>
                        {{
                            minute.participants
                                .filter(
                                    (participant: any) =>
                                        participant.role_type === key,
                                )
                                .map(
                                    (participant: any) =>
                                        participant.display_name,
                                )
                                .join('、') || '—'
                        }}
                    </dd>
                </div>
            </dl>
            <section class="mt-9">
                <h2 class="doc-title">第一议题学习内容</h2>
                <div class="text-[15px] leading-8 whitespace-pre-wrap">
                    {{ minute.first_topic_content }}
                </div>
            </section>
            <section v-if="minute.remarks" class="mt-8">
                <h2 class="doc-title">备注</h2>
                <p class="text-sm leading-7 whitespace-pre-wrap">
                    {{ minute.remarks }}
                </p>
            </section>
            <section
                class="mt-9 grid gap-5 border-t pt-6 text-sm sm:grid-cols-2"
            >
                <div>归档时间：{{ minuteDateTime(minute.archived_at) }}</div>
                <div>截止时间：{{ minuteDateTime(minute.due_at, true) }}</div>
                <div>当前版本：V{{ minute.current_version }}</div>
                <div>
                    归档结论：{{
                        minute.status === 'archived'
                            ? minute.is_overdue
                                ? '超时归档'
                                : '按时归档'
                            : '待重新归档'
                    }}
                </div>
            </section>
            <section class="mt-8 print:hidden">
                <h2 class="doc-title">版本与附件</h2>
                <div
                    v-for="version in minute.versions"
                    :key="version.id"
                    class="mt-2 border border-[#e2dbcf] bg-[#faf8f3] px-3 py-2 text-sm text-[#52625a]"
                >
                    V{{ version.version_no }} · 归档时间
                    {{ minuteDateTime(version.archived_at) }} · 截止时间
                    {{ minuteDateTime(version.due_at, true) }} ·
                    {{ version.is_overdue ? '超时归档' : '按时归档' }}
                </div>
                <a
                    v-for="file in minute.files"
                    :key="file.id"
                    :href="`/minutes/${minute.id}/files/${file.id}`"
                    class="mt-2 flex items-center gap-2 border px-3 py-2 text-sm text-[#2f6a59]"
                    ><Download :size="15" />V{{ file.version_no || '待归档' }} ·
                    {{ file.original_name }}</a
                >
            </section>
        </article>
        <div class="mx-auto mt-4 flex max-w-5xl justify-end gap-3 print:hidden">
            <button class="btn-secondary" @click="printPage">
                <Printer :size="16" />打印</button
            ><button v-if="canReturn" class="btn-danger" @click="sendBack">
                <RotateCcw :size="16" />退回修改
            </button>
        </div>
    </BusinessLayout>
</template>
