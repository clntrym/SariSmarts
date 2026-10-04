/* ============================================================
   RetailCore - shared DataTables defaults

   Pairs with assets/css/datatable-theme.css. Every table in the
   system inherits the same search box, record count, pagination
   and click-to-sort headers without repeating the config on each
   page. Individual pages can still override any of it by passing
   their own options to `new DataTable(...)`.
   ============================================================ */

(function () {

    function applyDefaults() {

        if (typeof window.DataTable === "undefined") {
            return false;
        }

        var defaults = window.DataTable.defaults;

        defaults.pageLength = 10;
        defaults.lengthChange = false;
        defaults.ordering = true;
        defaults.searching = true;
        defaults.paging = true;
        defaults.info = true;
        defaults.autoWidth = false;

        // Search top-left, record count bottom-left, pagination bottom-right.
        defaults.layout = {
            topStart: "search",
            topEnd: null,
            bottomStart: "info",
            bottomEnd: "paging"
        };

        // A page can keep its filters / Export button on the same line as
        // the search box by tagging the wrapper with
        // data-dt-toolbar="<table id>". It gets moved into the table's top
        // layout row, right-aligned opposite the search field.
        defaults.initComplete = function (settings) {

            var table = settings.nTable;
            var toolbar = document.querySelector('[data-dt-toolbar="' + table.id + '"]');

            if (!toolbar) {
                return;
            }

            var container = table.closest(".dt-container");
            var topRow = container
                ? container.querySelector(".dt-layout-row:not(.dt-layout-table)")
                : null;

            if (!topRow || topRow.contains(toolbar)) {
                return;
            }

            var slot = document.createElement("div");

            // Class list covers both renderers in use: the stock one keys off
            // dt-layout-end, the Bootstrap 5 one off the grid utilities.
            slot.className = "dt-layout-cell dt-layout-end dt-toolbar-slot col-md-auto ms-auto";
            slot.appendChild(toolbar);

            topRow.appendChild(slot);
        };

        defaults.language = Object.assign({}, defaults.language, {
            search: "",
            searchPlaceholder: "Search...",
            info: "Showing _START_ to _END_ of _TOTAL_",
            infoEmpty: "Showing 0 of 0",
            infoFiltered: "",
            emptyTable: "No records found",
            zeroRecords: "No matching records",
            paginate: {
                first: "First",
                last: "Last",
                previous: "Previous",
                next: "Next"
            }
        });

        return true;
    }

    // The library is normally already loaded by the page header. If a
    // page pulls it in later (further down the body), retry once the
    // document has finished parsing.
    if (!applyDefaults()) {
        document.addEventListener("DOMContentLoaded", applyDefaults);
    }

})();
