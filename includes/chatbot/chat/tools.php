<?php
/*
|--------------------------------------------------------------------------
| THE TOOL CATALOG
|--------------------------------------------------------------------------
|
| The whole boundary of what the assistant can know. The model chooses among
| these and nothing else: if there is no tool for something, no phrasing of a
| question can reach it.
|
| There is deliberately no tool that reads a salary or a payroll row. That is
| the owner's decision, enforced by the absence of the tool and by the audit in
| tests/chatbot/query_audit.py -- not by a setting somebody could flip.
|
| 'description' is written for the model, not for us: it is how it decides
| which tool answers the question in front of it.
*/
function chatTools(): array
{
    return [

        'sales_summary' => [
            'topic' => 'pos',
            'roles' => ['admin'],
            'scope' => 'company',
            'description' => 'Total sales amount, number of transactions and average '
                . 'transaction for a period. Use for questions about takings or revenue.',
            'input' => [
                'period' => [
                    'type' => 'enum',
                    'values' => ['today', 'yesterday', 'this_week', 'last_week',
                                 'this_month', 'last_month', 'custom'],
                    'required' => true,
                ],
                'from' => ['type' => 'date'],
                'to' => ['type' => 'date'],
            ],
            'handler' => 'chatToolSalesSummary',
        ],

        'sales_by_day' => [
            'topic' => 'pos',
            'roles' => ['admin'],
            'scope' => 'company',
            'description' => 'Sales totals for each day between two dates. Use to '
                . 'compare days, find trends, or find the best or worst day.',
            'input' => [
                'from' => ['type' => 'date', 'required' => true],
                'to' => ['type' => 'date', 'required' => true],
            ],
            'handler' => 'chatToolSalesByDay',
        ],

        'top_products' => [
            'topic' => 'reports',
            'roles' => ['admin'],
            'scope' => 'company',
            'description' => 'Best selling products in a period, with quantity sold '
                . 'and revenue. Use for what sells, what to restock, what is popular.',
            'input' => [
                'period' => [
                    'type' => 'enum',
                    'values' => ['today', 'yesterday', 'this_week', 'last_week',
                                 'this_month', 'last_month', 'custom'],
                    'required' => true,
                ],
                'from' => ['type' => 'date'],
                'to' => ['type' => 'date'],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
            ],
            'handler' => 'chatToolTopProducts',
        ],

        'payment_mix' => [
            'topic' => 'pos',
            'roles' => ['admin'],
            'scope' => 'company',
            'description' => 'How sales split between Cash and GCash for a period.',
            'input' => [
                'period' => [
                    'type' => 'enum',
                    'values' => ['today', 'yesterday', 'this_week', 'last_week',
                                 'this_month', 'last_month', 'custom'],
                    'required' => true,
                ],
                'from' => ['type' => 'date'],
                'to' => ['type' => 'date'],
            ],
            'handler' => 'chatToolPaymentMix',
        ],

        'stock_list' => [
            'topic' => 'inventory',
            'roles' => ['admin', 'cashier', 'inventory'],
            'scope' => 'company',
            'description' => 'Products by stock state: low (at or below reorder level), '
                . 'out (none left), or all. Use for restocking questions.',
            'input' => [
                'state' => [
                    'type' => 'enum',
                    'values' => ['low', 'out', 'all'],
                    'required' => true,
                ],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
            ],
            'handler' => 'chatToolStockList',
        ],

        'product_lookup' => [
            'topic' => 'inventory',
            'roles' => ['admin', 'cashier', 'inventory'],
            'scope' => 'company',
            'description' => 'Price, quantity on hand and category for products whose '
                . 'name matches the given text.',
            'input' => [
                'name' => ['type' => 'string', 'required' => true, 'max' => 100],
            ],
            'handler' => 'chatToolProductLookup',
        ],

        'stock_requests' => [
            'topic' => 'inventory',
            'roles' => ['admin', 'inventory', 'finance'],
            'scope' => 'company',
            'description' => 'Stock requests and their status, newest first. A request '
                . 'goes Pending Finance, then Pending Admin, then Received. Use "all" '
                . 'when the question does not name a stage.',
            'input' => [
                /* Exactly the values the status column holds. An earlier draft
                   offered Pending/Approved/Rejected, which match nothing, so the
                   assistant reported "no pending requests" while requests waited. */
                'status' => [
                    'type' => 'enum',
                    'values' => ['Pending Finance', 'Finance Approved', 'Finance Rejected',
                                 'Pending Admin', 'Admin Approved', 'Admin Rejected',
                                 'Received', 'Cancelled', 'all'],
                    'required' => true,
                ],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
            ],
            'handler' => 'chatToolStockRequests',
        ],
        'staff_list' => [
            'topic' => 'staff',
            'roles' => ['admin', 'hr'],
            'scope' => 'company',
            'description' => 'The people on the team: name, role, branch and whether '
                . 'they are active. Use for "how many staff", "who works at which '
                . 'branch", "sino ang mga empleyado".',
            'input' => [
                'state' => [
                    'type' => 'enum',
                    'values' => ['active', 'all'],
                    'required' => true,
                ],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
            ],
            'handler' => 'chatToolStaffList',
        ],

        'company_profile' => [
            'topic' => 'staff',
            'roles' => ['admin'],
            'scope' => 'company',
            'description' => 'The business itself: name, city, province, subscription '
                . 'plan and number of branches.',
            'input' => [],
            'handler' => 'chatToolCompanyProfile',
        ],
        'attendance_summary' => [
            'topic' => 'attendance',
            'roles' => ['admin', 'hr'],
            'scope' => 'company',
            'description' => 'Per-employee attendance counts for a period: days present, '
                . 'late, absent and total late minutes. Use for "who is often late", '
                . '"how many were absent", "sino ang madalas ma-late".',
            'input' => [
                'period' => [
                    'type' => 'enum',
                    'values' => ['today', 'yesterday', 'this_week', 'last_week',
                                 'this_month', 'last_month', 'custom'],
                    'required' => true,
                ],
                'from' => ['type' => 'date'],
                'to' => ['type' => 'date'],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
            ],
            'handler' => 'chatToolAttendanceSummary',
        ],

        'attendance_detail' => [
            'topic' => 'attendance',
            'roles' => ['admin', 'hr'],
            'scope' => 'company',
            'description' => 'One employee\'s day-by-day time in and time out for a '
                . 'period. Give part of their name.',
            'input' => [
                'employee' => ['type' => 'string', 'required' => true, 'max' => 100],
                'period' => [
                    'type' => 'enum',
                    'values' => ['today', 'yesterday', 'this_week', 'last_week',
                                 'this_month', 'last_month', 'custom'],
                    'required' => true,
                ],
                'from' => ['type' => 'date'],
                'to' => ['type' => 'date'],
            ],
            'handler' => 'chatToolAttendanceDetail',
        ],
        'my_attendance' => [
            'topic' => 'staff',
            'roles' => ['cashier', 'employee'],
            'scope' => 'own',
            'description' => 'The asker\'s own attendance for a period: date, time in, '
                . 'time out, status.',
            'input' => [
                'period' => [
                    'type' => 'enum',
                    'values' => ['today', 'yesterday', 'this_week', 'last_week',
                                 'this_month', 'last_month', 'custom'],
                    'required' => true,
                ],
                'from' => ['type' => 'date'],
                'to' => ['type' => 'date'],
            ],
            'handler' => 'chatToolMyAttendance',
        ],

        'my_leave' => [
            'topic' => 'staff',
            'roles' => ['cashier', 'employee'],
            'scope' => 'own',
            'description' => 'The asker\'s own leave requests with their HR status. The '
                . 'admin_status column is part of an approval step the app does not '
                . 'complete yet, so do not read it as the owner decision.',
            'input' => [
                'status' => [
                    'type' => 'enum',
                    'values' => ['Pending', 'Approved', 'Rejected', 'all'],
                    'required' => true,
                ],
            ],
            'handler' => 'chatToolMyLeave',
        ],
        'leave_requests' => [
            'topic' => 'leave',
            'roles' => ['admin', 'hr'],
            'scope' => 'company',
            'description' => 'Leave requests across the team with their HR status. The '
                . 'admin_status column is part of an approval step the app does not '
                . 'complete yet, so do not read it as the owner decision. Use for '
                . '"who has a pending leave", "sino ang may leave".',
            'input' => [
                'status' => [
                    'type' => 'enum',
                    'values' => ['Pending', 'Approved', 'Rejected', 'all'],
                    'required' => true,
                ],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
            ],
            'handler' => 'chatToolLeaveRequests',
        ],

        'recruitment_summary' => [
            'topic' => 'recruitment',
            'roles' => ['admin', 'hr'],
            'scope' => 'company',
            'description' => 'Job postings with how many people applied and how many '
                . 'are at each stage. Use for "how many applicants", "do we have an '
                . 'open hiring", "ilan ang nag-apply".',
            'input' => [
                'state' => [
                    'type' => 'enum',
                    'values' => ['open', 'all'],
                    'required' => true,
                ],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
            ],
            'handler' => 'chatToolRecruitmentSummary',
        ],
        'branch_list' => [
            'topic' => 'branch',
            'roles' => ['admin'],
            'scope' => 'company',
            'description' => 'The branches of this business: name, city, province, '
                . 'opening and closing time, and whether the branch is active.',
            'input' => [],
            'handler' => 'chatToolBranchList',
        ],
        'finance_expenses' => [
            'topic' => 'finance',
            'roles' => ['admin', 'finance'],
            'scope' => 'company',
            'description' => 'Expenses for a period, grouped by category, with the '
                . 'total. Use for "what did we spend on", "magkano ang gastos".',
            'input' => [
                'period' => [
                    'type' => 'enum',
                    'values' => ['today', 'yesterday', 'this_week', 'last_week',
                                 'this_month', 'last_month', 'custom'],
                    'required' => true,
                ],
                'from' => ['type' => 'date'],
                'to' => ['type' => 'date'],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
            ],
            'handler' => 'chatToolFinanceExpenses',
        ],

        'finance_payables' => [
            'topic' => 'finance',
            'roles' => ['admin', 'finance'],
            'scope' => 'company',
            'description' => 'What the business owes suppliers: each bill, what is '
                . 'still unpaid, and when it falls due.',
            'input' => [
                'status' => [
                    'type' => 'enum',
                    'values' => ['unpaid', 'Pending', 'Partial', 'Paid', 'all'],
                    'required' => true,
                ],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
            ],
            'handler' => 'chatToolFinancePayables',
        ],
    ];
}
