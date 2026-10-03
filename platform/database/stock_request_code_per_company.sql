/*
|--------------------------------------------------------------------------
| STOCK REQUEST CODE IS PER COMPANY, NOT GLOBAL
|--------------------------------------------------------------------------
|
| stock_requests.request_code carried a globally UNIQUE index, but the code
| that generates the next number has always scoped its lookup to one company:
|
|     SELECT request_code FROM stock_requests
|     WHERE company_id = ? ORDER BY request_id DESC LIMIT 1
|
| So the first company to file a request took SR-001 for the whole platform,
| and the next company to try was rejected with
| "Duplicate entry 'SR-001' for key 'uq_request_code'" -- on its very first
| request, with no way around it.
|
| The generator is right and the constraint was wrong: a reference code is
| meaningful inside the business that issued it, exactly like every other
| tenant-scoped value in this schema. The key becomes composite so each
| company keeps its own SR-001, SR-002, ... sequence.
|
*/

ALTER TABLE stock_requests
    DROP INDEX uq_request_code;

ALTER TABLE stock_requests
    ADD UNIQUE KEY uq_request_code_per_company (company_id, request_code);
