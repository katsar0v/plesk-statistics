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
  "windows": [60, 120, 300],
  "defaultWindow": 60,
  "pollMs": 3000,
  "rowLimit": 500
}
```

`name` is the ASCII (punycode) domain name. It is the value sent in requests and the value used in
the `domain` fields of responses. `displayName` is what the UI shows.

## `GET {dataUrl}?window=60&domain=all`

- `window` is one of `60`, `120` or `300` seconds.
- `domain` is `all` or one `name` from the config.

Success (HTTP 200):

```json
{
  "ok": true,
  "generatedAt": 1793013403,
  "window": 60,
  "domain": "all",
  "total": 123,
  "rps": 2.05,
  "byStatusClass": {"2xx": 100, "3xx": 10, "4xx": 12, "5xx": 1, "other": 0},
  "byStatus": [{"status": 200, "count": 90}, {"status": 404, "count": 12}],
  "byDomain": [{"domain": "example.com", "displayName": "example.com", "count": 50}],
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
      "src": "nginx"
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
  - `total`, `rps`, `byStatusClass`, `byStatus` and `rows` apply both the window and the domain filter.
  - `byDomain` applies only the window, so the breakdown always lists every domain. Clicking one
    sets the filter.
  - `byStatus` lists at most the 10 most frequent statuses, highest count first.
- **Rows:**
  - `rows` is newest first and holds at most `rowLimit` entries. `truncated` is `total > rows.length`.
  - `path` is already redacted and decoded for display. It is plain text: always insert it with
    `textContent`, never as HTML.
  - `rt` is the response time in seconds, or `null` when the log format doesn't record it.
  - `src` is `nginx` or `apache`.
- **Health:**
  - `lagging` is true when more log data was waiting than one poll may read, so the counts are
    still catching up.
  - `parseErrors` counts log lines that this poll could not parse and skipped.

Error (HTTP 4xx/5xx):

```json
{"ok": false, "error": "Human-readable message"}
```
