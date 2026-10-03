<?php
/*
| The promise this whole design exists to keep: one company's assistant never
| shows another company's data.
|
| A failure here is not a test to adjust.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

/* Two companies, deliberately similar so a leak would look plausible. */
$alpha = testMakeCompany($conn, 'Alpha', 2);
$beta = testMakeCompany($conn, 'Beta', 2);

function seedCompany(mysqli $conn, int $companyId, string $product, float $price, float $sale): void
{
    $conn->query("INSERT INTO categories (category_name, company_id) VALUES ('Cat', {$companyId})");
    $categoryId = (int) $conn->insert_id;

    $conn->query("INSERT INTO products (product_name, category_id, company_id)
                  VALUES ('{$product}', {$categoryId}, {$companyId})");
    $productId = (int) $conn->insert_id;

    $conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
                  VALUES ({$productId}, 2, 1.00, {$price}, 5, {$companyId})");

    $conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method)
                  VALUES ({$companyId}, {$sale}, 0.00, {$sale}, 0.00, 'Cash')");
    $saleId = (int) $conn->insert_id;

    $conn->query("INSERT INTO sale_items (sale_id, product_id, quantity, selling_price, company_id)
                  VALUES ({$saleId}, {$productId}, 1, {$price}, {$companyId})");

    $conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
                  VALUES ({$companyId}, '{$product}Person', 'Tester', 'Regular')");
}

seedCompany($conn, $alpha, 'AlphaOnlyProduct', 11.11, 1111.11);
seedCompany($conn, $beta, 'BetaOnlyProduct', 22.22, 2222.22);

$questions = [
    'magkano ang benta ngayong araw',
    'magkano ang benta ngayong buwan',
    'which products are low in stock',
    'anong produkto ang out of stock',
    'magkano ang alphaonlyproduct',
    'magkano ang betaonlyproduct',
    'may stock pa ba ang alphaonlyproduct',
    'may stock pa ba ang betaonlyproduct',
    'how many employees do we have',
    'pinakamabentang produkto',
];

foreach ([[$alpha, 'Alpha', 'Beta'], [$beta, 'Beta', 'Alpha']] as [$companyId, $mine, $theirs]) {

    $ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

    /*
    | The needles must be spelled the way an answer spells them: amounts are
    | rendered by chatbotPeso(), so a bare '1111.11' would never appear even
    | in a leak, and the assertion could not fail.
    */
    $theirProduct = $theirs . 'OnlyProduct';
    $theirTakings = number_format($theirs === 'Alpha' ? 1111.11 : 2222.22, 2);
    $theirPrice = number_format($theirs === 'Alpha' ? 11.11 : 22.22, 2);

    /* Guard the guard: these needles must really appear in that company's own
       answers, or the test below is checking for something that never shows. */
    $theirCtx = ['company_id' => $theirs === 'Alpha' ? $alpha : $beta,
                 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];
    $theirOwn = json_encode(chatbotAnswer($conn, $theirCtx, 'magkano ang benta ngayong araw'));

    t_ok(str_contains($theirOwn, $theirTakings),
        "{$theirs}'s own answer does contain {$theirTakings} (so the leak check can fail)");

    foreach ($questions as $question) {
        $json = json_encode(chatbotAnswer($conn, $ctx, $question));

        t_ok(!str_contains($json, $theirProduct),
            "{$mine}: \"{$question}\" does not name {$theirs}'s product");
        t_ok(!str_contains($json, $theirTakings),
            "{$mine}: \"{$question}\" does not show {$theirs}'s takings");
        t_ok(!str_contains($json, $theirPrice),
            "{$mine}: \"{$question}\" does not show {$theirs}'s price");
    }
}

/*
| A leak does not have to quote the other company verbatim: a query that
| forgets company_id SUMS both, producing a figure that belongs to neither and
| that no "does not contain" check would catch. So assert the exact total.
*/
foreach ([[$alpha, 1111.11], [$beta, 2222.22]] as [$companyId, $own]) {
    $ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];
    $answer = chatbotAnswer($conn, $ctx, 'magkano ang benta ngayong araw')['answer'];

    t_same(chatbotPeso($own), $answer['lines'][0][1],
        'the takings are exactly this company\'s own, not a sum across companies');
    t_same('1', $answer['lines'][1][1], 'and so is the transaction count');
}

/* The staff count must be each company's own, not both companies added up. */
foreach ([$alpha, $beta] as $companyId) {
    $ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];
    $answer = chatbotAnswer($conn, $ctx, 'how many employees do we have')['answer'];

    t_same('1', $answer['lines'][1][1], 'the employee count is this company\'s own');
}

t_done();
