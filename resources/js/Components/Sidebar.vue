<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const page = usePage()
const currentUrl = computed(() => page.url)
const authUser = computed(() => page.props.auth?.user)

const isActiveExact = (path) => currentUrl.value === path
const isActiveStartsWith = (prefix) => currentUrl.value.startsWith(prefix)

// Check if user has permission
const hasPermission = (permission) => {
    if (!authUser.value) return false
    // Check if permissions array exists
    if (authUser.value.permissions) {
        return authUser.value.permissions.includes(permission)
    }
    // Fallback: check if user has 'can' method (if using Inertia's permission helper)
    return false
}

// Check if user has role
const hasRole = (role) => {
    if (!authUser.value || !authUser.value.roles) return false
    return authUser.value.roles.some(r => r.name === role)
}
</script>

<template>
  <!-- Sidebar Start -->
  <aside class="left-sidebar">
    <div>
      <div class="brand-logo d-flex align-items-center justify-content-between">
        <Link href="/" class="text-nowrap logo-img">
          <img src="/public/assets/images/logos/logo.png" width="200" alt=""
            style="margin-top:10px; margin-bottom:-20px;" />
        </Link>
        <div class="close-btn d-xl-none d-block js-side-toggle cursor-pointer" id="sidebarCollapse">
          <i class="ti ti-x fs-8"></i>
        </div>
      </div>

      <nav class="sidebar-nav scroll-sidebar" data-simplebar="">
        <ul id="sidebarnav">
          <li class="nav-small-cap">
            <iconify-icon icon="solar:menu-dots-linear" class="nav-small-cap-icon fs-4"></iconify-icon>
          </li>

          <li class="sidebar-item">
            <Link :href="`/`" :class="['sidebar-link', 'primary-hover-bg', { active: isActiveExact('/') }]"
              aria-expanded="false">
              <iconify-icon icon="solar:atom-line-duotone"></iconify-icon>
              <span class="hide-menu">Dashboard</span>
            </Link>
          </li>

          <li class="sidebar-item">
            <Link :href="`/projects/manage-projects`" :class="[
              'sidebar-link', 'primary-hover-bg', 'justify-content-between',
              { active: isActiveStartsWith('/projects') }
            ]" aria-expanded="false">
              <div class="d-flex align-items-center gap-6">
                <span class="d-flex">
                  <iconify-icon icon="solar:screencast-2-line-duotone"></iconify-icon>
                </span>
                <span class="hide-menu">Manage Projects</span>
              </div>
            </Link>
          </li>

          <li class="sidebar-item">
            <Link :href="`/backups/manage-backups`" :class="[
              'sidebar-link', 'primary-hover-bg', 'justify-content-between',
              { active: isActiveStartsWith('/backups') }
            ]" aria-expanded="false">
              <div class="d-flex align-items-center gap-6">
                <span class="d-flex">
                  <iconify-icon icon="solar:chart-line-duotone"></iconify-icon>
                </span>
                <span class="hide-menu">Manage Backups</span>
              </div>
            </Link>
          </li>

          <li class="sidebar-item">
            <Link :href="`/settings`" :class="[
              'sidebar-link', 'primary-hover-bg', 'justify-content-between',
              { active: isActiveStartsWith('/settings') }
            ]" aria-expanded="false">
              <div class="d-flex align-items-center gap-6">
                <span class="d-flex">
                  <iconify-icon icon="solar:settings-line-duotone"></iconify-icon>
                </span>
                <span class="hide-menu">Settings</span>
              </div>
            </Link>
          </li>

          <!-- User Management - Only show if user has permission -->
          <li class="sidebar-item" v-if="hasPermission('manage users') || hasRole('admin')">
            <Link :href="`/user-management`" :class="[
              'sidebar-link', 'primary-hover-bg', 'justify-content-between',
              { active: isActiveStartsWith('/user-management') }
            ]" aria-expanded="false">
              <div class="d-flex align-items-center gap-6">
                <span class="d-flex">
                  <iconify-icon icon="solar:user-plus-line-duotone"></iconify-icon>
                </span>
                <span class="hide-menu">User Management</span>
              </div>
            </Link>
          </li>
        </ul>
      </nav>
    </div>
  </aside>
  <!-- Sidebar End -->
</template>