<script setup>
import MainLayout from '@/Layouts/MainLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'

defineOptions({
  layout: MainLayout
})

const props = defineProps({
  user: { type: Object, required: true },
  roles: { type: Array, default: () => [] },
  allPermissions: { type: Object, default: () => ({}) }
})

const form = useForm({
  name: props.user.name,
  email: props.user.email,
  role: props.user.roles[0]?.name || 'user',
  password: '',
  password_confirmation: '',
})

const roleDescriptions = {
  admin: 'Full access to all features including user management, projects, and backups',
  manager: 'Can manage users, create/edit projects and backups, but cannot delete projects',
  user: 'Can create and download backups, view projects',
  viewer: 'Read-only access - can only view and download'
}

const submit = () => {
  form.put(`/user-management/${props.user.id}`, {
    preserveScroll: true,
  })
}
</script>

<template>
  <div class="row">
    <div class="col-12">
      <div class="card w-100">
        <div class="card-body p-4">

          <!-- Header -->
          <div class="d-flex mb-4 justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold">Edit User</h5>
            <div class="d-flex gap-2">
              <Link :href="`/user-management/${user.id}/permissions`" class="btn btn-light-info text-info">
                <i class="ti ti-lock me-1"></i> Manage Permissions
              </Link>
              <Link href="/user-management" class="btn btn-light-primary text-primary">
                <i class="ti ti-arrow-left me-1"></i> Back to Users
              </Link>
            </div>
          </div>

          <form @submit.prevent="submit">
            <div class="row">

              <!-- User Avatar + Info -->
              <div class="col-12 mb-4">
                <div class="alert alert-light-info d-flex align-items-center">
                  <div v-if="user.avatar" class="me-3">
                    <img :src="user.avatar" alt="avatar" class="rounded-circle" width="50" height="50">
                  </div>
                  <div v-else class="me-3">
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                         style="width: 50px; height: 50px; font-size: 20px;">
                      {{ user.name.charAt(0).toUpperCase() }}
                    </div>
                  </div>
                  <div>
                    <h6 class="mb-0">{{ user.name }}</h6>
                    <small class="text-muted">ID: {{ user.id }}</small>
                  </div>
                </div>
              </div>

              <!-- Name -->
              <div class="col-md-6 mb-3">
                <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" :class="{ 'is-invalid': form.errors.name }"
                       id="name" v-model="form.name" placeholder="Enter user name">
                <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
              </div>

              <!-- Email -->
              <div class="col-md-6 mb-3">
                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                <input type="email" class="form-control" :class="{ 'is-invalid': form.errors.email }"
                       id="email" v-model="form.email" placeholder="Enter email address">
                <div v-if="form.errors.email" class="invalid-feedback">{{ form.errors.email }}</div>
              </div>

              <!-- Password Section (Optional) -->
              <div class="col-12 mt-4">
                <h6 class="mb-3 text-primary">
                  <i class="ti ti-shield-lock me-1"></i> Change Password (Optional)
                </h6>
                <div class="row">

                  <!-- New Password -->
                  <div class="col-md-6 mb-3">
                    <label for="password" class="form-label">New Password</label>
                    <input type="password" class="form-control" :class="{ 'is-invalid': form.errors.password }"
                           id="password" v-model="form.password" placeholder="Leave blank to keep current">
                    <div v-if="form.errors.password" class="invalid-feedback">{{ form.errors.password }}</div>
                  </div>

                  <!-- Confirm Password -->
                  <div class="col-md-6 mb-3">
                    <label for="password_confirmation" class="form-label">Confirm New Password</label>
                    <input type="password" class="form-control" id="password_confirmation"
                           v-model="form.password_confirmation" placeholder="Confirm password">
                  </div>

                </div>

                <small class="text-muted d-block">
                  <i class="ti ti-info-circle"></i>
                  Leave both fields blank to keep the current password unchanged.
                </small>
              </div>

              <!-- Role Selection -->
              <div class="col-12 mb-3 mt-4">
                <label class="form-label">Assign Role <span class="text-danger">*</span></label>
                <div class="row">
                  <div v-for="role in roles" :key="role.id" class="col-md-6 mb-3">
                    <div class="card h-100" :class="{ 'border-primary': form.role === role.name }">
                      <div class="card-body p-3">
                        <div class="form-check">
                          <input type="radio" class="form-check-input" :id="`role-${role.id}`"
                                 :value="role.name" v-model="form.role">
                          <label class="form-check-label w-100" :for="`role-${role.id}`">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                              <strong class="text-capitalize">{{ role.name }}</strong>
                              <span :class="[
                                'badge',
                                role.name === 'admin' ? 'bg-danger' :
                                role.name === 'manager' ? 'bg-warning' :
                                role.name === 'user' ? 'bg-primary' : 'bg-secondary'
                              ]">
                                {{ role.name }}
                              </span>
                            </div>
                            <small class="text-muted d-block">
                              {{ roleDescriptions[role.name] || 'Custom role' }}
                            </small>
                          </label>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div v-if="form.errors.role" class="text-danger small mt-1">{{ form.errors.role }}</div>

                <div class="alert alert-warning mt-3 p-2">
                  <i class="ti ti-alert-triangle me-1"></i>
                  <small>Changing the role will reset permissions to defaults. Use "Manage Permissions" to customize.</small>
                </div>
              </div>

              <!-- Submit -->
              <div class="col-12 mt-4">
                <div class="d-flex gap-2">
                  <button type="submit" class="btn btn-primary" :disabled="form.processing">
                    <span v-if="form.processing">
                      <span class="spinner-border spinner-border-sm me-1"></span> Updating...
                    </span>
                    <span v-else>
                      <i class="ti ti-device-floppy me-1"></i> Update User
                    </span>
                  </button>
                  <Link href="/user-management" class="btn btn-light-danger text-danger">
                    Cancel
                  </Link>
                </div>
              </div>

            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>