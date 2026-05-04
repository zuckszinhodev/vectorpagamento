import http from 'k6/http';
import { check } from 'k6';
import { authHeaders, baseUrl, defaultSleep, defaultStages, login, safeJson, uniqueDocument, uniqueEmail, uniquePhone } from './lib/common.js';

export const options = {
  stages: defaultStages(),
  thresholds: {
    http_req_failed: ['rate<0.02'],
    http_req_duration: ['p(95)<1500', 'p(99)<3000'],
    checks: ['rate>0.98'],
  },
};

export function setup() {
  return { token: login() };
}

export default function (data) {
  const payload = {
    amount: Number(__ENV.K6_CHARGE_AMOUNT || 500),
    payment_method: 'pix',
    customer: {
      name: 'K6 Charge Test',
      email: uniqueEmail('charge'),
      document: uniqueDocument(),
      phone: uniquePhone(),
    },
  };

  const response = http.post(
    `${baseUrl()}/api/v1/charges`,
    JSON.stringify(payload),
    {
      headers: authHeaders(data.token, {
        'X-Idempotency-Key': `k6-charge-${__VU}-${__ITER}-${Date.now()}`,
      }),
      tags: { flow: 'charge_create' },
    }
  );

  const body = safeJson(response);
  check(response, {
    'charge status is 201': (r) => r.status === 201,
    'charge success true': () => body.success === true,
    'charge id present': () => !!(body.data && body.data.id),
  });

  defaultSleep();
}
