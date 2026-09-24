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
const downloading = ref(null)

// Computed property to check if any operation is in progress
const isLoading = computed(() => {
    return deleting.value !== null || downloading.value !== null
})

const handleDelete = (backupId) => {
    Swal.fire({
        title: 'Are you sure you want to delete this backup?',
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
            router.delete(`/backups/delete-created-backup/${backupId}`, {
                onSuccess: () => {
                    Swal.fire('Deleted!', 'Backup has been deleted.', 'success')
                    deleting.value = null
                },
                onError: (errors) => {
                    console.error('Delete error:', errors)
                    Swal.fire('Error!', 'Failed to delete the backup.', 'error')
                    deleting.value = null
                },
            })
        }
    })
}

const handleDownload = (backupId) => {
    downloading.value = backupId
    
    // Create a temporary link for download
    const downloadUrl = `/backups/download/${backupId}`
    const link = document.createElement('a')
    link.href = downloadUrl
    link.style.display = 'none'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    
    // Reset loading state after a short delay
    setTimeout(() => {
        downloading.value = null
    }, 2000)
}

// Alternative download method using router (if you prefer)
const handleDownloadWithRouter = (backupId) => {
    downloading.value = backupId
    router.get(`/backups/download/${backupId}`, {}, {
        onSuccess: () => {
            downloading.value = null
        },
        onError: (errors) => {
            console.error('Download error:', errors)
            Swal.fire('Error!', 'Failed to download the backup.', 'error')
            downloading.value = null
        },
        onFinish: () => {
            // Always reset loading state
            downloading.value = null
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

// Method to get file status indicator
const getFileStatus = (backup) => {
    if (backup.expires_at && new Date(backup.expires_at) < new Date()) {
        return { status: 'expired', color: 'danger', text: 'Expired' }
    }
    return { status: 'active', color: 'success', text: 'Active' }
}
</script>

<template>
    <!-- Full Page Loader -->
    <div v-if="isLoading" class="full-page-loader">
        <div class="loader-content">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 mb-0 text-center">
                {{ deleting ? 'Deleting backup...' : 'Preparing download...' }}
            </p>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card w-100">
                <div class="card-body p-4">
                    <div class="d-flex mb-4 justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold">Individual Backups</h5>
                        <Link href="/backups/manage-backups" class="btn btn-secondary">Back to Main Backups</Link>
                    </div>
                    
                    <!-- Summary Stats -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h6 class="card-title">Total Backups</h6>
                                    <h4 class="text-primary">{{ backups.length }}</h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h6 class="card-title">Total Size</h6>
                                    <h4 class="text-info">
                                        {{ formatSize(backups.reduce((sum, backup) => sum + (backup.size || 0), 0)) }}
                                    </h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">Backup File Name</th>
                                    <th scope="col">Size</th>
                                    <th scope="col">Created At</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!backups.length">
                                    <td colspan="5" class="text-center py-4">
                                        <i class="ti ti-folder-x fs-2 text-muted"></i>
                                        <p class="text-muted mb-0">No backups found.</p>
                                    </td>
                                </tr>
                                <tr v-for="backup in backups" :key="backup.id">
                                    <td>
                                        <div>
                                            <strong>{{ backup.file_name }}</strong>
                                            <br>
                                            <small class="text-muted">{{ backup.backup?.project?.name || 'Unknown Project' }}</small>
                                        </div>
                                    </td>
                                    <td>{{ formatSize(backup.size) }}</td>
                                    <td>{{ formatDate(backup.created_at) }}</td>
                                    <td>
                                        <span 
                                            :class="`badge bg-${getFileStatus(backup).color}`"
                                        >
                                            {{ getFileStatus(backup).text }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex">
                                            <button
                                                @click="handleDownload(backup.id)"
                                                class="btn btn-sm btn-light-success text-success me-1"
                                                :disabled="downloading === backup.id || isLoading"
                                                title="Download"
                                            >
                                                <i class="ti ti-download" v-if="downloading !== backup.id"></i>
                                                <span v-else class="spinner-border spinner-border-sm" role="status"></span>
                                            </button>
                                            <button
                                                @click.prevent="handleDelete(backup.id)"
                                                class="btn btn-sm btn-light-danger text-danger"
                                                :disabled="deleting === backup.id || isLoading"
                                                title="Delete"
                                            >
                                                <i class="ti ti-trash" v-if="deleting !== backup.id"></i>
                                                <span v-else class="spinner-border spinner-border-sm" role="status"></span>
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
    background: rgba(255, 255, 255, 0.85);
    padding: 2rem;
    border-radius: 10px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    border: 1px solid rgba(0, 0, 0, 0.1);
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
</style>