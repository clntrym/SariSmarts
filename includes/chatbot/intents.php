<?php
/*
|--------------------------------------------------------------------------
| THE CATALOG
|--------------------------------------------------------------------------
|
| Every question the assistant can answer, who may ask it, and which plan
| topic it belongs to. Data only -- no queries, no logic. The role matrix in
| the spec is enforced by the 'roles' key here and nowhere else, so there is
| one list to read when asking "can a cashier see this?".
|
| A personal question is always its own entry ('scope' => 'own'), never the
| same entry with a wider scope for another role. One entry, one scope.
|
| Keyword groups are ANDed; the synonyms inside a group are ORed. A synonym
| may be a phrase ("out of stock"), which is matched as a phrase.
*/
function chatbotIntents(): array
{
    return [

        /*
        | First in the catalog on purpose. A pay question shares "how much is"
        | and "magkano" with the price question, and on a tie the earlier entry
        | wins -- so this one does. Without it, "how much is the salary of Ana
        | Cruz" came back as a product search for "salary of ana cruz", which is
        | a confusing answer to the one subject the owner ruled out.
        |
        | 'hidden' keeps it out of the suggestion chips: nobody needs a button
        | for something the assistant cannot do.
        */
        'salary_not_available' => [
            'topic' => 'staff',
            'roles' => ['admin', 'cashier', 'employee', 'hr', 'finance', 'inventory'],
            'scope' => 'company',
            'hidden' => true,
            'local_only' => true,
            'label' => 'Salaries and payroll',
            'keywords' => [
                ['salary', 'salaries', 'sweldo', 'suweldo', 'sahod', 'payroll',
                 'payrolls', 'payslip', 'pay of', 'deduction', 'deductions'],
            ],
            'handler' => 'chatbotSalaryNotAvailable',
        ],

        'sales_today' => [
            'topic' => 'pos',
            'roles' => ['admin'],
            'scope' => 'company',
            'label' => 'Sales today',
            'keywords' => [
                ['benta', 'sales', 'kita', 'sold', 'nabenta', 'kinita'],
                ['ngayon', 'ngayong araw', 'today', 'araw'],
            ],
            'handler' => 'chatbotSalesToday',
        ],

        'sales_month' => [
            'topic' => 'pos',
            'roles' => ['admin'],
            'scope' => 'company',
            'label' => 'Sales this month',
            'keywords' => [
                ['benta', 'sales', 'kita', 'sold', 'nabenta', 'kinita'],
                ['buwan', 'month', 'ngayong buwan', 'this month'],
            ],
            'handler' => 'chatbotSalesMonth',
        ],

        'top_products_month' => [
            'topic' => 'reports',
            'roles' => ['admin'],
            'scope' => 'company',
            'label' => 'Top selling products',
            'keywords' => [
                ['top', 'top selling', 'pinakamabenta', 'pinakamabentang', 'best selling', 'bestseller', 'mabenta'],
                ['produkto', 'product', 'products', 'item', 'items', 'paninda'],
            ],
            'handler' => 'chatbotTopProducts',
        ],

        'low_stock' => [
            'topic' => 'inventory',
            'roles' => ['admin'],
            'scope' => 'company',
            'label' => 'Low stock',
            'keywords' => [
                ['low', 'mababa', 'mababang', 'kulang', 'konti', 'reorder'],
                ['stock', 'stocks', 'inventory', 'produkto', 'paninda', 'products'],
            ],
            'handler' => 'chatbotLowStock',
        ],

        'out_of_stock' => [
            'topic' => 'inventory',
            'roles' => ['admin'],
            'scope' => 'company',
            'label' => 'Out of stock',
            'keywords' => [
                ['out of stock', 'ubos', 'wala nang stock', 'naubos', 'sold out'],
            ],
            'handler' => 'chatbotOutOfStock',
        ],

        'product_price' => [
            'topic' => 'pos',
            'roles' => ['admin', 'cashier', 'inventory'],
            'scope' => 'company',
            'label' => 'Price of product',
            'needs_input' => true,
            'keywords' => [
                ['magkano', 'presyo', 'price', 'price of', 'how much is', 'cost'],
            ],
            'handler' => 'chatbotProductPrice',
        ],

        'product_stock' => [
            'topic' => 'inventory',
            'roles' => ['admin', 'cashier', 'inventory'],
            'scope' => 'company',
            'label' => 'Stock of product',
            'needs_input' => true,
            'keywords' => [
                ['may stock', 'meron pa', 'in stock', 'available', 'ilan pa', 'stock of'],
            ],
            'handler' => 'chatbotProductStock',
        ],

        'staff_count' => [
            'topic' => 'staff',
            'roles' => ['admin'],
            'scope' => 'company',
            'label' => 'Employee count',
            'keywords' => [
                ['ilan', 'how many', 'bilang', 'count'],
                ['empleyado', 'employee', 'employees', 'staff', 'tauhan'],
            ],
            'handler' => 'chatbotStaffCount',
        ],

        'my_sales_today' => [
            'topic' => 'pos',
            'roles' => ['cashier'],
            'scope' => 'own',
            'label' => 'My sales today',
            'keywords' => [
                ['ako', 'ko', 'i', 'my', 'have i'],
                ['benta', 'sales', 'sold', 'nabenta', 'nabentahan'],
            ],
            'handler' => 'chatbotMySalesToday',
        ],

        /*
        | Personal attendance and leave sit under 'staff', not 'attendance'.
        | The attendance topic covers the company-wide HR views Retail Starter
        | does not buy -- but a Starter cashier still clocks in, and must be
        | able to ask about their own time records.
        */
        'my_attendance_today' => [
            'topic' => 'staff',
            'roles' => ['cashier', 'employee'],
            'scope' => 'own',
            'label' => 'My attendance today',
            'keywords' => [
                ['attendance', 'time in', 'time out', 'pasok', 'oras ko', 'ngayon', 'today'],
            ],
            'handler' => 'chatbotMyAttendanceToday',
        ],

        'my_leave_status' => [
            'topic' => 'staff',
            'roles' => ['cashier', 'employee'],
            'scope' => 'own',
            'label' => 'My leave status',
            'keywords' => [
                ['leave', 'bakasyon', 'day off', 'absent request', 'status'],
            ],
            'handler' => 'chatbotMyLeaveStatus',
        ],
        'what_needs_attention' => [
            'topic' => 'staff',
            'roles' => ['admin', 'hr', 'finance', 'inventory', 'cashier', 'employee'],
            'scope' => 'company',
            'label' => 'What needs attention',
            'keywords' => [
                /* Single words, because a phrase breaks on the word between:
                   "what needs MY attention" does not contain "needs attention". */
                ['attention', 'asikasuhin', 'aasikasuhin', 'dapat kong gawin',
                 'to do', 'pending items', 'anything for me', 'what should i do',
                 'ano ang dapat'],
            ],
            'handler' => 'chatbotWhatNeedsAttention',
        ],

        'hr_headcount' => [
            'topic' => 'hrms',
            /* Not the owner: they have staff_count from Phase 1, and two intents
               with the same label would fight over the same chip. */
            'roles' => ['hr'],
            'scope' => 'company',
            'label' => 'Employee count',
            'keywords' => [
                ['ilan', 'how many', 'bilang', 'count', 'headcount'],
                ['empleyado', 'employee', 'employees', 'staff', 'tauhan'],
            ],
            'handler' => 'chatbotHrHeadcount',
        ],

        'hr_pending_leave' => [
            'topic' => 'leave',
            'roles' => ['hr', 'admin'],
            'scope' => 'company',
            'label' => 'Pending leave requests',
            'keywords' => [
                ['pending', 'naghihintay', 'for approval'],
                ['leave', 'bakasyon', 'day off'],
            ],
            'handler' => 'chatbotHrPendingLeave',
        ],

        'hr_attendance_today' => [
            'topic' => 'attendance',
            'roles' => ['hr', 'admin'],
            'scope' => 'company',
            'label' => 'Attendance today',
            'keywords' => [
                ['late', 'absent', 'attendance', 'pasok', 'present'],
                ['today', 'ngayon', 'ngayong araw'],
            ],
            'handler' => 'chatbotHrAttendanceToday',
        ],

        'hr_applicants' => [
            'topic' => 'recruitment',
            'roles' => ['hr', 'admin'],
            'scope' => 'company',
            'label' => 'Applicants',
            'keywords' => [
                ['applicant', 'applicants', 'nag apply', 'nag-apply', 'aplikante',
                 'shortlisted', 'interview'],
            ],
            'handler' => 'chatbotHrApplicants',
        ],

        'hr_open_jobs' => [
            'topic' => 'recruitment',
            'roles' => ['hr', 'admin'],
            'scope' => 'company',
            'label' => 'Open job postings',
            'keywords' => [
                ['job posting', 'job postings', 'hiring', 'bakante', 'vacancy',
                 'vacancies', 'open position'],
            ],
            'handler' => 'chatbotHrOpenJobs',
        ],

        'hr_incomplete_records' => [
            'topic' => 'hrms',
            'roles' => ['hr', 'admin'],
            'scope' => 'company',
            'label' => 'Incomplete employee records',
            'keywords' => [
                ['incomplete', 'kulang', 'missing', 'walang'],
                ['record', 'records', 'detalye', 'information', 'employee'],
            ],
            'handler' => 'chatbotHrIncompleteRecords',
        ],
        'finance_payroll_total' => [
            'topic' => 'payroll',
            'roles' => ['finance', 'admin', 'hr'],
            'scope' => 'company',
            'local_only' => true,
            'label' => 'Payroll this month',
            'keywords' => [
                /* 'sweldo' and 'sahod' are back: they are how a Tagalog
                   speaker asks for the payroll run. An INDIVIDUAL's pay is told
                   apart by the person marker (ni / my / ko), which the engine
                   checks before this intent can win. */
                ['payroll', 'payrolls', 'sweldo', 'suweldo', 'sahod'],
                ['total', 'kabuuan', 'magkano', 'how much', 'this month', 'ngayong buwan'],
            ],
            'handler' => 'chatbotFinancePayrollTotal',
        ],

        'finance_payroll_pending' => [
            'topic' => 'payroll',
            'roles' => ['finance', 'admin'],
            'scope' => 'company',
            'local_only' => true,
            'label' => 'Payroll awaiting approval',
            'keywords' => [
                /* 'payrolls' too: "which payrolls are pending approval" is the
                   question the matrix itself uses as its example, and the
                   singular does not match it. */
                ['payroll', 'payrolls'],
                ['pending', 'approval', 'aprubahan', 'waiting', 'naghihintay'],
            ],
            'handler' => 'chatbotFinancePayrollPending',
        ],

        'finance_expenses' => [
            'topic' => 'finance',
            'roles' => ['finance', 'admin'],
            'scope' => 'company',
            'label' => 'Expenses this month',
            'keywords' => [
                ['expense', 'expenses', 'gastos', 'spending'],
            ],
            'handler' => 'chatbotFinanceExpenses',
        ],

        'finance_payables' => [
            'topic' => 'finance',
            'roles' => ['finance', 'admin'],
            'scope' => 'company',
            'label' => 'Unpaid supplier bills',
            'keywords' => [
                /* 'supplier bills' as well as the singular: the chip's own label
                   is "Unpaid supplier bills", and a label that does not match
                   its own keywords is a button that refuses itself. */
                ['owe', 'payable', 'payables', 'utang namin', 'babayaran',
                 'supplier bill', 'supplier bills'],
            ],
            'handler' => 'chatbotFinancePayables',
        ],

        'finance_stock_requests' => [
            'topic' => 'finance',
            'roles' => ['finance', 'admin'],
            'scope' => 'company',
            'label' => 'Stock requests for finance approval',
            'keywords' => [
                ['stock request', 'stock requests', 'request', 'budget request',
                 'budget requests'],
                ['finance', 'approval', 'aprubahan', 'pending'],
            ],
            'handler' => 'chatbotFinanceStockRequests',
        ],
        'inv_low_stock' => [
            'topic' => 'inventory',
            /* Not the owner: Phase 1 already answers this for them. */
            'roles' => ['inventory'],
            'scope' => 'company',
            'label' => 'Low stock',
            'keywords' => [
                ['low', 'mababa', 'mababang', 'kulang', 'konti', 'reorder'],
                ['stock', 'stocks', 'inventory', 'produkto', 'paninda', 'products'],
            ],
            'handler' => 'chatbotInvLowStock',
        ],

        'inv_out_of_stock' => [
            'topic' => 'inventory',
            /* Not the owner: Phase 1 already answers this for them. */
            'roles' => ['inventory'],
            'scope' => 'company',
            'label' => 'Out of stock',
            'keywords' => [
                ['out of stock', 'ubos', 'wala nang stock', 'naubos', 'sold out'],
            ],
            'handler' => 'chatbotInvOutOfStock',
        ],

        'inv_stock_requests' => [
            'topic' => 'inventory',
            'roles' => ['inventory', 'admin'],
            'scope' => 'company',
            'label' => 'Stock requests',
            'keywords' => [
                ['stock request', 'stock requests', 'request', 'requests', 'hiling'],
            ],
            'handler' => 'chatbotInvStockRequests',
        ],

        'inv_suppliers' => [
            'topic' => 'inventory',
            'roles' => ['inventory', 'admin'],
            'scope' => 'company',
            'label' => 'Suppliers',
            'keywords' => [
                ['supplier', 'suppliers', 'tagatustos', 'vendor', 'vendors'],
            ],
            'handler' => 'chatbotInvSuppliers',
        ],
    ];
}
