/*
|--------------------------------------------------------------------------
| A DOCUMENT FOR EVERY PERMIT NUMBER
|--------------------------------------------------------------------------
|
| Registration collected four permit numbers -- business registration, DTI or
| SEC, business permit, TIN -- and exactly one file to back all four up. The
| reviewer had a number to read and nothing to check it against.
|
| Each number now carries its own upload. The old single-file column stays
| where it is: companies registered before this have their document there,
| and dropping it would erase what the reviewer already accepted.
|
*/

ALTER TABLE company
    ADD COLUMN business_reg_document    VARCHAR(255) NULL AFTER business_reg_number,
    ADD COLUMN dti_sec_document         VARCHAR(255) NULL AFTER dti_sec_registration,
    ADD COLUMN business_permit_document VARCHAR(255) NULL AFTER business_permit,
    ADD COLUMN tin_document             VARCHAR(255) NULL AFTER tin_number;
