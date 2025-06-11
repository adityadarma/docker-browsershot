<?php

namespace App\Libraries;

use App\Utilities\StorageFile;
use Spatie\Browsershot\Browsershot;
use Illuminate\Support\Facades\Storage;

class BrowsershotGenerator
{
    protected $html;
    protected $options = [
        'format' => 'A4',
        'landscape' => false,
        'fullPage' => false,
        'margin' => null,
        'timeout' => 60,
        'noSandbox' => true,
        'deviceScaleFactor' => 1,
        'quality' => 90,
    ];

    /**
     * Constructor
     *
     * @param string $html
     */
    public function __construct(
        string $html = ''
    )
    {
        $this->html = $html;
    }

    /**
     * Set HTML content
     *
     * @param string $html
     * @return $this
     */
    public function setHtml(string $html): self
    {
        $this->html = $html;
        return $this;
    }

    /**
     * Set paper format
     *
     * @param string $format
     * @return $this
     */
    public function format(string $format): self
    {
        $this->options['format'] = $format;
        return $this;
    }

    /**
     * Set landscape orientation
     *
     * @param bool $landscape
     * @return $this
     */
    public function landscape(bool $landscape = true): self
    {
        $this->options['landscape'] = $landscape;
        return $this;
    }

    /**
     * Set full page capture
     *
     * @param bool $fullPage
     * @return $this
     */
    public function fullPage(bool $fullPage = true): self
    {
        $this->options['fullPage'] = $fullPage;
        return $this;
    }

    /**
     * Set margins
     *
     * @param mixed $margin (string or array)
     * @return $this
     */
    public function margin($margin): self
    {
        $this->options['margin'] = $margin;
        return $this;
    }

    /**
     * Set timeout
     *
     * @param int $timeout
     * @return $this
     */
    public function timeout(int $timeout): self
    {
        $this->options['timeout'] = $timeout;
        return $this;
    }

    /**
     * Set noSandbox option
     *
     * @param bool $noSandbox
     * @return $this
     */
    public function noSandbox(bool $noSandbox = true): self
    {
        $this->options['noSandbox'] = $noSandbox;
        return $this;
    }

    /**
     * Set device scale factor
     *
     * @param float $factor
     * @return $this
     */
    public function deviceScaleFactor(float $factor): self
    {
        $this->options['deviceScaleFactor'] = $factor;
        return $this;
    }

    /**
     * Set image quality
     *
     * @param int $quality
     * @return $this
     */
    public function quality(int $quality): self
    {
        $this->options['quality'] = $quality;
        return $this;
    }

    /**
     * Get PDF content as base64
     *
     * @return string
     */
    public function getPdfBase64(): string
    {
        return $this->prepareBrowsershot()
            ->format($this->options['format'])
            ->landscape($this->options['landscape'])
            ->margins(
                $this->options['margin']['top'] ?? 0,
                $this->options['margin']['right'] ?? 0,
                $this->options['margin']['bottom'] ?? 0,
                $this->options['margin']['left'] ?? 0
            )
            ->base64pdf();
    }

    /**
     * Get image content as base64
     *
     * @return string
     */
    public function getImageBase64(): string
    {
        return $this->prepareBrowsershot()
            ->fullPage($this->options['fullPage'])
            ->deviceScaleFactor($this->options['deviceScaleFactor'])
            ->quality($this->options['quality'])
            ->base64Screenshot();
    }

    /**
     * Prepare Browsershot instance with common options
     *
     * @return Browsershot
     */
    protected function prepareBrowsershot(): Browsershot
    {
        $browsershot = Browsershot::html($this->html)
            ->timeout($this->options['timeout']);

        if ($this->options['noSandbox']) {
            $browsershot->setOption('args', ['--no-sandbox'])
                ->setOption('executablePath', '/usr/bin/chromium-browser');
        }

        return $browsershot;
    }
}