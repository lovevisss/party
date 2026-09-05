<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ShieldAlert } from 'lucide-vue-next';
</script>
<template>
    <main class="grid min-h-screen place-items-center bg-[#173b32] p-6">
        <div
            class="max-w-lg border border-white/15 bg-white p-10 text-center shadow-2xl"
        >
            <ShieldAlert :size="42" class="mx-auto mb-5 text-[#a6473d]" />
            <h1 class="font-serif text-2xl font-semibold">暂未获得业务权限</h1>
            <p class="mt-3 text-sm leading-7 text-slate-600">
                统一认证已完成，但当前账号尚未加入学院提交人或管理员名单。请联系系统管理员完成授权。
            </p>
            <button
                class="mt-7 bg-[#173b32] px-5 py-2.5 text-sm text-white"
                @click="router.post('/auth/logout')"
            >
                退出登录
            </button>
        </div>
    </main>
</template>
