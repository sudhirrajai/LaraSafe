<script setup>
import MainLayout from '@/Layouts/MainLayout.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import axios from 'axios'

// SweetAlert2 (must be loaded globally)
const Swal = window.Swal

defineOptions({
  layout: MainLayout
})

/* ---- Props ---- */
const { backup, projects } = defineProps({
  backup: { type: Object, required: true },
  projects: { type: Array, default: () => [] },
})

/* ---- Form ---- */
const form = useForm({
  project_id: '',
  file_name: '',
  storage_disk: 'local',
  frequency: null,
  time: null,
  include_database: false,
  db_source: 'env', // env, custom, project_config
  db_host: '',
  db_port: '3306',
  db_name: '',
  db_username: '',
  db_password: '',
  db_tables: 'all',
  selected_tables: '',
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

/* If BOTH frequency and time exist, treat auto backup as enabled */
const autoBackupEnabled = ref(false)
const showAdvancedDb = ref(false)

/* ---- Populate form with backup data ---- */
watch(
  () => backup,
  (b) => {
    if (!b) return

    form.project_id = b.project_id || ''
    form.file_name = b.file_name || ''
    form.storage_disk = b.storage_disk || 'local'
    form.frequency = b.backup_frequency ?? b.frequency ?? null
    // Normalize time to "HH:MM" if available
    form.time = (b.backup_time ?? b.time) ? String((b.backup_time ?? b.time)).substr(0,5) : null

    // include_database may be stored as 0/1 or boolean
    form.include_database = !!(b.include_database || b.include_database === 1)

    // autoBackupEnabled should be true only if both frequency and time have values
    autoBackupEnabled.value = !!(form.frequency && form.time)

    if (b.database_config) {
      form.db_source = b.database_config.source || 'env'
      form.db_tables = b.database_config.tables || 'all'

      // if selected_tables is array -> join to string, else use as-is
      if (b.database_config.selected_tables) {
        form.selected_tables = Array.isArray(b.database_config.selected_tables)
          ? b.database_config.selected_tables.join(', ')
          : b.database_config.selected_tables
      } else {
        form.selected_tables = ''
      }

      // credentials should already be decrypted by controller and be an object
      if (b.database_config.credentials && typeof b.database_config.credentials === 'object') {
        const creds = b.database_config.credentials
        form.db_host = creds.host || ''
        form.db_port = creds.port || '3306'
        form.db_name = creds.database || ''
        form.db_username = creds.username || ''
        // For security we do not pre-fill password field with the actual value
        form.db_password = '' 
      } else {
        form.db_host = ''
        form.db_port = '3306'
        form.db_name = ''
        form.db_username = ''
        form.db_password = ''
      }
    } else {
      // Reset DB fields if there is no db config
      form.db_source = 'env'
      form.db_tables = 'all'
      form.selected_tables = ''
      form.db_host = ''
      form.db_port = '3306'
      form.db_name = ''
      form.db_username = ''
      form.db_password = ''
    }
  },
  { immediate: true }
)

/* ---- Keep autoBackupEnabled in sync with frequency/time changes ---- */
watch([() => form.frequency, () => form.time], ([f, t]) => {
  // if both have values, ensure toggle is on
  if (f && t) {
    autoBackupEnabled.value = true
  } else {
    // don't automatically turn off if user explicitly enabled toggle,
    // but keep this simple: if either is missing, keep toggle false so UI matches saved state
    autoBackupEnabled.value = false
  }
})

/* If user toggles the autoBackup switch off, clear freq/time locally (optional) */
watch(autoBackupEnabled, (val) => {
  if (!val) {
    form.frequency = null
    form.time = null
  } else {
    // if enabled and there was no value, set sensible defaults
    if (!form.frequency) form.frequency = 'daily'
    if (!form.time) form.time = form.time || new Date().toISOString().substr(11,5)
  }
})

/* ---- Validation ---- */
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

  if (form.include_database && form.db_tables === 'selected') {
    return {
      ...baseErrors,
      selected_tables: !form.selected_tables ? 'Please specify selected tables' : '',
    }
  }

  return baseErrors
})

const isValid = computed(() => {
  return !Object.values(errors.value).some(error => error !== '')
})

/* ---- Submit ---- */
const handleSubmit = () => {
  // mark touched for validation
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

    if (form.db_tables === 'selected' && form.selected_tables) {
      data.selected_tables = form.selected_tables
    }
  }

  form.put(`/backups/update-backup/${backup.id}`, {
    data,
    onSuccess: (page) => {
      const successMessage = page.props.flash?.status
      Swal.fire({
        icon: "success",
        title: "Success",
        text: successMessage || "Backup updated successfully!",
      }).then(() => {
        router.visit('/backups/manage-backups')
      })
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

/* ---- Test Database Connection ---- */
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
      <h5 class="card-title fw-semibold mb-4">Edit Backup</h5>
      <div class="card">
        <div class="card-body">
          <form @submit.prevent="handleSubmit">
            <!-- Project -->
            <div class="mb-3">
              <label for="projectSelect" class="form-label">Select Project</label>
              <select
                class="form-select"
                id="projectSelect"
                v-model="form.project_id"
                @blur="touched.project_id = true"
                :class="{ 'is-invalid': errors.project_id && touched.project_id }"
                :disabled="true"
              >
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
              <input
                type="text"
                class="form-control"
                id="backupFileName"
                v-model="form.file_name"
                @blur="touched.file_name = true"
                :class="{ 'is-invalid': errors.file_name && touched.file_name }"
              />
              <div v-if="errors.file_name && touched.file_name" class="invalid-feedback">
                {{ errors.file_name }}
              </div>
            </div>

            <!-- Storage Disk -->
            <div class="mb-3">
              <label for="storageDisk" class="form-label">Storage Disk</label>
              <select
                class="form-select"
                id="storageDisk"
                v-model="form.storage_disk"
                @blur="touched.storage_disk = true"
                :class="{ 'is-invalid': errors.storage_disk && touched.storage_disk }"
              >
                <option value="local">Local</option>
                <option value="s3">S3</option>
                <option value="other">Other Cloud Storage</option>
              </select>
              <div v-if="errors.storage_disk && touched.storage_disk" class="invalid-feedback">
                {{ errors.storage_disk }}
              </div>
            </div>

            <!-- Database Backup Section -->
            <div class="mb-4">
              <div class="card border-info">
                <div class="card-header bg-light">
                  <div class="form-check form-switch">
                    <input type="hidden" name="include_database" :value="0" />

                    <input
                      type="checkbox"
                      class="form-check-input"
                      id="includeDatabaseToggle"
                      v-model="form.include_database"
                    />
                    <label class="form-check-label fw-bold" for="includeDatabaseToggle">
                      <i class="ti ti-database me-2"></i>
                      Include Database Backup
                    </label>
                  </div>
                </div>

                <div v-if="form.include_database" class="card-body">
                  <!-- Database Source Selection -->
                  <div class="mb-3">
                    <label class="form-label">Database Credentials Source</label>
                    <div class="form-check">
                      <input
                        class="form-check-input"
                        type="radio"
                        name="dbSource"
                        id="dbSourceEnv"
                        value="env"
                        v-model="form.db_source"
                      />
                      <label class="form-check-label" for="dbSourceEnv">
                        <strong>Use Project's .env File</strong>
                        <small class="d-block text-muted">Automatically read from project's environment file</small>
                      </label>
                    </div>
                    <div class="form-check">
                      <input
                        class="form-check-input"
                        type="radio"
                        name="dbSource"
                        id="dbSourceCustom"
                        value="custom"
                        v-model="form.db_source"
                      />
                      <label class="form-check-label" for="dbSourceCustom">
                        <strong>Custom Database Credentials</strong>
                        <small class="d-block text-muted">Specify different database credentials</small>
                      </label>
                    </div>
                    <div class="alert alert-info mt-2">
                      <i class="ti ti-info-circle me-2"></i>
                      {{ form.db_source === 'env' ? "Will use database credentials from project's .env file" : form.db_source === 'project_config' ? 'Will use database credentials stored in project configuration' : 'Use custom database credentials for this backup' }}
                    </div>
                  </div>

                  <!-- Custom Database Credentials -->
                  <div v-if="form.db_source === 'custom'" class="border rounded p-3 bg-light mb-3">
                    <h6 class="mb-3">Database Connection Details</h6>
                    <div class="row">
                      <div class="col-md-8 mb-3">
                        <label for="dbHost" class="form-label">Host</label>
                        <input
                          type="text"
                          class="form-control"
                          id="dbHost"
                          v-model="form.db_host"
                          placeholder="localhost"
                          @blur="touched.db_host = true"
                          :class="{ 'is-invalid': errors.db_host && touched.db_host }"
                        />
                        <div v-if="errors.db_host && touched.db_host" class="invalid-feedback">
                          {{ errors.db_host }}
                        </div>
                      </div>
                      <div class="col-md-4 mb-3">
                        <label for="dbPort" class="form-label">Port</label>
                        <input
                          type="number"
                          class="form-control"
                          id="dbPort"
                          v-model="form.db_port"
                          placeholder="3306"
                        />
                      </div>
                    </div>
                    <div class="row">
                      <div class="col-md-6 mb-3">
                        <label for="dbName" class="form-label">Database Name</label>
                        <input
                          type="text"
                          class="form-control"
                          id="dbName"
                          v-model="form.db_name"
                          @blur="touched.db_name = true"
                          :class="{ 'is-invalid': errors.db_name && touched.db_name }"
                        />
                        <div v-if="errors.db_name && touched.db_name" class="invalid-feedback">
                          {{ errors.db_name }}
                        </div>
                      </div>
                      <div class="col-md-6 mb-3">
                        <label for="dbUsername" class="form-label">Username</label>
                        <input
                          type="text"
                          class="form-control"
                          id="dbUsername"
                          v-model="form.db_username"
                          @blur="touched.db_username = true"
                          :class="{ 'is-invalid': errors.db_username && touched.db_username }"
                        />
                        <div v-if="errors.db_username && touched.db_username" class="invalid-feedback">
                          {{ errors.db_username }}
                        </div>
                      </div>
                    </div>
                    <div class="mb-3">
                      <label for="dbPassword" class="form-label">Password</label>
                      <input
                        type="password"
                        class="form-control"
                        id="dbPassword"
                        v-model="form.db_password"
                        @blur="touched.db_password = true"
                        :class="{ 'is-invalid': errors.db_password && touched.db_password }"
                      />
                      <div v-if="errors.db_password && touched.db_password" class="invalid-feedback">
                        {{ errors.db_password }}
                      </div>
                    </div>
                    <button
                      type="button"
                      class="btn btn-outline-info btn-sm"
                      @click="testDatabaseConnection"
                    >
                      <i class="ti ti-plug-connected me-1"></i>
                      Test Connection
                    </button>
                  </div>

                  <!-- Advanced Database Options -->
                  <div class="mb-3">
                    <button
                      type="button"
                      class="btn btn-link p-0 text-decoration-none"
                      @click="showAdvancedDb = !showAdvancedDb"
                    >
                      <i :class="showAdvancedDb ? 'ti ti-chevron-down' : 'ti ti-chevron-right'"></i>
                      Advanced Database Options
                    </button>

                    <div v-if="showAdvancedDb" class="mt-3 border rounded p-3 bg-light">
                      <div class="mb-3">
                        <label class="form-label">Tables to Backup</label>
                        <div class="form-check">
                          <input
                            class="form-check-input"
                            type="radio"
                            name="dbTables"
                            id="tablesAll"
                            value="all"
                            v-model="form.db_tables"
                          />
                          <label class="form-check-label" for="tablesAll">
                            All Tables (Complete Database)
                          </label>
                        </div>
                        <div class="form-check">
                          <input
                            class="form-check-input"
                            type="radio"
                            name="dbTables"
                            id="tablesSelected"
                            value="selected"
                            v-model="form.db_tables"
                          />
                          <label class="form-check-label" for="tablesSelected">
                            Selected Tables Only
                          </label>
                        </div>

                        <div v-if="form.db_tables === 'selected'" class="mt-2">
                          <textarea
                            class="form-control"
                            rows="3"
                            placeholder="Enter table names separated by commas (e.g., users, posts, categories)"
                            v-model="form.selected_tables"
                          ></textarea>
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

            <!-- Auto Backup Toggle and Fields -->
            <div class="mb-3">
              <div class="form-check form-switch">
                <input
                  type="checkbox"
                  class="form-check-input"
                  id="autoBackupToggle"
                  v-model="autoBackupEnabled"
                  @change="touched.frequency = true; touched.time = true"
                />
                <label class="form-check-label" for="autoBackupToggle">Enable Auto Backups</label>
              </div>
              <div v-if="autoBackupEnabled" class="mt-3">
                <div class="row">
                  <div class="col-md-6 mb-3">
                    <label for="frequency" class="form-label">Frequency</label>
                    <select
                      class="form-select"
                      id="frequency"
                      v-model="form.frequency"
                      @blur="touched.frequency = true"
                      :class="{ 'is-invalid': errors.frequency && touched.frequency }"
                    >
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
                    <input
                      type="time"
                      class="form-control"
                      id="backupTime"
                      v-model="form.time"
                      @blur="touched.time = true"
                      :class="{ 'is-invalid': errors.time && touched.time }"
                    />
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
                {{ form.processing ? 'Updating...' : 'Update Backup' }}
              </button>
              <button type="button" class="btn btn-secondary" @click="router.visit('/backups/manage-backups')">
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
  border-bottom: 1px solid rgba(0,0,0,.125);
}

.form-check-label strong {
  color: #333;
}

.btn-link {
  font-size: 0.9rem;
}

.text-danger {
  font-size: 0.85rem;
}
</style>