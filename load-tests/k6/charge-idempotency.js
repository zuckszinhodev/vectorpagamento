import http from 'k6/http';
import { check } from 'k6';
import { authHeaders, baseUrl, login, safeJson, uniqueDocument, uniqueEmail, uniquePhone } from './lib/common.js';

export const options = {
  vus: Number(__ENV.K6_IDEMPOTENCY_VUS || 1),
  iterations: Number(__ENV.K6_IDEMPOTENCY_ITERATIONS || 5),
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<2000'],
    checks: ['rate>0.99'],
  },
};

export function setup() {
  return { token: login() };
}

export default function (data) {
  const idemKey = __ENV.K6_FIXED_IDEMPOTENCY_KEY || `k6-idempotency-${Date.now()}`;
  const payload = JSON.stringify({
    amount: Number(__ENV.K6_CHARGE_AMOUNT || 500),
    payment_method: 'pix',
    customer: {
      name: 'K6 Idempotency Test',
      email: uniqueEmail('idem'),
      document: uniqueDocument(),
      phone: uniquePhone(),
    },
  });

  const params = {
    headers: authHeaders(data.token, { 'X-Idempotency-Key': idemKey }),
    tags: { flow: 'charge_idempotency' },
  };

  const requests = Array.from({ length: Number(__ENV.K6_BATCH_SIZE || 5) }, () => ({
    method: 'POST',
    url: `${baseUrl()}/api/v1/charges`,
    body: payload,
    params,
  }));

  const responses = http.batch(requests);
  const ids = responses
    .map((response) => safeJson(response))
    .map((body) => body.data && body.data.id)
    .filter(Boolean);

  const uniqueIds = Array.from(new Set(ids));
  check(responses[0], {
    'all idempotent responses are 200 or 201': () => responses.every((r) => r.status === 200 || r.status === 201),
    'all idempotent responses returned success': () => responses.every((r) => safeJson(r).success === true),
    'idempotent batch produced a single charge id': () => uniqueIds.length === 1,
  });
}
