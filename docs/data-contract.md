# Data contract

The dashboard page and its JSON endpoint share these two structures.

## Page config

`plib/views/scripts/index/index.phtml` receives `$this->config` from `IndexController::indexAction()`.
It writes the config into the page as an HTML-escaped JSON `data-config` attribute on `#ltm-app`.

```json
{
  "dataUrl": "/modules/live-traffic-monitor/index.php/index/data",
  "domains": [
    {"name": "example.com", "displayName": "example.com"},
    {"name": "xn--80ak6aa92e.com", "displayName": "пример.com"}
  ],
  "containers": [
    {"name": "shop", "domains": ["example.com"]}
  ],
  "windows": [60, 120, 300],
  "defaultWindow": 60,
  "pollMs": 3000,
  "rowLimit": 500
}
```

`name` is the ASCII (punycode) domain name. It is the value sent in requests and the value used in
the `domain` fields of responses. `displayName` is what the UI shows.

`containers` lists the Docker containers that Plesk's Docker proxy rules send website traffic to,
sorted by name. `name` is the container name, or the rule's target address (`127.0.0.1:18080`)
when no container publishes that port. `domains` are the `name`s of the websites whose rules point
at it. The list is empty when no website has a Docker proxy rule; the UI then shows nothing about
Docker.

## `GET {dataUrl}?window=60&domain=all&container=all`

- `window` is one of `60`, `120` or `300` seconds.
- `domain` is `all` or one `name` from `domains` in the config.
- `container` is `all` or one `name` from `containers` in the config. It may be left out.
  The UI filters by a website or by a container, not both; if both are given, both apply.

Success (HTTP 200):

```json
{
  "ok": true,
  "generatedAt": 1793013403,
  "window": 60,
  "domain": "all",
  "container": "all",
  "total": 123,
  "rps": 2.05,
  "byStatusClass": {"2xx": 100, "3xx": 10, "4xx": 12, "5xx": 1, "other": 0},
  "byStatus": [{"status": 200, "count": 90}, {"status": 404, "count": 12}],
  "byDomain": [{"domain": "example.com", "displayName": "example.com", "count": 50}],
  "byContainer": [
    {
      "container": "shop",
      "image": "shop:2026-10-28",
      "state": "running",
      "health": "healthy",
      "status": "Up 12 hours (healthy)",
      "domains": ["example.com"],
      "count": 40,
      "errors": 1
    }
  ],
  "docker": {"available": true, "error": null},
  "timeline": {
    "from": 1793013343,
    "bucket": 2,
    "byStatusClass": {
      "2xx": [3, 4, 0],
      "3xx": [0, 1, 0],
      "4xx": [0, 0, 1],
      "5xx": [0, 0, 0],
      "other": [0, 0, 0]
    },
    "bytes": [51200, 80300, 640],
    "rt": [0.0412, null, 0.2305],
    "byDomain": [{"domain": "example.com", "counts": [2, 3, 0]}]
  },
  "rows": [
    {
      "ts": 1793013400,
      "domain": "example.com",
      "scheme": "https",
      "method": "GET",
      "path": "/shop?page=2&token=[redacted]",
      "status": 200,
      "bytes": 1234,
      "ip": "203.0.113.7",
      "rt": null,
      "src": "nginx",
      "container": "shop"
    }
  ],
  "rowLimit": 500,
  "truncated": false,
  "lagging": false,
  "parseErrors": 0,
  "files": {"read": 48, "unreadable": 0}
}
```

The fields mean:

- **Time:** `generatedAt` and `ts` are Unix seconds. The UI formats them in the browser's local time.
- **Window:** a row is in the window when `generatedAt - window < ts <= generatedAt`.
- **Totals and filters:**
  - `total`, `rps`, `byStatusClass`, `byStatus` and `rows` apply the window and the domain and
    container filters.
  - `byDomain` and `byContainer` apply only the window, so the breakdowns always list every
    website and container. Clicking one sets the filter.
  - `byStatus` lists at most the 10 most frequent statuses, highest count first.
- **Timeline:**
  - `timeline` is the window cut into 30 equal buckets, oldest first, for the charts. `from` is
    `generatedAt - window` and `bucket` is the length of one bucket in seconds (`window / 30`: 2, 4 or
    10). Bucket `i` holds the requests with `from + i * bucket < ts <= from + (i + 1) * bucket`, so the
    last bucket ends at `generatedAt`. Every array has exactly 30 entries; the example above shows only
    the first three of each.
  - `byStatusClass`, `bytes` and `rt` apply the window and the domain and container filters, like
    `total`. `byStatusClass` has the same five keys as the top-level one, each with the requests per
    bucket; adding up all buckets and classes gives `total`.
  - `bytes` is the sum of the response bytes per bucket.
  - `rt` is the average response time in seconds per bucket, over the requests that have one. It is
    `null` for a bucket where no request recorded a response time, which is every bucket when the log
    format doesn't record it.
  - `byDomain` has the requests per bucket of the 5 busiest websites, in the order of the top-level
    `byDomain`. It applies only the window, so it doesn't change with the filters. It is empty when
    there are no requests in the window.
- **Rows:**
  - `rows` is newest first and holds at most `rowLimit` entries. `truncated` is `total > rows.length`.
  - `path` is already redacted and decoded for display. It is plain text: always insert it with
    `textContent`, never as HTML.
  - `rt` is the response time in seconds, or `null` when the log format doesn't record it.
  - `src` is `nginx` or `apache`.
  - `container` is the Docker container that answered the request, or `null`. Only requests that
    Plesk's nginx proxied under a Docker proxy rule have one. Requests nginx answered itself
    (HTTP-to-HTTPS redirects, certificate validation, invalid requests) and Apache's lines don't.
- **Docker:**
  - `docker` is `null` when no website has a Docker proxy rule. Otherwise `available` says whether
    Docker answered; when it is `false`, `error` says why, and the containers' `state` is `unknown`.
  - `byContainer` lists every container from the config's `containers`, plus any other container
    that still has requests in the window, highest `count` first. `count` is the number of
    requests and `errors` the number of 5xx responses among them.
  - `state` is Docker's state: `running`, `restarting`, `paused`, `created`, `exited`, `dead` or
    `removing`. It is `missing` when no container publishes the rule's port, and `unknown` when
    Docker is not reachable.
  - `health` is `healthy`, `unhealthy` or `starting` for containers with a health check, otherwise
    `null`. `status` is Docker's own summary, such as `Up 12 hours (healthy)`, or `null`. `image`
    may be `null`.
  - `domains` are the websites whose Docker proxy rules point at the container.
- **Health:**
  - `lagging` is true when more log data was waiting than one poll may read, so the counts are
    still catching up.
  - `parseErrors` counts log lines that this poll could not parse and skipped.

Error (HTTP 4xx/5xx):

```json
{"ok": false, "error": "Human-readable message"}
```
