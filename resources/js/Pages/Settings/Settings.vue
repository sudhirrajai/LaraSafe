<script setup>
import MainLayout from '@/Layouts/MainLayout.vue'
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { Link } from '@inertiajs/vue3'
import Swal from 'sweetalert2'

defineOptions({
    layout: MainLayout
})

const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 2000,
    timerProgressBar: true,
})

const props = defineProps({
    savedSettings: {
        type: Object,
        default: () => ({})
    }
})

const tabs = ['S3', 'Backblaze', 'Wasabi']
const activeTab = ref('S3')
const loading = ref(false)
const saving = ref(false)

const settings = ref({
    s3: { access_key: '', secret_key: '', bucket: '', region: '' },
    b2: { key_id: '', app_key: '', bucket: '', endpoint: '' },
    wasabi: { access_key: '', secret_key: '', bucket: '', region: '' }
})

onMounted(async () => {
    await loadSettings()
})

const loadSettings = async () => {
    loading.value = true
    try {
        const response = await axios.get('/settings/settings')
        if (response.data) {
            if (response.data.s3) {
                settings.value.s3 = { ...settings.value.s3, ...response.data.s3 }
            }
            if (response.data.b2) {
                settings.value.b2 = { ...settings.value.b2, ...response.data.b2 }
            }
            if (response.data.wasabi) {
                settings.value.wasabi = { ...settings.value.wasabi, ...response.data.wasabi }
            }
        }
    } catch (error) {
        Toast.fire({
            icon: 'error',
            title: 'Failed to load settings'
        })
    } finally {
        loading.value = false
    }
}

const saveSettings = async (type) => {
    saving.value = true
    try {
        const response = await axios.post(`/settings/${type}`, settings.value[type])

        Toast.fire({
            icon: 'success',
            title: response.data.message || 'Settings saved successfully!'
        })
    } catch (error) {
        const errorMessage = error.response?.data?.message || 'Error saving settings. Please try again.'

        Toast.fire({
            icon: 'error',
            title: errorMessage
        })
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <div class="row p-2 p-md-4">
        <!-- Settings Header -->
        <div class="col-12 mb-3 mb-md-4">
            <div class="card bg-light-info hover-card">
                <div class="card-body text-center p-3 p-md-4">
                    <div class="text-info mb-2 mb-md-3">
                        <i class="ti ti-settings fs-1"></i>
                    </div>
                    <h4 class="fw-semibold mb-2">Storage Settings</h4>
                    <p class="mb-0 text-muted small-text">Configure your cloud storage integrations</p>
                </div>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="col-12">
            <div class="card">
                <div class="card-body text-center p-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3 text-muted">Loading settings...</p>
                </div>
            </div>
        </div>

        <!-- Tabs and Configuration -->
        <div v-else class="col-12">
            <div class="card w-100">
                <div class="card-body p-3 p-md-4">
                    <h5 class="card-title fw-semibold mb-3 mb-md-4">Storage Configuration</h5>
                    
                    <!-- Tabs - Responsive -->
                    <div class="tabs-container mb-3 mb-md-4 pb-2">
                        <button
                            v-for="tab in tabs"
                            :key="tab"
                            @click="activeTab = tab"
                            class="tab-button py-2 px-3 px-md-4 fw-semibold rounded-pill transition-all"
                            :class="{
                                'bg-primary text-white shadow-sm': activeTab === tab,
                                'bg-light-secondary text-secondary': activeTab !== tab
                            }"
                        >
                            {{ tab }}
                        </button>
                    </div>

                    <!-- S3 Configuration -->
                    <div v-if="activeTab === 'S3'" class="config-section">
                        <form @submit.prevent="saveSettings('s3')">
                            <div class="row g-3 g-md-4">
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-dark mb-2">Access Key</label>
                                    <input
                                        v-model="settings.s3.access_key"
                                        type="text"
                                        class="form-control border rounded p-2 p-md-3"
                                        :disabled="saving"
                                        required
                                    />
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-dark mb-2">Secret Key</label>
                                    <input
                                        v-model="settings.s3.secret_key"
                                        type="password"
                                        class="form-control border rounded p-2 p-md-3"
                                        :disabled="saving"
                                        required
                                    />
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-dark mb-2">Bucket</label>
                                    <input
                                        v-model="settings.s3.bucket"
                                        type="text"
                                        class="form-control border rounded p-2 p-md-3"
                                        :disabled="saving"
                                        required
                                    />
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-dark mb-2">Region</label>
                                    <input
                                        v-model="settings.s3.region"
                                        type="text"
                                        class="form-control border rounded p-2 p-md-3"
                                        :disabled="saving"
                                        required
                                    />
                                </div>
                                <div class="col-12 mt-3 mt-md-4">
                                    <div class="d-flex flex-column flex-sm-row gap-2">
                                        <button
                                            type="submit"
                                            class="btn btn-primary text-white py-2 px-4 px-md-6 rounded-pill"
                                            :disabled="saving"
                                        >
                                            <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
                                            <i v-else class="ti ti-check me-2"></i>
                                            {{ saving ? 'Saving...' : 'Save S3 Settings' }}
                                        </button>
                                        <Link
                                            href="/dashboard"
                                            class="btn btn-secondary text-white py-2 px-4 px-md-6 rounded-pill"
                                        >
                                            Cancel
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Backblaze Configuration -->
                    <div v-if="activeTab === 'Backblaze'" class="config-section">
                        <form @submit.prevent="saveSettings('b2')">
                            <div class="row g-3 g-md-4">
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-dark mb-2">Key ID</label>
                                    <input
                                        v-model="settings.b2.key_id"
                                        type="text"
                                        class="form-control border rounded p-2 p-md-3"
                                        :disabled="saving"
                                        required
                                    />
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-dark mb-2">Application Key</label>
                                    <input
                                        v-model="settings.b2.app_key"
                                        type="password"
                                        class="form-control border rounded p-2 p-md-3"
                                        :disabled="saving"
                                        required
                                    />
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-dark mb-2">Bucket</label>
                                    <input
                                        v-model="settings.b2.bucket"
                                        type="text"
                                        class="form-control border rounded p-2 p-md-3"
                                        :disabled="saving"
                                        required
                                    />
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-dark mb-2">Endpoint</label>
                                    <input
                                        v-model="settings.b2.endpoint"
                                        type="text"
                                        class="form-control border rounded p-2 p-md-3"
                                        :disabled="saving"
                                    />
                                </div>
                                <div class="col-12 mt-3 mt-md-4">
                                    <div class="d-flex flex-column flex-sm-row gap-2">
                                        <button
                                            type="submit"
                                            class="btn btn-primary text-white py-2 px-4 px-md-6 rounded-pill"
                                            :disabled="saving"
                                        >
                                            <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
                                            <i v-else class="ti ti-check me-2"></i>
                                            {{ saving ? 'Saving...' : 'Save Backblaze Settings' }}
                                        </button>
                                        <Link
                                            href="/dashboard"
                                            class="btn btn-secondary text-white py-2 px-4 px-md-6 rounded-pill"
                                        >
                                            Cancel
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Wasabi Configuration -->
                    <div v-if="activeTab === 'Wasabi'" class="config-section">
                        <form @submit.prevent="saveSettings('wasabi')">
                            <div class="row g-3 g-md-4">
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-dark mb-2">Access Key</label>
                                    <input
                                        v-model="settings.wasabi.access_key"
                                        type="text"
                                        class="form-control border rounded p-2 p-md-3"
                                        :disabled="saving"
                                        required
                                    />
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-dark mb-2">Secret Key</label>
                                    <input
                                        v-model="settings.wasabi.secret_key"
                                        type="password"
                                        class="form-control border rounded p-2 p-md-3"
                                        :disabled="saving"
                                        required
                                    />
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-dark mb-2">Bucket</label>
                                    <input
                                        v-model="settings.wasabi.bucket"
                                        type="text"
                                        class="form-control border rounded p-2 p-md-3"
                                        :disabled="saving"
                                        required
                                    />
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-dark mb-2">Region</label>
                                    <input
                                        v-model="settings.wasabi.region"
                                        type="text"
                                        class="form-control border rounded p-2 p-md-3"
                                        :disabled="saving"
                                    />
                                </div>
                                <div class="col-12 mt-3 mt-md-4">
                                    <div class="d-flex flex-column flex-sm-row gap-2">
                                        <button
                                            type="submit"
                                            class="btn btn-primary text-white py-2 px-4 px-md-6 rounded-pill"
                                            :disabled="saving"
                                        >
                                            <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
                                            <i v-else class="ti ti-check me-2"></i>
                                            {{ saving ? 'Saving...' : 'Save Wasabi Settings' }}
                                        </button>
                                        <Link
                                            href="/dashboard"
                                            class="btn btn-secondary text-white py-2 px-4 px-md-6 rounded-pill"
                                        >
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
    </div>
</template>

<style scoped>
/* Responsive tabs */
.tabs-container {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

@media (max-width: 575px) {
    .tabs-container {
        gap: 0.5rem;
    }
    
    .tab-button {
        flex: 1 1 auto;
        min-width: calc(33.333% - 0.5rem);
        font-size: 0.875rem;
    }
}

.tab-button {
    transition: all 0.2s ease-in-out;
    border: none;
    cursor: pointer;
}

.tab-button:hover:not(.bg-primary) {
    background-color: #e8f4ff !important;
    color: #2563eb !important;
}

/* Form controls */
.form-control {
    transition: border 0.2s ease-in-out;
    font-size: 1rem;
}

.form-control:focus {
    border-color: #2563eb;
    outline: none;
    box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.25);
}

.form-control:disabled {
    background-color: #f8f9fa;
    cursor: not-allowed;
}

.form-label {
    font-size: 0.95rem;
    font-weight: 500;
}

/* Card styles */
.hover-card {
    transition: transform 0.2s ease-in-out;
    cursor: pointer;
}

.hover-card:hover {
    transform: translateY(-2px);
}

/* Icon size */
.fs-1 {
    font-size: 2rem !important;
}

@media (max-width: 767px) {
    .fs-1 {
        font-size: 1.5rem !important;
    }
}

/* Button styles */
.rounded-pill {
    border-radius: 50rem;
}

.btn {
    font-size: 0.95rem;
    transition: all 0.2s ease-in-out;
}

.btn:disabled {
    cursor: not-allowed;
    opacity: 0.65;
}

@media (max-width: 575px) {
    .btn {
        font-size: 0.875rem;
        width: 100%;
    }
}

/* Small text for mobile */
.small-text {
    font-size: 0.9rem;
}

@media (max-width: 575px) {
    .small-text {
        font-size: 0.85rem;
    }
}

/* Config section */
.config-section {
    animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Validation styles */
.is-invalid {
    border-color: #dc3545;
}

.invalid-feedback {
    color: #dc3545;
    font-size: 0.875rem;
}
</style>