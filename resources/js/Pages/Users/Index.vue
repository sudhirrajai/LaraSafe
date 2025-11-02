<script setup>
import MainLayout from '@/Layouts/MainLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'

// Load SweetAlert2 from CDN
const Swal = window.Swal

defineOptions({
    layout: MainLayout
})

defineProps({
    users: {
        type: Array,
        default: () => [],
    }
})

const deleting = ref(null)

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

const getRoleBadges = (roles) => {
    return roles.map(role => role.name).join(', ')
}

const getRoleBadgeClass = (roleName) => {
    const roleClasses = {
        'admin': 'bg-danger',
        'manager': 'bg-warning',
        'user': 'bg-primary',
        'editor': 'bg-info',
    }
    return roleClasses[roleName.toLowerCase()] || 'bg-secondary'
}
</script>

<template>
    <div class="row">
        <div class="col-12">
            <div class="card w-100">
                <div class="card-body p-4">
                    <div class="d-flex mb-4 justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold">User Management</h5>
                        <Link href="/user-management/create" class="btn btn-primary">
                            <i class="ti ti-user-plus me-1"></i>Add New User
                        </Link>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">ID</th>
                                    <th scope="col">Name</th>
                                    <th scope="col">Email</th>
                                    <th scope="col">Roles</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!users.length">
                                    <td colspan="5" class="text-center">No users found.</td>
                                </tr>
                                <tr v-for="user in users" :key="user.id">
                                    <td>{{ user.id }}</td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div v-if="user.avatar" class="me-2">
                                                <img :src="user.avatar" alt="avatar" class="rounded-circle" width="35" height="35">
                                            </div>
                                            <div v-else class="me-2">
                                                <div class="rounded-circle bg-light-primary text-primary d-flex align-items-center justify-content-center" style="width: 35px; height: 35px;">
                                                    {{ user.name.charAt(0).toUpperCase() }}
                                                </div>
                                            </div>
                                            <span>{{ user.name }}</span>
                                        </div>
                                    </td>
                                    <td>{{ user.email }}</td>
                                    <td>
                                        <span 
                                            v-for="role in user.roles" 
                                            :key="role.id"
                                            :class="['badge', 'me-1', getRoleBadgeClass(role.name)]"
                                        >
                                            {{ role.name }}
                                        </span>
                                        <span v-if="!user.roles.length" class="text-muted">No roles assigned</span>
                                    </td>
                                    <td>
                                        <div class="d-flex">
                                            <Link 
                                                :href="`/user-management/${user.id}/permissions`" 
                                                class="btn btn-sm btn-light-info text-info me-1" 
                                                title="Manage Permissions"
                                            >
                                                <i class="ti ti-lock"></i>
                                            </Link>
                                            <Link 
                                                :href="`/user-management/${user.id}/edit`" 
                                                class="btn btn-sm btn-light-warning text-warning me-1" 
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
                                            </button>
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