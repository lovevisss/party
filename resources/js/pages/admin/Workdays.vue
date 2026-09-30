<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    CalendarDays,
    CalendarPlus,
    Pencil,
    RotateCcw,
    Trash2,
    Upload,
} from 'lucide-vue-next';
import BusinessLayout from '@/layouts/BusinessLayout.vue';

type CalendarRule = {
    id: number;
    kind: 'once' | 'annual';
    start_date: string | null;
    end_date: string | null;
    start_month_day: string | null;
    end_month_day: string | null;
    is_workday: boolean;
    name: string;
};

defineProps<{ workdays: any; rules: CalendarRule[]; year: number }>();

const singleForm = useForm({ date: '', is_workday: true, name: '' });
const saveSingle = () =>
    singleForm.post('/admin/workdays', { onSuccess: () => singleForm.reset() });
const file = ref<File | null>(null);
const upload = () =>
    file.value &&
    router.post(
        '/admin/workdays/import',
        { file: file.value },
        { forceFormData: true },
    );
const editingRuleId = ref<number | null>(null);
const ruleForm = useForm({
    kind: 'once' as 'once' | 'annual',
    start_date: '',
    end_date: '',
    start_month_day: '',
    end_month_day: '',
    is_workday: false,
    name: '',
});
const resetRule = () => {
    editingRuleId.value = null;
    ruleForm.reset();
    ruleForm.clearErrors();
};
const editRule = (rule: CalendarRule) => {
    editingRuleId.value = rule.id;
    ruleForm.kind = rule.kind;
    ruleForm.start_date = rule.start_date ?? '';
    ruleForm.end_date = rule.end_date ?? '';
    ruleForm.start_month_day = rule.start_month_day ?? '';
    ruleForm.end_month_day = rule.end_month_day ?? '';
    ruleForm.is_workday = rule.is_workday;
    ruleForm.name = rule.name;
    ruleForm.clearErrors();
    document
        .getElementById('calendar-rule-form')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};
const saveRule = () => {
    const options = { preserveScroll: true, onSuccess: resetRule };
    if (editingRuleId.value)
        ruleForm.put(`/admin/workday-rules/${editingRuleId.value}`, options);
    else ruleForm.post('/admin/workday-rules', options);
};
const deleteRule = (rule: CalendarRule) => {
    if (confirm(`确认删除“${rule.name}”这条日历规则吗？`))
        router.delete(`/admin/workday-rules/${rule.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                if (editingRuleId.value === rule.id) resetRule();
            },
        });
};
const deleteSingle = (date: string) => {
    if (confirm(`确认删除 ${date} 的单日配置吗？`))
        router.delete(`/admin/workdays/${date}`, { preserveScroll: true });
};
</script>

<template>
    <BusinessLayout title="工作日历" eyebrow="法定时限依据">
        <div
            class="mb-6 border-l-4 border-[#2f6a59] bg-white px-5 py-4 text-sm leading-6 text-[#42514b]"
        >
            未配置的日期默认周一至周五为工作日。优先顺序：单日配置与导入记录 →
            一次性范围 → 每年常规假期 →
            默认规则。日历变更影响之后的计算，已归档记录不追溯修改。
        </div>
        <div class="mb-6 grid gap-5 lg:grid-cols-2">
            <form class="form-card" @submit.prevent="saveSingle">
                <div class="mb-4 flex items-start gap-3">
                    <CalendarPlus :size="20" class="mt-1 text-[#2f6a59]" />
                    <div>
                        <h2 class="font-serif text-lg font-semibold">
                            单日例外
                        </h2>
                        <p class="mt-1 text-xs text-[#75807b]">
                            可覆盖范围规则和每年假期
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <label class="field"
                        ><span>日期</span
                        ><input v-model="singleForm.date" type="date" required
                    /></label>
                    <label class="field"
                        ><span>日期性质</span
                        ><select v-model="singleForm.is_workday">
                            <option :value="true">工作日</option>
                            <option :value="false">休息日</option>
                        </select></label
                    >
                    <label class="field flex-1"
                        ><span>名称</span
                        ><input
                            v-model="singleForm.name"
                            placeholder="节假日或调休说明"
                    /></label>
                    <button
                        class="btn-primary"
                        :disabled="singleForm.processing"
                    >
                        保存
                    </button>
                </div>
            </form>
            <div class="form-card">
                <div class="mb-4 flex items-start gap-3">
                    <Upload :size="20" class="mt-1 text-[#2f6a59]" />
                    <div>
                        <h2 class="font-serif text-lg font-semibold">
                            批量导入单日例外
                        </h2>
                        <p class="mt-1 text-xs text-[#75807b]">
                            CSV/XLSX 列：日期、是否工作日、名称
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <input
                        type="file"
                        accept=".csv,.xlsx"
                        @change="
                            file =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] || null
                        "
                    /><button
                        class="btn-secondary"
                        :disabled="!file"
                        @click="upload"
                    >
                        导入
                    </button>
                </div>
            </div>
        </div>

        <form
            id="calendar-rule-form"
            class="mb-6 border border-[#ded7c9] bg-white p-5 shadow-[0_8px_24px_rgba(48,55,45,.05)] md:p-7"
            @submit.prevent="saveRule"
        >
            <div
                class="mb-5 flex items-start justify-between gap-4 border-b border-[#e8e2d7] pb-5"
            >
                <div class="flex items-start gap-3">
                    <CalendarDays :size="21" class="mt-1 text-[#2f6a59]" />
                    <div>
                        <h2 class="font-serif text-xl font-semibold">
                            {{
                                editingRuleId ? '修改日历规则' : '配置日期范围'
                            }}
                        </h2>
                        <p class="mt-1 text-sm text-[#75807b]">
                            一次性休假或调休区间，也可设置每年固定公历假期
                        </p>
                    </div>
                </div>
                <button
                    v-if="editingRuleId"
                    type="button"
                    class="inline-flex items-center gap-1 text-sm text-[#2f6a59]"
                    @click="resetRule"
                >
                    <RotateCcw :size="15" />取消修改
                </button>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <label class="field"
                    ><span>规则类型</span
                    ><select v-model="ruleForm.kind">
                        <option value="once">一次性日期范围</option>
                        <option value="annual">每年常规假期</option>
                    </select></label
                >
                <label class="field"
                    ><span>名称</span
                    ><input
                        v-model="ruleForm.name"
                        placeholder="例如：元旦假期"
                        required
                        maxlength="100"
                /></label>
                <label v-if="ruleForm.kind === 'once'" class="field"
                    ><span>开始日期</span
                    ><input v-model="ruleForm.start_date" type="date" required
                /></label>
                <label v-if="ruleForm.kind === 'once'" class="field"
                    ><span>结束日期（含当天）</span
                    ><input
                        v-model="ruleForm.end_date"
                        type="date"
                        :min="ruleForm.start_date"
                        required
                /></label>
                <label v-if="ruleForm.kind === 'annual'" class="field"
                    ><span>每年开始（月-日）</span
                    ><input
                        v-model="ruleForm.start_month_day"
                        type="text"
                        inputmode="numeric"
                        placeholder="01-01"
                        pattern="[0-9]{2}-[0-9]{2}"
                        required
                /></label>
                <label v-if="ruleForm.kind === 'annual'" class="field"
                    ><span>每年结束（月-日，含当天）</span
                    ><input
                        v-model="ruleForm.end_month_day"
                        type="text"
                        inputmode="numeric"
                        placeholder="01-03"
                        pattern="[0-9]{2}-[0-9]{2}"
                        required
                /></label>
                <label v-if="ruleForm.kind === 'once'" class="field"
                    ><span>日期性质</span
                    ><select v-model="ruleForm.is_workday">
                        <option :value="false">休息日</option>
                        <option :value="true">工作日</option>
                    </select></label
                >
            </div>
            <p
                v-if="ruleForm.kind === 'annual'"
                class="mt-3 text-xs text-[#75807b]"
            >
                按固定公历月日每年重复，可跨年；2 月 29 日只在闰年生效。
            </p>
            <div
                v-if="Object.keys(ruleForm.errors).length"
                role="alert"
                class="mt-4 border-l-4 border-[#a6473d] bg-red-50 px-4 py-3 text-sm text-[#8a2f27]"
            >
                <p v-for="(error, key) in ruleForm.errors" :key="key">
                    {{ error }}
                </p>
            </div>
            <div class="mt-6 flex justify-end">
                <button class="btn-primary" :disabled="ruleForm.processing">
                    <CalendarPlus :size="16" />{{
                        editingRuleId ? '保存修改' : '新增规则'
                    }}
                </button>
            </div>
        </form>

        <section class="mb-6 border border-[#ded7c9] bg-white">
            <div class="border-b border-[#e8e2d7] px-5 py-4">
                <h2 class="font-serif text-lg font-semibold">
                    日期范围与年度规则
                </h2>
                <p class="mt-1 text-xs text-[#75807b]">
                    同类型规则不能重叠；单日配置始终优先
                </p>
            </div>
            <div v-if="rules.length" class="divide-y divide-[#eee9df]">
                <div
                    v-for="rule in rules"
                    :key="rule.id"
                    class="flex flex-wrap items-center gap-4 px-5 py-4 text-sm"
                >
                    <span
                        class="min-w-28 text-xs font-semibold tracking-wide text-[#8b6f35]"
                        >{{
                            rule.kind === 'annual'
                                ? '每年常规假期'
                                : '一次性范围'
                        }}</span
                    >
                    <div class="min-w-48 flex-1">
                        <p class="font-medium text-[#173b32]">
                            {{ rule.name }}
                        </p>
                        <p class="mt-1 text-xs text-[#75807b]">
                            {{
                                rule.kind === 'annual'
                                    ? `每年 ${rule.start_month_day} 至 ${rule.end_month_day}`
                                    : `${rule.start_date} 至 ${rule.end_date}`
                            }}
                            · {{ rule.is_workday ? '工作日' : '休息日' }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-[#2f6a59]"
                        @click="editRule(rule)"
                    >
                        <Pencil :size="15" />修改
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-[#9f3f36]"
                        @click="deleteRule(rule)"
                    >
                        <Trash2 :size="15" />删除
                    </button>
                </div>
            </div>
            <p v-else class="px-5 py-10 text-center text-sm text-[#87908b]">
                暂无日期范围规则
            </p>
        </section>

        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>单日配置</th>
                        <th>性质</th>
                        <th>名称</th>
                        <th>更新时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in workdays.data" :key="d.date">
                        <td>{{ d.date }}</td>
                        <td>
                            <span
                                :class="
                                    d.is_workday
                                        ? 'text-emerald-700'
                                        : 'text-amber-700'
                                "
                                >{{ d.is_workday ? '工作日' : '休息日' }}</span
                            >
                        </td>
                        <td>{{ d.name || '—' }}</td>
                        <td>{{ d.updated_at?.slice(0, 16) }}</td>
                        <td>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 text-[#9f3f36]"
                                @click="deleteSingle(d.date)"
                            >
                                <Trash2 :size="14" />删除
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!workdays.data.length">
                        <td
                            colspan="5"
                            class="py-10 text-center text-[#87908b]"
                        >
                            暂无单日配置
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div
            v-if="workdays.last_page > 1"
            class="mt-4 flex flex-wrap justify-end gap-2"
        >
            <Link
                v-for="link in workdays.links"
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
    </BusinessLayout>
</template>
