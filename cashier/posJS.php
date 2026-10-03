<script>
    let cart = [];

    const TAX_RATE = <?= (float)$taxRate ?>;
    const cartItems = document.getElementById("cartItems");
    const subtotalText = document.getElementById("subtotal");
    let currentTax = 0;
    const totalText = document.getElementById("total");
    const itemCount = document.getElementById("itemCount");
    const cashInput = document.getElementById("cash");
    cashInput.addEventListener("keydown", function (e) {

        if (e.key === ".") {

            e.preventDefault();

            Swal.fire({
                icon: "error",
                title: "Invalid Input",
                text: "Whole numbers only."
            });

        }

    });
    const changeText = document.getElementById("change");
    document.querySelectorAll(".product-item").forEach(product => {
        product.addEventListener("click", function () {
            const id = this.dataset.id;
            const name = this.dataset.name;
            const price = parseFloat(this.dataset.price);
            const stock = parseInt(this.dataset.stock);
            let existing = cart.find(item => item.id == id);
            if (existing) {
                if (existing.qty >= stock) {
                    Swal.fire({
                        icon: "warning",
                        title: "Insufficient Stock",
                        text: "No more stock available."
                    });
                    return;
                }
                existing.qty++;
            } else {
                if (stock <= 0) {
                    Swal.fire({
                        icon: "warning",
                        title: "Out of Stock"
                    });
                    return;
                }
                cart.push({
                    id: id,
                    name: name,
                    price: price,
                    qty: 1
                });
            }
            renderCart();
        });
    });

    function renderCart() {
        let html = "";
        let subtotal = 0;
        let items = 0;
        if (cart.length == 0) {
            cartItems.innerHTML = `
        <div class="text-center text-muted mt-5">
            Tap a product to start.
        </div>
        `;
            subtotalText.innerHTML = "₱0.00";
            currentTax = 0;
            totalText.innerHTML = "₱0.00";
            itemCount.innerHTML = "0 Items";
            computeChange();
            return;
        }
        cart.forEach((item, index) => {
            subtotal += item.price * item.qty;
            items += item.qty;
            html += `
        <div class="card mb-2 shadow-sm border-0">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="fw-bold">
                            ${item.name}
                        </div>
                        <small class="text-primary">
                            ₱${item.price.toFixed(2)}
                        </small>
                    </div>
                    <div class="text-end">
                        <button
                        class="btn btn-sm btn-outline-secondary"
                        onclick="decrease(${index})">
                        <i class="bi bi-dash"></i>
                        </button>
                        <span class="mx-2">
                        ${item.qty}
                        </span>
                        <button
                        class="btn btn-sm btn-outline-primary"
                        onclick="increase(${index})">
                        <i class="bi bi-plus"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        `;
        });

        cartItems.innerHTML = html;
        currentTax = subtotal * (TAX_RATE / 100);
        currentTax = Number(currentTax.toFixed(2));
        const total = Math.round(subtotal + currentTax);

        subtotalText.innerHTML = "₱" + subtotal.toFixed(2);
        totalText.innerHTML = "₱" + total.toFixed(2);
        itemCount.innerHTML = items + " Items";
        computeChange();
    }

    function increase(index) {
        const card = document.querySelector(`.product-item[data-id="${cart[index].id}"]`);
        const stock = parseInt(card.dataset.stock);

        if (cart[index].qty >= stock) {
            Swal.fire({
                icon: "warning",
                title: "Insufficient Stock",
                text: "Cannot exceed available stock."
            });
            return;
        }
        cart[index].qty++;
        renderCart();
    }
    function decrease(index) {
        cart[index].qty--;
        if (cart[index].qty <= 0) {
            cart.splice(index, 1);
        }
        renderCart();
    }
    cashInput.addEventListener("input", function () {
        if (this.value.includes(".")) {
            Swal.fire({
                icon: "error",
                title: "Invalid Amount",
                text: "Whole numbers only. Decimal is not allowed."
            });
            this.value = parseInt(this.value);
        }
        computeChange();
    });
    function computeChange() {
        let total = parseFloat(
            totalText.innerText.replace("₱", "")
        ) || 0;
        let cash = parseFloat(cashInput.value) || 0;
        let change = cash - total;
        if (change < 0) {
            changeText.innerHTML =
                '<span class="text-danger">₱' +
                change.toFixed(2) +
                '</span>';
        } else {
            changeText.innerHTML =
                '<span class="text-success">₱' +
                change.toFixed(2) +
                '</span>';
        }
    }

    //SEARCH
    document
        .getElementById("searchProduct")
        .addEventListener("keyup", function () {
            let value = this.value.toLowerCase();
            document.querySelectorAll(".product-wrapper")
                .forEach(product => {
                    let name = product
                        .querySelector("h6")
                        .innerText
                        .toLowerCase();
                    if (name.indexOf(value) > -1) {
                        product.style.display = "";
                    } else {
                        product.style.display = "none";
                    }
                });
        });

    //CATEGORY FILTER
    document.addEventListener("DOMContentLoaded", function () {
        const categoryContainer = document.querySelector(".category-scroll");
        if (!categoryContainer) return;
        categoryContainer.addEventListener("click", function (e) {
            const btn = e.target.closest(".category-btn");
            if (!btn) return;
            document.querySelectorAll(".category-btn").forEach(b => {
                b.classList.remove("active");
            });
            btn.classList.add("active");
            let category = btn.dataset.category;
            document.querySelectorAll(".product-wrapper").forEach(product => {
                let productCategory = product.dataset.category;
                if (category === "All" || category === productCategory) {
                    product.style.display = "";
                } else {
                    product.style.display = "none";
                }
            });
        });
    });

    // COMPLETE SALE
    document.getElementById("completeSale").addEventListener("click", function () {
        if (cart.length === 0) {
            Swal.fire({
                icon: "warning",
                title: "Empty Cart",
                text: "Please add products first."
            });
            return;
        }

        let subtotal = parseFloat(
            document.getElementById("subtotal")
                .innerText
                .replace("₱", "")
        ) || 0;
        let tax = currentTax;
        let total = parseFloat(
            document.getElementById("total")
                .innerText
                .replace("₱", "")
        ) || 0;
        let cash = parseFloat(
            document.getElementById("cash").value
        ) || 0;
        let change = cash - total;

        // ====================================
        // CASH VALIDATION
        // ====================================
        if (cash < total) {
            Swal.fire({
                icon: "error",
                title: "Insufficient Cash",
                text: "Cash received is not enough."
            });
            return;
        }

        // ====================================
        // CONFIRMATION
        // ====================================
        let html = `
            <div class="text-start">
                <p>
                    <b>Payment:</b> Cash
                </p>
                <p>
                    <b>Subtotal:</b>
                    ₱${subtotal.toFixed(2)}
                </p>
                <p>
                    <b>Tax:</b>
                    ₱${tax.toFixed(2)}
                </p>
                <p>
                    <b>Total:</b>
                    ₱${total.toFixed(2)}
                </p>
                <p>
                    <b>Cash:</b>
                    ₱${cash.toFixed(2)}
                </p>
                <p>
                    <b>Change:</b>
                    ₱${change.toFixed(2)}
                </p>
            </div>
        `;

        Swal.fire({
            title: "Complete Sale?",
            html: html,
            icon: "question",
            showCancelButton: true,
            confirmButtonText: "Complete Sale",
            cancelButtonText: "Cancel",
            confirmButtonColor: "#198754"
        }).then((result) => {
            if (!result.isConfirmed) {
                return;
            }
            saveSale();
        });

    });

    function saveSale() {

        let form = new FormData();

        form.append("complete_sale", 1);

        form.append(
            "cart",
            JSON.stringify(cart)
        );

        let total = parseFloat(
            document.getElementById("total")
                .innerText
                .replace("₱", "")
        ) || 0;

        let cash = parseFloat(
            cashInput.value
        ) || 0;


        if (cash < total) {

            Swal.fire({
                icon: "error",
                title: "Insufficient Cash",
                text: "Cash received is not enough."
            });

            return;
        }


        form.append(
            "cash",
            cash
        );


        // ====================================
        // SAVE SALE
        // ====================================

        fetch("pointofsales.php", {

            method: "POST",

            body: form

        })

            .then(response => response.json())

            .then(data => {

                if (data.status === "success") {

                    updateStockAfterSale();

                    showReceipt(data);

                } else {

                    Swal.fire({
                        icon: "error",
                        title: "Transaction Failed",
                        text: data.message
                    });

                }

            })

            .catch(error => {

                console.error(error);

                Swal.fire({
                    icon: "error",
                    title: "Server Error",
                    text: "Unable to process the transaction."
                });

            });
    }

    // Update product stock chips on-screen after a successful sale (Cash or GCash)
    function updateStockAfterSale() {

        cart.forEach(item => {

            const card = document.querySelector(
                `.product-item[data-id="${item.id}"]`
            );

            if (card) {

                let stock =
                    parseInt(card.dataset.stock);

                stock -= item.qty;

                card.dataset.stock = stock;


                const stockText =
                    card.querySelector(".stock");

                if (stockText) {

                    stockText.innerHTML =
                        "x" + stock;
                }


                // Remove product if stock is zero
                if (stock <= 0) {

                    card
                        .closest(".product-wrapper")
                        .remove();
                }
            }

        });

    }

    function resetPOS() {
        cart = [];
        renderCart();
        cashInput.value = "";
        changeText.innerHTML = "₱0.00";
        paymentMethod = "Cash";
        cashBtn.click();
    }

    function showReceipt(data) {
        let receiptItems = "";
        cart.forEach(item => {
            receiptItems += `
        <tr>
            <td>${item.name}</td>
            <td class="text-center">${item.qty}</td>
            <td class="text-end">
                ₱${(item.price * item.qty).toFixed(2)}
            </td>
        </tr>
        `;
        });
        //=========================
        // PAYMENT DETAILS
        //=========================
        let paymentInfo = "";
        if (paymentMethod == "Cash") {
            paymentInfo = `
            <div class="d-flex justify-content-between">
                <span>Cash Received</span>
                <strong>₱${parseFloat(cashInput.value || data.total).toFixed(2)}</strong>
            </div>
            <div class="d-flex justify-content-between">
                <span>Change</span>
                <strong>${document.getElementById("change").innerHTML}</strong>
            </div>
        `;
        }
        else if (paymentMethod == "GCash") {
            paymentInfo = `
            <div class="alert alert-success">
            <i class="bi bi-phone-fill"></i>
            GCash Payment Confirmed by PayMongo
            </div>
            `;
        }
        //=========================
        // RECEIPT
        //=========================
        Swal.fire({
            width: 700,
            showConfirmButton: false,
            html: `
        <div id="receiptArea">
            <h3 class="fw-bold">
                NCST Sari-Sari Store
            </h3>
            <small>
                Point of Sale Receipt
            </small>
            <hr>
            <div class="text-start">
                <p class="mb-1">
                    <b>Date:</b>
                    ${new Date().toLocaleString()}
                </p>
                <p class="mb-1">
                    <b>Cashier:</b>
                    <?php echo htmlspecialchars($_SESSION['fullname']); ?>
                </p>
                <p class="mb-2">
                    <b>Payment Method:</b>
                    ${paymentMethod}
                </p>
            </div>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th width="70" class="text-center">
                            Qty
                        </th>
                        <th class="text-end">
                            Total
                        </th>
                    </tr>
                </thead>
                <tbody>
                    ${receiptItems}
                </tbody>
            </table>
            <hr>
            <div class="d-flex justify-content-between">
                <span>Subtotal</span>
                <strong>${subtotalText.innerHTML}</strong>
            </div>
            <div class="d-flex justify-content-between">
                <span>Tax</span>
                <strong>₱${parseFloat(data.tax).toFixed(2)}</strong>
            </div>
            <div class="d-flex justify-content-between">
                <span>Total</span>
                <strong>₱${parseFloat(data.total).toFixed(2)}</strong>
            </div>
            ${paymentInfo}
            <hr>
            <h5 class="text-center">
                Thank you for shopping!
            </h5>
        </div>
        <div class="mt-3">
            <button
                class="btn btn-success"
                onclick="printReceipt()">
                <i class="bi bi-printer"></i>
                Print Receipt
            </button>
        </div>
        `
        });
        resetPOS();
    }

    function printReceipt() {
        let receipt = document.getElementById("receiptArea").innerHTML;
        let win = window.open("", "", "width=800,height=700");
        win.document.write(`
        <html>
        <head>
            <title>
                Receipt
            </title>
            <link
            rel="stylesheet"
            href="../bootstrap-5.3.8-dist/css/bootstrap.min.css">
        </head>
        <body>
            <div class="container mt-4">
                ${receipt}
            </div>
        </body>
        </html>
    `);
        win.document.close();
        win.print();
        win.close();
        resetPOS();
    }

    //====================================
    // PAYMENT METHOD
    //====================================

    let paymentMethod = "Cash";

    const cashBtn = document.getElementById("cashBtn");
    const gcashBtn = document.getElementById("gcashBtn");

    const cashSection = document.getElementById("cashSection");
    const gcashSection = document.getElementById("gcashSection");

    cashBtn.onclick = function () {
        paymentMethod = "Cash";
        cashBtn.className = "btn btn-primary w-100";
        gcashBtn.className = "btn btn-outline-primary w-100";
        cashSection.style.display = "block";
        gcashSection.style.display = "none";
    }

    gcashBtn.onclick = function () {
        paymentMethod = "GCash";
        cashBtn.className = "btn btn-outline-secondary w-100";
        gcashBtn.className = "btn btn-primary w-100";
        cashSection.style.display = "none";
        gcashSection.style.display = "block";
        document.getElementById("gcashAmountText").innerHTML =
            document.getElementById("total").innerHTML;
    }


    //====================================
    // GCASH — REAL PAYMONGO PAYMENT
    //====================================

    let gcashPollInterval = null;
    let gcashCountdownInterval = null;
    let gcashCurrentIntentId = null;

    const gcashModalEl = document.getElementById("gcashModal");
    const gcashModal = new bootstrap.Modal(gcashModalEl);
    const gcashLoading = document.getElementById("gcashLoading");
    const gcashQRWrapper = document.getElementById("gcashQRWrapper");
    const gcashQRCodeDiv = document.getElementById("gcashQRCode");
    const gcashTimerEl = document.getElementById("gcashTimer");
    const gcashWaitingAlert = document.getElementById("gcashWaitingAlert");

    const GCASH_POLL_MS = 3000;      // check PayMongo every 3 seconds
    const GCASH_WINDOW_SECONDS = 300; // 5 minute counter-side waiting window

    document.getElementById("gcashSale").addEventListener("click", function () {

        if (cart.length == 0) {
            Swal.fire({
                icon: "warning",
                title: "Empty Cart",
                text: "Please add products first."
            });
            return;
        }

        startGCashPayment();
    });

    function startGCashPayment() {

        // Reset modal to loading state
        gcashLoading.style.display = "block";
        gcashQRWrapper.style.display = "none";
        gcashQRCodeDiv.innerHTML = "";
        gcashWaitingAlert.style.display = "block";
        gcashWaitingAlert.className = "alert alert-warning";
        gcashWaitingAlert.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Waiting for payment...';

        gcashModal.show();

        let form = new FormData();
        form.append("create_gcash_intent", 1);
        form.append("cart", JSON.stringify(cart));

        fetch("pointofsales.php", {
            method: "POST",
            body: form
        })
            .then(response => response.json())
            .then(data => {

                if (data.status !== "success") {

                    gcashModal.hide();

                    Swal.fire({
                        icon: "error",
                        title: "Unable to Start GCash Payment",
                        text: data.message
                    });

                    return;
                }

                gcashCurrentIntentId = data.intent_id;

                // Render QR code encoding the PayMongo checkout URL
                gcashQRCodeDiv.innerHTML = "";
                new QRCode(gcashQRCodeDiv, {
                    text: data.checkout_url,
                    width: 220,
                    height: 220
                });

                document.getElementById("gcashAmount").innerHTML =
                    "₱" + parseFloat(data.total).toFixed(2);

                gcashLoading.style.display = "none";
                gcashQRWrapper.style.display = "block";

                startGCashCountdown();
                startGCashPolling(data.intent_id);

            })
            .catch(error => {

                gcashModal.hide();

                console.error(error);

                Swal.fire({
                    icon: "error",
                    title: "Server Error",
                    text: "Unable to start GCash payment."
                });

            });
    }

    function startGCashPolling(intentId) {

        clearInterval(gcashPollInterval);

        gcashPollInterval = setInterval(function () {

            let form = new FormData();
            form.append("check_gcash_status", 1);
            form.append("intent_id", intentId);

            fetch("pointofsales.php", {
                method: "POST",
                body: form
            })
                .then(response => response.json())
                .then(data => {

                    if (data.status === "success") {

                        stopGCashPolling();
                        gcashModal.hide();

                        updateStockAfterSale();
                        showReceipt(data);

                    } else if (data.status === "failed") {

                        stopGCashPolling();
                        gcashModal.hide();

                        Swal.fire({
                            icon: "error",
                            title: "Payment Failed",
                            text: data.message || "The GCash payment was not completed."
                        });

                    } else if (data.status === "error") {

                        stopGCashPolling();
                        gcashModal.hide();

                        Swal.fire({
                            icon: "error",
                            title: "Payment Error",
                            text: data.message
                        });

                    }
                    // status === "pending" -> keep polling silently

                })
                .catch(error => {
                    console.error(error);
                    // transient network hiccup — keep polling, don't interrupt the cashier
                });

        }, GCASH_POLL_MS);
    }

    function startGCashCountdown() {

        clearInterval(gcashCountdownInterval);

        let secondsLeft = GCASH_WINDOW_SECONDS;
        gcashTimerEl.innerHTML = secondsLeft;

        gcashCountdownInterval = setInterval(function () {

            secondsLeft--;
            gcashTimerEl.innerHTML = secondsLeft;

            if (secondsLeft <= 0) {

                stopGCashPolling();
                gcashModal.hide();

                Swal.fire({
                    icon: "warning",
                    title: "Payment Window Expired",
                    text: "The customer didn't complete the GCash payment in time. You can try again."
                });
            }

        }, 1000);
    }

    function stopGCashPolling() {
        clearInterval(gcashPollInterval);
        clearInterval(gcashCountdownInterval);
        gcashPollInterval = null;
        gcashCountdownInterval = null;
    }

    // Stop polling if the cashier manually cancels
    document.getElementById("gcashCancelBtn").addEventListener("click", function () {
        stopGCashPolling();
        gcashCurrentIntentId = null;
    });

</script>