<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Server-side configuration. Values here are never taken from the request body,
 * because they control which binaries are executed on the host.
 */
final class Config
{
    /**
     * Tuned for one short-lived render per browser. Compared to Puppeteer's
     * defaults this saves ~50 MB and 2 processes per render, and stops Chrome
     * from downloading components/models in the background.
     */
    public const DEFAULT_CHROMIUM_ARGUMENTS = [
        'headless=new',
        'disable-gpu',
        'disable-dev-shm-usage',
        'disable-extensions',
        'no-zygote',
        'mute-audio',
        'disable-component-update',
        'disable-background-networking',
        'disable-domain-reliability',
        'disable-breakpad',
        'no-pings',
        // One renderer process instead of one per site. Each render gets a fresh
        // browser, so cross-site isolation within a single render buys little.
        'disable-site-isolation-trials',
        'renderer-process-limit=1',
        'disable-features=Crashpad,IsolateOrigins,site-per-process,OptimizationGuideModelDownloading,OptimizationHintsFetching,OptimizationGuideOnDeviceModel,OnDeviceModelService,TextSafetyClassifier',
    ];

    public static function env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::env($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Configuration consumed by BrowsershotGenerator.
     */
    public static function browsershot(): array
    {
        $arguments = self::env('BROWSERSHOT_CHROMIUM_ARGS');

        return [
            'nodeBinary' => self::env('BROWSERSHOT_NODE_BINARY', '/usr/bin/node'),
            'npmBinary' => self::env('BROWSERSHOT_NPM_BINARY', '/usr/bin/npm'),
            'nodeModulePath' => self::env('BROWSERSHOT_NODE_MODULE_PATH'),
            'chromePath' => self::env('BROWSERSHOT_CHROME_PATH', '/usr/bin/chromium-browser'),
            'includePath' => self::env('BROWSERSHOT_INCLUDE_PATH', '$PATH:/usr/local/bin'),
            'tempPath' => self::env('BROWSERSHOT_TEMP_PATH'),
            'userDataDir' => self::env('BROWSERSHOT_USER_DATA_DIR'),
            'noSandbox' => self::bool('BROWSERSHOT_NO_SANDBOX', true),
            // Wrapper around spatie's browser.cjs that guarantees Chromium is killed
            // on timeout, crash, or when the PHP worker dies.
            'binPath' => self::env('BROWSERSHOT_BIN_PATH', dirname(__DIR__, 2).'/bin/browser-guard.cjs'),
            // Talk to Chromium over a pipe: Chromium exits by itself when node dies.
            'usePipe' => self::bool('BROWSERSHOT_USE_PIPE', true),
            'chromiumArguments' => $arguments === null
                ? self::DEFAULT_CHROMIUM_ARGUMENTS
                : array_values(array_filter(array_map('trim', explode(',', $arguments)))),
            'timeout' => (int) self::env('BROWSERSHOT_DEFAULT_TIMEOUT', '60'),
        ];
    }
}
