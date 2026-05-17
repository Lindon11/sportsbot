<template>
  <div class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900">
    <!-- Mobile Menu Overlay -->
    <Transition name="fade">
      <div v-if="mobileMenuOpen" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 lg:hidden" @click="closeMobileMenu" />
    </Transition>
    <!-- Sidebar -->
    <aside
      :class="[
        'fixed top-0 left-0 h-full z-50 flex flex-col transition-all duration-300 ease-out',
        'bg-slate-900/95 backdrop-blur-xl border-r border-slate-700/50',
        sidebarCollapsed ? 'w-20' : 'w-72',
        mobileMenuOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
      ]"
    >
      <!-- Logo -->
      <div class="flex items-center justify-between px-6 border-b border-slate-700/50 h-[73px]">
        <div class="flex items-center gap-3 h-full">
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/20">
            <BoltIcon class="w-6 h-6 text-white" />
          </div>
          <Transition name="fade">
            <span v-if="!sidebarCollapsed" class="text-xl font-bold text-white">LaravelCP</span>
          </Transition>
        </div>
        <button
          @click="toggleSidebarCollapse"
          class="hidden lg:flex p-2 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
        >
          <ChevronLeftIcon :class="['w-5 h-5 transition-transform', sidebarCollapsed && 'rotate-180']" />
        </button>
      </div>
      <!-- Sidebar Navigation -->
      <nav class="flex-1 overflow-y-auto px-2 py-4 scrollbar-thin">
        <div v-for="section in menuSections" :key="section.id" class="mb-1">
          <button
            @click="toggleMenu(section.id)"
            :class="[
              'w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all',
              isMenuOpen(section.id)
                ? 'text-white bg-slate-800/50'
                : 'text-slate-400 hover:text-white hover:bg-slate-800/30'
            ]"
          >
            <component :is="section.iconComponent" class="w-5 h-5 flex-shrink-0" />
            <span v-if="!sidebarCollapsed" class="flex-1 text-left">{{ section.label }}</span>
            <ChevronDownIcon
              v-if="!sidebarCollapsed"
              :class="['w-4 h-4 transition-transform', isMenuOpen(section.id) && 'rotate-180']"
            />
          </button>
          <Transition name="accordion">
            <div v-if="isMenuOpen(section.id) && !sidebarCollapsed" class="mt-1 ml-4 pl-4 border-l border-slate-700/50 space-y-1">
              <router-link
                v-for="item in section.children"
                :key="item.path"
                :to="item.path"
                :class="[
                  'flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-all',
                  route.path === item.path
                    ? 'text-amber-400 bg-amber-500/10'
                    : 'text-slate-400 hover:text-white hover:bg-slate-800/30'
                ]"
                @click="closeMobileMenu"
              >
                <component :is="item.iconComponent" class="w-4 h-4" />
                <span>{{ item.label }}</span>
              </router-link>
            </div>
          </Transition>
        </div>
      </nav>
      <!-- User / Logout -->
      <div class="p-3 border-t border-slate-700/50">
        <button
          @click="logout"
          class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-red-400 hover:text-red-300 hover:bg-red-500/10 transition-all"
        >
          <ArrowRightOnRectangleIcon class="w-5 h-5" />
          <span v-if="!sidebarCollapsed" class="font-medium">Logout</span>
        </button>
      </div>
    </aside>

    <!-- Main Content -->
    <main
      :class="[
        'min-h-screen transition-all duration-300',
        sidebarCollapsed ? 'lg:ml-20' : 'lg:ml-72'
      ]"
    >
      <!-- Header -->
      <header class="sticky top-0 z-30 backdrop-blur-xl bg-slate-900/80 border-b border-slate-700/50">
        <div class="flex items-center justify-between px-4 sm:px-6 h-[73px]">
          <div class="flex items-center gap-2 sm:gap-4 flex-1 min-w-0">
            <button
              @click="toggleMobileMenu"
              class="lg:hidden p-2 -ml-2 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
            >
              <Bars3Icon class="w-5 h-5" />
            </button>
            <div class="min-w-0">
              <!-- Breadcrumbs -->
              <nav class="flex items-center gap-2 text-sm mb-1" aria-label="Breadcrumb">
                <RouterLink
                  to="/dashboard"
                  class="text-slate-400 hover:text-white transition-colors flex items-center gap-1"
                >
                  <HomeIcon class="w-4 h-4" />
                  <span class="hidden sm:inline">Dashboard</span>
                </RouterLink>
                <ChevronRightIcon class="w-4 h-4 text-slate-600" />
                <span class="text-white font-medium truncate">{{ pageTitle }}</span>
              </nav>
              <p class="hidden md:block text-xs text-slate-500">{{ currentDate }}</p>
            </div>
          </div>

          <div class="flex items-center gap-1 sm:gap-2 md:gap-3">
            <!-- Search -->
            <div class="hidden md:flex items-center">
              <div class="relative">
                <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400" />
                <input
                  v-model="searchQuery"
                  @input="handleSearch"
                  @focus="handleSearch"
                  @blur="setTimeout(() => showSearchResults = false, 200)"
                  type="text"
                  placeholder="Search pages..."
                  class="w-64 pl-10 pr-4 py-2 rounded-xl bg-slate-800/50 border border-slate-700/50 text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500/50 transition-all"
                />

                <!-- Search Results Dropdown -->
                <Transition name="dropdown">
                  <div
                    v-if="showSearchResults && searchResults.length > 0"
                    class="absolute top-full left-0 right-0 mt-2 rounded-xl bg-slate-800 border border-slate-700/50 shadow-xl shadow-black/20 overflow-hidden z-50"
                  >
                    <div class="max-h-80 overflow-y-auto">
                      <button
                        v-for="result in searchResults"
                        :key="result.path"
                        @mousedown.prevent="navigateToResult(result.path)"
                        class="w-full flex items-center gap-3 px-4 py-3 hover:bg-slate-700/50 transition-colors text-left"
                      >
                        <component :is="result.iconComponent" class="w-5 h-5 text-amber-400" />
                        <div>
                          <p class="text-sm font-medium text-white">{{ result.label }}</p>
                          <p class="text-xs text-slate-400">{{ result.section }}</p>
                        </div>
                      </button>
                    </div>
                  </div>
                </Transition>
              </div>
            </div>

            <!-- Staff Chat -->
            <StaffChat />

            <!-- Notifications -->
            <NotificationDropdown />

            <!-- User Menu -->
            <div class="relative flex items-center gap-2 sm:gap-3 pl-2 sm:pl-3 border-l border-slate-700/50">
              <button
                @click="userMenuOpen = !userMenuOpen"
                class="flex items-center gap-2 sm:gap-3 hover:opacity-90 transition-all"
              >
                <div class="hidden md:block text-right">
                  <p class="text-sm font-medium text-white">{{ user?.username || 'Admin' }}</p>
                  <p class="text-xs text-slate-400">Administrator</p>
                </div>
                <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center text-white font-bold shadow-lg shadow-amber-500/20">
                  {{ (user?.username || 'A').charAt(0).toUpperCase() }}
                </div>
                <ChevronDownIcon class="w-4 h-4 text-slate-400 hidden md:block" />
              </button>

              <!-- User Dropdown -->
              <Transition name="dropdown">
                <div
                  v-if="userMenuOpen"
                  class="absolute right-0 top-full mt-2 w-56 rounded-xl bg-slate-800 border border-slate-700/50 shadow-xl shadow-black/20 overflow-hidden z-50"
                >
                  <div class="p-4 border-b border-slate-700/50">
                    <p class="text-sm font-medium text-white">{{ user?.username || 'Admin' }}</p>
                    <p class="text-xs text-slate-400">{{ user?.email || 'admin@example.com' }}</p>
                  </div>
                  <div class="py-2">
                    <button
                      @click="editProfile"
                      class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-slate-300 hover:bg-slate-700/50 hover:text-white transition-colors"
                    >
                      <UserCircleIcon class="w-5 h-5" />
                      Edit Profile
                    </button>
                    <div class="my-2 border-t border-slate-700/50"></div>
                    <button
                      @click="logout"
                      class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-red-400 hover:bg-red-500/10 hover:text-red-300 transition-colors"
                    >
                      <ArrowRightOnRectangleIcon class="w-5 h-5" />
                      Logout
                    </button>
                  </div>
                </div>
              </Transition>
            </div>
          </div>
        </div>
      </header>

      <!-- Page Content -->
      <div class="p-4 lg:p-6">
        <router-view />
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '@/services/api'
import NotificationDropdown from '@/components/NotificationDropdown.vue'
import StaffChat from '@/components/StaffChat.vue'
import {
  Bars3Icon,
  XMarkIcon,
  ChevronLeftIcon,
  ChevronDownIcon,
  ChevronRightIcon,
  BoltIcon,
  ChartBarIcon,
  CalendarIcon,
  ClipboardDocumentListIcon,
  ClipboardDocumentCheckIcon,
  UsersIcon,
  Cog6ToothIcon,
  ShieldCheckIcon,
  CurrencyDollarIcon,
  FireIcon,
  TrophyIcon,
  MegaphoneIcon,
  TicketIcon,
  WrenchScrewdriverIcon,
  WrenchIcon,
  ArrowRightOnRectangleIcon,
  ArrowTopRightOnSquareIcon,
  MagnifyingGlassIcon,
  MapPinIcon,
  MapIcon,
  BuildingOfficeIcon,
  AcademicCapIcon,
  UserGroupIcon,
  BanknotesIcon,
  HomeIcon,
  TruckIcon,
  SparklesIcon,
  GiftIcon,
  ChatBubbleLeftRightIcon,
  QuestionMarkCircleIcon,
  BookOpenIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  NoSymbolIcon,
  PuzzlePieceIcon,
  FlagIcon,
  CommandLineIcon,
  BeakerIcon,
  ShoppingBagIcon,
  ShoppingCartIcon,
  RocketLaunchIcon,
  StarIcon,
  UserCircleIcon,
  EnvelopeIcon,
  CircleStackIcon,
  HeartIcon,
  KeyIcon,
  BriefcaseIcon,
  LifebuoyIcon,
  ServerIcon,
  CubeIcon,
  IdentificationIcon,
  TagIcon,
  BellIcon,
  TvIcon,
} from '@heroicons/vue/24/outline'

const iconMap = {
  Bars3Icon, XMarkIcon, ChevronLeftIcon, ChevronDownIcon, ChevronRightIcon,
  BoltIcon, ChartBarIcon, CalendarIcon, ClipboardDocumentListIcon, ClipboardDocumentCheckIcon,
  UsersIcon, Cog6ToothIcon, ShieldCheckIcon, CurrencyDollarIcon, FireIcon,
  TrophyIcon, MegaphoneIcon, TicketIcon, WrenchScrewdriverIcon, WrenchIcon,
  ArrowRightOnRectangleIcon, ArrowTopRightOnSquareIcon, MagnifyingGlassIcon,
  MapPinIcon, MapIcon, BuildingOfficeIcon, AcademicCapIcon, UserGroupIcon,
  BanknotesIcon, HomeIcon, TruckIcon, SparklesIcon, GiftIcon,
  ChatBubbleLeftRightIcon, QuestionMarkCircleIcon, BookOpenIcon, ClockIcon,
  ExclamationTriangleIcon, NoSymbolIcon, PuzzlePieceIcon, FlagIcon, CommandLineIcon,
  BeakerIcon, ShoppingBagIcon, ShoppingCartIcon, RocketLaunchIcon, StarIcon,
  UserCircleIcon, EnvelopeIcon, CircleStackIcon, HeartIcon, KeyIcon,
  BriefcaseIcon, LifebuoyIcon, ServerIcon, CubeIcon, IdentificationIcon, TagIcon, BellIcon, TvIcon,
}

const normalizeSidebar = (sections) => {
  return sections.map(section => ({
    ...section,
    iconComponent: iconMap[section.icon] ?? BoltIcon,
    children: (section.children || []).map(child => ({
      ...child,
      path: child.route ?? child.path ?? '/',
      iconComponent: iconMap[child.icon] ?? BoltIcon,
    })),
  }))
}

const router = useRouter()
const route = useRoute()
const mobileMenuOpen = ref(false)
const sidebarCollapsed = ref(false)
const openMenus = ref(new Set())
const userMenuOpen = ref(false)
const searchQuery = ref('')
const searchResults = ref([])
const showSearchResults = ref(false)

const currentDate = computed(() => {
  return new Date().toLocaleDateString('en-US', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  })
})

const menuSections = ref([])
const sidebarLoading = ref(true)

onMounted(async () => {
  // Restore sidebar/menu state from localStorage
  const savedState = localStorage.getItem('sidebar_collapsed')
  if (savedState !== null) {
    sidebarCollapsed.value = savedState === 'true'
  }

  const savedMenus = localStorage.getItem('open_menus')
  if (savedMenus) {
    openMenus.value = new Set(JSON.parse(savedMenus))
  }

  // Load dynamic sidebar menu from API
  try {
    sidebarLoading.value = true
    const response = await api.get('/admin/sidebar')
    menuSections.value = normalizeSidebar(response.data.menu || [])
  } catch (e) {
    menuSections.value = []
  } finally {
    sidebarLoading.value = false
  }

  // Auto-open the section matching the current route
  const currentSection = menuSections.value.find(section =>
    section.children?.some(child => child.path === route.path)
  )
  if (currentSection && !openMenus.value.has(currentSection.id)) {
    openMenus.value.add(currentSection.id)
    saveOpenMenus()
  }
})

const toggleMenu = (menuId) => {
  if (openMenus.value.has(menuId)) {
    openMenus.value.delete(menuId)
  } else {
    openMenus.value.add(menuId)
  }
  saveOpenMenus()
}

const saveOpenMenus = () => {
  localStorage.setItem('open_menus', JSON.stringify([...openMenus.value]))
}

const isMenuOpen = (menuId) => openMenus.value.has(menuId)

const user = computed(() => {
  const userData = localStorage.getItem('admin_user')
  return userData ? JSON.parse(userData) : null
})

const pageTitle = computed(() => {
  // Custom overrides for routes where the auto-generated title isn't ideal
  const overrides = {
    '/dashboard': 'Dashboard',
    '/users': 'User Management',
    '/user-tools': 'User Tools',
    '/roles': 'Roles & Permissions',
    '/settings': 'Settings',
    '/email-settings': 'Email Settings',
    '/plugin-settings': 'Plugins',
    '/ip-bans': 'IP Bans',
    '/faq': 'FAQ',
    '/wiki': 'Wiki',
    '/tickets': 'Support Tickets',
    '/ticket-categories': 'Ticket Categories',
    '/api-keys': 'API Keys',
    '/system-health': 'System Health',
    '/forum-categories': 'Forum Categories',
    '/error-logs': 'Error Logs',
    '/activity-logs': 'Activity Logs',
    '/security': 'Security Settings',
    '/license': 'License Management',
    '/announcements': 'Announcements',
    '/sportsbot/dashboard': 'SportsBot Dashboard',
    '/sportsbot/fixtures-today': 'SportsBot Fixtures Today',
    '/sportsbot/football-fixtures': 'SportsBot Football Fixtures TV',
    '/sportsbot/rugby-fixtures': 'SportsBot Rugby Fixtures TV',
    '/sportsbot/fight-fixtures': 'SportsBot Fights TV',
    '/sportsbot/tv-guide': 'SportsBot TV Guide',
    '/sportsbot/live-now': 'SportsBot Live Now',
    '/sportsbot/routing': 'SportsBot Telegram Routes',
    '/sportsbot/coverage': 'SportsBot Coverage Settings',
    '/sportsbot/webhook-diagnostics': 'SportsBot Webhook Diagnostics',
    '/sportsbot/fixture-queue': 'SportsBot Fixture Queue',
  }

  // Check overrides first
  if (overrides[route.path]) return overrides[route.path]

  // Check ticket detail route
  if (route.path.startsWith('/tickets/')) return 'Ticket Detail'

  // Auto-generate from route name: "combat-enemies" → "Combat Enemies"
  if (route.name) {
    return String(route.name)
      .split('-')
      .map(word => word.charAt(0).toUpperCase() + word.slice(1))
      .join(' ')
  }

  return 'Admin Panel'
})

const toggleMobileMenu = () => {
  mobileMenuOpen.value = !mobileMenuOpen.value
}

const closeMobileMenu = () => {
  mobileMenuOpen.value = false
}

const toggleSidebarCollapse = () => {
  sidebarCollapsed.value = !sidebarCollapsed.value
  localStorage.setItem('sidebar_collapsed', sidebarCollapsed.value.toString())
}

const logout = () => {
  localStorage.removeItem('admin_token')
  localStorage.removeItem('admin_user')
  router.push('/login')
}

const editProfile = () => {
  router.push('/users?edit=' + (user.value?.id || ''))
  userMenuOpen.value = false
}

// Close user menu when clicking outside
const closeUserMenu = () => {
  userMenuOpen.value = false
}

// Search functionality
const searchableRoutes = computed(() => {
  const routes = []
  menuSections.value.forEach(section => {
    section.children.forEach(item => {
      routes.push({ ...item, section: section.label })
    })
  })
  return routes
})

const handleSearch = () => {
  if (!searchQuery.value.trim()) {
    searchResults.value = []
    showSearchResults.value = false
    return
  }

  const query = searchQuery.value.toLowerCase()
  searchResults.value = searchableRoutes.value.filter(route =>
    route.label.toLowerCase().includes(query) ||
    route.section.toLowerCase().includes(query)
  ).slice(0, 8)
  showSearchResults.value = true
}

const navigateToResult = (path) => {
  router.push(path)
  searchQuery.value = ''
  showSearchResults.value = false
}
</script>

<style scoped>
.nav-link {
  @apply relative flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800/50 transition-all;
}

.nav-link-active {
  @apply text-white bg-gradient-to-r from-amber-500/20 to-orange-500/20 border border-amber-500/30;
}

.nav-link-active .nav-icon {
  @apply text-amber-400;
}

.nav-icon {
  @apply w-5 h-5 flex-shrink-0 transition-colors;
}

.nav-label {
  @apply font-medium;
}

.nav-tooltip {
  @apply absolute left-full ml-3 px-3 py-1.5 rounded-lg bg-slate-800 text-white text-sm font-medium whitespace-nowrap opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50 shadow-xl;
}

.scrollbar-thin::-webkit-scrollbar {
  width: 4px;
}

.scrollbar-thin::-webkit-scrollbar-track {
  background: transparent;
}

.scrollbar-thin::-webkit-scrollbar-thumb {
  background: rgb(51 65 85 / 0.5);
  border-radius: 2px;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.accordion-enter-active,
.accordion-leave-active {
  transition: all 0.2s ease;
  overflow: hidden;
}

.accordion-enter-from,
.accordion-leave-to {
  opacity: 0;
  max-height: 0;
}

.accordion-enter-to,
.accordion-leave-from {
  opacity: 1;
  max-height: 500px;
}

.dropdown-enter-active,
.dropdown-leave-active {
  transition: all 0.15s ease;
}

.dropdown-enter-from,
.dropdown-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>
