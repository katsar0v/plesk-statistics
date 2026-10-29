# Dev harness

Exercise the dashboard in a browser without Plesk. Run everything from the repository root.

1. Optional: `composer install`. The router loads `vendor/autoload.php` when present and otherwise autoloads `src/plib/library` itself.
2. `php -S 127.0.0.1:8099 dev/router.php`, then open http://127.0.0.1:8099/. It renders `index.phtml` inside a minimal Plesk-like page and reads logs from `dev/vhosts/system/<domain>/logs/` (created on the first request; add more domain directories to see more sites). State goes to `dev/var/`.
3. `php dev/fake-traffic.php [rate=5] [duration=60] [--rotate] [--burst N]` appends access-log lines for four sites (secrets in query strings, XSS-looking paths, invalid request lines, X-Accel duplicates in both nginx and Apache logs). `--rotate` truncates one log in place halfway through; `--burst N` writes N lines at once.

Page options: `?theme=dark|light|contrast`, `?poll=<ms>`, and `?fake=1` for synthetic data without log files (also `&rows=<limit>&lagging=1&parseErrors=<n>`). `?docker=down` pretends Docker is not reachable and `?docker=none` that no website has a Docker proxy rule.

Docker comes from `dev/docker.php`: the proxy rules (all of shop.example.net, which redirects HTTP to HTTPS, and `/api` of blog.example.org) and what Docker answers about its containers (`shop` running, `blog-api` stopped). With real logs, nginx lines for those paths are credited to the containers; the generator writes `/api/v1/...` paths now and then. `touch dev/var/fail` makes `/data` answer 503 to show the error state; delete the file to recover.

The page also defines a global `$` and breaks `Array.prototype.toJSON`, `Array.from` and `Array#entries` the way prototype.js does, so `monitor.js` cannot rely on them by accident.
