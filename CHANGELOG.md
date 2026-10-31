# Changelog

## 1.2.1 – 2026-10-31

- **No more empty response-time chart.** Plesk's default log format doesn't record how long a request took, so the chart is gone and "Top websites" takes its full row. The data endpoint no longer sends `timeline.rt`.
- **"Time taken" column** appears only when a listed request has a response time, for servers with a customised log format.
- The bandwidth axis switches to the next unit from half of it, so it reads "0.5 MB" rather than "1,000 KB".

## 1.2.0 – 2026-10-31

- **Charts** above the breakdowns, across the selected time window: requests per second by status, bandwidth, the five busiest websites and the average response time (removed in 1.2.1). Hovering (or the arrow keys) shows each slice's numbers; the website legend sets the filter.
- **Docker panel collapses.** It is closed by default and remembers whether you opened it. The closed header counts the containers and says when one is stopped, unhealthy or Docker can't be reached.
- The data endpoint returns the window in 30 slices (`timeline`), counted from the same requests as the totals.

## 1.1.0 – 2026-10-29

- **Docker containers.** Requests to websites behind a Docker proxy rule are tagged with the container that answered them. They were already counted through nginx's logs; now the dashboard says which container served them.
- **Containers panel** with each container's state and health from Docker, its image, websites, requests and 5xx errors. A stopped container shows as "Exited", with nginx's 502 answers counted against it.
- **Filter by container**, from the panel or the filter menu, next to the filter by website.
- The rules come from the Docker extension's database, read-only, and the containers from Docker's API on the local socket. The panel user is already in the `docker` group, so nothing runs as root. Container logs are not read.

## 1.0.0 – 2026-10-26

First release: a **Live Traffic** page for Plesk administrators that shows the HTTP requests to all hosted websites in near real time.

- **Rolling windows** of 1, 2 or 5 minutes, refreshed every 3 seconds. Each window shows requests per second, a breakdown by website and status, and the newest 500 requests.
- **Every web server's logs** are read: nginx and Apache, HTTP and HTTPS. A static file that both servers log (X-Accel-Redirect in proxy mode) is counted once, with the status nginx sent to the client.
- **Incremental reading** with per-poll byte and time limits. A binary search by timestamp finds the start in large logs. Truncation, replacement and rewriting of a log are detected.
- **Redaction** of credentials, tokens and e-mail addresses in URLs before they are stored.
- **Administrators only.** Runs as the panel user, changes no file permissions and runs nothing as root.
