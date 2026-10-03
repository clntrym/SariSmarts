
</div>

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
</html>
