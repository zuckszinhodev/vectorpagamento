import http from 'k6/http';
import { check, fail, sleep } from 'k6';

export function requiredEnv(name) {
  const value = __ENV[name];
  if (!value) {
    fail(`Missing required env: ${name}`);
  }

  return value;
}

export function optionalEnv(name, fallback) {
  return __ENV[name] || fallback;
}

export function baseUrl() {
  return requiredEnv('K6_BASE_URL').replace(/\/$/, '');
}

export function randomSuffix() {
  return `${__VU}-${__ITER}-${Date.now()}`;
}

export function uniqueEmail(prefix = 'k6') {
  return `${prefix}.${randomSuffix()}@example.com`;
}

export function uniqueDocument() {
  const raw = `9${String(__VU).padStart(2, '0')}${String(__ITER).padStart(8, '0')}`;
  return raw.slice(0, 11);
}

export function uniquePhone() {
  return `119${String(__VU).padStart(4, '0')}${String(__ITER).padStart(4, '0')}`.slice(0, 11);
}

export function login() {
  const response = http.post(
    `${baseUrl()}/api/v1/auth/login`,
    JSON.stringify({
      email: requiredEnv('K6_EMAIL'),
      password: requiredEnv('K6_PASSWORD'),
    }),
    {
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      tags: { flow: 'auth_login' },
    }
  );

  const ok = check(response, {
    'login status is 200': (r) => r.status === 200,
    'login returned token': (r) => {
      const body = safeJson(r);
      return !!(body.data && (body.data.token || body.data.access_token));
    },
  });

  if (!ok) {
    fail(`Login failed: status=${response.status} body=${response.body}`);
  }

  const body = safeJson(response);

  return body.data.token || body.data.access_token;
}

export function authHeaders(token, extra = {}) {
  return {
    Authorization: `Bearer ${token}`,
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...extra,
  };
}

export function safeJson(response) {
  try {
    return response.json();
  } catch (_) {
    return {};
  }
}

export function defaultSleep() {
  sleep(Number(optionalEnv('K6_SLEEP_SECONDS', '1')));
}

export function defaultStages() {
  return [
    { duration: optionalEnv('K6_STAGE_RAMP_UP', '30s'), target: Number(optionalEnv('K6_TARGET_VUS', '10')) },
    { duration: optionalEnv('K6_STAGE_SUSTAIN', '1m'), target: Number(optionalEnv('K6_TARGET_VUS', '10')) },
    { duration: optionalEnv('K6_STAGE_RAMP_DOWN', '30s'), target: 0 },
  ];
}
