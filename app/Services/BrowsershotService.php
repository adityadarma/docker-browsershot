<?php

namespace App\Services;

use App\Libraries\BrowsershotGenerator;
use Exception;

class BrowsershotService
{
    public function handleRequest(array $input)
    {
        try {
            $type = $input['type'] ?? 'png';
            $html = $input['html'] ?? '';
            $url  = $input['url'] ?? '';
            
            if (empty($url) && empty($html)) {
                throw new Exception('Param url or html must be provided');
            }

            $generator = new BrowsershotGenerator($html);
            
            // Set format jika ada
            if (!empty($input['format'])) {
                $generator->format($input['format']);
            }

            // Generate output
            if ($type === 'pdf') {
                $base64 = $generator->getPdfBase64();
                $mime = 'application/pdf';
            } else {
                $base64 = $generator->getImageBase64();
                $mime = 'image/'.$type;
            }

            return [
                'status' => 'success',
                'data' => "data:$mime;base64,".$base64,
                'mime_type' => $mime
            ];
        } catch (Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }
}