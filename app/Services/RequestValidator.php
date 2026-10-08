<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ValidationException;

/**
 * Validates the JSON payload and normalizes it into a structure consumed by
 * BrowsershotGenerator:
 *
 *   [
 *     'content' => string, 'contentType' => 'html'|'url',
 *     'action' => string, 'type' => string, 'pageFunction' => ?string,
 *     'options' => array<string, mixed>,
 *   ]
 *
 * Options that would let a client execute code on the host or read local files
 * (node/chrome binaries, chromium arguments, node env, setOption, file paths) are
 * intentionally not accepted from the request; they come from App\Support\Config.
 */
final class RequestValidator
{
    public const ACTIONS = [
        'render',
        'bodyHtml',
        'evaluate',
        'triggeredRequests',
        'redirectHistory',
        'consoleMessages',
        'failedRequests',
        'pageErrors',
    ];

    public const TYPES = ['pdf', 'png', 'jpeg', 'jpg', 'webp'];

    /** Paper formats supported by Puppeteer (case-insensitive). */
    public const PDF_FORMATS = ['letter', 'legal', 'tabloid', 'ledger', 'a0', 'a1', 'a2', 'a3', 'a4', 'a5', 'a6'];

    public const UNITS = ['mm', 'cm', 'in', 'px'];

    public const MEDIA_TYPES = ['screen', 'print'];

    public const MOUSE_BUTTONS = ['left', 'right', 'middle'];

    public const POLLING = ['raf', 'mutation'];

    private const BOOLEAN_OPTIONS = [
        'landscape',
        'fullPage',
        'showBackground',
        'hideBackground',
        'transparentBackground',
        'taggedPdf',
        'showBrowserHeaderAndFooter',
        'hideHeader',
        'hideFooter',
        'mobile',
        'touch',
        'waitUntilNetworkIdle',
        'networkIdleStrict',
        'dismissDialogs',
        'disableJavascript',
        'disableImages',
        'disableCaptureURLS',
        'disableRedirects',
        'ignoreHttpsErrors',
        'preventUnsuccessfulResponse',
        'newHeadless',
        'usePipe',
        'writeOptionsToFile',
        'throwOnRemoteConnectionError',
    ];

    /** @var list<string> */
    private array $errors = [];

    private array $input = [];

    private array $options = [];

    public function __construct(private readonly bool $allowRemoteInstance = false)
    {
    }

    /**
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        $this->errors = [];
        $this->input = $input;
        $this->options = [];

        [$content, $contentType] = $this->content();

        $action = $this->enum('action', self::ACTIONS) ?? 'render';
        $type = $this->enum('type', self::TYPES) ?? 'png';

        $pageFunction = null;
        if ($action === 'evaluate') {
            $pageFunction = $this->string('pageFunction', required: true);
        }

        foreach (self::BOOLEAN_OPTIONS as $key) {
            $this->bool($key);
        }

        $this->pdfOptions();
        $this->screenshotOptions();
        $this->pageOptions();
        $this->interactionOptions();
        $this->networkOptions($contentType);
        $this->remoteOptions();

        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }

        return [
            'content' => $content,
            'contentType' => $contentType,
            'action' => $action,
            'type' => $type,
            'pageFunction' => $pageFunction,
            'options' => $this->options,
        ];
    }

    private function content(): array
    {
        $html = $this->input['html'] ?? null;
        $url = $this->input['url'] ?? null;

        if ($html !== null && ! is_string($html)) {
            $this->errors[] = 'html harus berupa string';
            $html = null;
        }

        if ($url !== null && ! is_string($url)) {
            $this->errors[] = 'url harus berupa string';
            $url = null;
        }

        if ($html !== null && $html !== '') {
            return [$html, 'html'];
        }

        if ($url === null || trim($url) === '') {
            $this->errors[] = 'Param html atau url harus diisi';

            return ['', 'url'];
        }

        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            $this->errors[] = 'URL tidak valid (hanya http/https)';
        }

        return [$url, 'url'];
    }

    private function pdfOptions(): void
    {
        $format = $this->string('format');
        if ($format !== null) {
            if (! in_array(strtolower($format), self::PDF_FORMATS, true)) {
                $this->errors[] = 'Format tidak valid, gunakan salah satu dari: '.implode(', ', self::PDF_FORMATS);
            } else {
                $this->options['format'] = $format;
            }
        }

        if ($this->has('margin')) {
            $margin = $this->object('margin');
            if ($margin !== null) {
                $normalized = ['unit' => $this->unit($margin, 'margin')];
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    $value = $margin[$side] ?? 0;
                    if (! is_int($value) && ! is_float($value) || $value < 0) {
                        $this->errors[] = 'Margin '.$side.' harus berupa angka >= 0';
                    }
                    $normalized[$side] = (float) (is_numeric($value) ? $value : 0);
                }
                $this->options['margin'] = $normalized;
            }
        }

        if ($this->has('paperSize')) {
            $paper = $this->object('paperSize');
            if ($paper !== null) {
                foreach (['width', 'height'] as $side) {
                    if (! isset($paper[$side]) || ! is_numeric($paper[$side]) || is_string($paper[$side]) || $paper[$side] <= 0) {
                        $this->errors[] = "paperSize.$side harus berupa angka > 0";
                    }
                }
                $this->options['paperSize'] = [
                    'width' => (float) ($paper['width'] ?? 0),
                    'height' => (float) ($paper['height'] ?? 0),
                    'unit' => $this->unit($paper, 'paperSize'),
                ];
            }
        }

        $pages = $this->string('pages');
        if ($pages !== null) {
            if (! preg_match('/^\s*\d+(\s*-\s*\d*)?(\s*,\s*\d+(\s*-\s*\d*)?)*\s*$/', $pages)) {
                $this->errors[] = 'pages tidak valid, contoh: "1-3, 5"';
                unset($this->options['pages']);
            }
        }

        $this->number('scale', 0.1, 2);
        $this->string('headerHtml', maxLength: 100_000);
        $this->string('footerHtml', maxLength: 100_000);
        $this->int('initialPageNumber', 1, 100_000);
    }

    private function screenshotOptions(): void
    {
        $this->int('quality', 0, 100);
        $this->int('deviceScaleFactor', 1, 3);

        if ($this->has('clip')) {
            $clip = $this->object('clip');
            if ($clip !== null) {
                $normalized = [];
                foreach (['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1] as $key => $min) {
                    if (! isset($clip[$key]) || ! is_int($clip[$key]) || $clip[$key] < $min) {
                        $this->errors[] = "clip.$key harus berupa integer >= $min";
                    }
                    $normalized[$key] = (int) ($clip[$key] ?? 0);
                }
                $this->options['clip'] = $normalized;
            }
        }

        if ($this->has('select')) {
            $select = $this->input['select'];
            if (is_string($select)) {
                $select = ['selector' => $select];
            }
            if (! is_array($select) || ! isset($select['selector']) || ! is_string($select['selector']) || $select['selector'] === '') {
                $this->errors[] = 'select harus berupa selector string atau {selector, index}';
            } elseif (isset($select['index']) && (! is_int($select['index']) || $select['index'] < 0)) {
                $this->errors[] = 'select.index harus berupa integer >= 0';
            } else {
                $this->options['select'] = ['selector' => $select['selector'], 'index' => $select['index'] ?? 0];
            }
        }
    }

    private function pageOptions(): void
    {
        if ($this->has('windowSize')) {
            $size = $this->object('windowSize');
            if ($size !== null) {
                foreach (['width', 'height'] as $key) {
                    if (! isset($size[$key]) || ! is_int($size[$key]) || $size[$key] < 1 || $size[$key] > 16384) {
                        $this->errors[] = "windowSize.$key harus berupa integer 1-16384";
                    }
                }
                $this->options['windowSize'] = ['width' => (int) ($size['width'] ?? 0), 'height' => (int) ($size['height'] ?? 0)];
            }
        }

        $this->string('device', maxLength: 200);
        $this->string('userAgent', maxLength: 1000);

        if ($this->has('emulateMedia')) {
            $media = $this->input['emulateMedia'];
            if ($media !== null && ! in_array($media, self::MEDIA_TYPES, true)) {
                $this->errors[] = 'emulateMedia harus salah satu dari: screen, print, null';
            } else {
                $this->options['emulateMedia'] = $media;
            }
        }

        if ($this->has('emulateMediaFeatures')) {
            $features = $this->input['emulateMediaFeatures'];
            if (! $this->isListOf($features, fn ($f) => is_array($f) && is_string($f['name'] ?? null) && is_string($f['value'] ?? null))) {
                $this->errors[] = 'emulateMediaFeatures harus berupa list {name, value}';
            } else {
                $this->options['emulateMediaFeatures'] = array_map(
                    fn (array $f) => ['name' => $f['name'], 'value' => $f['value']],
                    $features
                );
            }
        }

        $this->int('delay', 0, 300_000);
        $this->int('timeout', 1, 600);
        $this->int('protocolTimeout', 1, 600);
    }

    private function interactionOptions(): void
    {
        if ($this->has('waitForFunction')) {
            $wait = $this->input['waitForFunction'];
            if (is_string($wait)) {
                $wait = ['function' => $wait];
            }
            if (! is_array($wait) || ! is_string($wait['function'] ?? null) || $wait['function'] === '') {
                $this->errors[] = 'waitForFunction harus berupa string atau {function, polling, timeout}';
            } elseif (isset($wait['polling']) && ! in_array($wait['polling'], self::POLLING, true)) {
                $this->errors[] = 'waitForFunction.polling harus salah satu dari: raf, mutation';
            } elseif (isset($wait['timeout']) && (! is_int($wait['timeout']) || $wait['timeout'] < 0)) {
                $this->errors[] = 'waitForFunction.timeout harus berupa integer >= 0 (ms)';
            } else {
                $this->options['waitForFunction'] = [
                    'function' => $wait['function'],
                    'polling' => $wait['polling'] ?? 'raf',
                    'timeout' => $wait['timeout'] ?? 0,
                ];
            }
        }

        if ($this->has('waitForSelector')) {
            $wait = $this->input['waitForSelector'];
            if (is_string($wait)) {
                $wait = ['selector' => $wait];
            }
            if (! is_array($wait) || ! is_string($wait['selector'] ?? null) || $wait['selector'] === '') {
                $this->errors[] = 'waitForSelector harus berupa string atau {selector, options}';
            } elseif (isset($wait['options']) && ! $this->isAssoc($wait['options'])) {
                $this->errors[] = 'waitForSelector.options harus berupa object';
            } else {
                $this->options['waitForSelector'] = ['selector' => $wait['selector'], 'options' => $wait['options'] ?? []];
            }
        }

        $this->string('evaluateOnNewDocument', maxLength: 100_000);

        $clickRule = fn ($c) => is_array($c)
            && is_string($c['selector'] ?? null) && $c['selector'] !== ''
            && in_array($c['button'] ?? 'left', self::MOUSE_BUTTONS, true)
            && is_int($c['clickCount'] ?? 1) && ($c['clickCount'] ?? 1) >= 1
            && is_int($c['delay'] ?? 0) && ($c['delay'] ?? 0) >= 0;

        foreach (['click', 'locatorClick'] as $key) {
            $this->listOption($key, $clickRule, '{selector, button, clickCount, delay}', fn (array $c) => [
                'selector' => $c['selector'],
                'button' => $c['button'] ?? 'left',
                'clickCount' => $c['clickCount'] ?? 1,
                'delay' => $c['delay'] ?? 0,
            ]);
        }

        // `type` is the output type, so Spatie's type() is exposed as `typeText`.
        $this->listOption(
            'typeText',
            fn ($t) => is_array($t) && is_string($t['selector'] ?? null) && $t['selector'] !== ''
                && is_string($t['text'] ?? '') && is_int($t['delay'] ?? 0) && ($t['delay'] ?? 0) >= 0,
            '{selector, text, delay}',
            fn (array $t) => ['selector' => $t['selector'], 'text' => $t['text'] ?? '', 'delay' => $t['delay'] ?? 0]
        );

        $this->listOption(
            'selectOption',
            fn ($s) => is_array($s) && is_string($s['selector'] ?? null) && $s['selector'] !== '' && is_string($s['value'] ?? ''),
            '{selector, value}',
            fn (array $s) => ['selector' => $s['selector'], 'value' => $s['value'] ?? '']
        );

        foreach (['addStyleTag', 'addScriptTag'] as $key) {
            if (! $this->has($key)) {
                continue;
            }
            $tag = $this->input[$key];
            $allowed = $key === 'addStyleTag' ? ['url', 'content'] : ['url', 'content', 'type', 'id'];
            if (! $this->isAssoc($tag) || array_diff(array_keys($tag), $allowed) !== []
                || (! isset($tag['url']) && ! isset($tag['content']))
                || array_filter($tag, fn ($v) => ! is_string($v)) !== []) {
                $this->errors[] = "$key harus berupa object dengan key: ".implode(', ', $allowed).' (path lokal tidak diizinkan)';
            } elseif (isset($tag['url']) && ! $this->isHttpUrl($tag['url'])) {
                $this->errors[] = "$key.url harus berupa URL http/https";
            } else {
                $this->options[$key] = $tag;
            }
        }
    }

    private function networkOptions(string $contentType): void
    {
        $this->stringMap('extraHttpHeaders');
        $this->stringMap('extraNavigationHttpHeaders');

        if ($this->has('authenticate')) {
            $auth = $this->object('authenticate');
            if ($auth !== null) {
                if (! is_string($auth['username'] ?? null) || ! is_string($auth['password'] ?? null)) {
                    $this->errors[] = 'authenticate harus berupa {username, password}';
                } else {
                    $this->options['authenticate'] = ['username' => $auth['username'], 'password' => $auth['password']];
                }
            }
        }

        if ($this->has('cookies')) {
            $cookies = $this->input['cookies'];
            if ($this->isAssoc($cookies) && isset($cookies['cookies'])) {
                $domain = $cookies['domain'] ?? null;
                $cookies = $cookies['cookies'];
            } else {
                $domain = null;
            }

            if (! $this->isAssoc($cookies) || array_filter($cookies, fn ($v) => ! is_string($v)) !== []) {
                $this->errors[] = 'cookies harus berupa object {name: value} atau {cookies: {...}, domain}';
            } elseif ($domain !== null && (! is_string($domain) || $domain === '')) {
                $this->errors[] = 'cookies.domain harus berupa string';
            } elseif ($domain === null && $contentType === 'html') {
                $this->errors[] = 'cookies.domain wajib diisi jika menggunakan html';
            } else {
                $this->options['cookies'] = ['cookies' => $cookies, 'domain' => $domain];
            }
        }

        if ($this->has('post')) {
            $post = $this->input['post'];
            if (! $this->isAssoc($post) || array_filter($post, fn ($v) => ! is_scalar($v)) !== []) {
                $this->errors[] = 'post harus berupa object {key: value}';
            } else {
                $this->options['post'] = $post;
            }
        }

        foreach (['blockUrls', 'blockDomains'] as $key) {
            if (! $this->has($key)) {
                continue;
            }
            if (! $this->isListOf($this->input[$key], fn ($v) => is_string($v) && $v !== '')) {
                $this->errors[] = "$key harus berupa list string";
            } else {
                $this->options[$key] = $this->input[$key];
            }
        }

        $proxy = $this->string('proxyServer', maxLength: 500);
        if ($proxy !== null && ! preg_match('#^((https?|socks[45]?)://)?[A-Za-z0-9.\-\[\]:]+(:\d+)?$#', $proxy)) {
            $this->errors[] = 'proxyServer tidak valid, contoh: http://host:port';
            unset($this->options['proxyServer']);
        }

        $contentUrl = $this->string('contentUrl', maxLength: 2000);
        if ($contentUrl !== null && ! $this->isHttpUrl($contentUrl)) {
            $this->errors[] = 'contentUrl harus berupa URL http/https';
            unset($this->options['contentUrl']);
        }
    }

    private function remoteOptions(): void
    {
        $hasRemote = $this->has('remoteInstance') || $this->has('wsEndpoint');

        if ($hasRemote && ! $this->allowRemoteInstance) {
            $this->errors[] = 'remoteInstance/wsEndpoint dinonaktifkan (set BROWSERSHOT_ALLOW_REMOTE_INSTANCE=true)';

            return;
        }

        if ($this->has('remoteInstance')) {
            $remote = $this->object('remoteInstance');
            if ($remote !== null) {
                $ip = $remote['ip'] ?? '127.0.0.1';
                $port = $remote['port'] ?? 9222;
                if (! is_string($ip) || ! preg_match('/^[A-Za-z0-9.\-]+$/', $ip) || ! is_int($port) || $port < 1 || $port > 65535) {
                    $this->errors[] = 'remoteInstance harus berupa {ip, port}';
                } else {
                    $this->options['remoteInstance'] = ['ip' => $ip, 'port' => $port];
                }
            }
        }

        $ws = $this->string('wsEndpoint', maxLength: 2000);
        if ($ws !== null && ! preg_match('#^wss?://#i', $ws)) {
            $this->errors[] = 'wsEndpoint harus diawali ws:// atau wss://';
            unset($this->options['wsEndpoint']);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function has(string $key): bool
    {
        return array_key_exists($key, $this->input) && $this->input[$key] !== null
            || ($key === 'emulateMedia' && array_key_exists($key, $this->input));
    }

    private function bool(string $key): void
    {
        if (! $this->has($key)) {
            return;
        }

        if (! is_bool($this->input[$key])) {
            $this->errors[] = "$key harus boolean (true/false)";

            return;
        }

        $this->options[$key] = $this->input[$key];
    }

    private function int(string $key, int $min, int $max): void
    {
        if (! $this->has($key)) {
            return;
        }

        $value = $this->input[$key];

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            $this->errors[] = "$key harus berupa angka";

            return;
        }

        $value = (int) $value;

        if ($value < $min || $value > $max) {
            $this->errors[] = "$key harus di antara $min dan $max";

            return;
        }

        $this->options[$key] = $value;
    }

    private function number(string $key, float $min, float $max): void
    {
        if (! $this->has($key)) {
            return;
        }

        $value = $this->input[$key];

        if (! is_int($value) && ! is_float($value)) {
            $this->errors[] = "$key harus berupa angka";

            return;
        }

        if ($value < $min || $value > $max) {
            $this->errors[] = "$key harus di antara $min dan $max";

            return;
        }

        $this->options[$key] = (float) $value;
    }

    private function string(string $key, bool $required = false, int $maxLength = 10_000): ?string
    {
        if (! $this->has($key)) {
            if ($required) {
                $this->errors[] = "$key wajib diisi";
            }

            return null;
        }

        $value = $this->input[$key];

        if (! is_string($value) || $value === '') {
            $this->errors[] = "$key harus berupa string";

            return null;
        }

        if (strlen($value) > $maxLength) {
            $this->errors[] = "$key maksimal $maxLength karakter";

            return null;
        }

        if (! in_array($key, ['format', 'pageFunction'], true)) {
            $this->options[$key] = $value;
        }

        return $value;
    }

    private function enum(string $key, array $allowed): ?string
    {
        if (! $this->has($key)) {
            return null;
        }

        if (! in_array($this->input[$key], $allowed, true)) {
            $this->errors[] = ucfirst($key).' harus salah satu dari: '.implode(', ', $allowed);

            return null;
        }

        return $this->input[$key];
    }

    private function object(string $key): ?array
    {
        if (! $this->isAssoc($this->input[$key])) {
            $this->errors[] = "$key harus berupa object";

            return null;
        }

        return $this->input[$key];
    }

    private function unit(array $data, string $key): string
    {
        $unit = $data['unit'] ?? 'mm';

        if (! in_array($unit, self::UNITS, true)) {
            $this->errors[] = "$key.unit harus salah satu dari: ".implode(', ', self::UNITS);

            return 'mm';
        }

        return $unit;
    }

    private function stringMap(string $key): void
    {
        if (! $this->has($key)) {
            return;
        }

        $value = $this->input[$key];

        if (! $this->isAssoc($value) || array_filter($value, fn ($v) => ! is_string($v)) !== []) {
            $this->errors[] = "$key harus berupa object {name: value} dengan value string";

            return;
        }

        $this->options[$key] = $value;
    }

    private function listOption(string $key, callable $rule, string $shape, callable $normalize): void
    {
        if (! $this->has($key)) {
            return;
        }

        $value = $this->input[$key];

        // Allow a single object as shorthand for a one-item list.
        if ($this->isAssoc($value)) {
            $value = [$value];
        }

        if (! $this->isListOf($value, $rule)) {
            $this->errors[] = "$key harus berupa list $shape";

            return;
        }

        $this->options[$key] = array_map($normalize, $value);
    }

    private function isListOf(mixed $value, callable $rule): bool
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return false;
        }

        foreach ($value as $item) {
            if (! $rule($item)) {
                return false;
            }
        }

        return true;
    }

    private function isAssoc(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    private function isHttpUrl(mixed $value): bool
    {
        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
