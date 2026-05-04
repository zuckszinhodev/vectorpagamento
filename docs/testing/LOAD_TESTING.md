# Load Testing

This repository includes k6 scenarios for the highest-risk financial flows.

Location:

- `load-tests/k6/charge-load.js`
- `load-tests/k6/charge-idempotency.js`
- `load-tests/k6/public-checkout-load.js`
- `load-tests/k6/withdrawal-burst.js`

## Goals

The suite is designed to validate:

1. Authenticated charge creation throughput and latency.
2. Idempotency under concurrent duplicate submission.
3. Public checkout PIX throughput and latency.
4. Withdrawal request burst behavior on a funded MFA-enabled test account.

## Required environment

Common:

- `K6_BASE_URL`

Authenticated scenarios:

- `K6_EMAIL`
- `K6_PASSWORD`

Public checkout:

- `K6_CHECKOUT_SLUG`

Withdrawal burst:

- `K6_MFA_CODE`
- `K6_PIX_KEY`
- optional `K6_PIX_TYPE`

## Local examples

Charge load:

```bash
k6 run load-tests/k6/charge-load.js
```

Charge idempotency:

```bash
K6_FIXED_IDEMPOTENCY_KEY=prod-check-001 k6 run load-tests/k6/charge-idempotency.js
```

Public checkout:

```bash
K6_CHECKOUT_SLUG=seu-checkout k6 run load-tests/k6/public-checkout-load.js
```

Withdrawal burst:

```bash
K6_MFA_CODE=123456 K6_PIX_KEY=12345678900 k6 run load-tests/k6/withdrawal-burst.js
```

## Suggested approval gates

Start with these minimum thresholds:

1. `http_req_failed < 2%` for charge and checkout.
2. `p95 < 1500ms` and `p99 < 3000ms` for charge and checkout.
3. Idempotency batch returns a single charge id for duplicate requests.
4. Withdrawal burst should be limited to a short controlled run on a funded test account.

## GitHub Actions

Manual workflow:

- `.github/workflows/backend-load-tests.yml`

Recommended repository configuration:

- Variables:
  - `K6_BASE_URL`
  - `K6_CHECKOUT_SLUG`
  - optional tuning vars like `K6_TARGET_VUS`, `K6_STAGE_RAMP_UP`, `K6_STAGE_SUSTAIN`, `K6_STAGE_RAMP_DOWN`
- Secrets:
  - `K6_EMAIL`
  - `K6_PASSWORD`
  - `K6_MFA_CODE`
  - `K6_PIX_KEY`

## Important limitations

1. These scenarios do not replace financial reconciliation validation.
2. Withdrawal burst depends on a fresh MFA code and funded test account.
3. Webhook storm/replay testing still should be exercised separately with provider-specific signatures.
