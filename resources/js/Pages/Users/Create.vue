<script setup>
import MainLayout from '@/Layouts/MainLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

defineOptions({
    layout: MainLayout
})

const props = defineProps({
    roles: {
        type: Array,
        default: () => [],
    },
    allPermissions: {
        type: Object,
        default: () => ({}),
    },
    currentUserRole: {
        type: String,
        default: null,
    }
})

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'user',
})

const showPassword = ref(false)
const showPasswordConfirmation = ref(false)

const roleDescriptions = {
    admin: 'Full access to all features including user management, projects, and backups',
    manager: 'Can manage users, create/edit projects and backups, but cannot delete projects',
    user: 'Can create and download backups, view projects',
    viewer: 'Read-only access - can only view and download'
}

// Check if role is disabled for current user
const isRoleDisabled = (roleName) => {
    if (props.currentUserRole === 'admin') return false
    if (props.currentUserRole === 'manager') {
        return ['admin', 'manager'].includes(roleName)
    }
    return true
}

const submit = () => {
    form.post('/user-management', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        },
    })
}
</script>

<template>
    <div class="row">
        <div class="col-12">
            <div class="card w-100">
                <div class="card-body p-4">
                    <div class="d-flex mb-4 justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold">Create New User</h5>
                        <Link href="/user-management" class="btn btn-light-primary text-primary">
                            <i class="ti ti-arrow-left me-1"></i>Back to Users
                        </Link>
                    </div>

                    <form @submit.prevent="submit">
                        <div class="row">
                            <!-- Name -->
                            <div class="col-md-6 mb-3">
                                <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                                <input 
                                    type="text" 
                                    class="form-control" 
                                    :class="{ 'is-invalid': form.errors.name }"
                                    id="name" 
                                    v-model="form.name"
                                    placeholder="Enter user name"
                                >
                                <div v-if="form.errors.name" class="invalid-feedback">
                                    {{ form.errors.name }}
                                </div>
                            </div>

                            <!-- Email -->
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                <input 
                                    type="email" 
                                    class="form-control"
                                    :class="{ 'is-invalid': form.errors.email }"
                                    id="email" 
                                    v-model="form.email"
                                    placeholder="Enter email address"
                                >
                                <div v-if="form.errors.email" class="invalid-feedback">
                                    {{ form.errors.email }}
                                </div>
                            </div>

                            <!-- Password -->
                            <div class="col-md-6 mb-3">
                                <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input 
                                        :type="showPassword ? 'text' : 'password'" 
                                        class="form-control"
                                        :class="{ 'is-invalid': form.errors.password }"
                                        id="password" 
                                        v-model="form.password"
                                        placeholder="Enter password"
                                    >
                                    <button 
                                        class="btn btn-outline-secondary" 
                                        type="button"
                                        @click="showPassword = !showPassword"
                                    >
                                        <i :class="showPassword ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                                    </button>
                                </div>
                                <div v-if="form.errors.password" class="text-danger small mt-1">
                                    {{ form.errors.password }}
                                </div>
                            </div>

                            <!-- Confirm Password -->
                            <div class="col-md-6 mb-3">
                                <label for="password_confirmation" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input 
                                        :type="showPasswordConfirmation ? 'text' : 'password'" 
                                        class="form-control"
                                        id="password_confirmation" 
                                        v-model="form.password_confirmation"
                                        placeholder="Confirm password"
                                    >
                                    <button 
                                        class="btn btn-outline-secondary" 
                                        type="button"
                                        @click="showPasswordConfirmation = !showPasswordConfirmation"
                                    >
                                        <i :class="showPasswordConfirmation ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Role Selection -->
                            <div class="col-12 mb-3">
                                <label class="form-label">Assign Role <span class="text-danger">*</span></label>
                                <div class="row">
                                    <div v-for="role in roles" :key="role.id" class="col-md-6 mb-3">
                                        <div 
                                            class="card" 
                                            :class="{ 
                                                'border-primary': form.role === role.name,
                                                'opacity-50': isRoleDisabled(role.name)
                                            }"
                                        >
                                            <div class="card-body">
                                                <div class="form-check">
                                                    <input 
                                                        type="radio" 
                                                        class="form-check-input" 
                                                        :id="`role-${role.id}`"
                                                        :value="role.name"
                                                        v-model="form.role"
                                                        :disabled="isRoleDisabled(role.name)"
                                                    >
                                                    <label class="form-check-label w-100" :for="`role-${role.id}`">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <strong class="text-capitalize">{{ role.name }}</strong>
                                                            <span 
                                                                :class="[
                                                                    'badge',
                                                                    role.name === 'admin' ? 'bg-danger' : 
                                                                    role.name === 'manager' ? 'bg-warning' :
                                                                    role.name === 'user' ? 'bg-primary' : 'bg-secondary'
                                                                ]"
                                                            >
                                                                {{ role.name }}
                                                            </span>
                                                        </div>
                                                        <small class="text-muted d-block mt-2">
                                                            {{ roleDescriptions[role.name] || 'Custom role' }}
                                                        </small>
                                                        <small v-if="isRoleDisabled(role.name)" class="text-danger d-block mt-1">
                                                            <i class="ti ti-lock"></i> You cannot assign this role
                                                        </small>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-if="form.errors.role" class="text-danger small mt-1">
                                    {{ form.errors.role }}
                                </div>
                                <div class="alert alert-info mt-2">
                                    <i class="ti ti-info-circle me-2"></i>
                                    <small>Default permissions will be assigned based on the selected role. You can customize permissions later.</small>
                                </div>
                                <div v-if="currentUserRole === 'manager'" class="alert alert-warning mt-2">
                                    <i class="ti ti-alert-triangle me-2"></i>
                                    <small><strong>Manager Restriction:</strong> You can only create users with 'User' or 'Viewer' roles.</small>
                                </div>
                            </div>

                            <!-- Submit Buttons -->
                            <div class="col-12">
                                <div class="d-flex gap-2">
                                    <button 
                                        type="submit" 
                                        class="btn btn-primary"
                                        :disabled="form.processing"
                                    >
                                        <span v-if="form.processing">
                                            <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                            Creating...
                                        </span>
                                        <span v-else>
                                            <i class="ti ti-device-floppy me-1"></i>Create User
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