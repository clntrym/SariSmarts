<?php
/*
| Whether the sending domain will let somebody else send for it.
|
| A sender can be verified with Brevo and still be refused by every
| recipient, and these are different questions asked by different parties.
| Brevo's verification means "we checked that you own this mailbox". DMARC
| is the domain owner's instruction to receivers about who may send as that
| domain -- and a relay the owner never authorised is exactly what it exists
| to stop.
|
| RetailCore was configured to send as salarda.cleintraymund@ncst.edu.ph on
| the strength of Brevo showing a green "DMARC is configured". That check
| meant the domain HAS a policy, which is the opposite of permission:
|
|     _dmarc.ncst.edu.ph   v=DMARC1; p=reject; pct=100
|
| p=reject tells every receiver to refuse unauthenticated mail from that
| domain, and the school's webmaster is the only person who could authorise
| Brevo under it. Every approval mail soft-bounced:
|
|     550-5.7.26 Unauthenticated email from ncst.edu.ph is not accepted
|     due to domain's DMARC policy
|
| Gmail publishes p=none, so the freemail address Brevo warns about is the
| one that actually arrives. "Not recommended" and "will not be delivered"
| are different warnings, and only one of them stops mail.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/mail_settings.php';

/* ------------------------------------------------- reading the policy */

t_same('reject', mailDmarcPolicy('v=DMARC1; p=reject; rua=mailto:x@y; pct=100; sp=none'),
    'p=reject is read, in the record that actually caused this');
t_same('none', mailDmarcPolicy('v=DMARC1; p=none; sp=quarantine; rua=mailto:x@y'),
    'and p=none, which is what lets a relay through');
t_same('quarantine', mailDmarcPolicy('v=DMARC1;p=quarantine'),
    'spacing is not part of the policy');
t_same('reject', mailDmarcPolicy('v=DMARC1; P=REJECT'),
    'nor is case -- the tag is case-insensitive in practice');

/* A domain with no policy at all. */
t_same('none', mailDmarcPolicy(''), 'no record is the same permission as p=none');
t_same('none', mailDmarcPolicy('v=spf1 include:example.com ~all'),
    'an SPF record is not a DMARC record');

/*
| A record with no p= tag is malformed. Receivers treat it as no policy,
| which is the permissive reading -- and guessing "reject" here would warn
| somebody away from an address that works.
*/
t_same('none', mailDmarcPolicy('v=DMARC1; rua=mailto:x@y'),
    'a record with no p= is not an instruction to reject');

/* ------------------------------------------- which policies block a relay */

t_ok(mailPolicyBlocksRelay('reject'), 'reject blocks a third-party sender');
t_ok(mailPolicyBlocksRelay('quarantine'),
    'and quarantine does too -- delivered to spam is not delivered');
t_ok(!mailPolicyBlocksRelay('none'), 'none does not');

/* ------------------------------------------------- the domain of a sender */

t_same('ncst.edu.ph', mailSenderDomain('salarda.cleintraymund@ncst.edu.ph'),
    'the domain is taken from the address');
t_same('gmail.com', mailSenderDomain('  MagpantayDaizy3@GMAIL.com  '),
    'lowercased, and trimmed of what a copy-paste leaves behind');
t_same('', mailSenderDomain('not-an-address'), 'and absent where there is none');

t_done();
