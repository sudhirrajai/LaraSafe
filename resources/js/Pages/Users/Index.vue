<script setup>
import MainLayout from '@/Layouts/MainLayout.vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import Swal from 'sweetalert2'

defineOptions({
  layout: MainLayout
})

const props = defineProps({
  users: {
    type: Array,
    default: () => [],
  }
})

const page = usePage()
const currentUserRole = computed(() => page.props.auth?.user?.roles?.[0] || null)

const deleting = ref(null)

// Check if current user can edit/delete a specific user
const canEditUser = (user) => {
  if (currentUserRole.value === 'admin') return true
  if (currentUserRole.value === 'manager') {
    const userRoles = user.roles?.map(r => r.name) || []
    return !userRoles.includes('admin') && !userRoles.includes('manager')
  }
  return false
}

const handleDelete = (userId) => {
  Swal.fire({
    title: 'Are you sure?',
    text: 'This user will be permanently deleted!',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d33',
    cancelButtonColor: '#3085d6',
    confirmButtonText: 'Yes, delete it!',
    cancelButtonText: 'Cancel',
  }).then((result) => {
    if (result.isConfirmed) {
      deleting.value = userId
      router.delete(`/user-management/${userId}`, {
        preserveScroll: true,
        onSuccess: () => {
          Swal.fire('Deleted!', 'User has been deleted.', 'success')
          deleting.value = null
        },
        onError: () => {
          Swal.fire('Error!', 'Failed to delete the user.', 'error')
          deleting.value = null
        },
      })
    }
  })
}

const getRoleBadgeClass = (roleName) => {
  const map = {
    admin: 'bg-danger',
    manager: 'bg-warning',
    user: 'bg-primary',
    editor: 'bg-info',
    viewer: 'bg-secondary',
  }
  return map[roleName.toLowerCase()] || 'bg-secondary'
}

const getAvatarUrl = (avatarPath) => {
  return avatarPath
    ? `/storage/${avatarPath}`
    : '/assets/images/profile/user1.jpg'
}
</script>

<template>
  <div class="row">
    <div class="col-12">
      <div class="card w-100">
        <div class="card-body p-4">

          <!-- Header -->
          <div class="d-flex mb-4 justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold">User Management</h5>
            <Link href="/user-management/create" class="btn btn-primary">
              <i class="ti ti-user-plus me-1"></i> Add New User
            </Link>
          </div>

          <!-- Info Alert for Managers -->
          <div v-if="currentUserRole === 'manager'" class="alert alert-info mb-3">
            <i class="ti ti-info-circle me-2"></i>
            <strong>Manager View:</strong> You can only manage users with 'User' or 'Viewer' roles. Admin and Manager accounts are hidden or restricted.
          </div>

          <!-- Table -->
          <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Roles</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!users.length">
                  <td colspan="5" class="text-center text-muted">No users found.</td>
                </tr>
                <tr v-for="user in users" :key="user.id">
                  <td>{{ user.id }}</td>

                  <!-- Name + Avatar -->
                  <td>
                    <div class="d-flex align-items-center">
                      <div class="me-3">
                        <img
                          :src="getAvatarUrl(user.avatar)"
                          alt="Avatar"
                          class="rounded-circle"
                          width="40"
                          height="40"
                          @error="($event) => $event.target.src = '/assets/images/profile/user1.jpg'"
                        >
                      </div>
                      <span class="fw-medium">{{ user.name }}</span>
                    </div>
                  </td>

                  <td>{{ user.email }}</td>

                  <!-- Roles -->
                  <td>
                    <template v-if="user.roles?.length">
                      <span
                        v-for="role in user.roles"
                        :key="role.id"
                        :class="['badge', 'me-1', getRoleBadgeClass(role.name)]"
                      >
                        {{ role.name }}
                      </span>
                    </template>
                    <span v-else class="text-muted small">No roles</span>
                  </td>

                  <!-- Actions -->
                  <td>
                    <div v-if="canEditUser(user)" class="d-flex gap-1">
                      <Link
                        :href="`/user-management/${user.id}/permissions`"
                        class="btn btn-sm btn-light-info text-info"
                        title="Permissions"
                      >
                        <i class="ti ti-lock"></i>
                      </Link>
                      <Link
                        :href="`/user-management/${user.id}/edit`"
                        class="btn btn-sm btn-light-warning text-warning"
                        title="Edit"
                      >
                        <i class="ti ti-edit"></i>
                      </Link>
                      <button
                        @click.prevent="handleDelete(user.id)"
                        class="btn btn-sm btn-light-danger text-danger"
                        :disabled="deleting === user.id"
                        title="Delete"
                      >
                        <i class="ti ti-trash"></i>
                        <span v-if="deleting === user.id" class="spinner-border spinner-border-sm ms-1"></span>
                      </button>
                    </div>
                    <div v-else>
                      <span class="badge bg-light-secondary text-muted">
                        <i class="ti ti-lock"></i> Restricted
                      </span>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </div>
  </div>
</template>