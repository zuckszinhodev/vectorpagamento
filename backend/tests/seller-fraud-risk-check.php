<?php
require __DIR__.'/../app/Domain/Fraud/Services/SellerFraudRiskService.php';
$s = new App\Domain\Fraud\Services\SellerFraudRiskService();
$cases = [
    [[], 'insufficient'],
    [['total' => 100, 'settled' => 20], 'low'],
    [['total' => 100, 'settled' => 0], 'insufficient'],
    [['settled' => 1, 'chargebacks' => 1], 'attention'],
    [['settled' => 100, 'chargebacks' => 3], 'high'],
    [['settled' => 1000, 'chargebacks' => 3], 'attention'],
    [['flagged' => 1], 'high'],
    [['settled' => 20, 'refunds' => 5], 'attention'],
    [['settled' => 100, 'refunds' => 5], 'low'],
    [['card_attempts' => 20, 'card_declines' => 10], 'attention'],
    [['card_attempts' => 19, 'card_declines' => 19], 'insufficient'],
];
foreach ($cases as [$metrics, $expected]) {
    $result = $s->summarize($metrics);
    if ($result['level'] !== $expected) throw new RuntimeException('Unexpected level: '.json_encode($metrics));
}
echo count($cases)." fraud risk scenarios passed\n";
