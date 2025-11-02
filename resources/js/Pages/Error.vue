<script setup>
import MainLayout from '@/Layouts/MainLayout.vue'
import { Link } from '@inertiajs/vue3'

defineOptions({
    layout: MainLayout
})

defineProps({
    status: {
        type: Number,
        default: 500
    },
    title: {
        type: String,
        default: 'Error'
    },
    message: {
        type: String,
        default: 'Something went wrong'
    }
})
</script>

<template>
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-5">
                    <div class="mb-4">
                        <i v-if="status === 403" class="ti ti-lock text-danger" style="font-size: 5rem;"></i>
                        <i v-else-if="status === 404" class="ti ti-error-404 text-warning" style="font-size: 5rem;"></i>
                        <i v-else class="ti ti-alert-circle text-danger" style="font-size: 5rem;"></i>
                    </div>
                    
                    <h1 class="fw-bold mb-3">{{ status }}</h1>
                    <h3 class="fw-semibold mb-3">{{ title }}</h3>
                    <p class="text-muted fs-4 mb-4">{{ message }}</p>
                    
                    <div class="d-flex justify-content-center gap-2">
                        <Link href="/" class="btn btn-primary">
                            <i class="ti ti-home me-2"></i>
                            Go to Dashboard
                        </Link>
                        <button @click="$inertia.visit($page.url, { preserveState: false, preserveScroll: false })" 
                                class="btn btn-outline-secondary">
                            <i class="ti ti-refresh me-2"></i>
                            Try Again
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>