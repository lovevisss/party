<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Archive,
    BookmarkPlus,
    Download,
    FileCheck2,
    FileUp,
    ListPlus,
    Save,
    Search,
    Trash2,
    X,
} from 'lucide-vue-next';
import BusinessLayout from '@/layouts/BusinessLayout.vue';
import { minuteDateTime, minuteDateTimeInput } from '@/lib/minuteDateTime';

const props = defineProps<{
    minute: any | null;
    organizations: any[];
    participantPresets: any[];
    meetingType: {
        value: string;
        slug: string;
        label: string;
        scope_label: string;
    };
}>();
const form = useForm({
    meeting_scope_id:
        props.minute?.meeting_scope_id ?? props.organizations[0]?.id,
    meeting_year: props.minute?.meeting_year ?? new Date().getFullYear(),
    sequence_no: props.minute?.sequence_no ?? null,
    title: props.minute?.title ?? '',
    meeting_start_at: minuteDateTimeInput(props.minute?.meeting_start_at),
    meeting_end_at: minuteDateTimeInput(props.minute?.meeting_end_at),
    first_topic_content: props.minute?.first_topic_content ?? '',
    lock_version: props.minute?.lock_version ?? 0,
    participants: props.minute?.participants ?? [],
});
const attachmentForm = useForm<{ attachment: File | null }>({
    attachment: null,
});
const page = usePage<{ errors: Record<string, string> }>();
const archiving = ref(false);
const uploading = ref(false);
const declaredArchivedAt = ref('');
const archiveError = ref('');
const participantError = ref('');
const deadline = ref<string | null>(null);
const deadlineLoading = ref(false);
const deadlineError = ref('');
watch(
    () => form.meeting_end_at,
    async (meetingEnd, _previous, onCleanup) => {
        deadline.value = null;
        deadlineError.value = '';
        deadlineLoading.value = false;
        if (!meetingEnd) return;
        const controller = new AbortController();
        onCleanup(() => controller.abort());
        deadlineLoading.value = true;
        try {
            const response = await fetch(
                `/minutes/deadline?meeting_end_at=${encodeURIComponent(meetingEnd)}`,
                { signal: controller.signal },
            );
            if (response.status === 404) {
                throw new Error(
                    '截止时间接口不存在，请联系管理员检查后端部署与路由缓存。',
                );
            }
            if (!response.ok) throw new Error('截止时间计算失败，请稍后重试。');
            const payload: { due_at: string } = await response.json();
            deadline.value = payload.due_at;
        } catch (error) {
            if (!controller.signal.aborted)
                deadlineError.value =
                    error instanceof Error
                        ? error.message
                        : '截止时间计算失败，请稍后重试。';
        } finally {
            if (!controller.signal.aborted) deadlineLoading.value = false;
        }
    },
    { immediate: true },
);
const query = ref('');
const results = ref<any[]>([]);
const selectedPresetId = ref<number | ''>('');
const presetName = ref('');
const presetMessage = ref('');
const roles: Record<string, string> = {
    chair: '主持人',
    recorder: '记录人',
    attendee: '参会人员',
    absent: '缺席人员',
    observer: '列席人员',
};
const requiredRoles = ['chair', 'recorder', 'attendee'];
const missingRequiredRole = computed(() =>
    requiredRoles.find(
        (role) =>
            !form.participants.some((person: any) => person.role_type === role),
    ),
);
const pendingFiles = computed(
    () => props.minute?.files?.filter((file: any) => !file.version_no) ?? [],
);
const hasPendingPdf = computed(() =>
    pendingFiles.value.some((file: any) =>
        file.object_key?.toLowerCase().endsWith('.pdf'),
    ),
);
const searchMode = ref<'scope' | 'all'>('scope');
const availablePresets = computed(() =>
    props.participantPresets.filter(
        (preset) =>
            Number(preset.meeting_scope_id) === Number(form.meeting_scope_id),
    ),
);

const save = () =>
    props.minute
        ? form.put(`/minutes/${props.minute.id}`, { preserveScroll: true })
        : form.post(`/minutes/${props.meetingType.slug}`);
const archive = () => {
    archiveError.value = '';
    participantError.value = '';
    if (missingRequiredRole.value) {
        participantError.value = `请至少选择一名${roles[missingRequiredRole.value]}。`;
        return;
    }
    if (!declaredArchivedAt.value) {
        archiveError.value = '请填写实际归档时间。';
        return;
    }
    if (
        !props.minute ||
        archiving.value ||
        !confirm('归档后内容将锁定，确认正式归档吗？')
    )
        return;
    archiving.value = true;
    const submitArchive = () =>
        router.post(
            `/minutes/${props.minute.id}/archive`,
            { archived_at: declaredArchivedAt.value },
            {
                preserveScroll: true,
                onFinish: () => {
                    archiving.value = false;
                },
                onError: (errors) => {
                    archiveError.value = errors.archived_at || '';
                    participantError.value = errors.participants || '';
                },
            },
        );
    if (form.isDirty) {
        form.put(`/minutes/${props.minute.id}`, {
            preserveScroll: true,
            onSuccess: submitArchive,
            onError: () => {
                archiving.value = false;
            },
        });
        return;
    }
    submitArchive();
};
const search = async () => {
    results.value = await fetch(
        `/people/search?q=${encodeURIComponent(query.value)}&meeting_type=${props.meetingType.value}&meeting_scope_id=${form.meeting_scope_id}&mode=${searchMode.value}`,
    ).then((response) => response.json());
};
const add = (person: any, role: string | number) => {
    const key = String(role);
    if (
        !form.participants.some(
            (item: any) =>
                item.person_id === person.id && item.role_type === key,
        )
    ) {
        form.participants.push({
            person_id: person.id,
            display_name: person.name,
            role_type: key,
            is_external: false,
        });
        participantError.value = '';
    }
};
const applyPreset = () => {
    const preset = availablePresets.value.find(
        (item) => item.id === Number(selectedPresetId.value),
    );
    if (!preset) return;
    if (
        form.participants.length &&
        !confirm('套用常用名单将覆盖当前已选择的人员，是否继续？')
    )
        return;
    const availableItems = preset.items.filter(
        (item: any) => item.is_external || item.person?.status === 'active',
    );
    form.participants = availableItems.map((item: any) => ({
        person_id: item.is_external ? null : item.person_id,
        role_type: item.role_type,
        display_name: item.display_name,
        is_external: item.is_external,
    }));
    participantError.value = '';
    const skipped = preset.items.length - availableItems.length;
    presetMessage.value = skipped
        ? `已套用“${preset.name}”，其中 ${skipped} 名停用人员被跳过。`
        : `已套用“${preset.name}”，共 ${availableItems.length} 人。`;
};
const savePreset = () => {
    const name = presetName.value.trim();
    if (name.length < 2) {
        presetMessage.value = '请输入至少 2 个字的清单名称。';
        return;
    }
    if (!form.participants.length) {
        presetMessage.value = '当前人员清单为空，无法保存。';
        return;
    }
    router.post(
        '/participant-presets',
        {
            meeting_type: props.meetingType.value,
            meeting_scope_id: form.meeting_scope_id,
            name,
            participants: form.participants,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                presetMessage.value = `常用名单“${name}”已保存；同名清单会自动覆盖更新。`;
                presetName.value = '';
            },
        },
    );
};
const deletePreset = () => {
    const preset = availablePresets.value.find(
        (item) => item.id === Number(selectedPresetId.value),
    );
    if (!preset || !confirm(`确认删除常用名单“${preset.name}”吗？`)) return;
    router.delete(`/participant-presets/${preset.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            selectedPresetId.value = '';
            presetMessage.value = '常用名单已删除。';
        },
    });
};
const chooseAttachment = (event: Event) => {
    attachmentForm.attachment =
        (event.target as HTMLInputElement).files?.[0] ?? null;
};
const upload = () => {
    if (
        !attachmentForm.attachment ||
        attachmentForm.processing ||
        uploading.value
    )
        return;
    if (!props.minute) {
        uploading.value = true;
        form.clearErrors();
        attachmentForm.clearErrors();
        router.post(
            `/minutes/${props.meetingType.slug}`,
            { ...form.data(), attachment: attachmentForm.attachment },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (errors) => {
                    Object.entries(errors).forEach(([field, message]) => {
                        if (field === 'attachment')
                            attachmentForm.setError('attachment', message);
                        else
                            form.setError(
                                field as keyof ReturnType<typeof form.data>,
                                message,
                            );
                    });
                },
                onFinish: () => {
                    uploading.value = false;
                },
            },
        );
        return;
    }
    attachmentForm.post(`/minutes/${props.minute.id}/attachment`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => attachmentForm.reset(),
    });
};
</script>

<template>
    <BusinessLayout
        :title="`${minute ? '编辑' : '新建'}${meetingType.label}`"
        :eyebrow="`${meetingType.label} · 结构化归档`"
    >
        <form class="space-y-5" @submit.prevent="save">
            <section class="form-card">
                <div class="section-head">
                    <span>01</span>
                    <div>
                        <h2>基本信息</h2>
                        <p>会议编号与召开时间</p>
                    </div>
                </div>
                <div class="form-grid">
                    <label class="field"
                        ><span>{{ meetingType.scope_label }}</span
                        ><select v-model="form.meeting_scope_id" required>
                            <option
                                v-for="organization in organizations"
                                :key="organization.id"
                                :value="organization.id"
                            >
                                {{ organization.name }}
                            </option>
                        </select></label
                    ><label class="field"
                        ><span>会议年度</span
                        ><input
                            v-model="form.meeting_year"
                            type="number" /></label
                    ><label class="field"
                        ><span>会议序号</span
                        ><input
                            v-model="form.sequence_no"
                            type="number"
                            min="1"
                            max="999" /></label
                    ><label class="field md:col-span-3"
                        ><span>会议名称</span
                        ><input
                            v-model="form.title"
                            maxlength="200"
                            :placeholder="`例如：2026年第1次${meetingType.label.replace('纪要', '')}`" /></label
                    ><label class="field"
                        ><span>开始时间</span
                        ><input
                            v-model="form.meeting_start_at"
                            type="datetime-local" /></label
                    ><label class="field"
                        ><span>结束时间</span
                        ><input
                            v-model="form.meeting_end_at"
                            type="datetime-local"
                    /></label>
                    <div class="field md:col-span-3">
                        <span>自动生成的截止时间</span>
                        <div
                            class="control flex items-center bg-[#f6f8f5] text-[#52625a]"
                            aria-live="polite"
                        >
                            {{
                                deadlineLoading
                                    ? '正在计算…'
                                    : deadlineError ||
                                      (deadline
                                          ? minuteDateTime(deadline, true)
                                          : '填写会议结束时间后自动生成')
                            }}
                        </div>
                        <span
                            >自会议结束次日起算，第三个工作日 23:59:59
                            截止；节假日及调休按工作日历计算。</span
                        >
                    </div>
                </div>
            </section>
            <section class="form-card">
                <div class="section-head">
                    <span>02</span>
                    <div>
                        <h2>人员情况</h2>
                        <p>
                            从在职人员库精确选择，或套用当前会议类型的常用名单
                        </p>
                    </div>
                </div>
                <div
                    class="mb-5 grid gap-3 border border-[#d8e2dd] bg-[#f6faf8] p-4 lg:grid-cols-2"
                >
                    <div>
                        <p
                            class="mb-2 flex items-center gap-2 text-sm font-medium text-[#245446]"
                        >
                            <ListPlus :size="17" />快速套用常用名单
                        </p>
                        <div class="flex gap-2">
                            <select
                                v-model="selectedPresetId"
                                class="control flex-1"
                            >
                                <option value="">选择已保存的名单</option>
                                <option
                                    v-for="preset in availablePresets"
                                    :key="preset.id"
                                    :value="preset.id"
                                >
                                    {{ preset.name }}（{{ preset.items.length }}
                                    人）
                                </option></select
                            ><button
                                type="button"
                                class="btn-secondary"
                                :disabled="!selectedPresetId"
                                @click="applyPreset"
                            >
                                套用</button
                            ><button
                                type="button"
                                class="border border-[#d6c7c2] px-3 text-[#9f3f36] disabled:opacity-40"
                                :disabled="!selectedPresetId"
                                title="删除名单"
                                @click="deletePreset"
                            >
                                <Trash2 :size="16" />
                            </button>
                        </div>
                    </div>
                    <div>
                        <p
                            class="mb-2 flex items-center gap-2 text-sm font-medium text-[#245446]"
                        >
                            <BookmarkPlus :size="17" />保存当前人员配置
                        </p>
                        <div class="flex gap-2">
                            <input
                                v-model="presetName"
                                class="control flex-1"
                                maxlength="50"
                                placeholder="例如：固定参会人员"
                                @keyup.enter.prevent="savePreset"
                            /><button
                                type="button"
                                class="btn-secondary"
                                @click="savePreset"
                            >
                                保存名单
                            </button>
                        </div>
                    </div>
                    <p
                        v-if="presetMessage"
                        class="text-xs text-[#65736e] lg:col-span-2"
                    >
                        {{ presetMessage }}
                    </p>
                </div>
                <div class="mb-2 flex gap-4 text-sm">
                    <label
                        ><input
                            v-model="searchMode"
                            type="radio"
                            value="scope"
                        />
                        当前{{ meetingType.scope_label }}人员优先</label
                    ><label
                        ><input v-model="searchMode" type="radio" value="all" />
                        全校人员</label
                    >
                </div>
                <div class="flex gap-2">
                    <input
                        v-model="query"
                        class="control flex-1"
                        placeholder="输入姓名或工号"
                        @keyup.enter.prevent="search"
                    /><button
                        type="button"
                        class="btn-secondary"
                        @click="search"
                    >
                        <Search :size="16" />搜索
                    </button>
                </div>
                <div
                    v-if="results.length"
                    class="mt-3 max-h-56 overflow-y-auto border border-[#ded7c9]"
                >
                    <div
                        v-for="person in results"
                        :key="person.id"
                        class="flex flex-wrap items-center gap-2 border-b px-3 py-2 text-sm"
                    >
                        <span class="mr-auto font-medium"
                            >{{ person.name }}
                            <small class="font-normal text-slate-500"
                                >{{ person.employee_no }} ·
                                {{ person.organization?.name }}</small
                            ></span
                        ><button
                            v-for="(label, key) in roles"
                            :key="key"
                            type="button"
                            class="rounded border px-2 py-1 text-xs"
                            @click="add(person, key)"
                        >
                            + {{ label
                            }}<span
                                v-if="requiredRoles.includes(String(key))"
                                class="ml-0.5 text-red-700"
                                aria-label="必选角色"
                                >*</span
                            >
                        </button>
                    </div>
                </div>
                <p class="mt-3 text-xs text-[#66716c]">
                    主持人、记录人、参会人员各至少选择一名；缺席人员、列席人员可选。
                </p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <span
                        v-for="(person, index) in form.participants"
                        :key="index"
                        class="inline-flex items-center gap-2 rounded-full bg-[#edf3f0] px-3 py-1.5 text-xs text-[#245446]"
                        ><b>{{ roles[person.role_type] }}</b
                        >{{ person.display_name
                        }}<button
                            type="button"
                            @click="form.participants.splice(index, 1)"
                        >
                            <X :size="13" /></button
                    ></span>
                </div>
                <p
                    v-if="
                        participantError ||
                        (page.props.errors?.participants && missingRequiredRole)
                    "
                    class="mt-3 text-sm text-red-700"
                    role="alert"
                >
                    {{ participantError || page.props.errors?.participants }}
                </p>
            </section>
            <section class="form-card">
                <div class="section-head">
                    <span>03</span>
                    <div>
                        <h2>第一议题学习内容</h2>
                    </div>
                </div>
                <textarea
                    v-model="form.first_topic_content"
                    class="control min-h-56 resize-y leading-7"
                    maxlength="20000"
                    placeholder="请输入第一议题学习内容……"
                />
                <div class="mt-2 text-right text-xs text-[#8a918d]">
                    {{ form.first_topic_content.length }} / 20,000
                </div>
            </section>
            <section class="form-card">
                <div class="section-head">
                    <span>04</span>
                    <div>
                        <h2>
                            会议纪要
                            <span class="text-red-700" aria-label="必填"
                                >*</span
                            >
                        </h2>
                        <p>请上传主要领导签字的PDF扫描件，最大 20 MB</p>
                    </div>
                </div>
                <div class="space-y-4">
                    <div
                        class="flex flex-col gap-3 border border-dashed border-[#baa874] bg-[#faf8f1] p-5 sm:flex-row sm:items-center"
                    >
                        <FileUp class="text-[#8b6f35]" /><input
                            type="file"
                            accept=".pdf,application/pdf"
                            @change="chooseAttachment"
                        /><button
                            type="button"
                            class="btn-secondary sm:ml-auto"
                            :disabled="
                                !attachmentForm.attachment ||
                                attachmentForm.processing ||
                                uploading
                            "
                            @click="upload"
                        >
                            {{
                                attachmentForm.processing || uploading
                                    ? '正在上传…'
                                    : '上传会议纪要'
                            }}
                        </button>
                    </div>
                    <p
                        v-if="
                            attachmentForm.errors.attachment ||
                            page.props.errors?.attachment
                        "
                        class="text-sm text-red-700"
                        role="alert"
                    >
                        {{
                            attachmentForm.errors.attachment ||
                            page.props.errors?.attachment
                        }}
                    </p>
                    <div
                        v-if="minute && pendingFiles.length"
                        class="border border-[#d8e2dd] bg-[#f5faf7]"
                    >
                        <div
                            class="flex items-center gap-2 border-b border-[#d8e2dd] px-4 py-3 text-sm font-medium text-[#245446]"
                        >
                            <FileCheck2 :size="17" />已上传、待归档的会议纪要
                        </div>
                        <Link
                            v-for="file in pendingFiles"
                            :key="file.id"
                            :href="`/minutes/${minute.id}/files/${file.id}`"
                            class="flex items-center gap-2 px-4 py-3 text-sm text-[#2f6a59] hover:bg-white"
                            ><Download :size="15" />{{ file.original_name
                            }}<span class="ml-auto text-xs text-[#77827c]"
                                >{{
                                    (file.size_bytes / 1024).toFixed(1)
                                }}
                                KB</span
                            ></Link
                        >
                    </div>
                    <p
                        v-if="minute && !hasPendingPdf"
                        class="text-sm text-amber-700"
                    >
                        尚未上传主要领导签字的PDF会议纪要，归档前必须上传。
                    </p>
                </div>
            </section>
            <section v-if="minute" class="form-card">
                <div class="section-head">
                    <span>05</span>
                    <div>
                        <h2>归档时间</h2>
                        <p>
                            由会议提交人填写实际归档时间，系统据此判断是否超时
                        </p>
                    </div>
                </div>
                <label class="field max-w-sm">
                    <span
                        >实际归档时间 <span class="text-red-700">*</span></span
                    >
                    <input
                        v-model="declaredArchivedAt"
                        type="datetime-local"
                        @input="archiveError = ''"
                    />
                </label>
                <p
                    v-if="archiveError || page.props.errors?.archived_at"
                    class="mt-2 text-sm text-red-700"
                    role="alert"
                >
                    {{ archiveError || page.props.errors?.archived_at }}
                </p>
            </section>
            <div
                v-if="Object.keys(form.errors).length"
                class="border-l-4 border-red-700 bg-red-50 p-4 text-sm text-red-800"
            >
                <p v-for="error in form.errors" :key="error">{{ error }}</p>
            </div>
            <div
                class="sticky bottom-4 flex justify-end gap-3 border border-[#d8d1c3] bg-white/95 p-3 shadow-xl print:hidden"
            >
                <button
                    type="submit"
                    class="btn-secondary"
                    :disabled="form.processing || archiving || uploading"
                >
                    <Save :size="16" />{{
                        form.processing ? '正在保存…' : '保存草稿'
                    }}</button
                ><button
                    v-if="minute"
                    type="button"
                    class="btn-primary"
                    :disabled="
                        archiving ||
                        form.processing ||
                        attachmentForm.processing ||
                        uploading
                    "
                    @click="archive"
                >
                    <Archive :size="16" />{{
                        archiving ? '正在保存并归档…' : '保存并提交归档'
                    }}
                </button>
            </div>
        </form>
    </BusinessLayout>
</template>
