<script setup>
import Sidebar from "@/Components/Sidebar.vue";
import Header from "@/Components/Header.vue";
import Footer from "@/Components/Footer.vue";
import { usePage } from "@inertiajs/vue3";
import { onMounted } from "vue";
import { router } from "@inertiajs/vue3";
import Swal from "sweetalert2";

// onMounted(() => {
//   router.on("finish", (event) => {
//     const flash = event.detail.page.props.flash;

//     if (flash?.error) {
//       Swal.fire({
//         icon: "error",
//         title: "Access Denied",
//         text: flash.error,
//         timer: 3000,
//         showConfirmButton: false,
//         toast: true,
//         position: "top-end",
//       });
//     }

//     if (flash?.success) {
//       Swal.fire({
//         icon: "success",
//         title: "Success",
//         text: flash.success,
//         timer: 2000,
//         showConfirmButton: false,
//         toast: true,
//         position: "top-end",
//       });
//     }
//   });
// });
</script>
<template>
  <body>
    <!-- Body Wrapper -->
    <div
      class="page-wrapper"
      id="main-wrapper"
      data-layout="vertical"
      data-navbarbg="skin6"
      data-sidebartype="full"
      data-sidebar-position="fixed"
      data-header-position="fixed"
    >
      <Sidebar />

      <!-- Main wrapper -->
      <div class="body-wrapper">
        <div class="body-wrapper-inner">
          <div class="container-fluid">
            <Header />
            <slot />
            <Footer />
          </div>
        </div>
      </div>
    </div>
  </body>
</template>