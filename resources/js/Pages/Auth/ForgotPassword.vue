<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { useToast } from 'vue-toastification';
import { watch, onMounted } from 'vue';

const toast = useToast();
const page = usePage();

const props = defineProps({
  status: String,
  errors: Object,
});

// Watch for flash messages
watch(() => page.props.flash, (flash) => {
  if (flash?.success) {
    toast.success(flash.success);
  }
  if (flash?.error) {
    toast.error(flash.error);
  }
}, { deep: true, immediate: true });

// Check on mount for existing flash messages
onMounted(() => {
  if (page.props.flash?.success) {
    toast.success(page.props.flash.success);
  }
  if (page.props.flash?.error) {
    toast.error(page.props.flash.error);
  }
});

const form = useForm({
  email: '',
});

const submit = () => {
  form.post('/forgot-password', {
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
    },
    onError: (errors) => {
      if (errors.email) {
        toast.error(errors.email);
      } else {
        toast.error('Failed to send reset link. Please try again.');
      }
    },
  });
};

const clearError = (field) => {
  if (form.errors[field]) {
    form.errors[field] = null;
  }
};
</script>

<template>
  <div class="page-wrapper" id="main-wrapper" data-layout="vertical" data-navbarbg="skin6" data-sidebartype="full"
    data-sidebar-position="fixed" data-header-position="fixed">
    <div class="position-relative overflow-hidden text-bg-light min-vh-100 d-flex align-items-center justify-content-center">
      <div class="d-flex align-items-center justify-content-center w-100">
        <div class="row justify-content-center w-100">
          <div class="col-md-8 col-lg-6 col-xxl-3">
            <div class="card mb-0">
              <div class="card-body">
                <a href="/" class="text-nowrap logo-img text-center d-block py-3 w-100">
                  <img src="/public/assets/images/logos/logo.png" alt="Logo" style="width: 300px;">
                </a>
                
                <div class="text-center mb-4">
                  <h3 class="fw-bold">Forgot Password?</h3>
                  <p class="text-muted">Enter your email and we'll send you a reset link</p>
                </div>

                <!-- Success Message (Visual Feedback) -->
                <div v-if="$page.props.flash?.success" class="alert alert-success alert-dismissible fade show" role="alert">
                  <i class="ti ti-circle-check me-2"></i>
                  {{ $page.props.flash.success }}
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>

                <!-- Error Message (Visual Feedback) -->
                <div v-if="$page.props.flash?.error" class="alert alert-danger alert-dismissible fade show" role="alert">
                  <i class="ti ti-alert-circle me-2"></i>
                  {{ $page.props.flash.error }}
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                
                <form @submit.prevent="submit">
                  <div class="mb-4">
                    <label for="email" class="form-label">Email Address</label>
                    <input 
                      type="email" 
                      v-model="form.email"
                      class="form-control" 
                      :class="{ 'is-invalid': form.errors.email }"
                      id="email" 
                      placeholder="Enter your email"
                      required
                      @input="clearError('email')"
                    >
                    <div v-if="form.errors.email" class="invalid-feedback">
                      {{ form.errors.email }}
                    </div>
                  </div>
                  
                  <button 
                    type="submit" 
                    class="btn btn-primary w-100 py-8 fs-4 mb-3"
                    :disabled="form.processing"
                  >
                    <span v-if="form.processing">
                      <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                      Sending Reset Link...
                    </span>
                    <span v-else>
                      <i class="ti ti-mail me-2"></i>
                      Send Reset Link
                    </span>
                  </button>

                  <div class="text-center">
                    <a href="/login" class="text-primary fw-semibold">
                      <i class="ti ti-arrow-left me-1"></i>
                      Back to Login
                    </a>
                  </div>
                </form>

                <!-- Info Box -->
                <div class="alert alert-info mt-4" role="alert">
                  <div class="d-flex">
                    <i class="ti ti-info-circle me-2 fs-5"></i>
                    <div>
                      <strong>Note:</strong> The reset link will expire in 10 minutes for security reasons.
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.spinner-border {
  vertical-align: middle;
}

.alert {
  border-radius: 8px;
}

.alert-info {
  background-color: #e7f3ff;
  border-color: #bee5eb;
  color: #0c5460;
}

.alert-success {
  background-color: #d4edda;
  border-color: #c3e6cb;
  color: #155724;
}

.alert-danger {
  background-color: #f8d7da;
  border-color: #f5c6cb;
  color: #721c24;
}
</style>