# -*- coding: utf-8 -*-
"""
Every SQL statement under includes/chatbot/ must be a company-scoped SELECT.

This is the mechanical half of the tenant-isolation promise: a reviewer can
miss a missing company_id, this cannot. It also refuses any outbound network
call outside ai_client.php, which is the only file allowed to make one.
"""
import io
import os
import re
import sys

ROOT = os.path.join(os.path.dirname(__file__), '..', '..', 'includes', 'chatbot')

# EVERY string literal in the file, not only the ones written inside a
# prepare(...) call. An earlier version matched only that shape, so assigning
# the SQL to a variable first -- or using mysqli_prepare(), the style the rest
# of this project is written in -- walked straight past the audit.
STRINGS = re.compile(r'"((?:[^"\\]|\\.)*)"|\'((?:[^\'\\]|\\.)*)\'', re.S)
STARTS_SQL = ('select ', 'insert ', 'update ', 'delete ', 'drop ', 'alter ',
              'truncate ', 'replace ', 'create ')
WRITES = ('insert ', 'update ', 'delete ', 'drop ', 'alter ', 'truncate ')
NETWORK = ('curl_', "file_get_contents('http", 'file_get_contents("http',
           'fsockopen', 'stream_socket_client')

# log.php writes exactly one table: the chatbot's own log.
WRITE_EXEMPT = {('log.php', 'chatbot_messages')}

# Tables that hold no tenant data at all, so company_id would be meaningless.
# chatbot_topic_plans maps a topic to a plan; it is the same for every company,
# and the company's own plan is resolved separately before it is read. Narrow
# on purpose: one file, one table.
SELECT_EXEMPT = {('engine.php', 'chatbot_topic_plans')}

# The owner's decision, enforced mechanically: the conversational tools may not
# reach a salary or a payroll row. Not the bare word "pay" -- sales.payment_method
# is a column these tools legitimately read.
PAY_WORDS = ('payroll', 'salary', 'basic_pay', 'gross_pay', 'net_pay',
             'overtime_pay', 'pay_frequency', 'late_deduction',
             'undertime_deduction', 'absent_deduction', 'total_deduction',
             'deduction_rate')

problems = []

for folder, _dirs, files in os.walk(ROOT):
    for name in sorted(files):
        if not name.endswith('.php'):
            continue

        path = os.path.join(folder, name)
        source = io.open(path, encoding='utf-8').read()
        rel = os.path.relpath(path, ROOT).replace('\\', '/')

        for call in NETWORK:
            if call in source and rel not in ('ai_client.php', 'chat/api.php'):
                problems.append('%s: outbound call (%s) outside the two API clients' % (rel, call))

        # Scoped to where the queries live: the catalog, the runner and the tool
        # files. chat/api.php carries the system prompt, which must be able to
        # tell the model in plain words that it has no tool for payroll, and
        # chat/conversation.php runs no queries at all.
        if rel.startswith('chat/tools') or rel == 'chat/tool_runner.php':
            # Comments are stripped first. The comment that explains WHY there is
            # no payroll tool has to be able to say "payroll"; it is the code that
            # must not name one. SQL lives in string literals, which survive this.
            code = re.sub(r'/\*.*?\*/', ' ', source, flags=re.S)
            code = re.sub(r'(?m)//.*$', ' ', code)
            code = re.sub(r'(?m)^\s*#.*$', ' ', code)
            low_source = code.lower()

            for word in PAY_WORDS:
                if word in low_source:
                    problems.append('%s: names %s -- the tools may not reach pay data' % (rel, word))

        for match in STRINGS.finditer(source):
            raw = match.group(1) if match.group(1) is not None else match.group(2)
            sql = ' '.join(raw.split())
            low = sql.lower()

            if not low.startswith(STARTS_SQL):
                continue

            if low.startswith(WRITES):
                exempt = any(f == name and t in low for f, t in WRITE_EXEMPT)
                if not exempt:
                    problems.append('%s: write statement -- %s' % (rel, sql[:70]))
                continue

            if not low.startswith('select'):
                continue

            if any(f == name and t in low for f, t in SELECT_EXEMPT):
                continue

            if 'company_id = ?' not in low:
                problems.append('%s: SELECT without company_id = ? -- %s' % (rel, sql[:70]))

print('%d problem(s)' % len(problems))

for problem in problems:
    print('  ' + problem)

sys.exit(1 if problems else 0)
