# API reference

Example base URL: `http://localhost:8000`

## Endpoints

| Method | Path | Auth | Description |
| --- | --- | --- | --- |
| `GET` | `/health` | no | Server status. |
| `POST` | `/` | `App-Key` | Render or inspect a page. |

Other methods on these paths return `405`. Other paths return `404`.

### Authentication

When the `APP_KEY` env var is set, every `POST /` must send:

```
App-Key: <APP_KEY value>
```

The header name is case-insensitive. A missing or wrong header returns `401`.

### Body

`Content-Type: application/json` with a JSON object. Maximum size is 10 MB. Unknown fields are ignored.

## Main fields

| Field | Type | Default | Description |
| --- | --- | --- | --- |
| `html` | string | | HTML to render. Takes precedence when `url` is also sent. |
| `url` | string | | `http`/`https` URL to render. |
| `type` | string | `png` | `pdf`, `png`, `jpeg`, `jpg`, `webp`. |
| `action` | string | `render` | See [Actions](#actions). |
| `pageFunction` | string | | JavaScript expression, required for `action: evaluate`. |

One of `html` or `url` is required.

## Actions

| `action` | Result in `data` |
| --- | --- |
| `render` | `{ size, base64, mime_type, extension }`: the file for the given `type`. |
| `bodyHtml` | `{ html }`: final HTML after JavaScript has run. |
| `evaluate` | `{ result }`: result of `pageFunction` as a string. |
| `consoleMessages` | `{ messages: [{ type, message, location, stackTrace }] }` |
| `pageErrors` | `{ errors: [{ name, message }] }`: uncaught JavaScript errors. |
| `triggeredRequests` | `{ requests: [{ url }] }`: every request the page made. |
| `failedRequests` | `{ requests: [{ status, url }] }`: responses with status outside 200–399. |
| `redirectHistory` | `{ redirects: [{ url, status, reason, headers }] }` |

All options below also apply to non-`render` actions (for example `waitForSelector` before `bodyHtml`).

## PDF options

Only used when `type` is `"pdf"`.

| Field | Type | Description |
| --- | --- | --- |
| `format` | string | `A0`–`A6`, `Letter`, `Legal`, `Tabloid`, `Ledger` (case-insensitive). Default `A4`. |
| `paperSize` | object | `{ width, height, unit }`. Replaces `format`. `unit`: `mm` (default), `cm`, `in`, `px`. |
| `landscape` | bool | Landscape orientation. |
| `margin` | object | `{ top, right, bottom, left, unit }`. Numbers ≥ 0; missing sides are 0. `unit` defaults to `mm`. |
| `pages` | string | Page ranges, for example `"1-3, 5"` or `"2-"`. |
| `scale` | number | 0.1–2. |
| `headerHtml` | string | Header template. Turns header/footer on automatically. |
| `footerHtml` | string | Footer template. Turns header/footer on automatically. |
| `hideHeader` | bool | Empty header (footer stays). |
| `hideFooter` | bool | Empty footer (header stays). |
| `showBrowserHeaderAndFooter` | bool | Use Chrome's built-in header/footer (date, title, URL, page number). |
| `initialPageNumber` | int | First number used for `pageNumber`; earlier pages are skipped. |
| `taggedPdf` | bool | Tagged PDF (accessibility). |
| `transparentBackground` | bool | Transparent background. |

For `html` input, Chrome's built-in header/footer is off by default.

Header/footer templates support these Chrome classes: `date`, `title`, `url`, `pageNumber`, `totalPages`. Templates render separately from the page, so CSS must be inline and `font-size` should be set (the default is very small). Add top/bottom `margin` so content does not cover them.

## Screenshot options

Only used for `png`, `jpeg`, `jpg`, `webp`.

| Field | Type | Description |
| --- | --- | --- |
| `fullPage` | bool | Capture the full page height, not just the viewport. |
| `quality` | int | 0–100, for `jpeg`/`webp`. Default 90. Ignored for `png`. |
| `clip` | object | `{ x, y, width, height }` in pixels; the area to crop. |
| `select` | string \| object | Capture one element: `"#id"` or `{ selector, index }`. `index` picks a match when the selector matches several elements. A missing element returns `422`. |
| `hideBackground` | bool | Transparent background (PNG/WebP). |

## Page options

| Field | Type | Description |
| --- | --- | --- |
| `windowSize` | object | `{ width, height }`, 1–16384. Default `800×600`. |
| `deviceScaleFactor` | int | 1–3. The image size is `windowSize × factor`. |
| `device` | string | Puppeteer device emulation, for example `"iPhone X"`, `"iPad Pro"`, `"Pixel 5"`. |
| `mobile` | bool | Viewport `isMobile`. |
| `touch` | bool | Viewport `hasTouch`. |
| `userAgent` | string | Custom user agent. |
| `emulateMedia` | string \| null | `screen`, `print`, or `null`. |
| `emulateMediaFeatures` | array | `[{ "name": "prefers-color-scheme", "value": "dark" }]` |
| `delay` | int | Wait (ms) before rendering, 0–300000. |
| `timeout` | int | Seconds, 1–300. Default from `BROWSERSHOT_DEFAULT_TIMEOUT` (60). |
| `protocolTimeout` | int | Seconds, 1–300. DevTools protocol timeout. |
| `disableJavascript` | bool | Disable JavaScript. |
| `disableImages` | bool | Do not load images. |
| `dismissDialogs` | bool | Auto-dismiss `alert`/`confirm`/`prompt`. |
| `newHeadless` | bool | Use Chrome's new headless mode instead of `chrome-headless-shell`. |
| `contentUrl` | string | Base URL for `html`, so relative paths (`/css/app.css`) load from this URL. |

When `device` is set without `windowSize`, screen size, `deviceScaleFactor`, `mobile`, and `touch` come from the device. Send `windowSize` to override them.

## Network options

| Field | Type | Description |
| --- | --- | --- |
| `extraHttpHeaders` | object | Headers for every page request: `{ "X-Token": "abc" }`. Values must be strings. |
| `extraNavigationHttpHeaders` | object | Headers for the main navigation request only. |
| `authenticate` | object | HTTP Basic auth: `{ username, password }`. |
| `cookies` | object | `{ "name": "value" }` or `{ "cookies": { ... }, "domain": "example.com" }`. `domain` is required with `html`. |
| `post` | object | Load the page as a form POST: `{ "field": "value" }`. |
| `blockUrls` | string[] | Block requests whose URL contains any of these strings. |
| `blockDomains` | string[] | Block requests to these hostnames. |
| `proxyServer` | string | `host:port`, `http://host:port`, or `socks5://host:port`. |
| `ignoreHttpsErrors` | bool | Ignore TLS certificate errors. |
| `disableRedirects` | bool | Do not follow redirects. |
| `disableCaptureURLS` | bool | Do not collect the request list (lighter). |
| `preventUnsuccessfulResponse` | bool | Fail with `502` when the main page responds with 4xx/5xx. |
| `waitUntilNetworkIdle` | bool | Wait for the network to go idle before rendering. |
| `networkIdleStrict` | bool | `true` (default): 0 open connections. `false`: at most 2. |

## Interaction

Runs in order after the page loads: clicks, typing, selects, then waits.

| Field | Type | Description |
| --- | --- | --- |
| `click` | object \| array | `{ selector, button, clickCount, delay }`. `button`: `left` (default), `right`, `middle`. |
| `locatorClick` | object \| array | Same as `click`, but uses Puppeteer locators (supports `::-p-text(...)`, `::-p-aria(...)`). |
| `typeText` | object \| array | `{ selector, text, delay }`: type into an input. `delay` (ms) between keystrokes. |
| `selectOption` | object \| array | `{ selector, value }`: pick an option in a `<select>`. |
| `waitForSelector` | string \| object | `"#app"` or `{ selector, options: { visible, hidden, timeout } }`. |
| `waitForFunction` | string \| object | `"window.ready === true"` or `{ function, polling, timeout }`. `polling`: `raf` (default) or `mutation`. `timeout` in ms; `0` means no limit (still bounded by the request `timeout`). |
| `evaluateOnNewDocument` | string | JavaScript that runs before the page's own scripts. |
| `addStyleTag` | object | `{ content }` or `{ url }` (http/https). |
| `addScriptTag` | object | `{ content }` or `{ url }`, optionally `type` (for example `module`) and `id`. |

Fields typed `object | array` accept one object or a list of objects.

> The typing option is called `typeText` because `type` is already the output type.

## Advanced options

| Field | Type | Description |
| --- | --- | --- |
| `showBackground` | bool | Print background colors and images (PDF). |
| `usePipe` | bool | Talk to Chrome over a pipe instead of a WebSocket. |
| `writeOptionsToFile` | bool | Pass options to Node through a temporary file (for very large payloads). |
| `remoteInstance` | object | `{ ip, port }`: use a Chrome already running on another host. |
| `wsEndpoint` | string | `ws://` or `wss://` Chrome endpoint (for example browserless). |
| `throwOnRemoteConnectionError` | bool | Fail with `502` instead of falling back to the local Chromium. |

`remoteInstance` and `wsEndpoint` only work when `BROWSERSHOT_ALLOW_REMOTE_INSTANCE=true`.

## Response

Success:

```json
{
  "status": "success",
  "code": 200,
  "data": { "...": "depends on action" }
}
```

Error:

```json
{
  "status": "error",
  "code": 422,
  "message": "type must be one of: pdf, png, jpeg, jpg, webp, quality must be between 0 and 100",
  "errors": [
    "type must be one of: pdf, png, jpeg, jpg, webp",
    "quality must be between 0 and 100"
  ]
}
```

`errors` is only present for validation errors. With `APP_DEBUG=true`, 5xx errors include an `error` field with the internal message.

The HTTP status always matches the `code` field.

| Code | Cause |
| --- | --- |
| `200` | Success. |
| `400` | Body is not a valid JSON object. |
| `401` | Missing or wrong `App-Key`. |
| `404` | Unknown endpoint. |
| `405` | Method not allowed. |
| `422` | Validation failed, HTML contains `file://`/localhost references, or the `select` element was not found. |
| `502` | Page responded 4xx/5xx with `preventUnsuccessfulResponse`, or the remote instance connection failed. |
| `504` | `timeout` exceeded. |
| `500` | Chromium/Node failed, or another error. |

## Limits

- Request body is limited to 10 MB. Base64 output is about 33% larger than the file.
- `timeout` is at most 300 seconds; nginx closes the connection at 310 seconds.
- Concurrent renders are limited by `PHP_FPM_MAX_CHILDREN`; the rest wait in the queue.
- HTML may not contain `file:`, `view-source:`, or `//localhost`, `//127.*`, `//0.0.0.0`, `//[::1]` URLs. Absolute `http://localhost:...` URLs are allowed.
- Node/Chrome binary paths, Chromium arguments, and the Node environment cannot be changed through the request.
