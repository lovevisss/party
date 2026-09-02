<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Archive, Download, FileCheck2, FileUp, Save, Search, X } from 'lucide-vue-next';
import BusinessLayout from '@/layouts/BusinessLayout.vue';

const props = defineProps<{ minute: any | null; organizations: any[] }>();
const formatDateTime = (value: string | null) => value ? value.slice(0, 16) : '';
const form = useForm({
    organization_id: props.minute?.organization_id ?? props.organizations[0]?.id,
    meeting_year: props.minute?.meeting_year ?? new Date().getFullYear(),
    sequence_no: props.minute?.sequence_no ?? null,
    title: props.minute?.title ?? '',
    meeting_start_at: formatDateTime(props.minute?.meeting_start_at),
    meeting_end_at: formatDateTime(props.minute?.meeting_end_at),
    first_topic_content: props.minute?.first_topic_content ?? '',
    remarks: props.minute?.remarks ?? '',
    lock_version: props.minute?.lock_version ?? 0,
    participants: props.minute?.participants ?? [],
});
const attachmentForm = useForm<{ attachment: File | null }>({ attachment: null });
const archiving = ref(false);
const query = ref('');
const results = ref<any[]>([]);
const roles: Record<string, string> = { chair: '主持人', recorder: '记录人', attendee: '参会人员', absent: '缺席人员', observer: '列席人员' };
const pendingFiles = computed(() => props.minute?.files?.filter((file: any) => !file.version_no) ?? []);

const save = () => props.minute ? form.put(`/minutes/${props.minute.id}`, { preserveScroll: true }) : form.post('/minutes');
const archive = () => {
    if (!props.minute || archiving.value || !confirm('归档后内容将锁定，确认正式归档吗？')) return;
    archiving.value = true;
    const submitArchive = () => router.post(`/minutes/${props.minute.id}/archive`, {}, {
        preserveScroll: true,
        onFinish: () => { archiving.value = false; },
    });
    if (form.isDirty) {
        form.put(`/minutes/${props.minute.id}`, {
            preserveScroll: true,
            onSuccess: submitArchive,
            onError: () => { archiving.value = false; },
        });
        return;
    }
    submitArchive();
};
const search = async () => { results.value = await fetch(`/people/search?q=${encodeURIComponent(query.value)}`).then((response) => response.json()); };
const add = (person: any, role: string | number) => {
    const key = String(role);
    if (!form.participants.some((item: any) => item.person_id === person.id && item.role_type === key)) {
        form.participants.push({ person_id: person.id, display_name: person.name, role_type: key, is_external: false });
    }
};
const chooseAttachment = (event: Event) => { attachmentForm.attachment = (event.target as HTMLInputElement).files?.[0] ?? null; };
const upload = () => {
    if (!props.minute || !attachmentForm.attachment || attachmentForm.processing) return;
    attachmentForm.post(`/minutes/${props.minute.id}/attachment`, { forceFormData: true, preserveScroll: true, onSuccess: () => attachmentForm.reset() });
};
</script>

<template>
  <BusinessLayout :title="minute ? '编辑会议纪要' : '新建会议纪要'" eyebrow="党委会 · 结构化归档">
    <form class="space-y-5" @submit.prevent="save">
      <section class="form-card"><div class="section-head"><span>01</span><div><h2>基本信息</h2><p>会议编号与召开时间</p></div></div><div class="form-grid"><label class="field"><span>所属学院</span><select v-model="form.organization_id" required><option v-for="organization in organizations" :key="organization.id" :value="organization.id">{{ organization.name }}</option></select></label><label class="field"><span>会议年度</span><input v-model="form.meeting_year" type="number"></label><label class="field"><span>会议序号</span><input v-model="form.sequence_no" type="number" min="1" max="999"></label><label class="field md:col-span-3"><span>会议名称</span><input v-model="form.title" maxlength="200" placeholder="例如：XX学院2026年第1次党委会"></label><label class="field"><span>开始时间</span><input v-model="form.meeting_start_at" type="datetime-local"></label><label class="field"><span>结束时间</span><input v-model="form.meeting_end_at" type="datetime-local"></label></div></section>
      <section class="form-card"><div class="section-head"><span>02</span><div><h2>人员情况</h2><p>从在职人员库精确选择</p></div></div><div class="flex gap-2"><input v-model="query" class="control flex-1" placeholder="输入姓名或工号" @keyup.enter.prevent="search"><button type="button" class="btn-secondary" @click="search"><Search :size="16" />搜索</button></div><div v-if="results.length" class="mt-3 max-h-56 overflow-y-auto border border-[#ded7c9]"><div v-for="person in results" :key="person.id" class="flex flex-wrap items-center gap-2 border-b px-3 py-2 text-sm"><span class="mr-auto font-medium">{{ person.name }} <small class="font-normal text-slate-500">{{ person.employee_no }} · {{ person.organization?.name }}</small></span><button v-for="(label, key) in roles" :key="key" type="button" class="rounded border px-2 py-1 text-xs" @click="add(person, key)">+ {{ label }}</button></div></div><div class="mt-4 flex flex-wrap gap-2"><span v-for="(person, index) in form.participants" :key="index" class="inline-flex items-center gap-2 rounded-full bg-[#edf3f0] px-3 py-1.5 text-xs text-[#245446]"><b>{{ roles[person.role_type] }}</b>{{ person.display_name }}<button type="button" @click="form.participants.splice(index, 1)"><X :size="13" /></button></span></div></section>
      <section class="form-card"><div class="section-head"><span>03</span><div><h2>第一议题</h2><p>学习内容与会议要点</p></div></div><textarea v-model="form.first_topic_content" class="control min-h-56 resize-y leading-7" maxlength="20000" placeholder="请输入第一议题学习内容……" /><div class="mt-2 text-right text-xs text-[#8a918d]">{{ form.first_topic_content.length }} / 20,000</div><label class="field mt-4"><span>备注</span><textarea v-model="form.remarks" class="control min-h-20" maxlength="1000" /></label></section>
      <section class="form-card"><div class="section-head"><span>04</span><div><h2>正式附件</h2><p>DOC / DOCX / PDF，最大 20 MB</p></div></div><div v-if="minute" class="space-y-4"><div class="flex flex-col gap-3 border border-dashed border-[#baa874] bg-[#faf8f1] p-5 sm:flex-row sm:items-center"><FileUp class="text-[#8b6f35]" /><input type="file" accept=".doc,.docx,.pdf" @change="chooseAttachment"><button type="button" class="btn-secondary sm:ml-auto" :disabled="!attachmentForm.attachment || attachmentForm.processing" @click="upload">{{ attachmentForm.processing ? '正在上传…' : '上传附件' }}</button></div><div v-if="pendingFiles.length" class="border border-[#d8e2dd] bg-[#f5faf7]"><div class="flex items-center gap-2 border-b border-[#d8e2dd] px-4 py-3 text-sm font-medium text-[#245446]"><FileCheck2 :size="17" />已上传、待归档附件</div><Link v-for="file in pendingFiles" :key="file.id" :href="`/minutes/${minute.id}/files/${file.id}`" class="flex items-center gap-2 px-4 py-3 text-sm text-[#2f6a59] hover:bg-white"><Download :size="15" />{{ file.original_name }}<span class="ml-auto text-xs text-[#77827c]">{{ (file.size_bytes / 1024).toFixed(1) }} KB</span></Link></div><p v-else class="text-sm text-amber-700">尚未上传可用于归档的正式附件。</p></div><p v-else class="text-sm text-[#7b8580]">请先保存草稿，再上传正式附件。</p></section>
      <div v-if="Object.keys(form.errors).length" class="border-l-4 border-red-700 bg-red-50 p-4 text-sm text-red-800"><p v-for="error in form.errors" :key="error">{{ error }}</p></div>
      <div class="sticky bottom-4 flex justify-end gap-3 border border-[#d8d1c3] bg-white/95 p-3 shadow-xl print:hidden"><button type="submit" class="btn-secondary" :disabled="form.processing || archiving"><Save :size="16" />{{ form.processing ? '正在保存…' : '保存草稿' }}</button><button v-if="minute" type="button" class="btn-primary" :disabled="archiving || form.processing || attachmentForm.processing" @click="archive"><Archive :size="16" />{{ archiving ? '正在保存并归档…' : '保存并提交归档' }}</button></div>
    </form>
  </BusinessLayout>
</template>
