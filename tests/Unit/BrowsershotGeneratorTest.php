<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\BrowsershotGenerator;
use App\Services\RequestValidator;
use App\Support\Config;
use PHPUnit\Framework\TestCase;
use Spatie\Browsershot\Exceptions\HtmlIsNotAllowedToContainFile;
use Tests\Support\FakeBrowsershot;

/**
 * Mirrors spatie/browsershot tests/CommandTest.php: asserts the exact command
 * sent to bin/browser.cjs for each API option, without launching Chrome.
 */
final class BrowsershotGeneratorTest extends TestCase
{
    private FakeBrowsershot $fake;

    private function generator(array $config = []): BrowsershotGenerator
    {
        $this->fake = new FakeBrowsershot;

        return new BrowsershotGenerator($config, fn () => $this->fake);
    }

    /** Validate input exactly like the API does, then run the generator. */
    private function execute(array $input, array $config = [], array $output = ['result' => 'QUJD']): array
    {
        $generator = $this->generator($config);
        $this->fake->output = $output;

        return $generator->run((new RequestValidator(true))->validate($input));
    }

    private function command(array $input, array $config = []): array
    {
        $this->execute($input, $config);

        return $this->fake->lastCommand();
    }

    // ----------------------------------------------------------- screenshot

    public function test_default_screenshot_command(): void
    {
        $command = $this->command(['url' => 'https://example.com']);

        $this->assertSame('https://example.com', $command['url']);
        $this->assertSame('screenshot', $command['action']);
        $this->assertEquals([
            'type' => 'png',
            'viewport' => ['width' => 800, 'height' => 600],
            'timeout' => 60000,
            'args' => [],
        ], $command['options']);
    }

    public function test_highly_customized_screenshot(): void
    {
        $command = $this->command([
            'url' => 'https://example.com',
            'type' => 'jpeg',
            'quality' => 75,
            'clip' => ['x' => 100, 'y' => 50, 'width' => 600, 'height' => 400],
            'deviceScaleFactor' => 2,
            'fullPage' => true,
            'dismissDialogs' => true,
            'windowSize' => ['width' => 1920, 'height' => 1080],
        ]);

        $options = $command['options'];
        $this->assertSame('jpeg', $options['type']);
        $this->assertSame(75, $options['quality']);
        $this->assertSame(['x' => 100, 'y' => 50, 'width' => 600, 'height' => 400], $options['clip']);
        $this->assertTrue($options['fullPage']);
        $this->assertTrue($options['dismissDialogs']);
        $this->assertSame(['width' => 1920, 'height' => 1080, 'deviceScaleFactor' => 2], $options['viewport']);
    }

    public function test_full_page_is_opt_in(): void
    {
        // Previously fullPage was always forced on; now it follows the request.
        $this->assertArrayNotHasKey('fullPage', $this->command(['url' => 'https://example.com'])['options']);
        $this->assertArrayNotHasKey('fullPage', $this->command(['url' => 'https://example.com', 'fullPage' => false])['options']);
        $this->assertTrue($this->command(['url' => 'https://example.com', 'fullPage' => true])['options']['fullPage']);
    }

    public function test_jpg_is_sent_to_puppeteer_as_jpeg_with_default_quality(): void
    {
        $options = $this->command(['url' => 'https://example.com', 'type' => 'jpg'])['options'];

        $this->assertSame('jpeg', $options['type']);
        $this->assertSame(BrowsershotGenerator::DEFAULT_QUALITY, $options['quality']);
    }

    public function test_webp_screenshot(): void
    {
        $options = $this->command(['url' => 'https://example.com', 'type' => 'webp', 'quality' => 50])['options'];

        $this->assertSame('webp', $options['type']);
        $this->assertSame(50, $options['quality']);
    }

    public function test_png_never_sends_quality(): void
    {
        // Puppeteer throws "options.quality is unsupported for the png screenshots".
        $this->assertArrayNotHasKey('quality', $this->command(['url' => 'https://example.com', 'type' => 'png', 'quality' => 80])['options']);
    }

    public function test_hide_background_omits_screenshot_background(): void
    {
        $this->assertTrue($this->command(['url' => 'https://example.com', 'hideBackground' => true])['options']['omitBackground']);
    }

    public function test_select_element(): void
    {
        $options = $this->command(['url' => 'https://example.com', 'select' => ['selector' => '.item', 'index' => 2]])['options'];

        $this->assertSame('.item', $options['selector']);
        $this->assertSame(2, $options['selectorIndex']);
    }

    public function test_pdf_only_options_are_not_sent_for_screenshots(): void
    {
        $options = $this->command(['url' => 'https://example.com', 'format' => 'A4', 'landscape' => true, 'margin' => ['top' => 1]])['options'];

        $this->assertArrayNotHasKey('format', $options);
        $this->assertArrayNotHasKey('landscape', $options);
        $this->assertArrayNotHasKey('margin', $options);
    }

    // ----------------------------------------------------------- pdf

    public function test_default_pdf_command(): void
    {
        $command = $this->command(['url' => 'https://example.com', 'type' => 'pdf']);

        $this->assertSame('pdf', $command['action']);
        $this->assertSame('A4', $command['options']['format']);
        $this->assertArrayNotHasKey('type', $command['options']);
    }

    public function test_highly_customized_pdf(): void
    {
        $command = $this->command([
            'url' => 'https://example.com',
            'type' => 'pdf',
            'showBackground' => true,
            'transparentBackground' => true,
            'landscape' => true,
            'margin' => ['top' => 10, 'right' => 20, 'bottom' => 30, 'left' => 40],
            'pages' => '1-3',
            'paperSize' => ['width' => 210, 'height' => 148],
            'scale' => 0.5,
            'taggedPdf' => true,
        ]);

        $options = $command['options'];
        $this->assertTrue($options['printBackground']);
        $this->assertTrue($options['omitBackground']);
        $this->assertTrue($options['landscape']);
        $this->assertSame(['top' => '10mm', 'right' => '20mm', 'bottom' => '30mm', 'left' => '40mm'], $options['margin']);
        $this->assertSame('1-3', $options['pageRanges']);
        $this->assertSame('210mm', $options['width']);
        $this->assertSame('148mm', $options['height']);
        $this->assertSame(0.5, $options['scale']);
        $this->assertTrue($options['tagged']);
        $this->assertArrayNotHasKey('format', $options, 'paperSize must replace format');
    }

    public function test_pdf_custom_units(): void
    {
        $options = $this->command([
            'url' => 'https://example.com',
            'type' => 'pdf',
            'margin' => ['top' => 1, 'unit' => 'in'],
            'paperSize' => ['width' => 8.5, 'height' => 11, 'unit' => 'in'],
        ])['options'];

        $this->assertSame('1in', $options['margin']['top']);
        $this->assertSame('8.5in', $options['width']);
        $this->assertSame('11in', $options['height']);
    }

    public function test_pdf_custom_header_and_footer(): void
    {
        $options = $this->command([
            'url' => 'https://example.com',
            'type' => 'pdf',
            'headerHtml' => '<p>Header</p>',
            'footerHtml' => '<p>Footer</p>',
        ])['options'];

        $this->assertTrue($options['displayHeaderFooter']);
        $this->assertSame('<p>Header</p>', $options['headerTemplate']);
        $this->assertSame('<p>Footer</p>', $options['footerTemplate']);
    }

    public function test_pdf_hide_header_and_footer(): void
    {
        $options = $this->command(['url' => 'https://example.com', 'type' => 'pdf', 'hideHeader' => true, 'hideFooter' => true])['options'];

        $this->assertTrue($options['displayHeaderFooter']);
        $this->assertSame('<p></p>', $options['headerTemplate']);
        $this->assertSame('<p></p>', $options['footerTemplate']);
    }

    public function test_pdf_show_browser_header_and_footer(): void
    {
        $this->assertTrue($this->command(['url' => 'https://example.com', 'type' => 'pdf', 'showBrowserHeaderAndFooter' => true])['options']['displayHeaderFooter']);
    }

    public function test_html_pdf_hides_browser_header_and_footer_by_default(): void
    {
        $this->assertFalse($this->command(['html' => '<p>x</p>', 'type' => 'pdf'])['options']['displayHeaderFooter']);
    }

    public function test_pdf_initial_page_number(): void
    {
        $options = $this->command(['url' => 'https://example.com', 'type' => 'pdf', 'initialPageNumber' => 3])['options'];

        $this->assertSame(2, $options['initialPageNumber']);
        $this->assertSame('3-', $options['pageRanges']);
    }

    // ----------------------------------------------------------- html content

    public function test_html_is_written_to_temporary_file(): void
    {
        $command = $this->command(['html' => '<h1>Hello</h1>']);

        $this->assertStringStartsWith('file://', $command['url']);
        $this->assertStringEndsWith('index.html', $command['url']);
        // Spatie removes the temp file after the browser call.
        $this->assertFileDoesNotExist(substr($command['url'], 7));
    }

    public function test_html_with_content_url(): void
    {
        $this->assertSame('https://example.com/', $this->command(['html' => '<p>x</p>', 'contentUrl' => 'https://example.com/'])['options']['contentUrl']);
    }

    public function test_content_url_is_ignored_for_url_content(): void
    {
        $this->assertArrayNotHasKey('contentUrl', $this->command(['url' => 'https://example.com', 'contentUrl' => 'https://example.com/'])['options']);
    }

    public static function forbiddenHtml(): array
    {
        return [
            'file://' => ['<img src="file:///etc/passwd">'],
            'file:/' => ['<iframe src="file:/etc/passwd">'],
            'entity encoded file' => ['<img src="&#102;ile:///etc/passwd">'],
            'view-source' => ['<a href="view-source:https://x">'],
            'protocol-relative localhost' => ['<img src="//localhost/x.png">'],
            'protocol-relative 127' => ['<img src="//127.0.0.1:8000/x.png">'],
            'UNC path' => ['<img src="\\\\localhost\\share">'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenHtml')]
    public function test_rejects_html_with_local_file_references(string $html): void
    {
        $this->expectException(HtmlIsNotAllowedToContainFile::class);

        $this->execute(['html' => $html]);
    }

    public function test_allows_legitimate_html(): void
    {
        $this->execute(['html' => '<img src="//cdn.example.com/x.png"><a href="http://localhost:5173">vite</a><style>a{content:"\\\\"}</style>']);

        $this->assertCount(1, $this->fake->commands);
    }

    // ----------------------------------------------------------- page options

    public function test_page_options(): void
    {
        $options = $this->command([
            'url' => 'https://example.com',
            'userAgent' => 'my_special_snowflake',
            'emulateMedia' => 'screen',
            'emulateMediaFeatures' => [['name' => 'prefers-color-scheme', 'value' => 'dark']],
            'mobile' => true,
            'touch' => true,
            'delay' => 2000,
            'timeout' => 120,
            'protocolTimeout' => 30,
            'disableJavascript' => true,
            'disableImages' => true,
            'newHeadless' => true,
        ])['options'];

        $this->assertSame('my_special_snowflake', $options['userAgent']);
        $this->assertSame('screen', $options['emulateMedia']);
        $this->assertSame('[{"name":"prefers-color-scheme","value":"dark"}]', $options['emulateMediaFeatures']);
        $this->assertTrue($options['viewport']['isMobile']);
        $this->assertTrue($options['viewport']['hasTouch']);
        $this->assertSame(2000, $options['delay']);
        $this->assertSame(120000, $options['timeout']);
        $this->assertSame(30000, $options['protocolTimeout']);
        $this->assertTrue($options['disableJavascript']);
        $this->assertTrue($options['disableImages']);
        $this->assertTrue($options['newHeadless']);
    }

    public function test_device_emulation_skips_default_viewport(): void
    {
        $generator = new BrowsershotGenerator([], function (bool $deviceEmulate) use (&$flag) {
            $flag = $deviceEmulate;
            $this->fake = new FakeBrowsershot('', $deviceEmulate);
            $this->fake->output = ['result' => 'QUJD'];

            return $this->fake;
        });

        $generator->run((new RequestValidator)->validate([
            'url' => 'https://example.com', 'device' => 'iPhone X', 'deviceScaleFactor' => 2, 'mobile' => true,
        ]));

        $options = $this->fake->lastCommand()['options'];
        $this->assertTrue($flag);
        $this->assertSame('iPhone X', $options['device']);
        $this->assertArrayNotHasKey('viewport', $options, 'viewport would override the emulated device');
    }

    public function test_device_with_explicit_window_size_keeps_viewport(): void
    {
        $options = $this->command([
            'url' => 'https://example.com', 'device' => 'iPhone X', 'windowSize' => ['width' => 500, 'height' => 400], 'deviceScaleFactor' => 2,
        ])['options'];

        $this->assertSame('iPhone X', $options['device']);
        $this->assertSame(['width' => 500, 'height' => 400, 'deviceScaleFactor' => 2], $options['viewport']);
    }

    public function test_emulate_media_null_resets(): void
    {
        $options = $this->command(['url' => 'https://example.com', 'emulateMedia' => null])['options'];

        $this->assertArrayHasKey('emulateMedia', $options);
        $this->assertNull($options['emulateMedia']);
    }

    public function test_request_timeout_overrides_server_default(): void
    {
        $this->assertSame(10000, $this->command(['url' => 'https://example.com', 'timeout' => 10], ['timeout' => 90])['options']['timeout']);
        $this->assertSame(90000, $this->command(['url' => 'https://example.com'], ['timeout' => 90])['options']['timeout']);
    }

    // ----------------------------------------------------------- network options

    public function test_network_options(): void
    {
        $command = $this->command([
            'url' => 'https://example.com',
            'extraHttpHeaders' => ['X-Test' => '1'],
            'extraNavigationHttpHeaders' => ['X-Nav' => '2'],
            'authenticate' => ['username' => 'user', 'password' => 'pass'],
            'cookies' => ['session' => 'abc'],
            'post' => ['foo' => 'bar'],
            'blockUrls' => ['https://ads.example.com'],
            'blockDomains' => ['tracker.example.com'],
            'proxyServer' => 'http://proxy:8080',
            'ignoreHttpsErrors' => true,
            'disableRedirects' => true,
            'disableCaptureURLS' => true,
            'preventUnsuccessfulResponse' => true,
        ]);

        $options = $command['options'];
        $this->assertSame(['X-Test' => '1'], $options['extraHTTPHeaders']);
        $this->assertSame(['X-Nav' => '2'], $options['extraNavigationHTTPHeaders']);
        $this->assertSame(['username' => 'user', 'password' => 'pass'], $options['authentication']);
        $this->assertSame([['name' => 'session', 'value' => 'abc', 'domain' => 'example.com']], $options['cookies']);
        $this->assertSame(['foo' => 'bar'], $command['postParams']);
        $this->assertSame(['https://ads.example.com'], $options['blockUrls']);
        $this->assertSame(['tracker.example.com'], $options['blockDomains']);
        $this->assertContains('--proxy-server=http://proxy:8080', $options['args']);
        $this->assertTrue($options['acceptInsecureCerts']);
        $this->assertTrue($options['disableRedirects']);
        $this->assertTrue($options['disableCaptureURLS']);
        $this->assertTrue($options['preventUnsuccessfulResponse']);
    }

    public function test_cookies_with_explicit_domain_for_html(): void
    {
        $options = $this->command(['html' => '<p>x</p>', 'cookies' => ['cookies' => ['a' => 'b'], 'domain' => 'example.org']])['options'];

        $this->assertSame([['name' => 'a', 'value' => 'b', 'domain' => 'example.org']], $options['cookies']);
    }

    public function test_wait_until_network_idle(): void
    {
        $this->assertSame('networkidle0', $this->command(['url' => 'https://example.com', 'waitUntilNetworkIdle' => true])['options']['waitUntil']);
        $this->assertSame('networkidle2', $this->command(['url' => 'https://example.com', 'waitUntilNetworkIdle' => true, 'networkIdleStrict' => false])['options']['waitUntil']);
        $this->assertArrayNotHasKey('waitUntil', $this->command(['url' => 'https://example.com', 'waitUntilNetworkIdle' => false])['options']);
    }

    public function test_remote_instance_and_ws_endpoint(): void
    {
        $options = $this->command([
            'url' => 'https://example.com',
            'remoteInstance' => ['ip' => 'chrome', 'port' => 9333],
            'wsEndpoint' => 'ws://chrome:3000',
            'throwOnRemoteConnectionError' => true,
        ])['options'];

        $this->assertSame('http://chrome:9333', $options['remoteInstanceUrl']);
        $this->assertSame('ws://chrome:3000', $options['browserWSEndpoint']);
        $this->assertTrue($options['throwOnRemoteConnectionError']);
    }

    // ----------------------------------------------------------- interaction

    public function test_interactions(): void
    {
        $options = $this->command([
            'url' => 'https://example.com',
            'click' => [['selector' => '#a'], ['selector' => '#b', 'button' => 'right', 'clickCount' => 2, 'delay' => 5]],
            'locatorClick' => ['selector' => '::-p-text(Submit)'],
            'typeText' => ['selector' => '#name', 'text' => 'Aditya', 'delay' => 10],
            'selectOption' => ['selector' => '#color', 'value' => 'red'],
            'waitForFunction' => ['function' => 'window.ready === true', 'polling' => 'mutation', 'timeout' => 1000],
            'waitForSelector' => ['selector' => '#app', 'options' => ['visible' => true]],
            'evaluateOnNewDocument' => 'window.injected = true',
            'addStyleTag' => ['content' => 'body{background:red}'],
            'addScriptTag' => ['url' => 'https://cdn.example.com/x.js'],
        ])['options'];

        $this->assertSame([
            ['selector' => '#a', 'button' => 'left', 'clickCount' => 1, 'delay' => 0],
            ['selector' => '#b', 'button' => 'right', 'clickCount' => 2, 'delay' => 5],
        ], $options['clicks']);
        $this->assertSame([['selector' => '::-p-text(Submit)', 'button' => 'left', 'clickCount' => 1, 'delay' => 0]], $options['locatorClicks']);
        $this->assertSame([['selector' => '#name', 'text' => 'Aditya', 'delay' => 10]], $options['types']);
        $this->assertSame([['selector' => '#color', 'value' => 'red']], $options['selects']);
        $this->assertSame('window.ready === true', $options['function']);
        $this->assertSame('mutation', $options['functionPolling']);
        $this->assertSame(1000, $options['functionTimeout']);
        $this->assertSame('#app', $options['waitForSelector']);
        $this->assertSame(['visible' => true], $options['waitForSelectorOptions']);
        $this->assertSame('window.injected = true', $options['evaluateOnNewDocument']);
        $this->assertSame('{"content":"body{background:red}"}', $options['addStyleTag']);
        $this->assertSame('{"url":"https:\/\/cdn.example.com\/x.js"}', $options['addScriptTag']);
    }

    // ----------------------------------------------------------- server config

    public function test_server_config_is_applied(): void
    {
        $generator = $this->generator(Config::browsershot());
        $this->fake->output = ['result' => 'QUJD'];
        $generator->run((new RequestValidator)->validate(['url' => 'https://example.com']));

        $args = $this->fake->lastCommand()['options']['args'];

        $this->assertSame('/usr/bin/chromium-browser', $this->fake->lastCommand()['options']['executablePath']);
        $this->assertContains('--headless=new', $args);
        $this->assertContains('--disable-dev-shm-usage', $args);
        $this->assertContains('--no-sandbox', $args);
        $this->assertSame(1, count(array_keys($args, '--no-sandbox')), 'no-sandbox must not be duplicated');
    }

    public function test_user_data_dir_and_custom_arguments(): void
    {
        $args = $this->command(['url' => 'https://example.com'], [
            'chromiumArguments' => ['lang' => 'id-ID', 'hide-scrollbars'],
            'userDataDir' => '/tmp/chrome-user',
        ])['options']['args'];

        $this->assertSame(['--lang=id-ID', '--hide-scrollbars', '--user-data-dir=/tmp/chrome-user'], $args);
    }

    public function test_shell_command_uses_configured_binaries(): void
    {
        $this->execute(['url' => 'https://example.com'], [
            'nodeBinary' => '/opt/node/bin/node',
            'npmBinary' => '/opt/node/bin/npm',
            'includePath' => '$PATH:/opt/node/bin',
        ]);

        $shell = $this->fake->fullCommand($this->fake->lastCommand());

        $this->assertStringStartsWith('PATH=$PATH:/opt/node/bin NODE_PATH=$("/opt/node/bin/node" "/opt/node/bin/npm" root -g)', $shell);
        $this->assertStringContainsString('"/opt/node/bin/node" ', $shell);
        $this->assertStringContainsString('browser.cjs', $shell);
    }

    public function test_node_module_path_overrides_npm_root(): void
    {
        $this->execute(['url' => 'https://example.com'], ['nodeModulePath' => '/app/node_modules']);

        $this->assertStringContainsString('NODE_PATH="/app/node_modules"', $this->fake->fullCommand($this->fake->lastCommand()));
    }

    public function test_request_payload_is_shell_escaped(): void
    {
        $this->execute(['url' => 'https://example.com', 'userAgent' => "';rm -rf /;'"]);

        $shell = $this->fake->fullCommand($this->fake->lastCommand());

        // The JSON payload is a single escapeshellarg()-quoted argument.
        $this->assertMatchesRegularExpression("/browser\.cjs' '.*'$/s", $shell);
        $this->assertStringNotContainsString("';rm -rf /;'", $shell);
    }

    public function test_pipe_and_write_options_to_file(): void
    {
        $this->assertTrue($this->command(['url' => 'https://example.com', 'usePipe' => true])['options']['pipe']);

        $this->execute(['url' => 'https://example.com', 'writeOptionsToFile' => true]);
        $this->assertMatchesRegularExpression('/-f file:\/\/.+command\.js/', $this->fake->fullCommand($this->fake->lastCommand()));
    }

    // ----------------------------------------------------------- actions / results

    public function test_render_returns_base64_payload(): void
    {
        $data = $this->execute(['url' => 'https://example.com', 'type' => 'pdf'], output: ['result' => base64_encode('%PDF-1.7 test')]);

        $this->assertSame([
            'size' => 13,
            'base64' => base64_encode('%PDF-1.7 test'),
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
        ], $data);
    }

    public function test_render_mime_types(): void
    {
        foreach (BrowsershotGenerator::MIME_TYPES as $type => $mime) {
            $this->assertSame($mime, $this->execute(['url' => 'https://example.com', 'type' => $type])['mime_type']);
        }
    }

    public function test_render_fails_on_empty_output(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Browser returned empty output');

        $this->execute(['url' => 'https://example.com'], output: ['result' => '']);
    }

    public function test_body_html_action(): void
    {
        $data = $this->execute(['url' => 'https://example.com', 'action' => 'bodyHtml'], output: ['result' => '<html><body>x</body></html>']);

        $this->assertSame(['html' => '<html><body>x</body></html>'], $data);
        $this->assertSame('content', $this->fake->lastCommand()['action']);
    }

    public function test_evaluate_action(): void
    {
        $data = $this->execute(['url' => 'https://example.com', 'action' => 'evaluate', 'pageFunction' => '1 + 1'], output: ['result' => '2']);

        $this->assertSame(['result' => '2'], $data);
        $this->assertSame('evaluate', $this->fake->lastCommand()['action']);
        $this->assertSame('1 + 1', $this->fake->lastCommand()['options']['pageFunction']);
    }

    public static function inspectionActions(): array
    {
        return [
            'triggeredRequests' => ['triggeredRequests', 'requestsList', 'requestsList', 'requests', [['url' => 'https://example.com/']]],
            'redirectHistory' => ['redirectHistory', 'redirectHistory', 'redirectHistory', 'redirects', [['url' => 'https://example.com/', 'status' => 301, 'statusText' => '', 'headers' => []]]],
            'consoleMessages' => ['consoleMessages', 'consoleMessages', 'consoleMessages', 'messages', [['type' => 'log', 'message' => 'hi', 'location' => []]]],
            'failedRequests' => ['failedRequests', 'failedRequests', 'failedRequests', 'requests', [['status' => 404, 'url' => 'https://example.com/x']]],
            'pageErrors' => ['pageErrors', 'pageErrors', 'pageErrors', 'errors', [['name' => 'Error', 'message' => 'boom']]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inspectionActions')]
    public function test_inspection_actions(string $action, string $browserAction, string $outputKey, string $dataKey, array $payload): void
    {
        $data = $this->execute(['url' => 'https://example.com', 'action' => $action], output: ['result' => '', $outputKey => $payload]);

        $this->assertSame([$dataKey => $payload], $data);
        $this->assertSame($browserAction, $this->fake->lastCommand()['action']);
    }

    public function test_inspection_actions_return_empty_list_when_browser_returns_nothing(): void
    {
        $this->assertSame(['errors' => []], $this->execute(['url' => 'https://example.com', 'action' => 'pageErrors'], output: ['result' => '']));
    }
}
