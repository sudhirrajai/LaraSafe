<script setup>
import MainLayout from '@/Layouts/MainLayout.vue'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'

defineOptions({
    layout: MainLayout
})

const props = defineProps({
    stats: Object,
    recentBackups: Array,
    upcomingBackups: Array,
    projectStats: Array,
    storageUsage: Array,
    backupTrends: Object,
})

// Format bytes to human readable
const formatBytes = (bytes) => {
    if (!bytes && bytes !== 0) return '0 B'
    const k = 1024
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

// Format date
const formatDate = (dateStr) => {
    if (!dateStr) return 'N/A'
    const date = new Date(dateStr)
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    })
}

// Get status badge class
const getStatusBadge = (status) => {
    const badges = {
        success: 'bg-light-success text-success',
        failed: 'bg-light-danger text-danger',
        pending: 'bg-light-warning text-warning'
    }
    return badges[status] || 'bg-light-secondary text-secondary'
}

// Get time until next backup
const getTimeUntil = (dateStr) => {
    const date = new Date(dateStr)
    const now = new Date()
    const diff = date - now

    if (diff < 0) return 'Overdue'

    const hours = Math.floor(diff / (1000 * 60 * 60))
    const days = Math.floor(hours / 24)

    if (days > 0) return `${days}d ${hours % 24}h`
    return `${hours}h`
}
</script>

<template>
    <!-- Stats Cards Row -->
    <div class="row">
        <div class="col-lg-3 col-md-6">
            <div class="card overflow-hidden">
                <div class="card-body p-4">
                    <h5 class="card-title mb-10 fw-semibold">Total Projects</h5>
                    <div class="row align-items-center">
                        <div class="col-8">
                            <h4 class="fw-semibold mb-3">{{ stats.totalProjects }}</h4>
                            <div class="d-flex align-items-center mb-2">
                                <span
                                    class="me-1 rounded-circle bg-light-info round-20 d-flex align-items-center justify-content-center">
                                    <i class="ti ti-folder text-info"></i>
                                </span>
                                <p class="text-dark me-1 fs-3 mb-0">Active</p>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="d-flex justify-content-end">
                                <div
                                    class="text-white bg-info rounded-circle p-6 d-flex align-items-center justify-content-center">
                                    <i class="ti ti-folder fs-6"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="card overflow-hidden">
                <div class="card-body p-4">
                    <h5 class="card-title mb-10 fw-semibold">Total Backups</h5>
                    <div class="row align-items-center">
                        <div class="col-8">
                            <h4 class="fw-semibold mb-3">{{ stats.totalBackups }}</h4>
                            <div class="d-flex align-items-center mb-2">
                                <span
                                    class="me-1 rounded-circle bg-light-primary round-20 d-flex align-items-center justify-content-center">
                                    <i class="ti ti-database text-primary"></i>
                                </span>
                                <p class="text-dark me-1 fs-3 mb-0">{{ stats.todayBackups }} today</p>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="d-flex justify-content-end">
                                <div
                                    class="text-white bg-primary rounded-circle p-6 d-flex align-items-center justify-content-center">
                                    <i class="ti ti-database fs-6"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="card overflow-hidden">
                <div class="card-body p-4">
                    <h5 class="card-title mb-10 fw-semibold">Storage Used</h5>
                    <div class="row align-items-center">
                        <div class="col-8">
                            <h4 class="fw-semibold mb-3">{{ formatBytes(stats.totalSize) ?? 0 }}</h4>
                            <div class="d-flex align-items-center mb-2">
                                <span
                                    class="me-1 rounded-circle bg-light-warning round-20 d-flex align-items-center justify-content-center">
                                    <i class="ti ti-server text-warning"></i>
                                </span>
                                <p class="text-dark me-1 fs-3 mb-0">{{ stats.weekBackups }} this week</p>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="d-flex justify-content-end">
                                <div
                                    class="text-white bg-warning rounded-circle p-6 d-flex align-items-center justify-content-center">
                                    <i class="ti ti-server fs-6"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="card overflow-hidden">
                <div class="card-body p-4">
                    <h5 class="card-title mb-10 fw-semibold">Success Rate</h5>
                    <div class="row align-items-center">
                        <div class="col-8">
                            <h4 class="fw-semibold mb-3">{{ stats.successRate }}%</h4>
                            <div class="d-flex align-items-center mb-2">
                                <span
                                    class="me-1 rounded-circle bg-light-success round-20 d-flex align-items-center justify-content-center">
                                    <i class="ti ti-check text-success"></i>
                                </span>
                                <p class="text-dark me-1 fs-3 mb-0">{{ stats.successfulBackups }} successful</p>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="d-flex justify-content-end">
                                <div
                                    class="text-white bg-success rounded-circle p-6 d-flex align-items-center justify-content-center">
                                    <i class="ti ti-check fs-6"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Row -->
    <div class="row">
        <!-- Upcoming Backups Schedule -->
        <div class="col-lg-4 d-flex align-items-stretch">
            <div class="card w-100">
                <div class="card-body p-4">
                    <div class="d-flex mb-4 justify-content-between align-items-center">
                        <h5 class="card-title fw-semibold">Upcoming Backups</h5>
                        <Link href="/backups/manage-backups" class="btn btn-sm btn-outline-primary">View All</Link>
                    </div>

                    <ul class="timeline-widget mb-0 position-relative mb-n5">
                        <li v-for="backup in upcomingBackups.slice(0, 6)" :key="backup.id"
                            class="timeline-item d-flex position-relative overflow-hidden">
                            <div class="timeline-time text-dark flex-shrink-0 text-end">
                                {{ getTimeUntil(backup.next_backup_at) }}
                            </div>
                            <div class="timeline-badge-wrap d-flex flex-column align-items-center">
                                <span class="timeline-badge border-2 border border-primary flex-shrink-0 my-2"></span>
                                <span class="timeline-badge-border d-block flex-shrink-0"></span>
                            </div>
                            <div class="timeline-desc fs-3 text-dark mt-n1">
                                <div class="fw-semibold">{{ backup.project_name }}</div>
                                <small class="text-muted">{{ backup.frequency }} at {{ backup.backup_time }}</small>
                            </div>
                        </li>

                        <li v-if="!upcomingBackups.length"
                            class="timeline-item d-flex position-relative overflow-hidden">
                            <div class="timeline-desc fs-3 text-muted text-center w-100 py-3">
                                <i class="ti ti-calendar-off fs-2 d-block mb-2"></i>
                                No scheduled backups
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Recent Backups -->
        <div class="col-lg-8 d-flex align-items-stretch">
            <div class="card w-100">
                <div class="card-body p-4">
                    <div class="d-flex mb-4 justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold">Recent Backups</h5>
                        <Link href="/backups/manage-backups" class="btn btn-sm btn-outline-primary">View All</Link>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-borderless align-middle text-nowrap">
                            <thead>
                                <tr>
                                    <th scope="col">Project</th>
                                    <th scope="col">Size</th>
                                    <th scope="col">Created</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!recentBackups.length">
                                    <td colspan="5" class="text-center py-4">
                                        <i class="ti ti-database-off fs-2 text-muted"></i>
                                        <p class="text-muted mb-0">No backups found</p>
                                    </td>
                                </tr>

                                <tr v-for="backup in recentBackups.slice(0, 8)" :key="backup.id">
                                    <td>
                                        <div>
                                            <h6 class="mb-1 fw-bolder">{{ backup.project_name }}</h6>
                                            <p class="fs-3 mb-0 text-muted">{{ backup.file_name }}</p>
                                        </div>
                                    </td>
                                    <td>
                                        <p class="fs-3 fw-normal mb-0">{{ formatBytes(backup.size) }}</p>
                                    </td>
                                    <td>
                                        <p class="fs-3 fw-normal mb-0">{{ formatDate(backup.created_at) }}</p>
                                    </td>
                                    <td>
                                        <span
                                            :class="`badge rounded-pill px-3 py-2 fs-3 ${getStatusBadge(backup.status)}`">
                                            {{ backup.status }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <Link :href="`/backups/download/${backup.id}`"
                                                class="btn btn-sm btn-light-primary text-primary" title="Download">
                                            <i class="ti ti-download"></i>
                                            </Link>
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

    <!-- Project Statistics Row -->
    <div class="row">
        <div class="col-lg-8 d-flex align-items-stretch">
            <div class="card w-100">
                <div class="card-body p-4">
                    <div class="d-flex mb-4 justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold">Project Statistics</h5>
                        <Link href="/projects/manage-projects" class="btn btn-sm btn-outline-primary">Manage Projects
                        </Link>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-borderless align-middle text-nowrap">
                            <thead>
                                <tr>
                                    <th scope="col">Project</th>
                                    <th scope="col">Path</th>
                                    <th scope="col">Backups</th>
                                    <th scope="col">Total Size</th>
                                    <th scope="col">Last Backup</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!projectStats.length">
                                    <td colspan="5" class="text-center py-4">
                                        <i class="ti ti-folder-off fs-2 text-muted"></i>
                                        <p class="text-muted mb-0">No projects found</p>
                                    </td>
                                </tr>

                                <tr v-for="project in projectStats" :key="project.id">
                                    <td>
                                        <h6 class="mb-1 fw-bolder">{{ project.name }}</h6>
                                    </td>
                                    <td>
                                        <p class="fs-3 fw-normal mb-0 text-muted">{{ project.path }}</p>
                                    </td>
                                    <td>
                                        <span class="badge bg-light-info text-info px-3 py-2 fs-3">
                                            {{ project.backups_count }}
                                        </span>
                                    </td>
                                    <td>
                                        <p class="fs-3 fw-normal mb-0">{{ formatBytes(project.total_size) }}</p>
                                    </td>
                                    <td>
                                        <p class="fs-3 fw-normal mb-0">{{ formatDate(project.last_backup) }}</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Storage Usage -->
        <div class="col-lg-4 d-flex align-items-stretch">
            <div class="card w-100">
                <div class="card-body p-4">
                    <h5 class="card-title fw-semibold mb-4">Storage Usage by Project</h5>

                    <div v-if="!storageUsage.length" class="text-center py-4">
                        <i class="ti ti-chart-pie-off fs-2 text-muted"></i>
                        <p class="text-muted mb-0">No storage data</p>
                    </div>

                    <div v-for="(usage, index) in storageUsage.slice(0, 6)" :key="index" class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fs-3 fw-medium">{{ usage.project_name }}</span>
                            <span class="fs-3 text-muted">{{ formatBytes(usage.total_size) }}</span>
                        </div>
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar"
                                :class="`bg-${['primary', 'success', 'info', 'warning', 'danger', 'secondary'][index % 6]}`"
                                :style="`width: ${(usage.total_size / Math.max(...storageUsage.map(s => s.total_size))) * 100}%`">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions Row -->
    <div class="row">
        <div class="col-sm-6 col-xl-3">
            <Link href="/backups/create-backup" class="text-decoration-none">
            <div class="card bg-light-primary hover-card">
                <div class="card-body text-center p-4">
                    <div class="text-primary mb-3">
                        <i class="ti ti-plus fs-1"></i>
                    </div>
                    <h6 class="fw-semibold">Create New Backup</h6>
                    <p class="mb-0 text-muted">Set up a new backup job</p>
                </div>
            </div>
            </Link>
        </div>

        <div class="col-sm-6 col-xl-3">
            <Link href="/backups/manage-backups" class="text-decoration-none">
            <div class="card bg-light-success hover-card">
                <div class="card-body text-center p-4">
                    <div class="text-success mb-3">
                        <i class="ti ti-list fs-1"></i>
                    </div>
                    <h6 class="fw-semibold">Manage Backups</h6>
                    <p class="mb-0 text-muted">View and manage all backups</p>
                </div>
            </div>
            </Link>
        </div>

        <div class="col-sm-6 col-xl-3">
            <Link href="/projects/manage-projects" class="text-decoration-none">
            <div class="card bg-light-info hover-card">
                <div class="card-body text-center p-4">
                    <div class="text-info mb-3">
                        <i class="ti ti-folder fs-1"></i>
                    </div>
                    <h6 class="fw-semibold">Manage Projects</h6>
                    <p class="mb-0 text-muted">Add and configure projects</p>
                </div>
            </div>
            </Link>
        </div>

        <div class="col-sm-6 col-xl-3">
            <Link href="/settings" class="text-decoration-none">
            <div class="card bg-light-warning hover-card">
                <div class="card-body text-center p-4">
                    <div class="text-warning mb-3">
                        <i class="ti ti-settings fs-1"></i>
                    </div>
                    <h6 class="fw-semibold">Settings</h6>
                    <p class="mb-0 text-muted">Configure backup settings</p>
                </div>
            </div>
            </Link>
        </div>
    </div>
</template>

<style scoped>
.hover-card {
    transition: transform 0.2s ease-in-out;
    cursor: pointer;
}

.hover-card:hover {
    transform: translateY(-2px);
}

.timeline-widget .timeline-item .timeline-time {
    min-width: 60px;
    font-size: 0.875rem;
}

.fs-1 {
    font-size: 2rem !important;
}
</style>