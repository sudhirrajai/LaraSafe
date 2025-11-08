<script setup>
const props = defineProps({
  message: {
    type: String,
    default: 'This password reset link has expired.'
  }
});
</script>

<template>
  <div class="page-wrapper" id="main-wrapper" data-layout="vertical" data-navbarbg="skin6" data-sidebartype="full"
    data-sidebar-position="fixed" data-header-position="fixed">
    <div class="position-relative overflow-hidden text-bg-light min-vh-100 d-flex align-items-center justify-content-center">
      <div class="d-flex align-items-center justify-content-center w-100">
        <div class="row justify-content-center w-100">
          <div class="col-md-8 col-lg-6 col-xxl-4">
            <div class="card mb-0">
              <div class="card-body text-center p-5">
                <a href="/" class="text-nowrap logo-img text-center d-block py-3 w-100 mb-4">
                  <img src="/public/assets/images/logos/logo.png" alt="Logo" style="width: 300px;">
                </a>
                
                <!-- Error Icon -->
                <div class="mb-4">
                  <div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex p-4">
                    <i class="ti ti-alert-circle text-danger" style="font-size: 64px;"></i>
                  </div>
                </div>

                <!-- Error Message -->
                <h3 class="fw-bold text-danger mb-3">Link Expired</h3>
                <p class="text-muted mb-4 fs-4">
                  {{ message }}
                </p>

                <div class="alert alert-warning text-start" role="alert">
                  <div class="d-flex align-items-start">
                    <i class="ti ti-info-circle me-2 mt-1 fs-5"></i>
                    <div>
                      <strong>Why did this happen?</strong>
                      <p class="mb-0 mt-2 small">
                        For security reasons, password reset links expire after 10 minutes. This protects your account from unauthorized access.
                      </p>
                    </div>
                  </div>
                </div>

                <!-- Action Buttons -->
                <div class="d-grid gap-2 mt-4">
                  <a href="/forgot-password" class="btn btn-primary btn-lg py-3">
                    <i class="ti ti-mail me-2"></i>
                    Request New Reset Link
                  </a>
                  <a href="/login" class="btn btn-outline-primary btn-lg py-3">
                    <i class="ti ti-arrow-left me-2"></i>
                    Back to Login
                  </a>
                </div>

                <!-- Help Section -->
                <div class="mt-4 pt-4 border-top">
                  <p class="text-muted small mb-2">
                    <i class="ti ti-help-circle me-1"></i>
                    Need help? Contact our support team
                  </p>
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
.alert-warning {
  background-color: #fff3cd;
  border-color: #ffecb5;
  color: #664d03;
}

.bg-opacity-10 {
  opacity: 0.1;
}

.btn-lg {
  font-size: 1rem;
  font-weight: 500;
}
</style>