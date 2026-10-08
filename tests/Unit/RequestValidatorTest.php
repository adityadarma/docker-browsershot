<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RequestValidator;
use App\Support\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestValidatorTest extends TestCase
{
    private function validate(array $input, bool $allowRemote = false): array
    {
        return (new RequestValidator($allowRemote))->validate($input);
    }

    private function assertInvalid(array $input, string $expectedError, bool $allowRemote = false): void
    {
        try {
            $this->validate($input, $allowRemote);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertNotEmpty(
                array_filter($e->errors(), fn (string $error) => str_contains($error, $expectedError)),
                "Missing error containing [$expectedError], got: ".implode(' | ', $e->errors())
            );
        }
    }

    // ----------------------------------------------------------- content

    public function test_requires_html_or_url(): void
    {
        $this->assertInvalid([], 'Param html atau url harus diisi');
        $this->assertInvalid(['html' => '', 'url' => ''], 'Param html atau url harus diisi');
    }

    public function test_accepts_html(): void
    {
        $result = $this->validate(['html' => '<h1>Hi</h1>']);

        $this->assertSame('html', $result['contentType']);
        $this->assertSame('<h1>Hi</h1>', $result['content']);
    }

    public function test_html_takes_precedence_over_url(): void
    {
        $result = $this->validate(['html' => '<p>x</p>', 'url' => 'https://example.com']);

        $this->assertSame('html', $result['contentType']);
    }

    public function test_accepts_http_and_https_urls_and_trims(): void
    {
        $this->assertSame('https://example.com', $this->validate(['url' => '  https://example.com '])['content']);
        $this->assertSame('url', $this->validate(['url' => 'http://example.com'])['contentType']);
    }

    public static function invalidUrls(): array
    {
        return [
            'not a url' => ['not-a-url'],
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://example.com/file'],
            'javascript' => ['javascript:alert(1)'],
            'view-source' => ['view-source:https://example.com'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_rejects_non_http_urls(string $url): void
    {
        $this->assertInvalid(['url' => $url], 'URL tidak valid');
    }

    public function test_rejects_non_string_content(): void
    {
        $this->assertInvalid(['html' => ['x']], 'html harus berupa string');
        $this->assertInvalid(['url' => 123], 'url harus berupa string');
    }

    // ----------------------------------------------------------- type / action

    public function test_defaults(): void
    {
        $result = $this->validate(['url' => 'https://example.com']);

        $this->assertSame('png', $result['type']);
        $this->assertSame('render', $result['action']);
        $this->assertSame([], $result['options']);
    }

    public static function types(): array
    {
        return array_map(fn ($t) => [$t], array_combine(RequestValidator::TYPES, RequestValidator::TYPES));
    }

    #[DataProvider('types')]
    public function test_accepts_all_output_types(string $type): void
    {
        $this->assertSame($type, $this->validate(['url' => 'https://example.com', 'type' => $type])['type']);
    }

    public function test_rejects_unknown_type(): void
    {
        $this->assertInvalid(['url' => 'https://example.com', 'type' => 'gif'], 'Type harus salah satu dari');
    }

    public static function actions(): array
    {
        return array_map(fn ($a) => [$a], array_combine(RequestValidator::ACTIONS, RequestValidator::ACTIONS));
    }

    #[DataProvider('actions')]
    public function test_accepts_all_actions(string $action): void
    {
        $input = ['url' => 'https://example.com', 'action' => $action];
        if ($action === 'evaluate') {
            $input['pageFunction'] = '1 + 1';
        }

        $this->assertSame($action, $this->validate($input)['action']);
    }

    public function test_rejects_unknown_action(): void
    {
        $this->assertInvalid(['url' => 'https://example.com', 'action' => 'shell'], 'Action harus salah satu dari');
    }

    public function test_evaluate_requires_page_function(): void
    {
        $this->assertInvalid(['url' => 'https://example.com', 'action' => 'evaluate'], 'pageFunction wajib diisi');

        $result = $this->validate(['url' => 'https://example.com', 'action' => 'evaluate', 'pageFunction' => 'document.title']);
        $this->assertSame('document.title', $result['pageFunction']);
        $this->assertArrayNotHasKey('pageFunction', $result['options']);
    }

    // ----------------------------------------------------------- booleans

    public static function booleanOptions(): array
    {
        $keys = [
            'landscape', 'fullPage', 'showBackground', 'hideBackground', 'transparentBackground', 'taggedPdf',
            'showBrowserHeaderAndFooter', 'hideHeader', 'hideFooter', 'mobile', 'touch', 'waitUntilNetworkIdle',
            'networkIdleStrict', 'dismissDialogs', 'disableJavascript', 'disableImages', 'disableCaptureURLS',
            'disableRedirects', 'ignoreHttpsErrors', 'preventUnsuccessfulResponse', 'newHeadless', 'usePipe',
            'writeOptionsToFile', 'throwOnRemoteConnectionError',
        ];

        return array_map(fn ($k) => [$k], array_combine($keys, $keys));
    }

    #[DataProvider('booleanOptions')]
    public function test_boolean_options(string $key): void
    {
        $this->assertTrue($this->validate(['url' => 'https://example.com', $key => true])['options'][$key]);
        $this->assertFalse($this->validate(['url' => 'https://example.com', $key => false])['options'][$key]);
        $this->assertInvalid(['url' => 'https://example.com', $key => 'yes'], "$key harus boolean");
    }

    // ----------------------------------------------------------- pdf

    public function test_pdf_format_is_case_insensitive(): void
    {
        foreach (['A4', 'a4', 'Letter', 'LEDGER', 'a6'] as $format) {
            $this->assertSame($format, $this->validate(['url' => 'https://example.com', 'format' => $format])['options']['format']);
        }
    }

    public function test_rejects_unsupported_pdf_format(): void
    {
        // A7-A10 are not supported by Puppeteer.
        $this->assertInvalid(['url' => 'https://example.com', 'format' => 'A7'], 'Format tidak valid');
    }

    public function test_margin_normalization(): void
    {
        $result = $this->validate(['url' => 'https://example.com', 'margin' => ['top' => 10, 'left' => 5.5, 'unit' => 'px']]);

        $this->assertSame(['unit' => 'px', 'top' => 10.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 5.5], $result['options']['margin']);
    }

    public function test_margin_validation(): void
    {
        $this->assertInvalid(['url' => 'https://example.com', 'margin' => ['top' => 'abc']], 'Margin top harus berupa angka');
        $this->assertInvalid(['url' => 'https://example.com', 'margin' => ['top' => -1]], 'Margin top');
        $this->assertInvalid(['url' => 'https://example.com', 'margin' => ['unit' => 'pt']], 'margin.unit');
        $this->assertInvalid(['url' => 'https://example.com', 'margin' => '10mm'], 'margin harus berupa object');
    }

    public function test_paper_size(): void
    {
        $result = $this->validate(['url' => 'https://example.com', 'paperSize' => ['width' => 210, 'height' => 148, 'unit' => 'cm']]);
        $this->assertSame(['width' => 210.0, 'height' => 148.0, 'unit' => 'cm'], $result['options']['paperSize']);

        $this->assertInvalid(['url' => 'https://example.com', 'paperSize' => ['width' => 210]], 'paperSize.height');
        $this->assertInvalid(['url' => 'https://example.com', 'paperSize' => ['width' => 0, 'height' => 1]], 'paperSize.width');
    }

    public function test_pages(): void
    {
        foreach (['1', '1-3', '1-3, 5', '2-'] as $pages) {
            $this->assertSame($pages, $this->validate(['url' => 'https://example.com', 'pages' => $pages])['options']['pages']);
        }

        $this->assertInvalid(['url' => 'https://example.com', 'pages' => 'abc'], 'pages tidak valid');
    }

    public function test_scale(): void
    {
        $this->assertSame(0.5, $this->validate(['url' => 'https://example.com', 'scale' => 0.5])['options']['scale']);
        $this->assertSame(2.0, $this->validate(['url' => 'https://example.com', 'scale' => 2])['options']['scale']);
        $this->assertInvalid(['url' => 'https://example.com', 'scale' => 3], 'scale harus di antara');
        $this->assertInvalid(['url' => 'https://example.com', 'scale' => '1'], 'scale harus berupa angka');
    }

    public function test_header_footer_and_initial_page(): void
    {
        $options = $this->validate([
            'url' => 'https://example.com',
            'headerHtml' => '<p>H</p>',
            'footerHtml' => '<p>F</p>',
            'initialPageNumber' => 3,
        ])['options'];

        $this->assertSame('<p>H</p>', $options['headerHtml']);
        $this->assertSame('<p>F</p>', $options['footerHtml']);
        $this->assertSame(3, $options['initialPageNumber']);

        $this->assertInvalid(['url' => 'https://example.com', 'initialPageNumber' => 0], 'initialPageNumber');
    }

    // ----------------------------------------------------------- screenshot

    public function test_quality_and_device_scale_factor_bounds(): void
    {
        $options = $this->validate(['url' => 'https://example.com', 'quality' => 80, 'deviceScaleFactor' => 2])['options'];
        $this->assertSame(80, $options['quality']);
        $this->assertSame(2, $options['deviceScaleFactor']);

        // Numeric strings are accepted for backwards compatibility.
        $this->assertSame(70, $this->validate(['url' => 'https://example.com', 'quality' => '70'])['options']['quality']);

        $this->assertInvalid(['url' => 'https://example.com', 'quality' => 101], 'quality harus di antara 0 dan 100');
        $this->assertInvalid(['url' => 'https://example.com', 'quality' => 'high'], 'quality harus berupa angka');
        $this->assertInvalid(['url' => 'https://example.com', 'deviceScaleFactor' => 4], 'deviceScaleFactor harus di antara 1 dan 3');
    }

    public function test_clip(): void
    {
        $result = $this->validate(['url' => 'https://example.com', 'clip' => ['x' => 100, 'y' => 50, 'width' => 600, 'height' => 400]]);
        $this->assertSame(['x' => 100, 'y' => 50, 'width' => 600, 'height' => 400], $result['options']['clip']);

        $this->assertInvalid(['url' => 'https://example.com', 'clip' => ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 10]], 'clip.width');
    }

    public function test_select(): void
    {
        $this->assertSame(['selector' => '#a', 'index' => 0], $this->validate(['url' => 'https://example.com', 'select' => '#a'])['options']['select']);
        $this->assertSame(['selector' => '.b', 'index' => 2], $this->validate(['url' => 'https://example.com', 'select' => ['selector' => '.b', 'index' => 2]])['options']['select']);

        $this->assertInvalid(['url' => 'https://example.com', 'select' => ['index' => 1]], 'select harus berupa selector');
        $this->assertInvalid(['url' => 'https://example.com', 'select' => ['selector' => '#a', 'index' => -1]], 'select.index');
    }

    // ----------------------------------------------------------- page

    public function test_window_size(): void
    {
        $this->assertSame(['width' => 1920, 'height' => 1080], $this->validate(['url' => 'https://example.com', 'windowSize' => ['width' => 1920, 'height' => 1080]])['options']['windowSize']);
        $this->assertInvalid(['url' => 'https://example.com', 'windowSize' => ['width' => 1920]], 'windowSize.height');
    }

    public function test_device_user_agent_and_media(): void
    {
        $options = $this->validate([
            'url' => 'https://example.com',
            'device' => 'iPhone X',
            'userAgent' => 'my-agent',
            'emulateMedia' => 'print',
            'emulateMediaFeatures' => [['name' => 'prefers-color-scheme', 'value' => 'dark']],
        ])['options'];

        $this->assertSame('iPhone X', $options['device']);
        $this->assertSame('my-agent', $options['userAgent']);
        $this->assertSame('print', $options['emulateMedia']);
        $this->assertSame([['name' => 'prefers-color-scheme', 'value' => 'dark']], $options['emulateMediaFeatures']);

        $this->assertNull($this->validate(['url' => 'https://example.com', 'emulateMedia' => null])['options']['emulateMedia']);

        $this->assertInvalid(['url' => 'https://example.com', 'emulateMedia' => 'tv'], 'emulateMedia');
        $this->assertInvalid(['url' => 'https://example.com', 'emulateMediaFeatures' => ['dark']], 'emulateMediaFeatures');
    }

    public function test_delay_and_timeouts(): void
    {
        $options = $this->validate(['url' => 'https://example.com', 'delay' => 2000, 'timeout' => 120, 'protocolTimeout' => 30])['options'];

        $this->assertSame(2000, $options['delay']);
        $this->assertSame(120, $options['timeout']);
        $this->assertSame(30, $options['protocolTimeout']);

        $this->assertInvalid(['url' => 'https://example.com', 'timeout' => 0], 'timeout harus di antara');
        $this->assertInvalid(['url' => 'https://example.com', 'timeout' => 'abc'], 'timeout harus berupa angka');
        $this->assertInvalid(['url' => 'https://example.com', 'delay' => -1], 'delay');
    }

    // ----------------------------------------------------------- interaction

    public function test_wait_for_function(): void
    {
        $this->assertSame(
            ['function' => 'window.ready', 'polling' => 'raf', 'timeout' => 0],
            $this->validate(['url' => 'https://example.com', 'waitForFunction' => 'window.ready'])['options']['waitForFunction']
        );
        $this->assertSame(
            ['function' => 'window.ready', 'polling' => 'mutation', 'timeout' => 500],
            $this->validate(['url' => 'https://example.com', 'waitForFunction' => ['function' => 'window.ready', 'polling' => 'mutation', 'timeout' => 500]])['options']['waitForFunction']
        );

        $this->assertInvalid(['url' => 'https://example.com', 'waitForFunction' => ['function' => 'x', 'polling' => 100]], 'waitForFunction.polling');
    }

    public function test_wait_for_selector(): void
    {
        $this->assertSame(['selector' => '#app', 'options' => []], $this->validate(['url' => 'https://example.com', 'waitForSelector' => '#app'])['options']['waitForSelector']);
        $this->assertSame(
            ['selector' => '#app', 'options' => ['visible' => true]],
            $this->validate(['url' => 'https://example.com', 'waitForSelector' => ['selector' => '#app', 'options' => ['visible' => true]]])['options']['waitForSelector']
        );

        $this->assertInvalid(['url' => 'https://example.com', 'waitForSelector' => ['selector' => '#a', 'options' => [1]]], 'waitForSelector.options');
    }

    public function test_clicks_types_and_selects_accept_single_object_or_list(): void
    {
        $options = $this->validate([
            'url' => 'https://example.com',
            'click' => ['selector' => '#btn'],
            'locatorClick' => [['selector' => '::-p-text(Go)', 'button' => 'right', 'clickCount' => 2, 'delay' => 10]],
            'typeText' => [['selector' => '#name', 'text' => 'Aditya']],
            'selectOption' => ['selector' => '#color', 'value' => 'red'],
        ])['options'];

        $this->assertSame([['selector' => '#btn', 'button' => 'left', 'clickCount' => 1, 'delay' => 0]], $options['click']);
        $this->assertSame([['selector' => '::-p-text(Go)', 'button' => 'right', 'clickCount' => 2, 'delay' => 10]], $options['locatorClick']);
        $this->assertSame([['selector' => '#name', 'text' => 'Aditya', 'delay' => 0]], $options['typeText']);
        $this->assertSame([['selector' => '#color', 'value' => 'red']], $options['selectOption']);

        $this->assertInvalid(['url' => 'https://example.com', 'click' => [['selector' => '#a', 'button' => 'back']]], 'click harus berupa list');
        $this->assertInvalid(['url' => 'https://example.com', 'typeText' => [['text' => 'x']]], 'typeText harus berupa list');
    }

    public function test_style_and_script_tags_reject_local_paths(): void
    {
        $options = $this->validate([
            'url' => 'https://example.com',
            'addStyleTag' => ['content' => 'body{color:red}'],
            'addScriptTag' => ['url' => 'https://cdn.example.com/x.js', 'type' => 'module'],
        ])['options'];

        $this->assertSame(['content' => 'body{color:red}'], $options['addStyleTag']);
        $this->assertSame(['url' => 'https://cdn.example.com/x.js', 'type' => 'module'], $options['addScriptTag']);

        $this->assertInvalid(['url' => 'https://example.com', 'addStyleTag' => ['path' => '/etc/passwd']], 'path lokal tidak diizinkan');
        $this->assertInvalid(['url' => 'https://example.com', 'addScriptTag' => ['url' => 'file:///etc/passwd']], 'addScriptTag.url');
    }

    // ----------------------------------------------------------- network

    public function test_headers_auth_post_and_blocks(): void
    {
        $options = $this->validate([
            'url' => 'https://example.com',
            'extraHttpHeaders' => ['X-Test' => '1'],
            'extraNavigationHttpHeaders' => ['X-Nav' => '2'],
            'authenticate' => ['username' => 'u', 'password' => 'p'],
            'post' => ['foo' => 'bar', 'n' => 1],
            'blockUrls' => ['https://ads.example.com'],
            'blockDomains' => ['tracker.example.com'],
            'proxyServer' => 'http://proxy:8080',
            'contentUrl' => 'https://example.com/base/',
        ])['options'];

        $this->assertSame(['X-Test' => '1'], $options['extraHttpHeaders']);
        $this->assertSame(['X-Nav' => '2'], $options['extraNavigationHttpHeaders']);
        $this->assertSame(['username' => 'u', 'password' => 'p'], $options['authenticate']);
        $this->assertSame(['foo' => 'bar', 'n' => 1], $options['post']);
        $this->assertSame(['https://ads.example.com'], $options['blockUrls']);
        $this->assertSame(['tracker.example.com'], $options['blockDomains']);
        $this->assertSame('http://proxy:8080', $options['proxyServer']);
        $this->assertSame('https://example.com/base/', $options['contentUrl']);
    }

    public function test_network_validation_errors(): void
    {
        $this->assertInvalid(['url' => 'https://example.com', 'extraHttpHeaders' => ['X' => 1]], 'extraHttpHeaders');
        $this->assertInvalid(['url' => 'https://example.com', 'authenticate' => ['username' => 'u']], 'authenticate');
        $this->assertInvalid(['url' => 'https://example.com', 'post' => ['a' => ['b']]], 'post');
        $this->assertInvalid(['url' => 'https://example.com', 'blockUrls' => 'x'], 'blockUrls');
        $this->assertInvalid(['url' => 'https://example.com', 'proxyServer' => 'http://a b; rm -rf /'], 'proxyServer tidak valid');
        $this->assertInvalid(['url' => 'https://example.com', 'contentUrl' => 'file:///tmp/'], 'contentUrl');
    }

    public function test_cookies(): void
    {
        $this->assertSame(
            ['cookies' => ['session' => 'abc'], 'domain' => null],
            $this->validate(['url' => 'https://example.com', 'cookies' => ['session' => 'abc']])['options']['cookies']
        );
        $this->assertSame(
            ['cookies' => ['session' => 'abc'], 'domain' => 'example.com'],
            $this->validate(['html' => '<p>x</p>', 'cookies' => ['cookies' => ['session' => 'abc'], 'domain' => 'example.com']])['options']['cookies']
        );

        // Spatie derives the domain from the URL, which is empty for html.
        $this->assertInvalid(['html' => '<p>x</p>', 'cookies' => ['session' => 'abc']], 'cookies.domain wajib');
        $this->assertInvalid(['url' => 'https://example.com', 'cookies' => ['session' => 1]], 'cookies harus berupa object');
    }

    // ----------------------------------------------------------- remote

    public function test_remote_instance_is_disabled_by_default(): void
    {
        $this->assertInvalid(['url' => 'https://example.com', 'remoteInstance' => ['ip' => '10.0.0.1', 'port' => 9222]], 'dinonaktifkan');
        $this->assertInvalid(['url' => 'https://example.com', 'wsEndpoint' => 'ws://x'], 'dinonaktifkan');
    }

    public function test_remote_instance_when_enabled(): void
    {
        $options = $this->validate([
            'url' => 'https://example.com',
            'remoteInstance' => ['ip' => 'chrome', 'port' => 9222],
            'wsEndpoint' => 'ws://chrome:3000',
        ], allowRemote: true)['options'];

        $this->assertSame(['ip' => 'chrome', 'port' => 9222], $options['remoteInstance']);
        $this->assertSame('ws://chrome:3000', $options['wsEndpoint']);

        $this->assertInvalid(['url' => 'https://example.com', 'wsEndpoint' => 'http://x'], 'wsEndpoint harus diawali', true);
        $this->assertInvalid(['url' => 'https://example.com', 'remoteInstance' => ['port' => 70000]], 'remoteInstance harus', true);
    }

    // ----------------------------------------------------------- security

    public function test_server_side_options_are_ignored(): void
    {
        $options = $this->validate([
            'url' => 'https://example.com',
            'nodeBinary' => '/bin/sh',
            'chromePath' => '/tmp/evil',
            'binPath' => '/tmp/evil.js',
            'addChromiumArguments' => ['--remote-debugging-port' => '9222'],
            'setOption' => ['executablePath' => '/tmp/evil'],
            'nodeEnv' => ['NODE_OPTIONS' => '--require /tmp/x'],
        ])['options'];

        $this->assertSame([], $options);
    }

    public function test_collects_multiple_errors(): void
    {
        try {
            $this->validate(['type' => 'gif', 'quality' => 'x', 'landscape' => 'yes']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertCount(4, $e->errors());
            $this->assertSame(implode(', ', $e->errors()), $e->getMessage());
        }
    }
}
