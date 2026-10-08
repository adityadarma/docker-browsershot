# Usage examples

All examples use `http://localhost:8000` and `APP_KEY=secret`. The file comes back as base64 in `data.base64`.

## curl

Save a PDF from HTML:

```bash
curl -s -X POST http://localhost:8000/ \
  -H 'App-Key: secret' -H 'Content-Type: application/json' \
  -d '{"html":"<h1>Invoice #001</h1>","type":"pdf","format":"A4"}' \
  | jq -r '.data.base64' | base64 -d > invoice.pdf
```

Full-page screenshot of a URL:

```bash
curl -s -X POST http://localhost:8000/ \
  -H 'App-Key: secret' -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com","type":"png","fullPage":true,"windowSize":{"width":1440,"height":900}}' \
  | jq -r '.data.base64' | base64 -d > example.png
```

HTML from a local file (read by the client and sent as a string):

```bash
jq -n --rawfile html template.html '{html: $html, type: "pdf"}' \
  | curl -s -X POST http://localhost:8000/ \
      -H 'App-Key: secret' -H 'Content-Type: application/json' -d @- \
  | jq -r '.data.base64' | base64 -d > out.pdf
```

## PHP (no framework)

```php
<?php

function browsershot(array $payload): string
{
    $ch = curl_init('http://localhost:8000/');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'App-Key: '.getenv('BROWSERSHOT_KEY'),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('Cannot reach Browsershot: '.curl_error($ch));
    }

    $result = json_decode($body, true);
    if (($result['status'] ?? null) !== 'success') {
        throw new RuntimeException($result['message'] ?? 'Browsershot error');
    }

    return base64_decode($result['data']['base64']);
}

file_put_contents('invoice.pdf', browsershot([
    'html' => '<h1>Invoice</h1>',
    'type' => 'pdf',
    'margin' => ['top' => 15, 'right' => 15, 'bottom' => 15, 'left' => 15],
]));
```

## Laravel

`config/services.php`:

```php
'browsershot' => [
    'url' => env('BROWSERSHOT_URL', 'http://browsershot:8000'),
    'key' => env('BROWSERSHOT_KEY'),
],
```

`app/Services/Pdf.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class Pdf
{
    public static function fromView(string $view, array $data = [], array $options = []): string
    {
        $response = Http::baseUrl(config('services.browsershot.url'))
            ->withHeaders(['App-Key' => config('services.browsershot.key')])
            ->timeout(120)
            ->post('/', [
                'html' => view($view, $data)->render(),
                'type' => 'pdf',
                'format' => 'A4',
                'showBackground' => true,
                ...$options,
            ])
            ->throw();

        return base64_decode($response->json('data.base64'));
    }
}
```

Controller:

```php
public function download(Invoice $invoice)
{
    $pdf = \App\Services\Pdf::fromView('invoices.show', compact('invoice'));

    return response($pdf, 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'attachment; filename="invoice-'.$invoice->number.'.pdf"',
    ]);
}
```

On error, `->throw()` raises a `RequestException`; the message is in `$e->response->json('message')`.

Assets (CSS, images) with relative paths like `/css/app.css` will not load, because the HTML is rendered inside the container. Use one of these:

- Absolute URLs (`asset()`/`url()`) reachable from the container.
- Send `"contentUrl": "https://your-app.com/"` as the base URL.
- Inline CSS in `<style>` and images as data URIs.

## Node.js

```js
import { writeFile } from 'node:fs/promises';

const res = await fetch('http://localhost:8000/', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'App-Key': process.env.BROWSERSHOT_KEY },
  body: JSON.stringify({ url: 'https://example.com', type: 'webp', quality: 80 }),
});

const json = await res.json();
if (json.status !== 'success') throw new Error(json.message);

await writeFile('example.webp', Buffer.from(json.data.base64, 'base64'));
```

## Python

```python
import base64, os, requests

r = requests.post(
    "http://localhost:8000/",
    headers={"App-Key": os.environ["BROWSERSHOT_KEY"]},
    json={"html": "<h1>Report</h1>", "type": "pdf", "landscape": True},
    timeout=120,
)
data = r.json()
if data["status"] != "success":
    raise RuntimeError(data["message"])

with open("report.pdf", "wb") as f:
    f.write(base64.b64decode(data["data"]["base64"]))
```

## Recipes

Each example below is a JSON body for `POST /`.

### A4 invoice with header, footer, and page numbers

```json
{
  "html": "<html><body style='font-family:sans-serif'><h1>Invoice #001</h1>...</body></html>",
  "type": "pdf",
  "format": "A4",
  "showBackground": true,
  "margin": { "top": 25, "right": 15, "bottom": 20, "left": 15 },
  "headerHtml": "<div style='font-size:9px;width:100%;padding:0 15mm;'>Acme Inc.</div>",
  "footerHtml": "<div style='font-size:9px;width:100%;text-align:center;'>Page <span class='pageNumber'></span> of <span class='totalPages'></span></div>"
}
```

### Custom paper size (100×150 mm label)

```json
{
  "html": "<div style='font-size:24px'>SHIPMENT 123</div>",
  "type": "pdf",
  "paperSize": { "width": 100, "height": 150, "unit": "mm" },
  "margin": { "top": 0, "right": 0, "bottom": 0, "left": 0 }
}
```

### Selected pages only

```json
{ "url": "https://example.com/report", "type": "pdf", "pages": "1-2" }
```

### Retina thumbnail

```json
{
  "url": "https://example.com",
  "type": "jpeg",
  "quality": 80,
  "windowSize": { "width": 1280, "height": 720 },
  "deviceScaleFactor": 2
}
```

The result is 2560×1440 pixels.

### Screenshot of one element

```json
{ "url": "https://example.com", "select": { "selector": ".chart", "index": 0 } }
```

### Mobile view

```json
{ "url": "https://example.com", "device": "iPhone X", "fullPage": true }
```

### Dark mode

```json
{
  "url": "https://example.com",
  "emulateMediaFeatures": [{ "name": "prefers-color-scheme", "value": "dark" }]
}
```

### SPA: wait for data to load

```json
{
  "url": "https://app.example.com/dashboard",
  "type": "pdf",
  "waitUntilNetworkIdle": true,
  "waitForSelector": { "selector": "#chart canvas", "options": { "visible": true } },
  "timeout": 90
}
```

Or wait for a flag set by your app:

```json
{ "url": "https://app.example.com/report", "waitForFunction": "window.reportReady === true" }
```

### Pages behind a login

With a session cookie:

```json
{
  "url": "https://app.example.com/invoice/1",
  "type": "pdf",
  "cookies": { "laravel_session": "eyJpdiI6..." }
}
```

With a header token:

```json
{
  "url": "https://api.example.com/report/1",
  "extraHttpHeaders": { "Authorization": "Bearer xxx" }
}
```

With HTTP Basic auth:

```json
{ "url": "https://staging.example.com", "authenticate": { "username": "user", "password": "pass" } }
```

### Fill a form and read the result

```json
{
  "url": "https://example.com/search",
  "action": "bodyHtml",
  "typeText": { "selector": "input[name=q]", "text": "browsershot" },
  "selectOption": { "selector": "select[name=lang]", "value": "en" },
  "click": { "selector": "button[type=submit]" },
  "waitForSelector": ".results"
}
```

### Hide cookie banners and ads

```json
{
  "url": "https://example.com",
  "addStyleTag": { "content": ".cookie-banner, .ads { display: none !important; }" },
  "blockDomains": ["www.googletagmanager.com", "connect.facebook.net"]
}
```

### Extract data from a page

```json
{ "url": "https://example.com", "action": "evaluate", "pageFunction": "document.title" }
```

Response: `{"data": {"result": "Example Domain"}}`. For complex values, return a JSON string: `"JSON.stringify([...document.links].map(a => a.href))"`.

### Debug a page that renders blank

```json
{ "url": "https://example.com", "action": "consoleMessages" }
```

```json
{ "url": "https://example.com", "action": "pageErrors" }
```

```json
{ "url": "https://example.com", "action": "failedRequests" }
```

### Fail when the page errors (404/500)

```json
{ "url": "https://example.com/may-not-exist", "preventUnsuccessfulResponse": true }
```

If the page responds 4xx/5xx, the API returns `502` with `The given url ... responds with code 404`.

### Use a separate Chrome (browserless)

Set `BROWSERSHOT_ALLOW_REMOTE_INSTANCE=true`, then:

```json
{
  "url": "https://example.com",
  "wsEndpoint": "ws://browserless:3000?token=xxx",
  "throwOnRemoteConnectionError": true
}
```

## Running next to your app

Your app's `docker-compose.yml`:

```yaml
services:
  app:
    # ...
    environment:
      BROWSERSHOT_URL: http://browsershot:8000
      BROWSERSHOT_KEY: ${BROWSERSHOT_KEY}

  browsershot:
    image: adityadarma/docker-browsershot:1
    restart: unless-stopped
    environment:
      APP_KEY: ${BROWSERSHOT_KEY}
      PHP_FPM_MAX_CHILDREN: 3   # ~450 MB per concurrent render
    shm_size: 512m
    tmpfs:
      - /tmp
    # No `ports:` needed. The service is only reached over the internal Compose network.
```

Pin the major version (`:1`) to get minor and patch updates without breaking changes. Use `:1.2.3` for an exact version.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `Browser failed to process the request` (500) or Chromium crashes | Make sure `shm_size` is at least 512m. Set `APP_DEBUG=true` to see details in the `error` field. |
| `Browser timeout` (504) | Raise `timeout`. Use `waitForSelector` instead of `waitUntilNetworkIdle` on pages with polling or websockets, or `blockDomains` for slow third-party scripts. |
| Characters show as boxes | The image ships Noto (including emoji) and Liberation fonts. For other fonts, use `@font-face` with a URL or data URI. |
| CSS/images missing with `html` | Use absolute URLs or `contentUrl`. Local paths (`file://`) are not allowed. |
| PDF header/footer not visible | Add top/bottom `margin` and set `font-size` in the template. |
| `cookies.domain is required when using html` | Send `"cookies": {"cookies": {...}, "domain": "example.com"}`. |
| Container runs out of memory | Each concurrent render uses about 450 MB. Lower `PHP_FPM_MAX_CHILDREN` or raise the memory limit. |
| `401 Unauthorized` | The `App-Key` header must exactly match the `APP_KEY` env var. |
