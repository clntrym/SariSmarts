/* ============================================================
   RetailCore — shared DataTables defaults

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

    /*
    | EMPTY-STATE ROWS
    |
    | Pages render "no records" as a single <td colspan="N"> row. DataTables
    | cannot read a row with fewer cells than the header has columns, so every
    | empty table raised:
    |
    |   DataTables warning: table id=... - Requested unknown parameter '1'
    |   for row 0, column 1.
    |
    | Fourteen tables do this, so it is fixed once here rather than in each
    | page. Before a table is initialised, any single-cell colspan row is
    | lifted out of its body, and the markup it held becomes the table's
    | emptyTable message -- the page keeps its own empty-state design, now
    | drawn by DataTables. A page that sets its own emptyTable keeps that.
    */
    function liftEmptyStateRows(target, options) {

        var table = typeof target === "string" ? document.querySelector(target) : target;

        if (!table || !table.tBodies || !table.tBodies.length) {
            return options;
        }

        var placeholder = null;
        var rows = Array.prototype.slice.call(table.tBodies[0].rows);

        rows.forEach(function (row) {
            if (row.cells.length === 1 && row.cells[0].colSpan > 1) {
                if (placeholder === null) {
                    placeholder = row.cells[0].innerHTML.trim();
                }
                row.parentNode.removeChild(row);
            }
        });

        if (placeholder === null) {
            return options;
        }

        var merged = Object.assign({}, options || {});
        merged.language = Object.assign({}, merged.language || {});

        if (merged.language.emptyTable === undefined && placeholder !== "") {
            merged.language.emptyTable = placeholder;
        }

        return merged;
    }

    function wrapConstructor() {

        var Original = window.DataTable;

        if (typeof Original !== "function" || Original.__emptyStateAware) {
            return;
        }

        var Wrapped = function (target, options) {
            return new Original(target, liftEmptyStateRows(target, options));
        };

        // Statics (DataTable.ext, .defaults, .isDataTable, .Api ...) and
        // instanceof keep working through the prototype chain.
        Object.setPrototypeOf(Wrapped, Original);
        Wrapped.prototype = Original.prototype;
        Wrapped.__emptyStateAware = true;

        window.DataTable = Wrapped;
    }

    function setup() {
        if (!applyDefaults()) {
            return false;
        }
        wrapConstructor();
        return true;
    }

    // The library is normally already loaded by the page header. If a
    // page pulls it in later (further down the body), retry once the
    // document has finished parsing.
    if (!setup()) {
        document.addEventListener("DOMContentLoaded", setup);
    }

})();
