<script setup>
import MainLayout from '@/Layouts/MainLayout.vue'
import { ref } from 'vue'
import axios from 'axios'
import { Link } from '@inertiajs/vue3'

defineOptions({
    layout: MainLayout
})

const tabs = ['S3', 'Backblaze', 'Wasabi']
const activeTab = ref('S3')

const settings = ref({
    s3: { access_key: '', secret_key: '', bucket: '', region: '' },
    b2: { key_id: '', app_key: '', bucket: '', endpoint: '' },
    wasabi: { access_key: '', secret_key: '', bucket: '', region: '' }
})

const saveSettings = async (type) => {
    try {
        const response = await axios.post(`/api/settings/${type}`, settings.value[type])
        alert(response.data.message || `${type.toUpperCase()} settings saved successfully!`)
    } catch (error) {
        console.error(error)
        alert('Error saving settings. Please try again.')
    }
}
</script>

<template>
    <div class="row p-4">
        <!-- Settings Header -->
        <div class="col-12 mb-4">
            <div class="card bg-light-info hover-card">
                <div class="card-body text-center p-4">
                    <div class="text-info mb-3">
                        <i class="ti ti-settings fs-1"></i>
                    </div>
                    <h4 class="fw-semibold">Storage Settings</h4>
                    <p class="mb-0 text-muted">Configure your cloud storage integrations</p>
                </div>
            </div>
        </div>

        <!-- Tabs and Configuration -->
        <div class="col-12">
            <div class="card w-100">
                <div class="card-body p-4">
                    <h5 class="card-title fw-semibold mb-4">Storage Configuration</h5>
                    <!-- Tabs -->
                    <div class="d-flex mb-4 pb-2 gap-3">
                        <button
                            v-for="tab in tabs"
                            :key="tab"
                            @click="activeTab = tab"
                            class="py-2 px-4 fs-4 fw-semibold rounded-pill transition-all duration-200"
                            :class="{
                                'bg-primary text-white shadow-sm': activeTab === tab,
                                'bg-light-secondary text-secondary hover:bg-light-info hover:text-info': activeTab !== tab
                            }"
                        >
                            {{ tab }}
                        </button>
                    </div>

                    <!-- S3 Configuration -->
                    <div v-if="activeTab === 'S3'" class="row">
                        <div class="col-12">
                            <form @submit.prevent="saveSettings('s3')">
                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <label class="fs-3 text-dark mb-2">Access Key</label>
                                        <input
                                            v-model="settings.s3.access_key"
                                            type="text"
                                            class="form-control border rounded p-3 fs-3"
                                            required
                                        />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="fs-3 text-dark mb-2">Secret Key</label>
                                        <input
                                            v-model="settings.s3.secret_key"
                                            type="password"
                                            class="form-control border rounded p-3 fs-3"
                                            required
                                        />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="fs-3 text-dark mb-2">Bucket</label>
                                        <input
                                            v-model="settings.s3.bucket"
                                            type="text"
                                            class="form-control border rounded p-3 fs-3"
                                            required
                                        />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="fs-3 text-dark mb-2">Region</label>
                                        <input
                                            v-model="settings.s3.region"
                                            type="text"
                                            class="form-control border rounded p-3 fs-3"
                                            required
                                        />
                                    </div>
                                    <div class="col-12 mt-4">
                                        <button
                                            type="submit"
                                            class="btn btn-primary text-white py-2 px-6 rounded-pill fs-3"
                                        >
                                            <i class="ti ti-check me-2"></i> Save S3 Settings
                                        </button>
                                        <Link
                                            href="/dashboard"
                                            class="btn btn-secondary text-white py-2 px-6 rounded-pill fs-3 ms-2"
                                        >
                                            Cancel
                                        </Link>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Backblaze Configuration -->
                    <div v-if="activeTab === 'Backblaze'" class="row">
                        <div class="col-12">
                            <form @submit.prevent="saveSettings('b2')">
                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <label class="fs-3 text-dark mb-2">Key ID</label>
                                        <input
                                            v-model="settings.b2.key_id"
                                            type="text"
                                            class="form-control border rounded p-3 fs-3"
                                            required
                                        />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="fs-3 text-dark mb-2">Application Key</label>
                                        <input
                                            v-model="settings.b2.app_key"
                                            type="password"
                                            class="form-control border rounded p-3 fs-3"
                                            required
                                        />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="fs-3 text-dark mb-2">Bucket</label>
                                        <input
                                            v-model="settings.b2.bucket"
                                            type="text"
                                            class="form-control border rounded p-3 fs-3"
                                            required
                                        />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="fs-3 text-dark mb-2">Endpoint</label>
                                        <input
                                            v-model="settings.b2.endpoint"
                                            type="text"
                                            class="form-control border rounded p-3 fs-3"
                                        />
                                    </div>
                                    <div class="col-12 mt-4">
                                        <button
                                            type="submit"
                                            class="btn btn-primary text-white py-2 px-6 rounded-pill fs-3"
                                        >
                                            <i class="ti ti-check me-2"></i> Save Backblaze Settings
                                        </button>
                                        <Link
                                            href="/dashboard"
                                            class="btn btn-secondary text-white py-2 px-6 rounded-pill fs-3 ms-2"
                                        >
                                            Cancel
                                        </Link>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Wasabi Configuration -->
                    <div v-if="activeTab === 'Wasabi'" class="row">
                        <div class="col-12">
                            <form @submit.prevent="saveSettings('wasabi')">
                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <label class="fs-3 text-dark mb-2">Access Key</label>
                                        <input
                                            v-model="settings.wasabi.access_key"
                                            type="text"
                                            class="form-control border rounded p-3 fs-3"
                                            required
                                        />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="fs-3 text-dark mb-2">Secret Key</label>
                                        <input
                                            v-model="settings.wasabi.secret_key"
                                            type="password"
                                            class="form-control border rounded p-3 fs-3"
                                            required
                                        />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="fs-3 text-dark mb-2">Bucket</label>
                                        <input
                                            v-model="settings.wasabi.bucket"
                                            type="text"
                                            class="form-control border rounded p-3 fs-3"
                                            required
                                        />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="fs-3 text-dark mb-2">Region</label>
                                        <input
                                            v-model="settings.wasabi.region"
                                            type="text"
                                            class="form-control border rounded p-3 fs-3"
                                        />
                                    </div>
                                    <div class="col-12 mt-4">
                                        <button
                                            type="submit"
                                            class="btn btn-primary text-white py-2 px-6 rounded-pill fs-3"
                                        >
                                            <i class="ti ti-check me-2"></i> Save Wasabi Settings
                                        </button>
                                        <Link
                                            href="/dashboard"
                                            class="btn btn-secondary text-white py-2 px-6 rounded-pill fs-3 ms-2"
                                        >
                                            Cancel
                                        </Link>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.form-control {
    transition: border 0.2s ease-in-out;
}

.form-control:focus {
    border-color: #2563eb;
    outline: none;
}

.hover-card {
    transition: transform 0.2s ease-in-out;
    cursor: pointer;
}

.hover-card:hover {
    transform: translateY(-2px);
}

.fs-1 {
    font-size: 2rem !important;
}

.rounded-pill {
    border-radius: 50rem;
}

.is-invalid {
    border-color: #dc3545;
}

.invalid-feedback {
    color: #dc3545;
    font-size: 0.875rem;
}
</style>