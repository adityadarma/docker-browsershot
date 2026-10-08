<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Libraries\BrowsershotGenerator;
use App\Services\BrowsershotService;
use App\Services\RequestValidator;
use App\Support\Config;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Real Node + Puppeteer + Chrome. Mirrors the rendering cases in spatie's
 * ScreenshotTest / PdfTest / HtmlTest / BrowsershotTest that do not need the
 * internet. Skipped when the toolchain is unavailable.
 *
 * Override paths with BROWSERSHOT_NODE_BINARY, BROWSERSHOT_NPM_BINARY,
 * BROWSERSHOT_CHROME_PATH, BROWSERSHOT_NODE_MODULE_PATH.
 */
final class RenderTest extends TestCase
{
    private static ?array $config = null;

    private static ?string $skipReason = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);

        $node = Config::env('BROWSERSHOT_NODE_BINARY') ?? self::which('node');
        $npm = Config::env('BROWSERSHOT_NPM_BINARY') ?? self::which('npm');
        $chrome = Config::env('BROWSERSHOT_CHROME_PATH') ?? self::firstExisting([
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
            '/usr/bin/google-chrome',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
        ]);
        $modules = Config::env('BROWSERSHOT_NODE_MODULE_PATH')
            ?? (is_dir("$root/node_modules/puppeteer") ? "$root/node_modules" : null);

        if ($node === null || ! is_file($node)) {
            self::$skipReason = 'node not found';
        } elseif ($chrome === null || ! is_file($chrome)) {
            self::$skipReason = 'Chrome/Chromium not found';
        } elseif ($modules === null && $npm === null) {
            self::$skipReason = 'puppeteer not installed (run npm install)';
        }

        $config = Config::browsershot();
        $config['nodeBinary'] = $node;
        $config['npmBinary'] = $npm;
        $config['chromePath'] = $chrome;
        $config['nodeModulePath'] = $modules;
        $config['includePath'] = '$PATH:'.dirname((string) $node).':/usr/local/bin:/opt/homebrew/bin';
        $config['timeout'] = 60;
        // --no-zygote crashes Chrome on macOS; keep only portable args.
        $config['chromiumArguments'] = ['disable-gpu', 'disable-dev-shm-usage', 'mute-audio'];

        self::$config = $config;
    }

    protected function setUp(): void
    {
        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }
    }

    private function handle(array $input): array
    {
        $service = new BrowsershotService(
            new RequestValidator,
            new BrowsershotGenerator(self::$config),
            debug: true,
        );

        return $service->handleRequest($input);
    }

    private function success(array $input): array
    {
        $result = $this->handle($input);

        $this->assertSame('success', $result['status'], json_encode($result));

        return $result['data'];
    }

    private function binary(array $data): string
    {
        return base64_decode($data['base64'], true);
    }

    /** @return array{0: int, 1: int} */
    private function imageSize(array $data): array
    {
        $size = getimagesizefromstring($this->binary($data));

        return [$size[0], $size[1]];
    }

    // ----------------------------------------------------------- screenshots

    public function test_can_take_a_png_screenshot_of_html(): void
    {
        $data = $this->success(['html' => '<h1>Hello world</h1>']);

        $this->assertSame('image/png', $data['mime_type']);
        $this->assertStringStartsWith("\x89PNG", $this->binary($data));
        $this->assertSame([800, 600], $this->imageSize($data));
        $this->assertSame($data['size'], strlen($this->binary($data)));
    }

    public function test_can_set_window_size(): void
    {
        $this->assertSame([400, 300], $this->imageSize($this->success(['html' => '<p>x</p>', 'windowSize' => ['width' => 400, 'height' => 300]])));
    }

    public function test_can_take_a_high_density_screenshot(): void
    {
        $this->assertSame([800, 600], $this->imageSize($this->success([
            'html' => '<p>x</p>', 'windowSize' => ['width' => 400, 'height' => 300], 'deviceScaleFactor' => 2,
        ])));
    }

    public function test_can_take_a_full_page_screenshot(): void
    {
        $html = '<body style="margin:0"><div style="height:2000px">tall</div></body>';

        [, $viewportHeight] = $this->imageSize($this->success(['html' => $html]));
        [, $fullHeight] = $this->imageSize($this->success(['html' => $html, 'fullPage' => true]));

        $this->assertSame(600, $viewportHeight);
        $this->assertSame(2000, $fullHeight);
    }

    public function test_can_clip_a_screenshot(): void
    {
        $this->assertSame([120, 80], $this->imageSize($this->success([
            'html' => '<p>x</p>', 'clip' => ['x' => 10, 'y' => 10, 'width' => 120, 'height' => 80],
        ])));
    }

    public function test_can_take_a_screenshot_of_an_element(): void
    {
        $this->assertSame([150, 50], $this->imageSize($this->success([
            'html' => '<div style="height:100px"></div><div id="box" style="width:150px;height:50px;background:red"></div>',
            'select' => '#box',
        ])));
    }

    public function test_element_not_found_returns_422(): void
    {
        $result = $this->handle(['html' => '<p>x</p>', 'select' => '#missing']);

        $this->assertSame(422, $result['code']);
        $this->assertStringContainsString('#missing', $result['message']);
    }

    public function test_can_take_jpeg_and_webp_screenshots(): void
    {
        $jpeg = $this->success(['html' => '<p>x</p>', 'type' => 'jpg', 'quality' => 50]);
        $this->assertSame('image/jpeg', $jpeg['mime_type']);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $this->binary($jpeg));

        $webp = $this->success(['html' => '<p>x</p>', 'type' => 'webp']);
        $this->assertSame('image/webp', $webp['mime_type']);
        $this->assertSame('WEBP', substr($this->binary($webp), 8, 4));
    }

    public function test_quality_affects_jpeg_size(): void
    {
        $html = '<body style="background:linear-gradient(90deg,red,blue,green)"><h1>Quality</h1></body>';

        $low = $this->success(['html' => $html, 'type' => 'jpeg', 'quality' => 10]);
        $high = $this->success(['html' => $html, 'type' => 'jpeg', 'quality' => 100]);

        $this->assertLessThan($high['size'], $low['size']);
    }

    public function test_can_take_a_scaled_and_delayed_screenshot(): void
    {
        $start = microtime(true);
        $this->success(['html' => '<p>x</p>', 'delay' => 1000]);

        $this->assertGreaterThanOrEqual(1.0, microtime(true) - $start);
    }

    // ----------------------------------------------------------- pdf

    public function test_can_render_a_pdf(): void
    {
        $data = $this->success(['html' => '<h1>Invoice</h1>', 'type' => 'pdf']);

        $this->assertSame('application/pdf', $data['mime_type']);
        $this->assertStringStartsWith('%PDF-', $this->binary($data));
    }

    public function test_pdf_page_ranges_and_landscape(): void
    {
        $html = '<div style="page-break-after:always">1</div><div style="page-break-after:always">2</div><div>3</div>';

        $all = $this->binary($this->success(['html' => $html, 'type' => 'pdf']));
        $some = $this->binary($this->success(['html' => $html, 'type' => 'pdf', 'pages' => '1-2']));

        $this->assertSame(3, $this->pdfPageCount($all));
        $this->assertSame(2, $this->pdfPageCount($some));

        $landscape = $this->binary($this->success(['html' => '<p>x</p>', 'type' => 'pdf', 'format' => 'A4', 'landscape' => true]));
        [$width, $height] = $this->pdfMediaBox($landscape);
        $this->assertGreaterThan($height, $width);
    }

    public function test_pdf_custom_paper_size(): void
    {
        $pdf = $this->binary($this->success([
            'html' => '<p>x</p>', 'type' => 'pdf', 'paperSize' => ['width' => 4, 'height' => 6, 'unit' => 'in'],
        ]));

        [$width, $height] = $this->pdfMediaBox($pdf);
        $this->assertEqualsWithDelta(288, $width, 1);
        $this->assertEqualsWithDelta(432, $height, 1);
    }

    public function test_highly_customized_pdf(): void
    {
        $data = $this->success([
            'html' => '<h1 style="background:#eee">Report</h1>',
            'type' => 'pdf',
            'format' => 'Letter',
            'margin' => ['top' => 20, 'right' => 10, 'bottom' => 20, 'left' => 10],
            'showBackground' => true,
            'scale' => 0.8,
            'headerHtml' => '<div style="font-size:8px">Header</div>',
            'footerHtml' => '<div style="font-size:8px"><span class="pageNumber"></span></div>',
            'taggedPdf' => true,
            'emulateMedia' => 'screen',
        ]);

        $this->assertStringStartsWith('%PDF-', $this->binary($data));
    }

    // ----------------------------------------------------------- html / js

    public function test_can_get_body_html_after_javascript(): void
    {
        $data = $this->success([
            'html' => '<div id="t"></div><script>document.getElementById("t").textContent = "rendered by js"</script>',
            'action' => 'bodyHtml',
        ]);

        $this->assertStringContainsString('rendered by js', $data['html']);
    }

    public function test_can_disable_javascript(): void
    {
        $data = $this->success([
            'html' => '<div id="t">static</div><script>document.getElementById("t").textContent = "js"</script>',
            'action' => 'bodyHtml',
            'disableJavascript' => true,
        ]);

        $this->assertStringContainsString('>static<', $data['html']);
    }

    public function test_can_evaluate(): void
    {
        $this->assertSame('2', $this->success(['html' => '<p>x</p>', 'action' => 'evaluate', 'pageFunction' => '1 + 1'])['result']);
    }

    public function test_can_evaluate_on_new_document(): void
    {
        $data = $this->success([
            'html' => '<p>x</p>',
            'action' => 'evaluate',
            'pageFunction' => 'window.injected',
            'evaluateOnNewDocument' => 'window.injected = "yes"',
        ]);

        $this->assertSame('yes', $data['result']);
    }

    public function test_can_type_click_and_select(): void
    {
        $data = $this->success([
            'html' => '<input id="name"><select id="color"><option value="red">R</option><option value="blue">B</option></select>'
                .'<button id="go" onclick="document.body.dataset.r = document.getElementById(\'name\').value + \'-\' + document.getElementById(\'color\').value">go</button>',
            'action' => 'evaluate',
            'pageFunction' => 'document.body.dataset.r',
            'typeText' => ['selector' => '#name', 'text' => 'Aditya'],
            'selectOption' => ['selector' => '#color', 'value' => 'blue'],
            'click' => ['selector' => '#go'],
        ]);

        $this->assertSame('Aditya-blue', $data['result']);
    }

    public function test_can_wait_for_function_and_selector(): void
    {
        $data = $this->success([
            'html' => '<script>setTimeout(() => { window.ready = true; document.body.innerHTML = "<p id=late>late</p>" }, 300)</script>',
            'action' => 'bodyHtml',
            'waitForFunction' => 'window.ready === true',
            'waitForSelector' => '#late',
        ]);

        $this->assertStringContainsString('id="late"', $data['html']);
    }

    public function test_can_add_style_tag(): void
    {
        $data = $this->success([
            'html' => '<p id="p">x</p>',
            'action' => 'evaluate',
            'pageFunction' => 'getComputedStyle(document.getElementById("p")).color',
            'addStyleTag' => ['content' => '#p{color:rgb(255, 0, 0)}'],
        ]);

        $this->assertSame('rgb(255, 0, 0)', $data['result']);
    }

    public function test_can_set_user_agent(): void
    {
        $this->assertSame('bridge-test-agent', $this->success([
            'html' => '<p>x</p>', 'action' => 'evaluate', 'pageFunction' => 'navigator.userAgent', 'userAgent' => 'bridge-test-agent',
        ])['result']);
    }

    public function test_can_emulate_media_features(): void
    {
        $this->assertSame('true', $this->success([
            'html' => '<p>x</p>',
            'action' => 'evaluate',
            'pageFunction' => 'String(matchMedia("(prefers-color-scheme: dark)").matches)',
            'emulateMediaFeatures' => [['name' => 'prefers-color-scheme', 'value' => 'dark']],
        ])['result']);
    }

    public function test_can_emulate_device(): void
    {
        // iPhone X: 375x812 CSS px at deviceScaleFactor 3.
        $this->assertSame([1125, 2436], $this->imageSize($this->success(['html' => '<p>x</p>', 'device' => 'iPhone X'])));

        $this->assertSame('true', $this->success([
            'html' => '<p>x</p>', 'action' => 'evaluate', 'device' => 'iPhone X',
            'pageFunction' => 'String(screen.width === 375 && /iPhone/.test(navigator.userAgent))',
        ])['result']);
    }

    // ----------------------------------------------------------- inspection

    public function test_can_get_console_messages(): void
    {
        $messages = $this->success(['html' => '<script>console.log("hello console")</script>', 'action' => 'consoleMessages'])['messages'];

        $this->assertSame('hello console', $messages[0]['message']);
        $this->assertSame('log', $messages[0]['type']);
    }

    public function test_can_get_page_errors(): void
    {
        $errors = $this->success(['html' => '<script>throw new Error("boom")</script>', 'action' => 'pageErrors'])['errors'];

        $this->assertStringContainsString('boom', $errors[0]['message']);
    }

    public function test_can_get_triggered_requests(): void
    {
        $requests = $this->success(['html' => '<p>x</p>', 'action' => 'triggeredRequests'])['requests'];

        $this->assertStringStartsWith('file://', $requests[0]['url']);
    }

    // ----------------------------------------------------------- security

    public function test_html_with_file_url_is_rejected_before_launching_chrome(): void
    {
        $result = $this->handle(['html' => '<iframe src="file:///etc/passwd"></iframe>']);

        $this->assertSame(422, $result['code']);
    }

    public function test_timeout_returns_504(): void
    {
        $result = $this->handle([
            'html' => '<p>x</p>',
            'waitForFunction' => ['function' => 'false', 'timeout' => 0],
            'timeout' => 3,
        ]);

        $this->assertSame('error', $result['status']);
        $this->assertSame(504, $result['code']);
    }

    public function test_hung_render_leaves_no_browser_or_temp_files(): void
    {
        $before = self::chromeProfilePids();

        $start = microtime(true);
        $result = $this->handle([
            'html' => '<p>x</p>',
            'waitForFunction' => ['function' => 'false', 'timeout' => 0],
            'timeout' => 3,
        ]);

        $this->assertSame(504, $result['code']);
        $this->assertLessThan(5, microtime(true) - $start, 'guard must stop the render before PHP times out');

        usleep(500_000);
        $this->assertSame([], array_values(array_diff(self::chromeProfilePids(), $before)), 'Chromium survived the timeout');
        $this->assertSame([], glob(sys_get_temp_dir().'/browsershot-*'), 'work dir left behind');
    }

    public function test_infinite_javascript_loop_is_killed(): void
    {
        $before = self::chromeProfilePids();

        $result = $this->handle(['html' => '<script>while (true) {}</script>', 'timeout' => 3]);

        $this->assertSame(504, $result['code']);

        usleep(500_000);
        $this->assertSame([], array_values(array_diff(self::chromeProfilePids(), $before)));
    }

    public function test_node_killed_mid_render_leaves_no_browser_or_temp_files(): void
    {
        $before = self::chromeProfilePids();

        // SIGKILL the node guard while Chromium is running, so the guard itself
        // cannot clean up. PHP must still kill Chromium and remove the work dir.
        $killer = Process::fromShellCommandline('sleep 2; pkill -9 -f "[b]rowser-guard.cjs"');
        $killer->start();

        $result = $this->handle([
            'html' => '<p>x</p>',
            'waitForFunction' => ['function' => 'false', 'timeout' => 0],
            'timeout' => 20,
        ]);
        $killer->wait();

        $this->assertSame(500, $result['code']);
        $this->assertSame('Browser process was killed', $result['message']);
        $this->assertSame([], array_values(array_diff(self::chromeProfilePids(), $before)), 'Chromium survived node being killed');
        $this->assertSame([], glob(sys_get_temp_dir().'/browsershot-*'), 'work dir left behind');
    }

    /** @return list<int> PIDs of Chromium browsers started by Puppeteer. */
    private static function chromeProfilePids(): array
    {
        $out = trim((string) shell_exec('pgrep -f "user-data-dir=.*puppeteer_dev_chrome_profile" 2>/dev/null'));

        return $out === '' ? [] : array_map('intval', explode("\n", $out));
    }

    // ----------------------------------------------------------- helpers

    private function pdfPageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    /** @return array{0: float, 1: float} */
    private function pdfMediaBox(string $pdf): array
    {
        preg_match('#/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]#', $pdf, $m);

        return [(float) $m[1], (float) $m[2]];
    }

    private static function which(string $binary): ?string
    {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null'));

        return $path !== '' ? $path : null;
    }

    private static function firstExisting(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
