<script setup lang="ts">
import BusinessLayout from '@/layouts/BusinessLayout.vue';
import { Check, LockKeyhole, Minus, ShieldCheck } from 'lucide-vue-next';

type Role = {
    name: string;
    scope: string;
    draft: boolean;
    return: boolean;
    delete: boolean;
    admin: boolean;
};

defineProps<{ roles: Role[] }>();

const permissions = [
    { label: '工作台', key: 'dashboard' },
    { label: '查看会议纪要', key: 'scope' },
    { label: '新建、修改与归档', key: 'draft' },
    { label: '退回修改', key: 'return' },
    { label: '删除终稿', key: 'delete' },
    { label: '人员同步', key: 'admin' },
    { label: '授权名单', key: 'admin' },
    { label: '工作日历', key: 'admin' },
    { label: '审计日志', key: 'admin' },
    { label: '角色权限总览', key: 'admin' },
] as const;

const allowed = (role: Role, key: string) =>
    key === 'dashboard' || key === 'scope' || Boolean(role[key as keyof Role]);
</script>

<template>
    <BusinessLayout title="角色权限总览" eyebrow="权限边界 · 只读">
        <div
            class="mb-7 grid gap-5 border border-[#d9d2c4] bg-[#173b32] p-6 text-white shadow-[0_16px_35px_rgba(23,59,50,.12)] md:grid-cols-[1fr_auto] md:items-end md:p-8"
        >
            <div>
                <div
                    class="mb-5 flex size-11 items-center justify-center border border-white/25 bg-white/10"
                >
                    <ShieldCheck :size="23" />
                </div>
                <p class="mb-2 text-xs tracking-[.22em] text-[#d4bb80]">
                    ACCESS REGISTER
                </p>
                <h2 class="font-serif text-2xl font-semibold">
                    四种角色，一个清晰的权限边界
                </h2>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-white/65">
                    此处展示当前系统的角色权限。人员授权请到“授权名单”维护；角色权限由系统规则决定。
                </p>
            </div>
            <div
                class="flex items-center gap-2 border-t border-white/20 pt-4 text-xs text-white/70 md:border-t-0 md:pt-0"
            >
                <LockKeyhole :size="15" /> 仅系统管理员可查看
            </div>
        </div>

        <div
            class="overflow-x-auto border border-[#ded7c9] bg-white shadow-[0_8px_24px_rgba(48,55,45,.05)]"
        >
            <table
                class="w-full min-w-[850px] border-collapse text-left text-sm"
            >
                <thead>
                    <tr class="border-b border-[#ded7c9] bg-[#f8f5ee]">
                        <th class="w-52 px-6 py-5 font-medium text-[#68736e]">
                            功能 / 操作
                        </th>
                        <th
                            v-for="role in roles"
                            :key="role.name"
                            class="min-w-40 border-l border-[#e8e2d7] px-5 py-5 font-serif text-base font-semibold text-[#173b32]"
                        >
                            {{ role.name }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(permission, index) in permissions"
                        :key="index"
                        class="border-b border-[#eee9df] last:border-b-0"
                    >
                        <th
                            scope="row"
                            class="px-6 py-4 font-medium text-[#394b42]"
                        >
                            {{ permission.label }}
                        </th>
                        <td
                            v-for="role in roles"
                            :key="role.name"
                            class="border-l border-[#eee9df] px-5 py-4 align-middle"
                        >
                            <span
                                v-if="permission.key === 'scope'"
                                class="text-xs leading-5 text-[#305e50]"
                                >{{ role.scope }}</span
                            >
                            <span
                                v-else-if="allowed(role, permission.key)"
                                class="inline-flex items-center gap-1.5 text-xs font-medium text-[#25624e]"
                                ><Check :size="15" />允许</span
                            >
                            <span
                                v-else
                                class="inline-flex items-center gap-1.5 text-xs text-[#9a9d96]"
                                ><Minus :size="15" />无权限</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-4 text-xs leading-5 text-[#78827d]">
            拥有多个角色时，系统管理员优先；全局管理员与普通会议角色并存时，纪要按全局只读权限处理。
        </p>
    </BusinessLayout>
</template>
