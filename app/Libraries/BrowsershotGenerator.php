<?php

declare(strict_types=1);

namespace App\Libraries;

use Closure;
use Spatie\Browsershot\Browsershot;
use Spatie\Browsershot\Enums\Polling;

/**
 * Builds a Spatie Browsershot instance from a normalized request (see
 * App\Services\RequestValidator) and server config (see App\Support\Config),
 * then runs the requested action.
 */
class BrowsershotGenerator
{
    public const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    /** Default JPEG/WebP quality when the client does not send one. */
    public const DEFAULT_QUALITY = 90;

    private Closure $factory;

    /**
     * @param  array  $config  Server config, see Config::browsershot()
     * @param  (callable(bool $deviceEmulate): Browsershot)|null  $factory  Creates the Browsershot instance (overridable in tests)
     */
    public function __construct(private readonly array $config = [], ?callable $factory = null)
    {
        $this->factory = $factory !== null
            ? Closure::fromCallable($factory)
            : static fn (bool $deviceEmulate = false): Browsershot => new Browsershot('', $deviceEmulate);
    }

    /**
     * Build a configured Browsershot instance without calling the browser.
     */
    public function build(array $request): Browsershot
    {
        $options = $request['options'] ?? [];
        $type = $request['type'] ?? 'png';

        // With a device, skip Spatie's default 800x600 viewport: browser.cjs applies
        // `viewport` after `emulate(device)`, so it would override the device size.
        $deviceEmulate = isset($options['device']) && ! isset($options['windowSize']);

        /** @var Browsershot $browsershot */
        $browsershot = ($this->factory)($deviceEmulate);

        $request['contentType'] === 'html'
            ? $browsershot->setHtml($request['content'])
            : $browsershot->setUrl($request['content']);

        $this->applyServerConfig($browsershot);
        $this->applyPageOptions($browsershot, $options);
        $this->applyNetworkOptions($browsershot, $options);
        $this->applyInteractionOptions($browsershot, $options);

        if ($type === 'pdf') {
            $this->applyPdfOptions($browsershot, $options);
        } else {
            $this->applyScreenshotOptions($browsershot, $options, $type);
        }

        $this->applyShared($browsershot, $options);

        return $browsershot;
    }

    /**
     * Execute the request and return the API `data` payload.
     */
    public function run(array $request): array
    {
        $browsershot = $this->build($request);
        $workDir = $this->attachWorkDir($browsershot, $request);

        try {
            return $this->execute($browsershot, $request);
        } finally {
            // Kill anything still using the work dir (e.g. Chromium after node was
            // SIGKILLed), wait for it to exit, then remove the dir.
            RenderCleanup::run($workDir);
        }
    }

    /** Milliseconds the node guard gets before PHP's own process timeout fires. */
    public const DEADLINE_MARGIN_MS = 1500;

    /**
     * Give every render a private temp dir for the HTML file, the Chromium
     * profile and Chromium's own temp files, so a crashed or killed render
     * leaves nothing behind once the dir is removed.
     */
    private function attachWorkDir(Browsershot $browsershot, array $request): string
    {
        $base = rtrim($this->config['tempPath'] ?? '' ?: sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $workDir = $base.DIRECTORY_SEPARATOR.'browsershot-'.bin2hex(random_bytes(8));

        if (! mkdir($workDir, 0700, true) && ! is_dir($workDir)) {
            throw new \RuntimeException("Cannot create work dir {$workDir}");
        }

        // Spatie writes the temporary index.html here.
        $browsershot->setCustomTempPath($workDir);

        $timeout = (int) ($request['options']['timeout'] ?? $this->config['timeout'] ?? 60);

        $browsershot->setNodeEnv([
            // Puppeteer's temp profile and Chromium's temp files follow TMPDIR.
            'TMPDIR' => $workDir,
            'BROWSERSHOT_WORKDIR' => $workDir,
            'BROWSERSHOT_DEADLINE_MS' => (string) max(1000, $timeout * 1000 - self::DEADLINE_MARGIN_MS),
        ]);

        return $workDir;
    }

    public static function removeDirectory(string $path): void
    {
        RenderCleanup::removeDirectory($path);
    }

    private function execute(Browsershot $browsershot, array $request): array
    {
        $action = $request['action'] ?? 'render';
        $type = $request['type'] ?? 'png';

        return match ($action) {
            'render' => $this->render($browsershot, $type),
            'bodyHtml' => ['html' => $browsershot->bodyHtml()],
            'evaluate' => ['result' => $browsershot->evaluate($request['pageFunction'])],
            'triggeredRequests' => ['requests' => $browsershot->triggeredRequests() ?? []],
            'redirectHistory' => ['redirects' => $browsershot->redirectHistory() ?? []],
            'consoleMessages' => ['messages' => $browsershot->consoleMessages() ?? []],
            'failedRequests' => ['requests' => $browsershot->failedRequests() ?? []],
            'pageErrors' => ['errors' => $browsershot->pageErrors() ?? []],
            default => throw new \InvalidArgumentException("Unsupported action: {$action}"),
        };
    }

    private function render(Browsershot $browsershot, string $type): array
    {
        $base64 = $type === 'pdf'
            ? $browsershot->base64pdf()
            : $browsershot->base64Screenshot();

        if ($base64 === '') {
            throw new \RuntimeException('Browser returned empty output');
        }

        return [
            'size' => strlen((string) base64_decode($base64, true)),
            'base64' => $base64,
            'mime_type' => self::MIME_TYPES[$type],
            'extension' => $type === 'jpg' ? 'jpg' : $type,
        ];
    }

    private function applyServerConfig(Browsershot $browsershot): void
    {
        $config = $this->config;

        if (! empty($config['nodeBinary'])) {
            $browsershot->setNodeBinary($config['nodeBinary']);
        }

        if (! empty($config['npmBinary'])) {
            $browsershot->setNpmBinary($config['npmBinary']);
        }

        if (! empty($config['nodeModulePath'])) {
            $browsershot->setNodeModulePath($config['nodeModulePath']);
        }

        if (! empty($config['chromePath'])) {
            $browsershot->setChromePath($config['chromePath']);
        }

        if (! empty($config['includePath'])) {
            $browsershot->setIncludePath($config['includePath']);
        }

        if (! empty($config['binPath'])) {
            $browsershot->setBinPath($config['binPath']);
        }

        if ($config['usePipe'] ?? false) {
            $browsershot->usePipe();
        }

        if (! empty($config['chromiumArguments'])) {
            $browsershot->addChromiumArguments($config['chromiumArguments']);
        }

        if (! empty($config['userDataDir'])) {
            $browsershot->setUserDataDir($config['userDataDir']);
        }

        if ($config['noSandbox'] ?? false) {
            $browsershot->noSandbox();
        }

        $browsershot->timeout((int) ($config['timeout'] ?? 60));
    }

    private function applyPageOptions(Browsershot $browsershot, array $options): void
    {
        if (isset($options['windowSize'])) {
            $browsershot->windowSize($options['windowSize']['width'], $options['windowSize']['height']);
        }

        if (isset($options['device'])) {
            $browsershot->device($options['device']);
        }

        if (isset($options['userAgent'])) {
            $browsershot->userAgent($options['userAgent']);
        }

        if (array_key_exists('emulateMedia', $options)) {
            $browsershot->emulateMedia($options['emulateMedia']);
        }

        if (isset($options['emulateMediaFeatures'])) {
            $browsershot->emulateMediaFeatures($options['emulateMediaFeatures']);
        }

        // Viewport tweaks would override the emulated device (browser.cjs applies
        // `viewport` after `emulate(device)`), so they only apply without a device
        // or together with an explicit windowSize.
        $viewportAllowed = ! isset($options['device']) || isset($options['windowSize']);

        if ($viewportAllowed && isset($options['mobile'])) {
            $browsershot->mobile($options['mobile']);
        }

        if ($viewportAllowed && isset($options['touch'])) {
            $browsershot->touch($options['touch']);
        }

        if ($viewportAllowed && isset($options['deviceScaleFactor'])) {
            $browsershot->deviceScaleFactor($options['deviceScaleFactor']);
        }

        if (isset($options['delay'])) {
            $browsershot->setDelay($options['delay']);
        }

        if (isset($options['timeout'])) {
            $browsershot->timeout($options['timeout']);
        }

        if (isset($options['protocolTimeout'])) {
            $browsershot->protocolTimeout($options['protocolTimeout']);
        }

        if ($options['disableJavascript'] ?? false) {
            $browsershot->disableJavascript();
        }

        if ($options['disableImages'] ?? false) {
            $browsershot->disableImages();
        }

        if ($options['dismissDialogs'] ?? false) {
            $browsershot->dismissDialogs();
        }

        if ($options['newHeadless'] ?? false) {
            $browsershot->newHeadless();
        }

        if (isset($options['contentUrl'])) {
            $browsershot->setContentUrl($options['contentUrl']);
        }
    }

    private function applyNetworkOptions(Browsershot $browsershot, array $options): void
    {
        if (isset($options['extraHttpHeaders'])) {
            $browsershot->setExtraHttpHeaders($options['extraHttpHeaders']);
        }

        if (isset($options['extraNavigationHttpHeaders'])) {
            $browsershot->setExtraNavigationHttpHeaders($options['extraNavigationHttpHeaders']);
        }

        if (isset($options['authenticate'])) {
            $browsershot->authenticate($options['authenticate']['username'], $options['authenticate']['password']);
        }

        if (isset($options['cookies'])) {
            $browsershot->useCookies($options['cookies']['cookies'], $options['cookies']['domain']);
        }

        if (isset($options['post'])) {
            $browsershot->post($options['post']);
        }

        if (isset($options['blockUrls'])) {
            $browsershot->blockUrls($options['blockUrls']);
        }

        if (isset($options['blockDomains'])) {
            $browsershot->blockDomains($options['blockDomains']);
        }

        if (isset($options['proxyServer'])) {
            $browsershot->setProxyServer($options['proxyServer']);
        }

        if ($options['ignoreHttpsErrors'] ?? false) {
            $browsershot->ignoreHttpsErrors();
        }

        if ($options['disableRedirects'] ?? false) {
            $browsershot->disableRedirects();
        }

        if ($options['disableCaptureURLS'] ?? false) {
            $browsershot->disableCaptureURLS();
        }

        if (isset($options['preventUnsuccessfulResponse'])) {
            $browsershot->preventUnsuccessfulResponse($options['preventUnsuccessfulResponse']);
        }

        if ($options['waitUntilNetworkIdle'] ?? false) {
            $browsershot->waitUntilNetworkIdle($options['networkIdleStrict'] ?? true);
        }

        if (isset($options['remoteInstance'])) {
            $browsershot->setRemoteInstance($options['remoteInstance']['ip'], $options['remoteInstance']['port']);
        }

        if (isset($options['wsEndpoint'])) {
            $browsershot->setWSEndpoint($options['wsEndpoint']);
        }

        if (isset($options['throwOnRemoteConnectionError'])) {
            $browsershot->throwOnRemoteConnectionError($options['throwOnRemoteConnectionError']);
        }
    }

    private function applyInteractionOptions(Browsershot $browsershot, array $options): void
    {
        foreach ($options['click'] ?? [] as $click) {
            $browsershot->click($click['selector'], $click['button'], $click['clickCount'], $click['delay']);
        }

        foreach ($options['locatorClick'] ?? [] as $click) {
            $browsershot->locatorClick($click['selector'], $click['button'], $click['clickCount'], $click['delay']);
        }

        foreach ($options['typeText'] ?? [] as $type) {
            $browsershot->type($type['selector'], $type['text'], $type['delay']);
        }

        foreach ($options['selectOption'] ?? [] as $select) {
            $browsershot->selectOption($select['selector'], $select['value']);
        }

        if (isset($options['waitForFunction'])) {
            $wait = $options['waitForFunction'];
            $browsershot->waitForFunction($wait['function'], Polling::from($wait['polling']), $wait['timeout']);
        }

        if (isset($options['waitForSelector'])) {
            $browsershot->waitForSelector($options['waitForSelector']['selector'], $options['waitForSelector']['options']);
        }

        if (isset($options['evaluateOnNewDocument'])) {
            $browsershot->evaluateOnNewDocument($options['evaluateOnNewDocument']);
        }

        // browser.cjs reads these as JSON strings; no dedicated Spatie method exists.
        if (isset($options['addStyleTag'])) {
            $browsershot->setOption('addStyleTag', json_encode($options['addStyleTag']));
        }

        if (isset($options['addScriptTag'])) {
            $browsershot->setOption('addScriptTag', json_encode($options['addScriptTag']));
        }
    }

    private function applyPdfOptions(Browsershot $browsershot, array $options): void
    {
        if (isset($options['paperSize'])) {
            $paper = $options['paperSize'];
            $browsershot->paperSize($paper['width'], $paper['height'], $paper['unit']);
        } else {
            $browsershot->format($options['format'] ?? 'A4');
        }

        if (isset($options['landscape'])) {
            $browsershot->landscape($options['landscape']);
        }

        if (isset($options['margin'])) {
            $m = $options['margin'];
            $browsershot->margins($m['top'], $m['right'], $m['bottom'], $m['left'], $m['unit']);
        }

        if (isset($options['pages'])) {
            $browsershot->pages($options['pages']);
        }

        if (isset($options['scale'])) {
            $browsershot->scale($options['scale']);
        }

        if ($options['taggedPdf'] ?? false) {
            $browsershot->taggedPdf();
        }

        $hasTemplate = isset($options['headerHtml']) || isset($options['footerHtml'])
            || ($options['hideHeader'] ?? false) || ($options['hideFooter'] ?? false);

        if (($options['showBrowserHeaderAndFooter'] ?? false) || $hasTemplate) {
            $browsershot->showBrowserHeaderAndFooter();
        }

        if (isset($options['headerHtml'])) {
            $browsershot->headerHtml($options['headerHtml']);
        }

        if (isset($options['footerHtml'])) {
            $browsershot->footerHtml($options['footerHtml']);
        }

        if ($options['hideHeader'] ?? false) {
            $browsershot->hideHeader();
        }

        if ($options['hideFooter'] ?? false) {
            $browsershot->hideFooter();
        }

        if (isset($options['initialPageNumber'])) {
            $browsershot->initialPageNumber($options['initialPageNumber']);
        }
    }

    private function applyScreenshotOptions(Browsershot $browsershot, array $options, string $type): void
    {
        $puppeteerType = $type === 'jpg' ? 'jpeg' : $type;

        // Puppeteer rejects `quality` for PNG.
        $quality = $puppeteerType === 'png' ? null : ($options['quality'] ?? self::DEFAULT_QUALITY);

        $browsershot->setScreenshotType($puppeteerType, $quality);

        if ($options['fullPage'] ?? false) {
            $browsershot->fullPage();
        }

        if (isset($options['clip'])) {
            $c = $options['clip'];
            $browsershot->clip($c['x'], $c['y'], $c['width'], $c['height']);
        }

        if (isset($options['select'])) {
            $browsershot->select($options['select']['selector'], $options['select']['index']);
        }
    }

    private function applyShared(Browsershot $browsershot, array $options): void
    {
        if ($options['showBackground'] ?? false) {
            $browsershot->showBackground();
        }

        if ($options['hideBackground'] ?? false) {
            $browsershot->hideBackground();
        }

        if ($options['transparentBackground'] ?? false) {
            $browsershot->transparentBackground();
        }

        if ($options['usePipe'] ?? false) {
            $browsershot->usePipe();
        }

        if ($options['writeOptionsToFile'] ?? false) {
            $browsershot->writeOptionsToFile();
        }
    }
}
