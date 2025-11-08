<script setup>
import MainLayout from '@/Layouts/MainLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'

const Swal = window.Swal

defineOptions({
    layout: MainLayout
})

defineProps({
    projects: {
        type: Array,
        default: () => [],
    }
})

const form = useForm({
    project_id: '',
    file_name: '',
    storage_disk: 'local',
    frequency: null,
    time: null,
    include_database: false,
    db_source: 'env',
    db_host: '',
    db_port: '3306',
    db_name: '',
    db_username: '',
    db_password: '',
    db_tables: 'all',
    selected_tables: [],
    auto_delete_enabled: false,
    auto_delete_after_days: 7,
})

const touched = ref({
    project_id: false,
    file_name: false,
    storage_disk: false,
    frequency: false,
    time: false,
    db_host: false,
    db_name: false,
    db_username: false,
    db_password: false,
})

const autoBackupEnabled = ref(false)
const showAdvancedDb = ref(false)

const errors = computed(() => {
    const baseErrors = {
        project_id: touched.value.project_id && !form.project_id ? 'Project is required' : '',
        file_name: touched.value.file_name && !form.file_name ? 'File name is required' : '',
        storage_disk: touched.value.storage_disk && !form.storage_disk ? 'Storage disk is required' : '',
        frequency: touched.value.frequency && autoBackupEnabled.value && !form.frequency ? 'Frequency is required' : '',
        time: touched.value.time && autoBackupEnabled.value && !form.time ? 'Backup time is required' : '',
    }

    if (form.include_database && form.db_source === 'custom') {
        return {
            ...baseErrors,
            db_host: touched.value.db_host && !form.db_host ? 'Database host is required' : '',
            db_name: touched.value.db_name && !form.db_name ? 'Database name is required' : '',
            db_username: touched.value.db_username && !form.db_username ? 'Database username is required' : '',
        }
    }

    return baseErrors
})

const isValid = computed(() => {
    return !Object.values(errors.value).some(error => error !== '')
})

const dbSourceInfo = computed(() => {
    switch (form.db_source) {
        case 'env':
            return 'Will use database credentials from project\'s .env file'
        case 'project_config':
            return 'Will use database credentials stored in project configuration'
        case 'custom':
            return 'Use custom database credentials for this backup'
        default:
            return ''
    }
})

const handleSubmit = () => {
    Object.keys(touched.value).forEach(key => touched.value[key] = true)

    if (!isValid.value) {
        const errorList = Object.values(errors.value)
            .filter(error => error !== '')
            .map(error => `<li>${error}</li>`)
            .join("")

        Swal.fire({
            icon: "error",
            title: "Validation Error",
            html: `<ul style="text-align:left;">${errorList}</ul>`,
        })
        return
    }

    const data = {
        project_id: form.project_id,
        file_name: form.file_name,
        storage_disk: form.storage_disk,
        include_database: form.include_database,
        ...(autoBackupEnabled.value && { frequency: form.frequency, time: form.time }),
    }

    if (form.include_database) {
        data.db_source = form.db_source
        data.db_tables = form.db_tables

        if (form.db_source === 'custom') {
            data.db_host = form.db_host
            data.db_port = form.db_port
            data.db_name = form.db_name
            data.db_username = form.db_username
            data.db_password = form.db_password
        }

        if (form.db_tables === 'selected' && form.selected_tables.length > 0) {
            data.selected_tables = form.selected_tables
        }
    }

    if (form.auto_delete_enabled) {
        data.auto_delete_enabled = true
        data.auto_delete_after_days = form.auto_delete_after_days
    }

    form.post('/backups/store-backup', {
        data: data,
        onSuccess: (page) => {
            const successMessage = page.props.flash?.status
            Swal.fire({
                icon: "success",
                title: "Success",
                text: successMessage || "Backup created successfully!",
            }).then(() => {
                router.visit('/backups/manage-backups')
            })
            form.reset()
            Object.keys(touched.value).forEach(key => touched.value[key] = false)
        },
        onError: (serverErrors) => {
            const errorList = Object.values(serverErrors)
                .flat()
                .map(error => `<li>${error}</li>`)
                .join("")
            Swal.fire({
                icon: "error",
                title: "Validation Error",
                html: errorList ? `<ul style="text-align:left;">${errorList}</ul>` : 'An unexpected error occurred.',
            })
        }
    })
}

const testDatabaseConnection = () => {
    if (form.db_source !== 'custom') return

    const testData = {
        db_host: form.db_host === 'localhost' ? '127.0.0.1' : form.db_host,
        db_port: form.db_port,
        db_name: form.db_name,
        db_username: form.db_username,
        db_password: form.db_password,
    }

    axios.post('/backups/test-db-connection', testData)
        .then(response => {
            if (response.data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Connection Successful',
                    text: 'Database connection established successfully!'
                })
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Failed',
                    text: response.data.message || 'Failed to connect to database'
                })
            }
        })
        .catch(error => {
            Swal.fire({
                icon: 'error',
                title: 'Connection Failed',
                text: error.response?.data?.message || 'Failed to connect to database'
            })
        })
}
</script>

<template>
    <div class="card">
        <div class="card-body">
            <h5 class="card-title fw-semibold mb-4">Create Backup</h5>
            <div class="card">
                <div class="card-body">
                    <form @submit.prevent="handleSubmit">
                        <!-- Project Selection -->
                        <div class="mb-3">
                            <label for="projectSelect" class="form-label">Select Project</label>
                            <select class="form-select" id="projectSelect" v-model="form.project_id"
                                @blur="touched.project_id = true"
                                :class="{ 'is-invalid': errors.project_id && touched.project_id }">
                                <option value="" disabled>Select a project</option>
                                <option v-for="project in projects" :key="project.id" :value="project.id">
                                    {{ project.name }}
                                </option>
                            </select>
                            <div v-if="errors.project_id && touched.project_id" class="invalid-feedback">
                                {{ errors.project_id }}
                            </div>
                        </div>

                        <!-- File Name -->
                        <div class="mb-3">
                            <label for="backupFileName" class="form-label">File Name</label>
                            <input type="text" class="form-control" id="backupFileName" v-model="form.file_name"
                                @blur="touched.file_name = true"
                                :class="{ 'is-invalid': errors.file_name && touched.file_name }">
                            <div v-if="errors.file_name && touched.file_name" class="invalid-feedback">
                                {{ errors.file_name }}
                            </div>
                        </div>

                        <!-- Storage Location -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">Where to Store Backup</label>

                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" id="storeLocal" value="local"
                                    v-model="form.storage_disk">
                                <label class="form-check-label" for="storeLocal">
                                    <strong>Local Storage</strong>
                                    <small class="d-block text-muted">Store backups on the same server (default)</small>
                                </label>
                            </div>

                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" id="storeS3" value="s3"
                                    v-model="form.storage_disk">
                                <label class="form-check-label" for="storeS3">
                                    <strong>Amazon S3</strong>
                                    <small class="d-block text-muted">Store securely on Amazon S3 bucket</small>
                                </label>
                            </div>

                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" id="storeB2" value="b2"
                                    v-model="form.storage_disk">
                                <label class="form-check-label" for="storeB2">
                                    <strong>Backblaze B2</strong>
                                    <small class="d-block text-muted">Low-cost and reliable object storage</small>
                                </label>
                            </div>

                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" id="storeWasabi" value="wasabi"
                                    v-model="form.storage_disk">
                                <label class="form-check-label" for="storeWasabi">
                                    <strong>Wasabi Cloud</strong>
                                    <small class="d-block text-muted">Fast S3-compatible storage service</small>
                                </label>
                            </div>

                            <div v-if="errors.storage_disk && touched.storage_disk" class="invalid-feedback d-block">
                                {{ errors.storage_disk }}
                            </div>

                            <div v-if="form.storage_disk" class="alert alert-info mt-2">
                                <i class="ti ti-info-circle me-2"></i>
                                <span v-if="form.storage_disk === 'local'">Backups will be stored locally on the same server.</span>
                                <span v-else-if="form.storage_disk === 's3'">Backups will be uploaded to Amazon S3. Ensure your keys are configured in Cloud Settings.</span>
                                <span v-else-if="form.storage_disk === 'b2'">Backups will be uploaded to Backblaze B2 cloud storage.</span>
                                <span v-else-if="form.storage_disk === 'wasabi'">Backups will be uploaded to Wasabi cloud storage.</span>
                            </div>
                        </div>

                        <!-- Database Backup Section -->
                        <div class="mb-4">
                            <div class="card border-info">
                                <div class="card-header bg-light">
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="include_database" :value="0" />
                                        <input type="checkbox" class="form-check-input" id="includeDatabaseToggle"
                                            v-model="form.include_database">
                                        <label class="form-check-label fw-bold" for="includeDatabaseToggle">
                                            <i class="ti ti-database me-2"></i>
                                            Include Database Backup
                                        </label>
                                    </div>
                                </div>

                                <div v-if="form.include_database" class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label">Database Credentials Source</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="dbSource"
                                                id="dbSourceEnv" value="env" v-model="form.db_source">
                                            <label class="form-check-label" for="dbSourceEnv">
                                                <strong>Use Project's .env File</strong>
                                                <small class="d-block text-muted">Automatically read from project's environment file</small>
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="dbSource"
                                                id="dbSourceCustom" value="custom" v-model="form.db_source">
                                            <label class="form-check-label" for="dbSourceCustom">
                                                <strong>Custom Database Credentials</strong>
                                                <small class="d-block text-muted">Specify different database credentials</small>
                                            </label>
                                        </div>
                                        <div class="alert alert-info mt-2">
                                            <i class="ti ti-info-circle me-2"></i>
                                            {{ dbSourceInfo }}
                                        </div>
                                    </div>

                                    <div v-if="form.db_source === 'custom'" class="border rounded p-3 bg-light mb-3">
                                        <h6 class="mb-3">Database Connection Details</h6>
                                        <div class="row">
                                            <div class="col-md-8 mb-3">
                                                <label for="dbHost" class="form-label">Host</label>
                                                <input type="text" class="form-control" id="dbHost"
                                                    v-model="form.db_host" placeholder="localhost"
                                                    @blur="touched.db_host = true"
                                                    :class="{ 'is-invalid': errors.db_host && touched.db_host }">
                                                <div v-if="errors.db_host && touched.db_host" class="invalid-feedback">
                                                    {{ errors.db_host }}
                                                </div>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <label for="dbPort" class="form-label">Port</label>
                                                <input type="number" class="form-control" id="dbPort"
                                                    v-model="form.db_port" placeholder="3306">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="dbName" class="form-label">Database Name</label>
                                                <input type="text" class="form-control" id="dbName"
                                                    v-model="form.db_name" @blur="touched.db_name = true"
                                                    :class="{ 'is-invalid': errors.db_name && touched.db_name }">
                                                <div v-if="errors.db_name && touched.db_name" class="invalid-feedback">
                                                    {{ errors.db_name }}
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="dbUsername" class="form-label">Username</label>
                                                <input type="text" class="form-control" id="dbUsername"
                                                    v-model="form.db_username" @blur="touched.db_username = true"
                                                    :class="{ 'is-invalid': errors.db_username && touched.db_username }">
                                                <div v-if="errors.db_username && touched.db_username" class="invalid-feedback">
                                                    {{ errors.db_username }}
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label for="dbPassword" class="form-label">Password</label>
                                            <input type="password" class="form-control" id="dbPassword"
                                                v-model="form.db_password" @blur="touched.db_password = true"
                                                :class="{ 'is-invalid': errors.db_password && touched.db_password }">
                                            <div v-if="errors.db_password && touched.db_password" class="invalid-feedback">
                                                {{ errors.db_password }}
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-outline-info btn-sm"
                                            @click="testDatabaseConnection">
                                            <i class="ti ti-plug-connected me-1"></i>
                                            Test Connection
                                        </button>
                                    </div>

                                    <div class="mb-3">
                                        <button type="button" class="btn btn-link p-0 text-decoration-none"
                                            @click="showAdvancedDb = !showAdvancedDb">
                                            <i :class="showAdvancedDb ? 'ti ti-chevron-down' : 'ti ti-chevron-right'"></i>
                                            Advanced Database Options
                                        </button>

                                        <div v-if="showAdvancedDb" class="mt-3 border rounded p-3 bg-light">
                                            <div class="mb-3">
                                                <label class="form-label">Tables to Backup</label>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="dbTables"
                                                        id="tablesAll" value="all" v-model="form.db_tables">
                                                    <label class="form-check-label" for="tablesAll">
                                                        All Tables (Complete Database)
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="dbTables"
                                                        id="tablesSelected" value="selected" v-model="form.db_tables">
                                                    <label class="form-check-label" for="tablesSelected">
                                                        Selected Tables Only
                                                    </label>
                                                </div>

                                                <div v-if="form.db_tables === 'selected'" class="mt-2">
                                                    <textarea class="form-control" rows="3"
                                                        placeholder="Enter table names separated by commas (e.g., users, posts, categories)"
                                                        v-model="form.selected_tables"></textarea>
                                                    <small class="form-text text-muted">
                                                        Specify which tables to include in the backup
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Auto Delete Section -->
                        <div class="mb-4">
                            <div class="card border-warning">
                                <div class="card-header bg-light">
                                    <div class="form-check form-switch">
                                        <input type="checkbox" class="form-check-input" id="autoDeleteToggle"
                                            v-model="form.auto_delete_enabled">
                                        <label class="form-check-label fw-bold" for="autoDeleteToggle">
                                            <i class="ti ti-trash-x me-2"></i>
                                            Auto-Delete Old Backups
                                        </label>
                                    </div>
                                </div>

                                <div v-if="form.auto_delete_enabled" class="card-body">
                                    <div class="alert alert-warning">
                                        <i class="ti ti-alert-triangle me-2"></i>
                                        <strong>Warning:</strong> Backups older than the specified period will be automatically deleted to save storage space.
                                    </div>

                                    <div class="mb-3">
                                        <label for="autoDeleteDays" class="form-label">Delete Backups Older Than</label>
                                        <select class="form-select" id="autoDeleteDays" v-model.number="form.auto_delete_after_days">
                                            <option :value="7">7 days (1 week)</option>
                                            <option :value="14">14 days (2 weeks)</option>
                                            <option :value="30">30 days (1 month)</option>
                                            <option :value="60">60 days (2 months)</option>
                                            <option :value="90">90 days (3 months)</option>
                                            <option :value="180">180 days (6 months)</option>
                                            <option :value="365">365 days (1 year)</option>
                                        </select>
                                        <small class="form-text text-muted">
                                            Backups created more than {{ form.auto_delete_after_days }} days ago will be automatically removed
                                        </small>
                                    </div>

                                    <div class="alert alert-info">
                                        <i class="ti ti-info-circle me-2"></i>
                                        <strong>How it works:</strong>
                                        <ul class="mb-0 mt-2">
                                            <li>The system checks for old backups daily at 2 AM</li>
                                            <li>Only backups older than your specified period are deleted</li>
                                            <li>Both the backup files and database records are removed</li>
                                            <li>You can change this setting anytime</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Auto Backup Toggle and Fields -->
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="autoBackupToggle"
                                    v-model="autoBackupEnabled" @change="touched.frequency = true; touched.time = true">
                                <label class="form-check-label" for="autoBackupToggle">Enable Auto Backups</label>
                            </div>
                            <div v-if="autoBackupEnabled" class="mt-3">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="frequency" class="form-label">Frequency</label>
                                        <select class="form-select" id="frequency" v-model="form.frequency"
                                            @blur="touched.frequency = true"
                                            :class="{ 'is-invalid': errors.frequency && touched.frequency }">
                                            <option value="daily">Daily</option>
                                            <option value="weekly">Weekly</option>
                                            <option value="monthly">Monthly</option>
                                        </select>
                                        <div v-if="errors.frequency && touched.frequency" class="invalid-feedback">
                                            {{ errors.frequency }}
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="backupTime" class="form-label">Backup Time</label>
                                        <input type="time" class="form-control" id="backupTime" v-model="form.time"
                                            @blur="touched.time = true"
                                            :class="{ 'is-invalid': errors.time && touched.time }">
                                        <div v-if="errors.time && touched.time" class="invalid-feedback">
                                            {{ errors.time }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary" :disabled="form.processing">
                                <i class="ti ti-device-floppy me-1"></i>
                                {{ form.processing ? 'Creating...' : 'Create Backup' }}
                            </button>
                            <button type="button" class="btn btn-secondary"
                                @click="router.visit('/backups/manage-backups')">
                                <i class="ti ti-x me-1"></i>
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.is-invalid {
    border-color: #dc3545;
}

.invalid-feedback {
    color: #dc3545;
    font-size: 0.875rem;
}

.form-text {
    color: #6c757d;
    font-size: 0.875rem;
}

.card-header {
    border-bottom: 1px solid rgba(0, 0, 0, .125);
}

.form-check-label strong {
    color: #333;
}

.btn-link {
    font-size: 0.9rem;
}
</style>