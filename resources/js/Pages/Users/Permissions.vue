<script setup>
import MainLayout from '@/Layouts/MainLayout.vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

defineOptions({
    layout: MainLayout
})

const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
    allPermissions: {
        type: Object,
        default: () => ({}),
    },
    userPermissions: {
        type: Array,
        default: () => [],
    },
    currentUserRole: {
        type: String,
        default: null
    }
})

const page = usePage()
const currentUserRole = computed(() => props.currentUserRole || page.props.auth?.user?.roles?.[0] || null)

const form = useForm({
    permissions: [...props.userPermissions],
})

const categoryIcons = {
    'User Management': 'ti ti-users',
    'Project Management': 'ti ti-folder',
    'Backup Management': 'ti ti-database',
    'Other': 'ti ti-settings'
}

const categoryColors = {
    'User Management': 'primary',
    'Project Management': 'success',
    'Backup Management': 'warning',
    'Other': 'info'
}

// Check if a permission should be restricted for managers
const isPermissionRestricted = (permissionName) => {
    if (currentUserRole.value === 'admin') return false
    
    if (currentUserRole.value === 'manager') {
        // Managers cannot grant these sensitive permissions
        const restrictedPermissions = [
            'manage users',
            'delete users',
            'delete project' // Managers can't delete projects themselves, so shouldn't grant it
        ]
        return restrictedPermissions.includes(permissionName)
    }
    
    return true // Other roles can't edit permissions at all
}

// Check if entire category should be restricted
const isCategoryRestricted = (category) => {
    if (currentUserRole.value === 'admin') return false
    
    if (currentUserRole.value === 'manager') {
        // User Management category is partially restricted for managers
        return category === 'User Management'
    }
    
    return true
}

const toggleCategory = (category) => {
    const categoryPermissions = props.allPermissions[category]
        .filter(p => !isPermissionRestricted(p.name))
        .map(p => p.name)
    
    const allSelected = categoryPermissions.every(p => form.permissions.includes(p))
    
    if (allSelected) {
        // Deselect all in category
        form.permissions = form.permissions.filter(p => !categoryPermissions.includes(p))
    } else {
        // Select all in category
        categoryPermissions.forEach(p => {
            if (!form.permissions.includes(p)) {
                form.permissions.push(p)
            }
        })
    }
}

const isCategoryFullySelected = (category) => {
    const categoryPermissions = props.allPermissions[category]
        .filter(p => !isPermissionRestricted(p.name))
        .map(p => p.name)
    
    if (categoryPermissions.length === 0) return false
    return categoryPermissions.every(p => form.permissions.includes(p))
}

const isCategoryPartiallySelected = (category) => {
    const categoryPermissions = props.allPermissions[category]
        .filter(p => !isPermissionRestricted(p.name))
        .map(p => p.name)
    
    const selectedCount = categoryPermissions.filter(p => form.permissions.includes(p)).length
    return selectedCount > 0 && selectedCount < categoryPermissions.length
}

const submit = () => {
    form.put(`/user-management/${props.user.id}/permissions`, {
        preserveScroll: true,
    })
}

const selectAll = () => {
    const allAvailablePermissions = Object.values(props.allPermissions)
        .flat()
        .filter(p => !isPermissionRestricted(p.name))
        .map(p => p.name)
    
    form.permissions = [...new Set([...form.permissions, ...allAvailablePermissions])]
}

const deselectAll = () => {
    // Keep restricted permissions if they were already assigned
    const restrictedPermissions = form.permissions.filter(p => isPermissionRestricted(p))
    form.permissions = restrictedPermissions
}

// Check if user being edited is admin or manager
const isEditingRestrictedUser = computed(() => {
    const userRoles = props.user.roles?.map(r => r.name) || []
    return userRoles.includes('admin') || userRoles.includes('manager')
})
</script>

<template>
    <div class="row">
        <div class="col-12">
            <div class="card w-100">
                <div class="card-body p-4">
                    <div class="d-flex mb-4 justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-1 fw-bold">Manage Permissions</h5>
                            <small class="text-muted">User: {{ user.name }} ({{ user.roles[0]?.name || 'No role' }})</small>
                        </div>
                        <Link :href="`/user-management/${user.id}/edit`" class="btn btn-light-primary text-primary">
                            <i class="ti ti-arrow-left me-1"></i>Back to Edit
                        </Link>
                    </div>

                    <!-- Restriction Warning for Managers -->
                    <div v-if="currentUserRole === 'manager' && isEditingRestrictedUser" class="alert alert-danger mb-4">
                        <i class="ti ti-shield-lock me-2"></i>
                        <strong>Access Denied:</strong> You cannot edit permissions for admin or manager users.
                    </div>

                    <div v-else-if="currentUserRole === 'manager'" class="alert alert-warning mb-4">
                        <i class="ti ti-alert-triangle me-2"></i>
                        <strong>Manager Restriction:</strong> Some sensitive permissions (like "manage users", "delete users", "delete project") are restricted and cannot be modified.
                    </div>

                    <!-- User Info -->
                    <div class="alert alert-light-info d-flex align-items-center mb-4">
                        <div v-if="user.avatar" class="me-3">
                            <img :src="`/storage/${user.avatar}`" alt="avatar" class="rounded-circle" width="50" height="50">
                        </div>
                        <div v-else class="me-3">
                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; font-size: 20px;">
                                {{ user.name.charAt(0).toUpperCase() }}
                            </div>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">{{ user.name }}</h6>
                            <div class="d-flex gap-2 align-items-center">
                                <small class="text-muted">{{ user.email }}</small>
                                <span 
                                    v-if="user.roles[0]"
                                    :class="[
                                        'badge',
                                        user.roles[0].name === 'admin' ? 'bg-danger' : 
                                        user.roles[0].name === 'manager' ? 'bg-warning' :
                                        user.roles[0].name === 'user' ? 'bg-primary' : 'bg-secondary'
                                    ]"
                                >
                                    {{ user.roles[0].name }}
                                </span>
                            </div>
                        </div>
                        <div class="text-end">
                            <small class="text-muted">{{ form.permissions.length }} permissions selected</small>
                        </div>
                    </div>

                    <form @submit.prevent="submit" v-if="!isEditingRestrictedUser || currentUserRole === 'admin'">
                        <!-- Quick Actions -->
                        <div class="d-flex gap-2 mb-3">
                            <button type="button" @click="selectAll" class="btn btn-sm btn-light-success text-success">
                                <i class="ti ti-check-all me-1"></i>Select All{{ currentUserRole === 'manager' ? ' (Available)' : '' }}
                            </button>
                            <button type="button" @click="deselectAll" class="btn btn-sm btn-light-danger text-danger">
                                <i class="ti ti-x me-1"></i>Deselect All{{ currentUserRole === 'manager' ? ' (Available)' : '' }}
                            </button>
                        </div>

                        <!-- Permission Categories -->
                        <div class="row">
                            <div 
                                v-for="(permissions, category) in allPermissions" 
                                :key="category"
                                class="col-md-6 mb-4"
                            >
                                <div class="card" :class="{ 'border-warning': isCategoryRestricted(category) && currentUserRole === 'manager' }">
                                    <div :class="`card-header bg-light-${categoryColors[category]}`">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0">
                                                <i :class="categoryIcons[category]"></i>
                                                {{ category }}
                                                <i v-if="isCategoryRestricted(category) && currentUserRole === 'manager'" 
                                                   class="ti ti-alert-circle text-warning ms-2" 
                                                   title="Some permissions in this category are restricted"></i>
                                            </h6>
                                            <button 
                                                type="button"
                                                @click="toggleCategory(category)"
                                                :class="[
                                                    'btn btn-sm',
                                                    isCategoryFullySelected(category) ? `btn-${categoryColors[category]}` : `btn-light-${categoryColors[category]}`
                                                ]"
                                            >
                                                <i 
                                                    :class="[
                                                        'ti',
                                                        isCategoryFullySelected(category) ? 'ti-check' :
                                                        isCategoryPartiallySelected(category) ? 'ti-minus' : 'ti-square'
                                                    ]"
                                                ></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div 
                                            v-for="permission in permissions" 
                                            :key="permission.id"
                                            class="form-check mb-2"
                                            :class="{ 'opacity-50': isPermissionRestricted(permission.name) }"
                                        >
                                            <input 
                                                type="checkbox" 
                                                class="form-check-input" 
                                                :id="`permission-${permission.id}`"
                                                :value="permission.name"
                                                v-model="form.permissions"
                                                :disabled="isPermissionRestricted(permission.name)"
                                            >
                                            <label class="form-check-label text-capitalize" :for="`permission-${permission.id}`">
                                                {{ permission.name }}
                                                <i v-if="isPermissionRestricted(permission.name)" 
                                                   class="ti ti-lock text-warning ms-1" 
                                                   title="This permission is restricted"></i>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="form.errors.permissions" class="alert alert-danger">
                            {{ form.errors.permissions }}
                        </div>

                        <!-- Submit Buttons -->
                        <div class="d-flex gap-2 justify-content-between align-items-center">
                            <div class="alert alert-warning mb-0 flex-grow-1">
                                <i class="ti ti-alert-triangle me-2"></i>
                                <small>Custom permissions will override default role permissions</small>
                            </div>
                            <div class="d-flex gap-2">
                                <button 
                                    type="submit" 
                                    class="btn btn-primary"
                                    :disabled="form.processing"
                                >
                                    <span v-if="form.processing">
                                        <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                        Saving...
                                    </span>
                                    <span v-else>
                                        <i class="ti ti-device-floppy me-1"></i>Save Permissions
                                    </span>
                                </button>
                                <Link :href="`/user-management/${user.id}/edit`" class="btn btn-light-danger text-danger">
                                    Cancel
                                </Link>
                            </div>
                        </div>
                    </form>

                    <!-- Message when manager tries to edit admin/manager -->
                    <div v-else class="text-center py-5">
                        <i class="ti ti-shield-lock" style="font-size: 4rem; color: #dc3545;"></i>
                        <h4 class="mt-3">Access Restricted</h4>
                        <p class="text-muted">You do not have permission to modify this user's permissions.</p>
                        <Link href="/user-management" class="btn btn-primary mt-3">
                            <i class="ti ti-arrow-left me-1"></i>Back to User Management
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>