<script setup>
import MainLayout from '@/Layouts/MainLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

// Load SweetAlert2 from CDN
const Swal = window.Swal

defineOptions({
    layout: MainLayout
})

defineProps({
    backups: {
        type: Array,
        default: () => [],
    },
})

const deleting = ref(null)
const retrying = ref(null)
const restoreLoading = ref(false)

const showRestoreModal = ref(false)
const modalCreatedBackups = ref([]) // Holds created backups for selected backup
const selectedCreatedBackupId = ref(null)

// Computed property to check if any operation is in progress
const isLoading = computed(() => {
    return deleting.value !== null || retrying.value !== null || restoreLoading.value
})

const handleDelete = (backupId) => {
    Swal.fire({
        title: 'Are you sure you want to delete all the backups of this project?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel',
    }).then((result) => {
        if (result.isConfirmed) {
            deleting.value = backupId
            router.delete(`/backups/delete-backup/${backupId}`, {
                preserveScroll: true,
                onSuccess: () => {
                    Swal.fire('Deleted!', 'Backup has been deleted.', 'success')
                    deleting.value = null
                },
                onError: () => {
                    Swal.fire('Error!', 'Failed to delete the backup.', 'error')
                    deleting.value = null
                },
            })
        }
    })
}

const handleRetry = (backupId) => {
    Swal.fire({
        title: 'Are you sure you want to create new backup?',
        text: 'This will reprocess the backup job.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, create it!',
        cancelButtonText: 'Cancel',
    }).then((result) => {
        if (result.isConfirmed) {
            retrying.value = backupId
            router.post(`/backups/retry-backup/${backupId}`, {}, {
                preserveScroll: true,
                onSuccess: () => {
                    Swal.fire('Creating Backup!', 'Creating backup has been initiated.', 'success')
                    retrying.value = null
                },
                onError: () => {
                    Swal.fire('Error!', 'Failed to create backup.', 'error')
                    retrying.value = null
                },
            })
        }
    })
}

const handleView = (backupId) => {
    router.get(`/backups/view-backup/${backupId}`, {
    })
}

// Open restore modal and populate created backups directly
const openRestoreModal = (backup) => {
    modalCreatedBackups.value = backup.created_backups || []
    selectedCreatedBackupId.value = null
    showRestoreModal.value = true
}

// Handle restore backup submission
const handleRestore = () => {
    if (!selectedCreatedBackupId.value) {
        Swal.fire('Select Backup', 'Please select a backup from the dropdown.', 'warning')
        return
    }

    Swal.fire({
        title: 'Are you sure to restore the selected backup?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Restore',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            restoreLoading.value = true
            router.post('/backups/restore', { created_backup_id: selectedCreatedBackupId.value }, {
                preserveScroll: true,
                onSuccess: () => {
                    Swal.fire('Restored!', 'Backup restored successfully.', 'success')
                    showRestoreModal.value = false
                    restoreLoading.value = false
                },
                onError: () => {
                    Swal.fire('Error', 'Failed to restore backup.', 'error')
                    restoreLoading.value = false
                }
            })
        }
    })
}

// Method to format size in MB or GB
const formatSize = (bytes) => {
    if (!bytes && bytes !== 0) return 'N/A'
    const mb = bytes / (1024 * 1024)
    const gb = bytes / (1024 * 1024 * 1024)
    if (gb >= 1) return `${gb.toFixed(2)} GB`
    return `${mb.toFixed(2)} MB`
}

// Method to format date to human-readable form
const formatDate = (dateStr) => {
    if (!dateStr) return 'N/A'
    const date = new Date(dateStr)
    return date.toLocaleString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true
    })
}

// Method to get loading message based on current operation
const getLoadingMessage = () => {
    if (deleting.value) return 'Deleting backup...'
    if (retrying.value) return 'Creating new backup...'
    if (restoreLoading.value) return 'Restoring backup...'
    return 'Processing...'
}
</script>

<template>
    <!-- Full Page Loader -->
    <div v-if="isLoading" class="full-page-loader">
        <div class="loader-content">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 mb-0 text-center">{{ getLoadingMessage() }}</p>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card w-100">
                <div class="card-body p-4">
                    <div class="d-flex mb-4 justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold">Backups List</h5>
                        <Link href="/backups/create-backup" class="btn btn-primary">Create New Backup</Link>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">ID</th>
                                    <th scope="col">Project Name</th>
                                    <th scope="col">Size</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Next Backup</th>
                                    <th scope="col">Created At</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!backups.length">
                                    <td colspan="8" class="text-center">No backups found.</td>
                                </tr>
                                <tr v-for="backup in backups" :key="backup.id">
                                    <td>{{ backup.id }}</td>
                                    <td>{{ backup.project.name }}</td>
                                    <td>{{ formatSize(backup.size) }}</td>
                                    <td>{{ backup.status || 'N/A' }}</td>
                                    <td>{{ formatDate(backup.next_backup_at) }}</td>
                                    <td>{{ formatDate(backup.created_at) }}</td>
                                    <td>
                                        <div class="d-flex">
                                            <button
                                                @click="handleRetry(backup.id)"
                                                class="btn btn-sm btn-light-warning text-warning me-1"
                                                :disabled="retrying === backup.id || isLoading"
                                                title="Create New Backup"
                                            >
                                                <i class="ti ti-refresh" v-if="retrying !== backup.id"></i>
                                                <span v-else class="spinner-border spinner-border-sm" role="status"></span>
                                            </button>
                                            <button
                                                @click="handleView(backup.id)"
                                                class="btn btn-sm btn-light-warning text-warning me-1"
                                                :disabled="isLoading"
                                                title="View all Backups"
                                            >
                                                <i class="ti ti-eye"></i>
                                            </button>
                                            <Link
                                                :href="`/backups/edit-backup/${backup.id}`"
                                                class="btn btn-sm btn-light-info text-info me-1"
                                                title="Edit"
                                                :class="{ 'disabled': isLoading }"
                                            >
                                                <i class="ti ti-edit"></i>
                                            </Link>
                                            <button
                                                @click.prevent="handleDelete(backup.id)"
                                                class="btn btn-sm btn-light-danger text-danger me-1"
                                                :disabled="deleting === backup.id || isLoading"
                                                title="Delete"
                                            >
                                                <i class="ti ti-trash" v-if="deleting !== backup.id"></i>
                                                <span v-else class="spinner-border spinner-border-sm" role="status"></span>
                                            </button>

                                            <!-- New Restore Backup Button -->
                                            <button
                                                @click="openRestoreModal(backup)"
                                                class="btn btn-sm btn-light-success text-success"
                                                :disabled="isLoading"
                                                title="Restore Backup"
                                            >
                                                <i class="ti ti-arrow-back-up"></i>
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

    <!-- Restore Backup Modal -->
    <div v-if="showRestoreModal" class="modal fade show d-block" tabindex="-1" aria-modal="true" role="dialog">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Restore Backup</h5>
                    <button type="button" class="btn-close" @click="showRestoreModal = false"></button>
                </div>

                <div class="modal-body">
                    <label for="createdBackupSelect" class="form-label">Select Backup to Restore:</label>
                    <select
                        id="createdBackupSelect"
                        class="form-select"
                        v-model="selectedCreatedBackupId"
                        :disabled="restoreLoading"
                    >
                        <option value="" disabled>Select a backup</option>
                        <option v-for="cb in modalCreatedBackups" :key="cb.id" :value="cb.id">
                            {{ cb.file_name }} - {{ formatDate(cb.created_at) }}
                        </option>
                    </select>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-secondary" @click="showRestoreModal = false" :disabled="restoreLoading">Cancel</button>
                    <button class="btn btn-primary" @click="handleRestore" :disabled="restoreLoading">Restore</button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.full-page-loader {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.3);
    backdrop-filter: blur(1px);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 9999;
}

.loader-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: white;
    padding: 2rem;
    border-radius: 10px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    border: 1px solid rgba(0, 0, 0, 0.1);
}

.loader-content .spinner-border {
    width: 3rem;
    height: 3rem;
}

.loader-content p {
    color: #6c757d;
    font-weight: 500;
    font-size: 1rem;
    margin: 0;
}

/* Ensure buttons are properly disabled during loading */
.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.disabled {
    opacity: 0.6;
    pointer-events: none;
    cursor: not-allowed;
}

/* Modal overrides to display modal properly */
.modal.fade.show.d-block {
    background-color: rgba(0, 0, 0, 0.5);
}

/* Center modal vertically */
.modal-dialog-centered {
    display: flex;
    align-items: center;
    min-height: calc(100% - 1rem);
}
</style>