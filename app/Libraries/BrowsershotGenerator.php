<?php

namespace App\Libraries;

use Spatie\Browsershot\Browsershot;

class BrowsershotGenerator
{
    protected $content;
    protected $contentType; // 'html' or 'url'
    protected $outputType = 'pdf';
    protected $options = [
        'format' => 'A4',
        'landscape' => false,
        'fullPage' => false,
        'margin' => null,
        'timeout' => 60,
        'noSandbox' => true,
        'deviceScaleFactor' => 1,
        'quality' => 90,
        'nodeBinary' => '',
        'npmBinary' => ''
    ];

    /**
     * Constructor
     *
     * @param string $content HTML content or URL
     * @param string $type 'html' or 'url'
     */
    public function __construct(string $content = '', string $type = 'html')
    {
        $this->setContent($content, $type);

        // Ambil path binary dari environment jika ada
        $this->options['nodeBinary'] = getenv('NODE_BINARY_PATH') ?: $this->options['nodeBinary'];
        $this->options['npmBinary'] = getenv('NPM_BINARY_PATH') ?: $this->options['npmBinary'];
        
        // Optional: Ambil chromium path jika ada
        if (getenv('CHROMIUM_BINARY_PATH')) {
            $this->options['executablePath'] = getenv('CHROMIUM_BINARY_PATH');
        }
    }

    /**
     * Set content (HTML or URL)
     *
     * @param string $content
     * @param string $type 'html' or 'url'
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function setContent(string $content, string $type = 'html'): self
    {
        if (!in_array($type, ['html', 'url'])) {
            throw new \InvalidArgumentException("Content type must be either 'html' or 'url'");
        }

        $this->content = $content;
        $this->contentType = $type;
        return $this;
    }

    /**
     * Set output type (pdf or image)
     *
     * @param string $type
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function setOutputType(string $type): self
    {
        if (!in_array($type, ['pdf', 'image'])) {
            throw new \InvalidArgumentException("Output type must be either 'pdf' or 'image'");
        }

        $this->outputType = $type;
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
     * Set Node.js binary path
     *
     * @param string $path
     * @return $this
     */
    public function setNodeBinary(string $path): self
    {
        $this->options['nodeBinary'] = $path;
        return $this;
    }

    /**
     * Set NPM binary path
     *
     * @param string $path
     * @return $this
     */
    public function setNpmBinary(string $path): self
    {
        $this->options['npmBinary'] = $path;
        return $this;
    }

    /**
     * Get content as base64
     *
     * @return string
     */
    public function getBase64(): string
    {
        $browsershot = $this->prepareBrowsershot();

        if ($this->outputType === 'pdf') {
            return $browsershot->base64pdf();
        }

        return $browsershot->base64Screenshot();
    }

    /**
     * Get content as binary string
     *
     * @return string
     */
    public function getBinary(): string
    {
        $browsershot = $this->prepareBrowsershot();

        if ($this->outputType === 'pdf') {
            return $browsershot->pdf();
        }

        return $browsershot->screenshot();
    }

    /**
     * Save PDF to file
     * 
     * @param string $path File path to save
     * @param bool $overwrite Overwrite existing file
     * @return array
     */
    public function savePdf(string $path, bool $overwrite = false): array
    {
        return $this->save($path, [
            'type' => 'pdf',
            'overwrite' => $overwrite
        ]);
    }

    /**
     * Save image to file
     * 
     * @param string $path File path to save
     * @param string $type Image type (png, jpeg)
     * @param bool $overwrite Overwrite existing file
     * @return array
     */
    public function saveImage(string $path, string $type = 'png', bool $overwrite = false): array
    {
        return $this->save($path, [
            'type' => $type,
            'overwrite' => $overwrite
        ]);
    }

    /**
     * Save the output directly using Browsershot's save functionality
     * 
     * @param string $filePath Path to save the file
     * @param array $options Save options:
     *              - 'overwrite' => bool
     *              - 'type' => 'pdf'|'png'|'jpeg'
     * @return array
     */
    public function save(string $filePath, array $options = []): array
    {
        $defaultOptions = [
            'overwrite' => false,
            'type' => $this->outputType,
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        try {
            // Check if file exists and overwrite is false
            if (file_exists($filePath) && !$options['overwrite']) {
                throw new \Exception("File already exists at path: {$filePath}");
            }
            
            // Prepare the Browsershot instance
            $browsershot = $this->prepareBrowsershot();
            
            // Determine save method based on type
            switch ($options['type']) {
                case 'pdf':
                    $browsershot->save($filePath);
                    $mimeType = 'application/pdf';
                    break;
                    
                case 'png':
                    $browsershot->save($filePath);
                    $mimeType = 'image/png';
                    break;
                    
                case 'jpeg':
                case 'jpg':
                    $browsershot->save($filePath);
                    $mimeType = 'image/jpeg';
                    break;
                    
                default:
                    throw new \Exception("Unsupported file type: {$options['type']}");
            }
            
            // Verify file was created
            if (!file_exists($filePath)) {
                throw new \Exception("Failed to save file to: {$filePath}");
            }

            $image_data = file_get_contents($filePath);
            $base64string = base64_encode($image_data);
            $fileSize = filesize($filePath);
            unlink($filePath);
            
            return [
                'path' => $filePath,
                'size' => $fileSize,
                'base64' => $base64string,
                'mime_type' => $mimeType
            ];
        } catch (\Exception $e) {
            // Clean up if file was partially created
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ];
        }
    }

    /**
     * Prepare Browsershot instance with configured options
     *
     * @return Browsershot
     */
    protected function prepareBrowsershot(): Browsershot
    {
        $browsershot = $this->contentType === 'html' 
            ? Browsershot::html($this->content)
            : Browsershot::url($this->content);

        $browsershot
            ->setNodeBinary($this->options['nodeBinary'])
            ->setNpmBinary($this->options['npmBinary'])
            ->timeout($this->options['timeout']);

        if ($this->options['noSandbox']) {
            $browsershot->addChromiumArguments(['no-sandbox']);
        }

        if ($this->outputType === 'pdf') {
            $browsershot
                ->format($this->options['format'])
                ->landscape($this->options['landscape']);

            if ($this->options['margin']) {
                $browsershot->margins(
                    $this->options['margin']['top'] ?? 0,
                    $this->options['margin']['right'] ?? 0,
                    $this->options['margin']['bottom'] ?? 0,
                    $this->options['margin']['left'] ?? 0
                );
            }
        } else {
            $browsershot
                ->fullPage($this->options['fullPage'])
                ->deviceScaleFactor($this->options['deviceScaleFactor'])
                ->quality($this->options['quality']);
        }

        return $browsershot;
    }
}