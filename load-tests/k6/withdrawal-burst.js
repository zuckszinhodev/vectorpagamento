import http from 'k6/http';
import { check } from 'k6';
import { authHeaders, baseUrl, defaultSleep, login, requiredEnv, safeJson } from './lib/common.js';

export const options = {
  vus: Number(__ENV.K6_WITHDRAWAL_VUS || 1),
  iterations: Number(__ENV.K6_WITHDRAWAL_ITERATIONS || 3),
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<2500'],
    checks: ['rate>0.99'],
  },
};

export function setup() {
  return { token: login() };
}

export default function (data) {
  const payload = {
    amount: Number(__ENV.K6_WITHDRAWAL_AMOUNT || 1000),
    method: 'pix',
    mfa_code: requiredEnv('K6_MFA_CODE'),
    destination: {
      type: 'pix',
      pix_key: requiredEnv('K6_PIX_KEY'),
      pix_type: __ENV.K6_PIX_TYPE || 'cpf',
    },
  };

  const response = http.post(
    `${baseUrl()}/api/v1/withdrawals`,
    JSON.stringify(payload),
    {
      headers: authHeaders(data.token),
      tags: { flow: 'withdrawal_create' },
    }
  );

  const body = safeJson(response);
  check(response, {
    'withdrawal status is 201': (r) => r.status === 201,
    'withdrawal success true': () => body.success === true,
    'withdrawal id present': () => !!(body.data && body.data.id),
  });

  defaultSleep();
}
