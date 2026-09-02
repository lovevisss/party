<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { CheckCircle2, Download, Search, ShieldCheck, Trash2, Upload, UserPlus, Users } from '@lucide/vue';
import BusinessLayout from '@/layouts/BusinessLayout.vue';

const props = defineProps<{ assignments: any; batches: any[]; organizations: any[]; filters: any }>();
const personQuery = ref(''); const people = ref<any[]>([]); const selected = ref<any | null>(null);
const role = ref('college_submitter'); const positionLabel = ref('组织员'); const file = ref<File | null>(null);
const filters = ref({ q: props.filters.q ?? '', role: props.filters.role ?? '', organization_id: props.filters.organization_id ?? '' });
const roleLabels: Record<string, string> = { college_submitter: '学院提交人', school_manager: '校级业务管理员', system_admin: '系统管理员' };
const searchPeople = async () => { people.value = await fetch(`/people/search?q=${encodeURIComponent(personQuery.value)}`).then(response => response.json()); };
const choose = (person: any) => { selected.value = person; people.value = []; personQuery.value = `${person.name}（${person.employee_no}）`; };
const grant = () => selected.value && router.post('/admin/authorizations', { person_id: selected.value.id, role: role.value, position_label: role.value === 'college_submitter' ? positionLabel.value : null }, { preserveScroll: true });
const revoke = (assignment: any) => confirm(`确认撤销 ${assignment.user?.name} 的${roleLabels[assignment.role]}权限吗？`) && router.delete(`/admin/authorizations/${assignment.id}`, { preserveScroll: true });
const applyFilters = () => router.get('/admin/authorization-import', filters.value, { preserveState: true, replace: true });
const chooseFile = (event: Event) => { file.value = (event.target as HTMLInputElement).files?.[0] ?? null; };
const upload = () => file.value && router.post('/admin/authorization-import/preview', { file: file.value }, { forceFormData: true, preserveScroll: true });
const commit = (id: string) => router.post(`/admin/authorization-import/${id}/commit`, {}, { preserveScroll: true });
const selectedScope = computed(() => role.value === 'college_submitter' ? selected.value?.organization?.name ?? '由人员库自动确定' : '全校范围');
</script>

<template>
  <BusinessLayout title="授权管理" eyebrow="人员库 · 岗位权限">
    <section class="grid gap-5 xl:grid-cols-[1.2fr_.8fr]">
      <div class="form-card">
        <div class="section-head"><span>01</span><div><h2>从人员库添加授权</h2><p>搜索已同步的在职人员，权限立即生效</p></div></div>
        <div class="relative">
          <div class="flex gap-2"><input v-model="personQuery" class="control flex-1" placeholder="输入姓名或工号" @keyup.enter="searchPeople"><button class="btn-secondary" @click="searchPeople"><Search :size="16"/>查询人员</button></div>
          <div v-if="people.length" class="absolute z-10 mt-1 max-h-72 w-full overflow-y-auto border border-[#cec6b7] bg-white shadow-xl"><button v-for="person in people" :key="person.id" class="flex w-full items-center gap-3 border-b px-4 py-3 text-left text-sm transition last:border-0 hover:bg-[#f5f2eb]" @click="choose(person)"><span class="grid size-9 place-items-center rounded-full bg-[#edf3f0] font-serif text-[#2f6a59]">{{person.name.slice(0,1)}}</span><span class="flex-1"><b>{{person.name}}</b><small class="ml-2 text-[#78827d]">{{person.employee_no}}</small><span class="mt-0.5 block text-xs text-[#78827d]">{{person.organization?.external_code}} · {{person.organization?.name}}</span></span></button></div>
        </div>
        <div v-if="selected" class="mt-5 grid gap-4 rounded-sm border border-[#d9d1c2] bg-[#faf8f3] p-4 md:grid-cols-2">
          <div><p class="text-xs text-[#7b8580]">已选择人员</p><p class="mt-1 font-medium">{{selected.name}} · {{selected.employee_no}}</p><p class="mt-1 text-xs text-[#65716b]">{{selected.organization?.name}}</p></div>
          <label class="field"><span>系统角色</span><select v-model="role"><option value="college_submitter">学院提交人</option><option value="school_manager">校级业务管理员</option><option value="system_admin">系统管理员</option></select></label>
          <label v-if="role==='college_submitter'" class="field"><span>岗位标签</span><select v-model="positionLabel"><option>组织员</option><option>办公室主任</option></select></label>
          <div class="field"><span>授权范围</span><div class="flex min-h-10 items-center border border-[#d8d1c3] bg-white px-3 text-sm">{{selectedScope}}</div></div>
          <div class="md:col-span-2 flex justify-end"><button class="btn-primary" @click="grant"><UserPlus :size="16"/>保存人员授权</button></div>
        </div>
        <div v-else class="mt-5 grid min-h-32 place-items-center border border-dashed border-[#d9d1c2] text-sm text-[#7d8782]"><div class="text-center"><Users :size="25" class="mx-auto mb-2 text-[#b29456]"/>请先搜索并选择人员</div></div>
      </div>

      <div class="form-card">
        <div class="section-head"><span>02</span><div><h2>批量导入</h2><p>适合一次维护多个学院和角色</p></div></div>
        <a href="/admin/authorization-import/template" class="flex items-center gap-3 border border-[#b9ab83] bg-[#faf7ee] p-4 text-sm transition hover:bg-[#f4eddc]"><span class="grid size-10 place-items-center bg-[#173b32] text-white"><Download :size="18"/></span><span><b class="block">下载 Excel 授权模板</b><small class="text-[#75807b]">含填写说明和下拉选项</small></span></a>
        <div class="mt-4 border border-dashed border-[#cfc5b2] p-4"><input type="file" accept=".csv,.xlsx" class="block w-full text-sm" @change="chooseFile"><button class="btn-secondary mt-4 w-full" :disabled="!file" @click="upload"><Upload :size="16"/>上传并校验</button></div>
        <div v-if="batches.length" class="mt-5 space-y-2"><details v-for="batch in batches" :key="batch.id" class="border border-[#e2dccf] px-3 py-2 text-xs"><summary class="cursor-pointer list-none"><span class="font-medium">{{batch.status==='committed'?'已生效':batch.status==='invalid'?'校验失败':'等待确认'}}</span><span class="ml-2 text-[#7e8782]">{{batch.payload.length}} 条 · {{batch.created_at?.slice(0,16)}}</span></summary><div v-if="batch.errors?.length" class="mt-2 space-y-1 border-t pt-2 text-red-700"><p v-for="error in batch.errors" :key="error.line">第 {{error.line}} 行 {{error.employee_no}}：{{error.messages.join('、')}}</p></div><button v-if="batch.status==='preview'&&!batch.errors?.length" class="mt-3 inline-flex items-center gap-1 font-medium text-[#2f6a59]" @click="commit(batch.id)"><CheckCircle2 :size="14"/>确认生效</button></details></div>
      </div>
    </section>

    <section class="mt-6">
      <div class="mb-3 flex flex-col gap-3 border border-[#ded7c9] bg-white p-4 lg:flex-row lg:items-end"><label class="field flex-1"><span>姓名或工号</span><input v-model="filters.q" placeholder="筛选已授权人员"></label><label class="field"><span>系统角色</span><select v-model="filters.role"><option value="">全部角色</option><option v-for="(label,key) in roleLabels" :key="key" :value="key">{{label}}</option></select></label><label class="field"><span>学院范围</span><select v-model="filters.organization_id"><option value="">全部学院</option><option v-for="org in organizations" :key="org.id" :value="org.id">{{org.name}}</option></select></label><button class="btn-secondary" @click="applyFilters"><Search :size="16"/>筛选</button></div>
      <div class="table-card"><table><thead><tr><th>授权人员</th><th>所属单位</th><th>系统角色</th><th>岗位标签</th><th>授权范围</th><th>操作</th></tr></thead><tbody><tr v-for="assignment in assignments.data" :key="assignment.id"><td><b>{{assignment.user?.name}}</b><small class="ml-2 text-[#7c8580]">{{assignment.user?.person?.employee_no}}</small></td><td>{{assignment.user?.person?.organization?.name||'—'}}</td><td><span class="inline-flex items-center gap-1 text-[#245446]"><ShieldCheck :size="14"/>{{roleLabels[assignment.role]}}</span></td><td>{{assignment.position_label||'—'}}</td><td>{{assignment.organization?.name||'全校'}}</td><td><button class="inline-flex items-center gap-1 text-[#9f3f36]" @click="revoke(assignment)"><Trash2 :size="14"/>撤销</button></td></tr><tr v-if="!assignments.data.length"><td colspan="6" class="py-14 text-center text-[#7d8782]">暂无符合条件的授权</td></tr></tbody></table></div>
      <div class="mt-4 flex justify-end gap-2"><Link v-for="link in assignments.links" :key="link.label" :href="link.url||'#'" class="border px-3 py-1.5 text-sm" :class="link.active?'border-[#2f6a59] bg-[#2f6a59] text-white':'border-[#d8d2c5] bg-white'" v-html="link.label"/></div>
    </section>
  </BusinessLayout>
</template>
