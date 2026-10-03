<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/chatbot/intents.php';
require_once __DIR__ . '/../../includes/chatbot/understand.php';

$intents = chatbotIntents();

/* Normalising */
t_same('magkano ang benta ngayon', chatbotNormalise('  Magkano ang BENTA ngayon??  '),
    'normalise lowercases, strips punctuation, collapses spaces');
t_same('', chatbotNormalise('   '), 'blank question normalises to empty');

/* English and Tagalog reach the same intent */
t_same('sales_today', chatbotKeywordMatch('What are our total sales today?', $intents),
    'English sales today');
t_same('sales_today', chatbotKeywordMatch('magkano ang benta ngayong araw', $intents),
    'Tagalog sales today');

/* The specific intent wins over the looser one */
t_same('sales_month', chatbotKeywordMatch('how much were our sales this month', $intents),
    'this month does not fall through to today');

t_same('low_stock', chatbotKeywordMatch('which products are low in stock', $intents),
    'low stock');
t_same('out_of_stock', chatbotKeywordMatch('anong produkto ang out of stock', $intents),
    'multi-word synonym matches');
t_same('staff_count', chatbotKeywordMatch('how many employees do we have', $intents),
    'staff count');
t_same('my_sales_today', chatbotKeywordMatch('how much have I sold today', $intents),
    'personal sales is its own intent');

/* Nothing sensible must not be forced into an intent */
t_same(null, chatbotKeywordMatch('what is the weather tomorrow', $intents),
    'unrelated question matches nothing');
t_same(null, chatbotKeywordMatch('', $intents), 'empty question matches nothing');

/* Product name extraction */
t_same('lucky me', chatbotExtractProductName('magkano ang lucky me', $intents['product_price']),
    'extracts the product name out of a price question');
t_same('coke mismo', chatbotExtractProductName('what is the price of Coke Mismo?', $intents['product_price']),
    'extracts the product name from the English phrasing');

/* Catalog shape: every entry is complete and internally consistent */
foreach ($intents as $id => $intent) {
    foreach (['topic', 'roles', 'scope', 'label', 'keywords', 'handler'] as $key) {
        t_ok(array_key_exists($key, $intent), "{$id} defines {$key}");
    }

    t_ok(in_array($intent['scope'], ['company', 'own'], true), "{$id} has a valid scope");
    t_ok($intent['keywords'] !== [], "{$id} has at least one keyword group");
    t_ok($intent['roles'] !== [], "{$id} names at least one role");
}

t_done();
