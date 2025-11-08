<script setup>
import { useForm, usePage, router } from '@inertiajs/vue3';
import { useToast } from 'vue-toastification';
import { ref, computed, watch, onMounted } from 'vue';

const toast = useToast();
const page = usePage();

const props = defineProps({
  token: {
    type: String,
    required: true
  },
  email: {
    type: String,
    required: true
  }
});

// Watch for flash messages (only for errors, success redirects to login)
watch(() => page.props.flash, (flash) => {
  if (flash?.error) {
    toast.error(flash.error);
  }
}, { deep: true, immediate: true });

// Check on mount for existing flash messages
onMounted(() => {
  if (page.props.flash?.error) {
    toast.error(page.props.flash.error);
  }
});

const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

const form = useForm({
  token: props.token,
  email: props.email,
  password: '',
  password_confirmation: '',
});

const passwordStrength = computed(() => {
  const password = form.password;
  if (!password) return { strength: 0, label: '', color: '' };
  
  let strength = 0;
  if (password.length >= 8) strength++;
  if (password.length >= 12) strength++;
  if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
  if (/\d/.test(password)) strength++;
  if (/[^a-zA-Z\d]/.test(password)) strength++;
  
  const labels = ['', 'Weak', 'Fair', 'Good', 'Strong', 'Very Strong'];
  const colors = ['', 'danger', 'warning', 'info', 'success', 'success'];
  
  return {
    strength: (strength / 5) * 100,
    label: labels[strength],
    color: colors[strength]
  };
});

const submit = () => {
  form.post('/reset-password', {
    preserveScroll: true,
    onError: (errors) => {
      if (errors.email) {
        toast.error(errors.email);
      } else if (errors.password) {
        toast.error(errors.password);
      } else {
        toast.error('Failed to reset password. Please try again.');
      }
    },
  });
};

const clearError = (field) => {
  if (form.errors[field]) {
    form.errors[field] = null;
  }
};

const togglePasswordVisibility = (field) => {
  if (field === 'password') {
    showPassword.value = !showPassword.value;
  } else {
    showPasswordConfirmation.value = !showPasswordConfirmation.value;
  }
};
</script>

<template>
  <div class="page-wrapper" id="main-wrapper" data-layout="vertical" data-navbarbg="skin6" data-sidebartype="full"
    data-sidebar-position="fixed" data-header-position="fixed">
    <div class="position-relative overflow-hidden text-bg-light min-vh-100 d-flex align-items-center justify-content-center">
      <div class="d-flex align-items-center justify-content-center w-100">
        <div class="row justify-content-center w-100">
          <div class="col-md-8 col-lg-6 col-xxl-4">
            <div class="card mb-0">
              <div class="card-body">
                <a href="/" class="text-nowrap logo-img text-center d-block py-3 w-100">
                  <img src="/public/assets/images/logos/logo.png" alt="Logo" style="width: 300px;">
                </a>
                
                <div class="text-center mb-4">
                  <h3 class="fw-bold">Reset Your Password</h3>
                  <p class="text-muted">Create a new secure password</p>
                </div>

                <!-- Success Message -->
                <div v-if="$page.props.flash?.success" class="alert alert-success alert-dismissible fade show" role="alert">
                  <i class="ti ti-circle-check me-2"></i>
                  Password reset successful! Redirecting to login...
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>

                <!-- Error Message -->
                <div v-if="$page.props.flash?.error" class="alert alert-danger alert-dismissible fade show" role="alert">
                  <i class="ti ti-alert-circle me-2"></i>
                  {{ $page.props.flash.error }}
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                
                <form @submit.prevent="submit">
                  <!-- Email (readonly) -->
                  <div class="mb-3">
                    <label for="email" class="form-label">Email Address</label>
                    <input 
                      type="email" 
                      v-model="form.email"
                      class="form-control" 
                      id="email" 
                      readonly
                      disabled
                    >
                  </div>

                  <!-- New Password -->
                  <div class="mb-3">
                    <label for="password" class="form-label">New Password</label>
                    <div class="input-group">
                      <input 
                        :type="showPassword ? 'text' : 'password'" 
                        v-model="form.password"
                        class="form-control" 
                        :class="{ 'is-invalid': form.errors.password }"
                        id="password"
                        placeholder="Enter new password"
                        required
                        @input="clearError('password')"
                      >
                      <button 
                        class="btn btn-outline-secondary" 
                        type="button"
                        @click="togglePasswordVisibility('password')"
                      >
                        <i :class="showPassword ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                      </button>
                    </div>
                    
                    <!-- Password Strength Indicator -->
                    <div v-if="form.password" class="mt-2">
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <small class="text-muted">Password Strength:</small>
                        <small :class="`text-${passwordStrength.color}`">
                          {{ passwordStrength.label }}
                        </small>
                      </div>
                      <div class="progress" style="height: 5px;">
                        <div 
                          class="progress-bar" 
                          :class="`bg-${passwordStrength.color}`"
                          :style="{ width: passwordStrength.strength + '%' }"
                        ></div>
                      </div>
                    </div>

                    <div v-if="form.errors.password" class="invalid-feedback d-block">
                      {{ form.errors.password }}
                    </div>
                    <small class="text-muted">
                      Password must be at least 8 characters long
                    </small>
                  </div>

                  <!-- Confirm Password -->
                  <div class="mb-4">
                    <label for="password_confirmation" class="form-label">Confirm New Password</label>
                    <div class="input-group">
                      <input 
                        :type="showPasswordConfirmation ? 'text' : 'password'" 
                        v-model="form.password_confirmation"
                        class="form-control" 
                        :class="{ 'is-invalid': form.errors.password_confirmation }"
                        id="password_confirmation"
                        placeholder="Re-enter new password"
                        required
                        @input="clearError('password_confirmation')"
                      >
                      <button 
                        class="btn btn-outline-secondary" 
                        type="button"
                        @click="togglePasswordVisibility('confirmation')"
                      >
                        <i :class="showPasswordConfirmation ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                      </button>
                    </div>
                    <div v-if="form.errors.password_confirmation" class="invalid-feedback d-block">
                      {{ form.errors.password_confirmation }}
                    </div>
                  </div>
                  
                  <button 
                    type="submit" 
                    class="btn btn-primary w-100 py-8 fs-4 mb-3"
                    :disabled="form.processing"
                  >
                    <span v-if="form.processing">
                      <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                      Resetting Password...
                    </span>
                    <span v-else>
                      <i class="ti ti-lock-open me-2"></i>
                      Reset Password
                    </span>
                  </button>

                  <div class="text-center">
                    <a href="/login" class="text-primary fw-semibold">
                      <i class="ti ti-arrow-left me-1"></i>
                      Back to Login
                    </a>
                  </div>
                </form>

                <!-- Security Tips -->
                <div class="alert alert-info mt-4" role="alert">
                  <strong><i class="ti ti-shield-check me-2"></i>Security Tips:</strong>
                  <ul class="mb-0 mt-2 ps-3">
                    <li>Use a mix of uppercase and lowercase letters</li>
                    <li>Include numbers and special characters</li>
                    <li>Avoid using personal information</li>
                    <li>Don't reuse passwords from other accounts</li>
                  </ul>
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

.input-group .btn {
  border-color: #dee2e6;
}

.input-group .btn:hover {
  background-color: #f8f9fa;
}

.progress {
  border-radius: 3px;
  background-color: #e9ecef;
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

.alert-info ul {
  font-size: 0.875rem;
}

.alert-info li {
  margin-bottom: 4px;
}
</style>