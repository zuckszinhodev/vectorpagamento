$wid = '7d76ffec-df82-4247-91d1-8c9c07ccad21';
$tid = 'e0866abd-ca9d-49cb-bb14-e134c8a65654';
$w = \App\Models\Withdrawal::find($wid);
$tx = app(\App\Services\AcquirerFactory::class)->enki()->getTransaction($tid);
$status = null;
if (is_array($tx)) {
  $status = strtolower((string)($tx['status'] ?? ''));
} elseif (is_object($tx)) {
  $arr = json_decode(json_encode($tx), true);
  $status = strtolower((string)($arr['status'] ?? ''));
} else {
  $status = strtolower((string)$tx);
}
$approved = in_array($status, ['paid','approved','completed','settled'], true);
$out = [
  'withdrawal' => [
    'id' => $w?->id,
    'status' => $w?->status,
    'acquirer' => $w?->acquirer,
    'acquirer_transfer_id' => $w?->acquirer_transfer_id,
    'failure_reason' => $w?->failure_reason,
    'processed_at' => optional($w?->processed_at)->toISOString(),
    'updated_at' => optional($w?->updated_at)->toISOString(),
  ],
  'enki_getTransaction' => $tx,
  'approved_interpretation' => $approved,
];
echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
