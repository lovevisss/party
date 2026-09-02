<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Archive, FileUp, Save, Search, X } from '@lucide/vue';
import BusinessLayout from '@/layouts/BusinessLayout.vue';

const props = defineProps<{ minute: any | null; organizations: any[] }>();
const dt = (value: string | null) => value ? value.slice(0, 16) : '';
const form = useForm({
    organization_id: props.minute?.organization_id ?? props.organizations[0]?.id,
    meeting_year: props.minute?.meeting_year ?? new Date().getFullYear(),
    sequence_no: props.minute?.sequence_no ?? null,
    title: props.minute?.title ?? '',
    meeting_start_at: dt(props.minute?.meeting_start_at),
    meeting_end_at: dt(props.minute?.meeting_end_at),
    first_topic_content: props.minute?.first_topic_content ?? '',
    remarks: props.minute?.remarks ?? '',
    lock_version: props.minute?.lock_version ?? 0,
    participants: props.minute?.participants ?? [],
});
const roles: Record<string, string> = { chair: '主持人', recorder: '记录人', attendee: '参会', absent: '缺席', observer: '列席' };
const query = ref(''); const results = ref<any[]>([]); const attachment = ref<File | null>(null);
const hasFile = computed(() => props.minute?.files?.some((file: any) => !file.version_no));
const save = () => props.minute ? form.put(`/minutes/${props.minute.id}`, { preserveScroll: true }) : form.post('/minutes');
const archive = () => confirm('归档后内容将锁定，确认正式归档吗？') && router.post(`/minutes/${props.minute.id}/archive`);
const search = async () => { results.value = await fetch(`/people/search?q=${encodeURIComponent(query.value)}`).then(response => response.json()); };
const add = (person: any, role: string | number) => { const key = String(role); if (!form.participants.some((item: any) => item.person_id === person.id && item.role_type === key)) form.participants.push({ person_id: person.id, display_name: person.name, role_type: key, is_external: false }); };
const chooseAttachment = (event: Event) => { attachment.value = (event.target as HTMLInputElement).files?.[0] ?? null; };
const upload = () => attachment.value && router.post(`/minutes/${props.minute.id}/attachment`, { attachment: attachment.value }, { forceFormData: true, preserveScroll: true });
</script>

<template>
  <BusinessLayout :title="minute ? '编辑会议纪要' : '新建会议纪要'" eyebrow="党委会 · 结构化归档">
    <form class="space-y-5" @submit.prevent="save">
      <section class="form-card"><div class="section-head"><span>01</span><div><h2>基本信息</h2><p>会议编号与召开时间</p></div></div><div class="form-grid"><label class="field"><span>所属学院</span><select v-model="form.organization_id" required><option v-for="org in organizations" :key="org.id" :value="org.id">{{ org.name }}</option></select></label><label class="field"><span>会议年度</span><input v-model="form.meeting_year" type="number"></label><label class="field"><span>会议序号</span><input v-model="form.sequence_no" type="number" min="1" max="999"></label><label class="field md:col-span-3"><span>会议名称</span><input v-model="form.title" maxlength="200" placeholder="例如：XX学院2026年第1次党委会"></label><label class="field"><span>开始时间</span><input v-model="form.meeting_start_at" type="datetime-local"></label><label class="field"><span>结束时间</span><input v-model="form.meeting_end_at" type="datetime-local"></label></div></section>
      <section class="form-card"><div class="section-head"><span>02</span><div><h2>人员情况</h2><p>从在职人员库精确选择</p></div></div><div class="flex gap-2"><input v-model="query" class="control flex-1" placeholder="输入姓名或工号" @keyup.enter.prevent="search"><button type="button" class="btn-secondary" @click="search"><Search :size="16"/>搜索</button></div><div v-if="results.length" class="mt-3 max-h-56 overflow-y-auto border border-[#ded7c9]"><div v-for="person in results" :key="person.id" class="flex flex-wrap items-center gap-2 border-b px-3 py-2 text-sm"><span class="mr-auto font-medium">{{ person.name }} <small class="font-normal text-slate-500">{{ person.employee_no }} · {{ person.organization?.name }}</small></span><button v-for="(label,key) in roles" :key="key" type="button" class="rounded border px-2 py-1 text-xs" @click="add(person,key)">+ {{ label }}</button></div></div><div class="mt-4 flex flex-wrap gap-2"><span v-for="(person,index) in form.participants" :key="index" class="inline-flex items-center gap-2 rounded-full bg-[#edf3f0] px-3 py-1.5 text-xs text-[#245446]"><b>{{ roles[person.role_type] }}</b>{{ person.display_name }}<button type="button" @click="form.participants.splice(index,1)"><X :size="13"/></button></span></div></section>
      <section class="form-card"><div class="section-head"><span>03</span><div><h2>第一议题</h2><p>学习内容与会议要点</p></div></div><textarea v-model="form.first_topic_content" class="control min-h-56 resize-y leading-7" maxlength="20000" placeholder="请输入第一议题学习内容……"/><div class="mt-2 text-right text-xs text-[#8a918d]">{{ form.first_topic_content.length }} / 20,000</div><label class="field mt-4"><span>备注</span><textarea v-model="form.remarks" class="control min-h-20" maxlength="1000"/></label></section>
      <section class="form-card"><div class="section-head"><span>04</span><div><h2>正式附件</h2><p>DOC / DOCX / PDF，最大 20 MB</p></div></div><div v-if="minute" class="flex flex-col gap-3 border border-dashed border-[#baa874] bg-[#faf8f1] p-5 sm:flex-row sm:items-center"><FileUp class="text-[#8b6f35]"/><input type="file" accept=".doc,.docx,.pdf" @change="chooseAttachment"><button type="button" class="btn-secondary sm:ml-auto" @click="upload">上传附件</button><span v-if="hasFile" class="text-xs text-emerald-700">已具备待归档附件</span></div><p v-else class="text-sm text-[#7b8580]">请先保存草稿，再上传正式附件。</p></section>
      <div v-if="Object.keys(form.errors).length" class="border-l-4 border-red-700 bg-red-50 p-4 text-sm text-red-800"><p v-for="error in form.errors" :key="error">{{ error }}</p></div>
      <div class="sticky bottom-4 flex justify-end gap-3 border border-[#d8d1c3] bg-white/95 p-3 shadow-xl print:hidden"><button type="submit" class="btn-secondary"><Save :size="16"/>保存草稿</button><button v-if="minute" type="button" class="btn-primary" @click="archive"><Archive :size="16"/>提交归档</button></div>
    </form>
  </BusinessLayout>
</template>
