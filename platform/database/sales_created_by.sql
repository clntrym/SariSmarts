/*
| Who rang up a sale.
|
| The Cashier assistant promises "how much have I sold today?", but sales
| recorded only the amounts, the payment method, the date and the company --
| never the person. cashier/pointofsales.php now fills this in.
|
| Nullable on purpose: every sale already taken has no cashier to name, and
| those rows must keep working. The assistant counts only attributed rows and
| says so, rather than quietly reporting a smaller number.
*/
ALTER TABLE sales
    ADD COLUMN created_by INT(11) NULL AFTER company_id,
    ADD CONSTRAINT fk_sales_created_by
        FOREIGN KEY (created_by) REFERENCES users(user_id);
