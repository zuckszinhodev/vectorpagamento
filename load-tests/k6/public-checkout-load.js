import http from 'k6/http';
import { check } from 'k6';
import { baseUrl, defaultSleep, defaultStages, safeJson, uniqueDocument, uniqueEmail, uniquePhone, requiredEnv } from './lib/common.js';

export const options = {
  stages: defaultStages(),
  thresholds: {
    http_req_failed: ['rate<0.02'],
    http_req_duration: ['p(95)<1500', 'p(99)<3000'],
    checks: ['rate>0.98'],
  },
};

export default function () {
  const slug = requiredEnv('K6_CHECKOUT_SLUG');
  const payload = {
    payment_method: 'pix',
    customer: {
      name: 'K6 Public Checkout',
      email: uniqueEmail('checkout'),
      document: uniqueDocument(),
      phone: uniquePhone(),
    },
  };

  const response = http.post(
    `${baseUrl()}/api/v1/checkout/${slug}/process`,
    JSON.stringify(payload),
    {
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      tags: { flow: 'public_checkout_pix' },
    }
  );

  const body = safeJson(response);
  check(response, {
    'checkout status is 201': (r) => r.status === 201,
    'checkout success true': () => body.success === true,
    'checkout charge id present': () => !!(body.data && body.data.charge_id),
  });

  defaultSleep();
}
