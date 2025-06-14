<?php

namespace App\Services;

use App\Libraries\BrowsershotGenerator;
use Exception;

class BrowsershotService
{
    /**
     * Validasi input
     */
    private function validateInput(array $input): array
    {
        $errors = [];
        
        // Validasi required fields
        if (empty($input['html']) && empty($input['url'])) {
            $errors[] = 'Param html atau url harus diisi';
        }
        
        // Validasi URL jika ada
        if (!empty($input['url']) && !filter_var($input['url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'URL tidak valid';
        }
        
        // Validasi type
        $allowedTypes = ['pdf', 'png', 'jpeg', 'jpg'];
        if (!empty($input['type']) && !in_array($input['type'], $allowedTypes)) {
            $errors[] = 'Type harus salah satu dari: ' . implode(', ', $allowedTypes);
        }
        
        // Validasi format untuk PDF
        $allowedFormats = ['A0','A1','A2','A3','A4','A5','A6','A7','A8','A9','A10','Letter','Legal','Tabloid','Ledger'];
        if (!empty($input['format']) && !in_array($input['format'], $allowedFormats)) {
            $errors[] = 'Format tidak valid';
        }
        
        // Validasi numeric fields
        $numericFields = [
            'margin.top' => 'Margin top',
            'margin.right' => 'Margin right', 
            'margin.bottom' => 'Margin bottom',
            'margin.left' => 'Margin left',
            'timeout' => 'Timeout',
            'quality' => 'Quality',
            'deviceScaleFactor' => 'Device scale factor'
        ];
        
        foreach ($numericFields as $field => $name) {
            if (isset($input[$field]) && !is_numeric($input[$field])) {
                $errors[] = "$name harus berupa angka";
            }
        }
        
        // Validasi boolean fields
        $booleanFields = ['landscape', 'fullPage'];
        foreach ($booleanFields as $field) {
            if (isset($input[$field]) && !is_bool($input[$field])) {
                $errors[] = "$field harus boolean (true/false)";
            }
        }
        
        return $errors;
    }

    /**
     * Handle API request
     */
    public function handleRequest(array $input): array
    {
        try {
            // Validasi input
            $validationErrors = $this->validateInput($input);
            if (!empty($validationErrors)) {
                throw new Exception(implode(', ', $validationErrors));
            }
            
            // Tentukan tipe konten
            $content = !empty($input['html']) ? $input['html'] : $input['url'];
            $contentType = !empty($input['html']) ? 'html' : 'url';
            
            // Inisialisasi generator
            $generator = new BrowsershotGenerator($content, $contentType);
            
            // Set output type
            $type = $input['type'] ?? 'png';
            if ($type === 'pdf') {
                $generator->setOutputType('pdf');
            }
            
            // Apply options
            if (!empty($input['format'])) {
                $generator->format($input['format']);
            }
            
            if (isset($input['landscape'])) {
                $generator->landscape($input['landscape']);
            }
            
            if (isset($input['fullPage'])) {
                $generator->fullPage($input['fullPage']);
            }
            
            if (!empty($input['margin'])) {
                $generator->margin($input['margin']);
            }
            
            if (!empty($input['timeout'])) {
                $generator->timeout((int)$input['timeout']);
            }
            
            if (!empty($input['quality'])) {
                $generator->quality((int)$input['quality']);
            }
            
            if (!empty($input['deviceScaleFactor'])) {
                $generator->deviceScaleFactor((float)$input['deviceScaleFactor']);
            }

            $randomString = bin2hex(random_bytes(16));
            $filePath      = "tmp/{$randomString}.{$type}";

            if ($type === 'pdf') {
                $result = $generator->savePdf($filePath);
            } else {
                $result = $generator->saveImage($filePath, $type);
            }

            return [
                'status' => 'success',
                'data' => $result,
                'code' => 200
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ];
        }
    }
    
    /**
     * Generate dan simpan ke file
     */
    public function generateToFile(array $input, string $filePath): array
    {
        try {
            $result = $this->handleRequest($input);
            
            if ($result['status'] !== 'success') {
                return $result;
            }
            
            // Ekstrak base64 dari data URI
            // $base64 = substr($result['data'], strpos($result['data'], ',') + 1);
            // $binary = base64_decode($base64);
            
            // Simpan ke file
            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            file_put_contents($filePath, $result['data']);
            
            return [
                'status' => 'success',
                'path' => $filePath,
                // 'size' => strlen($binary),
                'mime_type' => $result['mime_type']
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ];
        }
    }
}