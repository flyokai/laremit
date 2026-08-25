// Phase 2 ingest load test.
//
//   k6 run load/k6-ingest.js                          # 5k events/s for 60s
//   k6 run -e RPS=100 -e BATCH=200 load/k6-ingest.js  # 20k events/s burst
//
// Rate is requests/sec; events/sec = RPS * BATCH. The p99 threshold is the
// Phase 2 deliverable: 5,000 events/sec sustained with p99 < 50ms.
//
// While it runs, watch the pipeline drain:  php artisan events:status

import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8100';
const TOKEN = __ENV.TOKEN || 'local-dev-token';
const BATCH = Number(__ENV.BATCH || 100);
const RPS = Number(__ENV.RPS || 50); // 50 rps x 100 events = 5k events/s

const eventsAccepted = new Counter('events_accepted');
const eventsShed = new Counter('events_shed');

export const options = {
  scenarios: {
    ingest: {
      executor: 'constant-arrival-rate',
      rate: RPS,
      timeUnit: '1s',
      duration: __ENV.DURATION || '60s',
      preAllocatedVUs: 50,
      maxVUs: 500,
    },
  },
  thresholds: {
    http_req_duration: ['p(99)<50'],
    http_req_failed: ['rate<0.001'],
  },
};

function uuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

const PRODUCTS = ['edtech', 'vpn', 'ai-tutor'];

export default function () {
  const occurredAt = new Date().toISOString();

  const events = Array.from({ length: BATCH }, () => ({
    event_id: uuid(),
    type: 'video.watched',
    schema_version: 2,
    occurred_at: occurredAt,
    user_id: 1 + Math.floor(Math.random() * 100_000),
    product: PRODUCTS[Math.floor(Math.random() * PRODUCTS.length)],
    priority: 'analytics',
    payload: { video_id: 'v-load', position_ms: Math.floor(Math.random() * 600_000) },
  }));

  const res = http.post(`${BASE_URL}/v1/events`, JSON.stringify({ events }), {
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${TOKEN}`,
    },
  });

  check(res, { 'status is 202': (r) => r.status === 202 });

  if (res.status === 202) {
    const body = res.json();
    eventsAccepted.add(body.accepted || 0);
    eventsShed.add(body.shed || 0);
  }
}
