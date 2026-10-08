# Docker Browsershot

An HTTP API for [spatie/browsershot](https://github.com/spatie/browsershot). It renders HTML or a URL to PDF, PNG, JPEG, or WebP with headless Chromium, so your application does not need Node, Puppeteer, or Chrome installed.

- PHP 8.4, spatie/browsershot 5.4, Puppeteer 24, Chromium (Alpine 3.24)
- Multi-arch image: `linux/amd64`, `linux/arm64`
- Almost every Browsershot option is available through JSON
- Output is returned as base64; nothing is stored on the server

## Quick start

```bash
docker run -d --name browsershot \
  -p 8000:8000 \
  --shm-size=512m \
  -e APP_KEY=change-me \
  adityadarma/docker-browsershot:latest
```

```bash
curl -s http://localhost:8000/health
# {"status":"success","message":"Server up and running"}

curl -s -X POST http://localhost:8000/ \
  -H 'App-Key: change-me' \
  -H 'Content-Type: application/json' \
  -d '{"html":"<h1>Hello</h1>","type":"pdf"}' \
  | jq -r '.data.base64' | base64 -d > output.pdf
```

With Docker Compose:

```bash
APP_KEY=change-me docker compose up -d
```

> `--shm-size=512m` (or `shm_size` in Compose) is required. Docker's default `/dev/shm` is only 64 MB, and Chromium can crash on large pages.

## Documentation

- [API reference](docs/api.md): endpoints, every request option, response format, error codes
- [Usage examples](docs/examples.md): curl, PHP, Laravel, Node.js, Python, and common recipes (invoices, headers/footers, logged-in pages, and more)

## Request at a glance

Send `POST /` with a JSON body. Either `html` or `url` is required.

```json
{
  "url": "https://example.com",
  "type": "png",
  "fullPage": true,
  "windowSize": { "width": 1280, "height": 800 }
}
```

Response:

```json
{
  "status": "success",
  "code": 200,
  "data": {
    "size": 48213,
    "base64": "iVBORw0KGgo...",
    "mime_type": "image/png",
    "extension": "png"
  }
}
```

## Configuration (environment)

| Variable | Default | Description |
| --- | --- | --- |
| `APP_KEY` | _(empty)_ | Required value of the `App-Key` header. Empty disables authentication. |
| `APP_DEBUG` | `false` | When `true`, internal error details are included in the `error` field. |
| `TZ` | `UTC` | Container time zone. |
| `PHP_FPM_MAX_CHILDREN` | `3` | Number of renders that can run at the same time. Each render starts one Chromium (about 400–450 MB while it runs). |
| `PHP_FPM_MAX_REQUESTS` | `50` | PHP workers are recycled after this many requests. |
| `BROWSERSHOT_DEFAULT_TIMEOUT` | `60` | Default timeout in seconds when the request does not send `timeout`. |
| `BROWSERSHOT_ALLOW_REMOTE_INSTANCE` | `false` | Allow the `remoteInstance` and `wsEndpoint` options. |
| `BROWSERSHOT_NO_SANDBOX` | `true` | Run Chromium with `--no-sandbox` (needed in non-privileged containers). |
| `BROWSERSHOT_CHROMIUM_ARGS` | see `app/Support/Config.php` | Comma-separated Chromium arguments without `--`, for example `disable-gpu,lang=en-US`. |
| `BROWSERSHOT_CHROME_PATH` | `/usr/bin/chromium-browser` | Chromium path. |
| `BROWSERSHOT_NODE_BINARY` | `/usr/bin/node` | Node path. |
| `BROWSERSHOT_NODE_MODULE_PATH` | `/app/node_modules` | Where Puppeteer is installed. |
| `BROWSERSHOT_NPM_BINARY` | `/usr/bin/npm` | Only used when `BROWSERSHOT_NODE_MODULE_PATH` is empty. |
| `BROWSERSHOT_INCLUDE_PATH` | `/usr/local/bin:/usr/bin:/bin` | `PATH` for the Node process. |
| `BROWSERSHOT_USER_DATA_DIR` | _(empty)_ | Persistent Chromium profile (`--user-data-dir`). |
| `BROWSERSHOT_TEMP_PATH` | _(system temp)_ | Base directory for per-render work dirs (`browsershot-*`: HTML file, Chromium profile). |
| `BROWSERSHOT_USE_PIPE` | `true` | Talk to Chromium over a pipe, so Chromium exits by itself if node dies. |
| `BROWSERSHOT_REAPER_INTERVAL` | `30` | Seconds between reaper runs (kills orphaned Chromium, removes stale work dirs). |
| `BROWSERSHOT_REAPER_STALE_MINUTES` | `10` | Work dirs older than this are removed by the reaper. |

Binary paths and Chromium arguments can only be set through the environment, never through the request body.

## Security

- **Set `APP_KEY` in production.** Without it, anyone who can reach port 8000 can use your server to render pages.
- The `url` option makes the server fetch that URL. Do not expose this service publicly without authentication, because it can be used to reach your internal network (SSRF). Keep it on a private network and restrict egress if you can.
- HTML containing `file://`, `view-source:`, or protocol-relative URLs to `localhost`/`127.*` is rejected (built-in Browsershot protection).
- `addStyleTag` and `addScriptTag` only accept an http(s) `url` or inline `content`, never a local path.

## Sizing

Each render launches a fresh Chromium that uses about 400–450 MB while it runs and is fully released afterwards. Idle memory is about 10 MB.

Rough guide: peak RAM ≈ `PHP_FPM_MAX_CHILDREN × 450 MB` + 100 MB. The default of 3 workers fits the 2 GB limit in the Compose example. Requests beyond the worker count wait in the queue.

## Process cleanup

Chromium can never outlive its request:

1. `bin/browser-guard.cjs` wraps Spatie's `browser.cjs`. It kills Chromium's whole process group on success, error, signals, a hard deadline (request `timeout` minus 1.5 s), or when the PHP worker that started it disappears.
2. Chromium is driven over a pipe (`BROWSERSHOT_USE_PIPE`), so it also exits by itself if node is killed with `SIGKILL`.
3. Every render uses a private `browsershot-*` work dir (HTML file, Chromium profile, temp files). After every render, PHP kills any process still using that dir, waits for it to exit, then deletes the dir. This covers node being `SIGKILL`ed (for example by the OOM killer).
4. A background reaper is a last resort, for the case where the PHP worker itself dies mid-render. `tini` runs as PID 1 and reaps zombie processes.

A hung render returns `504 Browser timeout`, and a killed browser returns `500 Browser process was killed`. In both cases the memory is freed before the response is sent.

## Development

Requires PHP 8.4, Composer, Node 22+, and a local Chrome or Chromium.

```bash
composer install
PUPPETEER_SKIP_DOWNLOAD=1 npm install

composer test           # unit + feature tests (no Chrome needed)
composer test:browser   # real rendering; skipped automatically if Chrome/Node are missing

php -S 127.0.0.1:8000 -t public public/index.php   # local server
```

For the local server, point the paths at your machine. On macOS, for example:

```bash
BROWSERSHOT_NODE_BINARY=$(which node) \
BROWSERSHOT_NODE_MODULE_PATH=$PWD/node_modules \
BROWSERSHOT_CHROME_PATH="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
BROWSERSHOT_CHROMIUM_ARGS=disable-gpu \
php -S 127.0.0.1:8000 -t public public/index.php
```

Layout:

```
app/Http/Application.php                routing, App-Key, JSON parsing
app/Services/RequestValidator.php       validation and normalization of every option
app/Services/BrowsershotService.php     exception -> HTTP code mapping
app/Libraries/BrowsershotGenerator.php  options -> Spatie Browsershot calls
app/Support/Config.php                  configuration from the environment
tests/Unit, tests/Feature, tests/Browser
```

Build the image locally:

```bash
docker build -t docker-browsershot:dev .
```

## Releasing

Push a semver tag. GitHub Actions runs the tests, builds a multi-arch image, pushes it to Docker Hub, then runs a smoke test.

```bash
git tag v1.0.0
git push origin v1.0.0
```

| Git tag | Docker Hub tags |
| --- | --- |
| `v1.2.3` | `1.2.3`, `1.2`, `1`, `latest` |
| `v1.3.0-rc.1` | `1.3.0-rc.1` only |

You can also run it manually from **Actions → release → Run workflow** with a version input. Required secrets: `DOCKER_USERNAME` and `DOCKER_PASSWORD` (use a Docker Hub access token).

## License

[MIT License](LICENSE)

Copyright (c) 2026 Aditya Darma (adhit.boys1@gmail.com)