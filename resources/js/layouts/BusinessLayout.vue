<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Archive, CalendarDays, ClipboardList, Database, FileClock, LayoutDashboard, LogOut, ShieldCheck, Users } from 'lucide-vue-next';
defineProps<{ title: string; eyebrow?: string }>();
const page = usePage<any>();
const roles = computed(() => page.props.auth?.roles?.map((x: any) => x.role) ?? []);
const isAdmin = computed(() => roles.value.includes('system_admin'));
const logout = () => router.post('/auth/logout');
const links = computed(() => [
  { label: '工作台', href: '/dashboard', icon: LayoutDashboard },
  { label: '会议纪要', href: '/minutes', icon: ClipboardList },
  ...(isAdmin.value ? [
    { label: '人员同步', href: '/admin/personnel-sync', icon: Database },
    { label: '授权名单', href: '/admin/authorization-import', icon: Users },
    { label: '工作日历', href: '/admin/workdays', icon: CalendarDays },
    { label: '审计日志', href: '/admin/audit-logs', icon: FileClock },
  ] : []),
]);
</script>

<template>
  <div class="min-h-screen bg-[#f4f1ea] text-[#18231f]">
    <aside class="fixed inset-y-0 left-0 z-20 hidden w-64 overflow-hidden bg-[#173b32] text-white lg:block print:hidden">
      <div class="absolute inset-0 opacity-10" style="background-image:radial-gradient(#fff 0.8px,transparent 0.8px);background-size:18px 18px" />
      <div class="relative flex h-full flex-col px-5 py-7">
        <div class="mb-10 flex items-center gap-3 border-b border-white/15 pb-6">
          <div class="grid size-11 place-items-center rounded-sm bg-[#b99a55] shadow-lg"><Archive :size="22" /></div>
          <div><p class="font-serif text-lg font-semibold tracking-wide">党委会纪要</p><p class="text-xs text-white/55">规范 · 留痕 · 可追溯</p></div>
        </div>
        <nav class="space-y-1.5">
          <Link v-for="item in links" :key="item.href" :href="item.href" class="group flex items-center gap-3 rounded-md px-3 py-2.5 text-sm text-white/72 transition hover:bg-white/10 hover:text-white" :class="{ 'bg-white/12 text-white': page.url.startsWith(item.href) }"><component :is="item.icon" :size="17" /><span>{{ item.label }}</span></Link>
        </nav>
        <div class="mt-auto border-t border-white/15 pt-5">
          <div class="mb-3 flex items-center gap-3"><div class="grid size-9 place-items-center rounded-full bg-white/10 text-sm">{{ page.props.auth?.user?.name?.slice(0,1) }}</div><div class="min-w-0"><p class="truncate text-sm">{{ page.props.auth?.user?.name }}</p><p class="truncate text-xs text-white/45">{{ page.props.auth?.user?.cas_account }}</p></div></div>
          <button class="flex w-full items-center gap-2 rounded px-2 py-2 text-xs text-white/55 hover:bg-white/10 hover:text-white" @click="logout"><LogOut :size="15" />退出统一认证</button>
        </div>
      </div>
    </aside>
    <main class="lg:pl-64">
      <header class="border-b border-[#d9d2c4] bg-[#faf8f3]/95 px-5 py-5 backdrop-blur md:px-9 print:hidden">
        <div class="mx-auto flex max-w-[1320px] items-end justify-between"><div><p class="mb-1 text-[11px] font-semibold uppercase tracking-[.24em] text-[#8b6f35]">{{ eyebrow || '党委会会议纪要管理系统' }}</p><h1 class="font-serif text-2xl font-semibold tracking-tight">{{ title }}</h1></div><div class="hidden items-center gap-2 text-xs text-[#65736e] sm:flex"><ShieldCheck :size="16" class="text-[#2f6a59]" />校内统一认证 · 权限隔离</div></div>
      </header>
      <div class="mx-auto max-w-[1320px] p-5 md:p-9 print:max-w-none print:p-0"><div v-if="page.props.flash?.success" class="mb-5 border-l-4 border-[#2f6a59] bg-white px-4 py-3 text-sm shadow-sm print:hidden">{{ page.props.flash.success }}</div><div v-if="page.props.flash?.error" class="mb-5 border-l-4 border-[#a6473d] bg-white px-4 py-3 text-sm text-[#8a2f27] shadow-sm print:hidden">{{ page.props.flash.error }}</div><slot /></div>
    </main>
  </div>
</template>
