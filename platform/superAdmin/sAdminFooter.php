</div>
</div>


<script>

    document.addEventListener("DOMContentLoaded", function () {

        // ==========================
        // Mobile Sidebar Toggle
        // ==========================

        const sidebar = document.querySelector(".sidebar");
        const toggle = document.getElementById("toggleSidebar");

        if (toggle) {

            toggle.addEventListener("click", function () {

                sidebar.classList.toggle("show");

            });

        }

        // ==========================
        // Close sidebar when clicking outside
        // ==========================

        document.addEventListener("click", function (e) {

            if (window.innerWidth <= 992) {

                if (
                    !sidebar.contains(e.target) &&
                    !toggle.contains(e.target)
                ) {

                    sidebar.classList.remove("show");

                }

            }

        });

        // ==========================
        // Auto open Website Management
        // if one of its pages is active
        // ==========================

        const activeSub = document.querySelector(".active-sub");

        if (activeSub) {

            const collapse = document.getElementById("websiteMenu");

            if (collapse) {

                const bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapse, {
                    toggle: false
                });

                bsCollapse.show();

            }

        }

    });

</script>

<?php if (!empty($access_denied)): ?>
    <!-- requirePlatformAccess() sent somebody here from a module their role
         does not hold. Saying so beats silently landing them elsewhere. -->
    <script>
        Swal.fire({
            icon: "info",
            title: "Not Your Area",
            text: <?= json_encode($access_denied) ?>,
            confirmButtonColor: "#00224c"
        });
    </script>
<?php endif; ?>

</body>

</html>



<!-- </div>

</div>

<script>
    document.addEventListener("DOMContentLoaded", function () {

        const sidebar = document.querySelector(".sidebar");
        const content = document.querySelector(".main-content");
        const toggle = document.getElementById("sidebarToggle");
        const overlay = document.getElementById("mobileOverlay");

        function mobile() {
            return window.innerWidth < 992;
        }

        /* Restore desktop state */
        if (localStorage.getItem("sidebarCollapsed") === "true" && !mobile()) {
            sidebar.classList.add("collapsed");
            content.classList.add("expanded");
        }

        toggle.addEventListener("click", function () {

            if (mobile()) {

                sidebar.classList.toggle("show");
                overlay.classList.toggle("show");

            } else {

                sidebar.classList.toggle("collapsed");
                content.classList.toggle("expanded");

                localStorage.setItem(
                    "sidebarCollapsed",
                    sidebar.classList.contains("collapsed")
                );

            }

        });

        overlay.addEventListener("click", function () {

            sidebar.classList.remove("show");
            overlay.classList.remove("show");

        });

        window.addEventListener("resize", function () {

            if (!mobile()) {

                sidebar.classList.remove("show");
                overlay.classList.remove("show");

                if (localStorage.getItem("sidebarCollapsed") === "true") {
                    sidebar.classList.add("collapsed");
                    content.classList.add("expanded");
                }

            } else {

                sidebar.classList.remove("collapsed");
                content.classList.remove("expanded");

            }

        });

    });
</script>
</body>

</html> -->