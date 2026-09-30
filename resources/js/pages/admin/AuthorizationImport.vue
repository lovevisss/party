<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Download,
    Pencil,
    Search,
    ShieldCheck,
    Trash2,
    Upload,
    UserPlus,
    Users,
} from '@lucide/vue';
import BusinessLayout from '@/layouts/BusinessLayout.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{
    assignments: any;
    batches: any[];
    organizations: any[];
    filters: any;
    meetingTypes: any[];
}>();
const personQuery = ref('');
const people = ref<any[]>([]);
const selected = ref<any | null>(null);
const role = ref('minute_submitter');
const meetingType = ref('party_branch');
const file = ref<File | null>(null);
const adjustment = ref<any | null>(null);
const adjustmentOpen = ref(false);
const adjustmentMode = ref<'role' | 'revoke'>('role');
const targetRole = ref('');
const targetMeetingType = ref('');
const adjustmentErrors = ref<Record<string, string>>({});
const adjustmentProcessing = ref(false);
const filters = ref({
    q: props.filters.q ?? '',
    role: props.filters.role ?? '',
    meeting_type: props.filters.meeting_type ?? '',
    meeting_scope_id: props.filters.meeting_scope_id
        ? String(props.filters.meeting_scope_id)
        : '',
});
const filteredScopes = computed(() =>
    props.organizations.filter(
        (scope) => scope.meeting_type === filters.value.meeting_type,
    ),
);
const roleLabels: Record<string, string> = {
    minute_submitter: '会议提交人',
    minute_manager: '会议管理员',
    global_admin: '全局管理员',
    system_admin: '系统管理员',
};
const availableTargetRoles = computed(() =>
    Object.entries(roleLabels).filter(
        ([key]) => key !== adjustment.value?.role,
    ),
);
const searchPeople = async () => {
    people.value = await fetch(
        `/people/search?q=${encodeURIComponent(personQuery.value)}`,
    ).then((response) => response.json());
};
const choose = (person: any) => {
    selected.value = person;
    people.value = [];
    personQuery.value = `${person.name}（${person.employee_no}）`;
};
const grant = () =>
    selected.value &&
    router.post(
        '/admin/authorizations',
        {
            person_id: selected.value.id,
            role: role.value,
            meeting_type: ['system_admin', 'global_admin'].includes(role.value)
                ? null
                : meetingType.value,
        },
        { preserveScroll: true },
    );
const openAdjustment = (assignment: any) => {
    adjustment.value = assignment;
    adjustmentMode.value = 'role';
    targetRole.value = '';
    targetMeetingType.value = assignment.meeting_type ?? '';
    adjustmentErrors.value = {};
    adjustmentOpen.value = true;
};
const targetNeedsMeetingType = computed(() =>
    ['minute_submitter', 'minute_manager'].includes(targetRole.value),
);
const targetScope = computed(() => {
    if (!targetNeedsMeetingType.value) return '全局';
    const organizationId = adjustment.value?.user?.person?.organization_id;
    return (
        props.organizations.find(
            (scope) =>
                scope.meeting_type === targetMeetingType.value &&
                scope.organizations?.some(
                    (org: any) => org.id === organizationId,
                ),
        )?.name ?? '该单位未映射到所选会议类型'
    );
});
const submitAdjustment = () => {
    if (!adjustment.value || adjustmentProcessing.value) return;
    adjustmentErrors.value = {};
    adjustmentProcessing.value = true;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            adjustmentOpen.value = false;
        },
        onError: (errors: Record<string, string>) => {
            adjustmentErrors.value = errors;
        },
        onFinish: () => {
            adjustmentProcessing.value = false;
        },
    };
    if (adjustmentMode.value === 'revoke') {
        router.delete(`/admin/authorizations/${adjustment.value.id}`, options);
    } else {
        router.patch(
            `/admin/authorizations/${adjustment.value.id}`,
            {
                role: targetRole.value,
                meeting_type: targetNeedsMeetingType.value
                    ? targetMeetingType.value
                    : null,
            },
            options,
        );
    }
};
const applyFilters = () =>
    router.get('/admin/authorization-import', filters.value, {
        preserveState: true,
        replace: true,
    });
const chooseFile = (event: Event) => {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
};
const upload = () =>
    file.value &&
    router.post(
        '/admin/authorization-import/preview',
        { file: file.value },
        { forceFormData: true, preserveScroll: true },
    );
const commit = (id: string) =>
    router.post(
        `/admin/authorization-import/${id}/commit`,
        {},
        { preserveScroll: true },
    );
const selectedScope = computed(() => {
    if (role.value === 'system_admin') return '全局系统权限';
    if (role.value === 'global_admin') return '全局纪要只读';
    const sourceId = selected.value?.organization_id;
    return (
        props.organizations.find(
            (scope) =>
                scope.meeting_type === meetingType.value &&
                scope.organizations?.some((org: any) => org.id === sourceId),
        )?.name ?? '该单位未映射，不能授权'
    );
});
</script>

<template>
    <BusinessLayout title="授权管理" eyebrow="人员库 · 双会议类型权限">
        <section class="grid gap-5 xl:grid-cols-[1.2fr_.8fr]">
            <div class="form-card">
                <div class="section-head">
                    <span>01</span>
                    <div>
                        <h2>从人员库添加授权</h2>
                        <p>搜索已同步的在职人员，权限立即生效</p>
                    </div>
                </div>
                <div class="relative">
                    <div class="flex gap-2">
                        <input
                            v-model="personQuery"
                            class="control flex-1"
                            placeholder="输入姓名或工号"
                            @keyup.enter="searchPeople"
                        /><button class="btn-secondary" @click="searchPeople">
                            <Search :size="16" />查询人员
                        </button>
                    </div>
                    <div
                        v-if="people.length"
                        class="absolute z-10 mt-1 max-h-72 w-full overflow-y-auto border border-[#cec6b7] bg-white shadow-xl"
                    >
                        <button
                            v-for="person in people"
                            :key="person.id"
                            class="flex w-full items-center gap-3 border-b px-4 py-3 text-left text-sm transition last:border-0 hover:bg-[#f5f2eb]"
                            @click="choose(person)"
                        >
                            <span
                                class="grid size-9 place-items-center rounded-full bg-[#edf3f0] font-serif text-[#2f6a59]"
                                >{{ person.name.slice(0, 1) }}</span
                            ><span class="flex-1"
                                ><b>{{ person.name }}</b
                                ><small class="ml-2 text-[#78827d]">{{
                                    person.employee_no
                                }}</small
                                ><span
                                    class="mt-0.5 block text-xs text-[#78827d]"
                                    >{{ person.organization?.external_code }} ·
                                    {{ person.organization?.name }}</span
                                ></span
                            >
                        </button>
                    </div>
                </div>
                <div
                    v-if="selected"
                    class="mt-5 grid gap-4 rounded-sm border border-[#d9d1c2] bg-[#faf8f3] p-4 md:grid-cols-2"
                >
                    <div>
                        <p class="text-xs text-[#7b8580]">已选择人员</p>
                        <p class="mt-1 font-medium">
                            {{ selected.name }} · {{ selected.employee_no }}
                        </p>
                        <p class="mt-1 text-xs text-[#65716b]">
                            {{ selected.organization?.name }}
                        </p>
                    </div>
                    <label
                        v-if="!['system_admin', 'global_admin'].includes(role)"
                        class="field"
                        ><span>会议类型</span
                        ><select v-model="meetingType">
                            <option
                                v-for="type in meetingTypes"
                                :key="type.value"
                                :value="type.value"
                            >
                                {{ type.label }}
                            </option>
                        </select></label
                    >
                    <label class="field"
                        ><span>权限角色</span
                        ><select v-model="role">
                            <option value="minute_submitter">会议提交人</option>
                            <option value="minute_manager">会议管理员</option>
                            <option value="global_admin">全局管理员</option>
                            <option value="system_admin">系统管理员</option>
                        </select></label
                    >
                    <div class="field">
                        <span>授权范围</span>
                        <div
                            class="flex min-h-10 items-center border border-[#d8d1c3] bg-white px-3 text-sm"
                        >
                            {{ selectedScope }}
                        </div>
                    </div>
                    <div class="flex justify-end md:col-span-2">
                        <button class="btn-primary" @click="grant">
                            <UserPlus :size="16" />保存人员授权
                        </button>
                    </div>
                </div>
                <div
                    v-else
                    class="mt-5 grid min-h-32 place-items-center border border-dashed border-[#d9d1c2] text-sm text-[#7d8782]"
                >
                    <div class="text-center">
                        <Users
                            :size="25"
                            class="mx-auto mb-2 text-[#b29456]"
                        />请先搜索并选择人员
                    </div>
                </div>
            </div>

            <div class="form-card">
                <div class="section-head">
                    <span>02</span>
                    <div>
                        <h2>批量导入</h2>
                        <p>适合一次维护两类会议的多个人员权限</p>
                    </div>
                </div>
                <a
                    href="/admin/authorization-import/template"
                    class="flex items-center gap-3 border border-[#b9ab83] bg-[#faf7ee] p-4 text-sm transition hover:bg-[#f4eddc]"
                    ><span
                        class="grid size-10 place-items-center bg-[#173b32] text-white"
                        ><Download :size="18" /></span
                    ><span
                        ><b class="block">下载 Excel 授权模板</b
                        ><small class="text-[#75807b]"
                            >含填写说明和下拉选项</small
                        ></span
                    ></a
                >
                <div class="mt-4 border border-dashed border-[#cfc5b2] p-4">
                    <input
                        type="file"
                        accept=".csv,.xlsx"
                        class="block w-full text-sm"
                        @change="chooseFile"
                    /><button
                        class="btn-secondary mt-4 w-full"
                        :disabled="!file"
                        @click="upload"
                    >
                        <Upload :size="16" />上传并校验
                    </button>
                </div>
                <div v-if="batches.length" class="mt-5 space-y-2">
                    <details
                        v-for="batch in batches"
                        :key="batch.id"
                        class="border border-[#e2dccf] px-3 py-2 text-xs"
                    >
                        <summary class="cursor-pointer list-none">
                            <span class="font-medium">{{
                                batch.status === 'committed'
                                    ? '已生效'
                                    : batch.status === 'invalid'
                                      ? '校验失败'
                                      : '等待确认'
                            }}</span
                            ><span class="ml-2 text-[#7e8782]"
                                >{{ batch.payload.length }} 条 ·
                                {{ batch.created_at?.slice(0, 16) }}</span
                            >
                        </summary>
                        <div
                            v-if="batch.errors?.length"
                            class="mt-2 space-y-1 border-t pt-2 text-red-700"
                        >
                            <p v-for="error in batch.errors" :key="error.line">
                                第 {{ error.line }} 行
                                {{ error.employee_no }}：{{
                                    error.messages.join('、')
                                }}
                            </p>
                        </div>
                        <div
                            v-if="batch.status === 'preview'"
                            class="mt-3 max-h-48 space-y-1 overflow-y-auto border-t pt-2 text-[#52635b]"
                        >
                            <p
                                v-for="(item, index) in batch.payload"
                                :key="index"
                            >
                                {{ item.name }}（{{ item.employee_no }}） ·
                                {{ roleLabels[item.role] }} ·
                                {{ item.meeting_scope || '全局' }} ·
                                {{ item.enabled ? '启用' : '停用' }}
                            </p>
                        </div>
                        <button
                            v-if="
                                batch.status === 'preview' &&
                                !batch.errors?.length
                            "
                            class="mt-3 inline-flex items-center gap-1 font-medium text-[#2f6a59]"
                            @click="commit(batch.id)"
                        >
                            <CheckCircle2 :size="14" />确认生效
                        </button>
                    </details>
                </div>
            </div>
        </section>

        <section class="mt-6">
            <div
                class="mb-3 flex flex-col gap-3 border border-[#ded7c9] bg-white p-4 lg:flex-row lg:items-end"
            >
                <label class="field flex-1"
                    ><span>姓名或工号</span
                    ><input
                        v-model="filters.q"
                        placeholder="筛选已授权人员" /></label
                ><label class="field"
                    ><span>权限角色</span
                    ><select v-model="filters.role">
                        <option value="">全部角色</option>
                        <option
                            v-for="(label, key) in roleLabels"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </select></label
                ><label class="field"
                    ><span>会议类型</span
                    ><select
                        v-model="filters.meeting_type"
                        @change="filters.meeting_scope_id = ''"
                    >
                        <option value="">全部类型</option>
                        <option
                            v-for="type in meetingTypes"
                            :key="type.value"
                            :value="type.value"
                        >
                            {{ type.label }}
                        </option>
                    </select></label
                ><label class="field"
                    ><span>会议范围</span
                    ><select
                        v-model="filters.meeting_scope_id"
                        :disabled="!filters.meeting_type"
                    >
                        <option value="">
                            {{
                                filters.meeting_type
                                    ? '全部范围'
                                    : '请先选择会议类型'
                            }}
                        </option>
                        <option
                            v-for="scope in filteredScopes"
                            :key="scope.id"
                            :value="String(scope.id)"
                        >
                            {{ scope.name }}
                        </option>
                    </select></label
                ><button class="btn-secondary" @click="applyFilters">
                    <Search :size="16" />筛选
                </button>
            </div>
            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th>授权人员</th>
                            <th>所属单位</th>
                            <th>会议类型</th>
                            <th>权限角色</th>
                            <th>授权范围</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="assignment in assignments.data"
                            :key="assignment.id"
                        >
                            <td>
                                <b>{{ assignment.user?.name }}</b
                                ><small class="ml-2 text-[#7c8580]">{{
                                    assignment.user?.person?.employee_no
                                }}</small>
                            </td>
                            <td>
                                {{
                                    assignment.user?.person?.organization
                                        ?.name || '—'
                                }}
                            </td>
                            <td>
                                {{
                                    meetingTypes.find(
                                        (type) =>
                                            type.value ===
                                            assignment.meeting_type,
                                    )?.label || '全局'
                                }}
                            </td>
                            <td>
                                <span
                                    class="inline-flex items-center gap-1 text-[#245446]"
                                    ><ShieldCheck :size="14" />{{
                                        roleLabels[assignment.role]
                                    }}</span
                                >
                            </td>
                            <td>
                                {{
                                    assignment.meeting_scope?.name ||
                                    (['system_admin', 'global_admin'].includes(
                                        assignment.role,
                                    )
                                        ? '全局'
                                        : '未映射，需重新授权')
                                }}
                            </td>
                            <td>
                                <button
                                    class="inline-flex items-center gap-1 font-medium text-[#2f6a59] hover:text-[#173b32]"
                                    @click="openAdjustment(assignment)"
                                >
                                    <Pencil :size="14" />调整
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!assignments.data.length">
                            <td
                                colspan="6"
                                class="py-14 text-center text-[#7d8782]"
                            >
                                暂无符合条件的授权
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <Link
                    v-for="link in assignments.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    class="border px-3 py-1.5 text-sm"
                    :class="
                        link.active
                            ? 'border-[#2f6a59] bg-[#2f6a59] text-white'
                            : 'border-[#d8d2c5] bg-white'
                    "
                    v-html="link.label"
                />
            </div>
        </section>
        <Dialog v-model:open="adjustmentOpen">
            <DialogContent class="border-[#d9d2c4] bg-[#faf8f3] sm:max-w-xl">
                <DialogTitle class="font-serif text-xl text-[#173b32]"
                    >调整人员授权</DialogTitle
                >
                <DialogDescription class="text-[#68736e]"
                    >仅处理当前这一条授权，该人员的其他授权不受影响。</DialogDescription
                >
                <div v-if="adjustment" class="space-y-5 text-sm">
                    <div
                        class="grid gap-3 border border-[#ded7c9] bg-white p-4 sm:grid-cols-2"
                    >
                        <div>
                            <p class="text-xs text-[#7c8580]">授权人员</p>
                            <p class="mt-1 font-medium">
                                {{ adjustment.user?.name }} ·
                                {{ adjustment.user?.person?.employee_no }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-[#7c8580]">当前授权</p>
                            <p class="mt-1 font-medium">
                                {{ roleLabels[adjustment.role] }} ·
                                {{
                                    meetingTypes.find(
                                        (type) =>
                                            type.value ===
                                            adjustment.meeting_type,
                                    )?.label || '全局'
                                }}
                            </p>
                        </div>
                    </div>
                    <div
                        class="grid grid-cols-2 gap-2"
                        role="group"
                        aria-label="调整方式"
                    >
                        <button
                            type="button"
                            class="border px-4 py-3 text-left font-medium transition"
                            :class="
                                adjustmentMode === 'role'
                                    ? 'border-[#2f6a59] bg-[#e9f1ed] text-[#205342]'
                                    : 'border-[#ded7c9] bg-white text-[#68736e]'
                            "
                            :aria-pressed="adjustmentMode === 'role'"
                            @click="
                                adjustmentMode = 'role';
                                adjustmentErrors = {};
                            "
                        >
                            调整角色
                        </button>
                        <button
                            type="button"
                            class="border px-4 py-3 text-left font-medium transition"
                            :class="
                                adjustmentMode === 'revoke'
                                    ? 'border-[#a6473d] bg-[#fbefed] text-[#8a2f27]'
                                    : 'border-[#ded7c9] bg-white text-[#68736e]'
                            "
                            :aria-pressed="adjustmentMode === 'revoke'"
                            @click="
                                adjustmentMode = 'revoke';
                                adjustmentErrors = {};
                            "
                        >
                            撤销授权
                        </button>
                    </div>
                    <div v-if="adjustmentMode === 'role'" class="space-y-4">
                        <label class="field"
                            ><span>调整为</span
                            ><select v-model="targetRole">
                                <option value="" disabled>
                                    请选择其他角色
                                </option>
                                <option
                                    v-for="[key, label] in availableTargetRoles"
                                    :key="key"
                                    :value="key"
                                >
                                    {{ label }}
                                </option>
                            </select></label
                        >
                        <label v-if="targetNeedsMeetingType" class="field"
                            ><span>会议类型</span
                            ><select v-model="targetMeetingType">
                                <option value="" disabled>
                                    请选择会议类型
                                </option>
                                <option
                                    v-for="type in meetingTypes"
                                    :key="type.value"
                                    :value="type.value"
                                >
                                    {{ type.label }}
                                </option>
                            </select></label
                        >
                        <p
                            v-if="targetNeedsMeetingType && targetMeetingType"
                            class="text-xs text-[#68736e]"
                        >
                            授权范围：{{ targetScope }}
                        </p>
                    </div>
                    <p
                        v-else
                        class="border-l-4 border-[#a6473d] bg-[#fbefed] px-4 py-3 text-[#8a2f27]"
                    >
                        确认后将撤销当前这条授权。
                    </p>
                    <div
                        v-if="Object.keys(adjustmentErrors).length"
                        role="alert"
                        class="border-l-4 border-[#a6473d] bg-white px-4 py-3 text-[#8a2f27]"
                    >
                        <p v-for="(error, key) in adjustmentErrors" :key="key">
                            {{ error }}
                        </p>
                    </div>
                    <div
                        class="flex justify-end gap-3 border-t border-[#ded7c9] pt-4"
                    >
                        <button
                            type="button"
                            class="btn-secondary"
                            :disabled="adjustmentProcessing"
                            @click="adjustmentOpen = false"
                        >
                            取消
                        </button>
                        <button
                            type="button"
                            :class="
                                adjustmentMode === 'revoke'
                                    ? 'inline-flex items-center gap-2 bg-[#a6473d] px-4 py-2 text-sm font-medium text-white disabled:opacity-50'
                                    : 'btn-primary'
                            "
                            :disabled="
                                adjustmentProcessing ||
                                (adjustmentMode === 'role' &&
                                    (!targetRole ||
                                        (targetNeedsMeetingType &&
                                            !targetMeetingType)))
                            "
                            @click="submitAdjustment"
                        >
                            <Trash2
                                v-if="adjustmentMode === 'revoke'"
                                :size="15"
                            />{{
                                adjustmentProcessing
                                    ? '处理中…'
                                    : adjustmentMode === 'revoke'
                                      ? '确认撤销'
                                      : '保存调整'
                            }}
                        </button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </BusinessLayout>
</template>
