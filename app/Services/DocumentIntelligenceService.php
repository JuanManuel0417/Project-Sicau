<?php

namespace App\Services;

class DocumentIntelligenceService
{
    private array $config;
    private array $profiles;
    private array $lastOcrDebug = [];
    private array $lastStructuredHints = [];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->profiles = [
            'identity_document' => [
                'keywords' => ['cedula', 'cédula', 'ciudadania', 'ciudadanía', 'república de colombia', 'documento de identidad', 'sexo', 'nacimiento', 'expedicion'],
            ],
            'graduation_act' => [
                'keywords' => ['acta de grado', 'diploma', 'graduacion', 'graduación', 'titulo', 'título', 'institución educativa', 'otorga'],
            ],
            'saber_11' => [
                'keywords' => ['saber 11', 'icfes', 'puntaje global', 'resultado', 'competencias', 'presentacion', 'presentación'],
            ],
            'portrait_photo' => [
                'keywords' => ['foto', 'photograph', 'selfie'],
            ],
            'sisben_certificate' => [
                'keywords' => ['sisben', 'sisbén', 'grupo', 'puntaje', 'beneficiario'],
            ],
            'utility_bill' => [
                'keywords' => ['factura', 'servicios publicos', 'servicios públicos', 'direccion', 'dirección', 'estrato', 'titular', 'suscriptor'],
            ],
        ];
    }

    public function analyze(string $filePath, array $expectedType, array $user = []): array
    {
        if (function_exists('set_time_limit')) { @set_time_limit(120); }
        $this->lastOcrDebug = [];
        $this->lastStructuredHints = [];
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = $this->detectMime($filePath, $extension);
        $isImage = in_array($extension, ['jpg', 'jpeg', 'png'], true);
        $ocrAvailable = $this->isOcrAvailableForExtension($extension);
        $expectedCode = (string)($expectedType['code'] ?? '');
        $ocrText = $this->extractText($filePath, $extension, $expectedCode);
        $normalizedText = $this->normalize($ocrText);
        $filenameText = $this->normalize(basename($filePath));
        $photoMeta = $this->photoMetadata($filePath, $isImage, (string)($expectedType['code'] ?? ''));
        $quality = $this->evaluateQuality($filePath, $extension, $ocrText, $photoMeta);

        $detected = $this->classify($expectedType, $normalizedText, $filenameText, $quality, $isImage, $photoMeta);
        $fields = $this->extractFields($detected['code'] ?? $expectedType['code'], $ocrText, $user, $photoMeta, $this->lastStructuredHints);
        $comparison = $this->compareWithUser($fields, $user);

        $confidence = $detected['confidence'];
        if (!empty($comparison['name_match'])) {
            $confidence = min(99.0, $confidence + 8.0);
        }

        $summary = $this->buildSummary($expectedType['name'] ?? $expectedType['code'], $detected['name'] ?? $expectedType['name'], $confidence, $quality, array_merge($detected['observations'], $comparison['observations']));
        $status = $this->resolveStatus($confidence, $quality, $detected['code'] ?? null, $expectedType['code'] ?? null, $ocrText, $isImage, $photoMeta, $ocrAvailable);

        if (!$ocrAvailable) {
            $detected['observations'][] = 'OCR no disponible en el servidor. Resultado basado en reglas heurísticas.';
        }

        return [
            'engine_name' => $this->config['app']['analysis']['ocr_engine'] ?? 'heuristic-ocr',
            'mime' => $mime,
            'extension' => $extension,
            'ocr_text' => $ocrText,
            'quality_score' => $quality,
            'confidence' => round($confidence, 2),
            'status_code' => $status['code'],
            'status_label' => $status['label'],
            'state_code' => $status['code'],
            'detected_document_type_code' => $detected['code'] ?? null,
            'detected_document_type_name' => $detected['name'] ?? null,
            'detected_document_type_id' => null,
            'is_readable' => $quality >= 45,
            'is_match' => ($detected['code'] ?? null) === ($expectedType['code'] ?? null),
            'summary' => $summary,
            'observations' => $this->mergeObservations($detected['observations'], $comparison['observations']),
            'extracted_fields' => $fields,
            'analysis_payload' => [
                'mime' => $mime,
                'extension' => $extension,
                'ocr_available' => $ocrAvailable,
                'ocr_debug' => $this->lastOcrDebug,
                'structured_hints' => $this->lastStructuredHints,
                'comparison' => $comparison,
                'detected' => $detected,
                'photo_metadata' => $photoMeta,
                'quality_score' => $quality,
            ],
        ];
    }

    private function detectMime(string $filePath, string $extension): string
    {
        $mime = @mime_content_type($filePath);
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    private function extractText(string $filePath, string $extension, string $expectedCode = ''): string
    {
        if ($extension === 'pdf') {
            return $this->extractPdfText($filePath, $expectedCode);
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            return $this->extractImageText($filePath, $expectedCode);
        }

        return '';
    }

    private function isOcrAvailableForExtension(string $extension): bool
    {
        if ($extension === 'pdf') {
            $pdftotext = $this->resolveBinaryPath('pdftotext');
            $pdftoppm = $this->resolveBinaryPath('pdftoppm');
            $tesseract = $this->resolveBinaryPath('tesseract');
            return $this->commandExists($pdftotext) || ($this->commandExists($pdftoppm) && $this->commandExists($tesseract));
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            $binary = $this->config['app']['analysis']['tesseract_bin'] ?? 'tesseract';
            return $this->commandExists($binary);
        }

        return false;
    }

    private function extractPdfText(string $filePath, string $expectedCode = ''): string
    {
        $binary = $this->resolveBinaryPath('pdftotext');
        $content = '';

        if ($this->commandExists($binary)) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'sicau_pdf_');
            if ($tmpFile !== false) {
                $txtFile = $tmpFile . '.txt';
                @unlink($tmpFile);
                $command = $this->binaryCommand($binary) . ' -layout -enc UTF-8 ' . escapeshellarg($filePath) . ' ' . escapeshellarg($txtFile);
                @shell_exec($command . ' 2>&1');
                $content = is_file($txtFile) ? (string)file_get_contents($txtFile) : '';
                @unlink($txtFile);
            }
        }

        $content = str_replace("\f", "\n", (string)$content);
        $content = trim($content);

        // Scanned PDFs usually have no embedded text; OCR the first page image as fallback.
        if (strlen($this->normalize($content)) < 40) {
            $ocrFallback = $this->extractPdfByOcr($filePath, $expectedCode);
            if (strlen($this->normalize($ocrFallback)) > strlen($this->normalize($content))) {
                $content = $ocrFallback;
            }
        }

        $this->lastOcrDebug['pdf_text_length'] = strlen($content);

        return trim($content);
    }

    private function extractPdfByOcr(string $filePath, string $expectedCode = ''): string
    {
        try {
            $ocrResult = $this->runOcr($filePath);
            return $this->validateOcrResult($ocrResult, $expectedCode);
        } catch (Exception $e) {
            return '';
        }
    }

    private function runOcr(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = $this->detectMime($filePath, $extension);
        $isImage = in_array($extension, ['jpg', 'jpeg', 'png'], true);
        $ocrAvailable = $this->isOcrAvailableForExtension($extension);
        $expectedCode = (string)($expectedType['code'] ?? '');
        $ocrText = $this->extractText($filePath, $extension, $expectedCode);
        $normalizedText = $this->normalize($ocrText);
        $filenameText = $this->normalize(basename($filePath));
        $photoMeta = $this->photoMetadata($filePath, $isImage, (string)($expectedType['code'] ?? ''));
        $quality = $this->evaluateQuality($filePath, $extension, $ocrText, $photoMeta);

        $detected = $this->classify($expectedType, $normalizedText, $filenameText, $quality, $isImage, $photoMeta);
        $fields = $this->extractFields($detected['code'] ?? $expectedType['code'], $ocrText, $user, $photoMeta, $this->lastStructuredHints);
        $comparison = $this->compareWithUser($fields, $user);

        $confidence = $detected['confidence'];
        if (!empty($comparison['name_match'])) {
            $confidence = min(99.0, $confidence + 8.0);
        }

        $summary = $this->buildSummary($expectedType['name'] ?? $expectedType['code'], $detected['name'] ?? $expectedType['name'], $confidence, $quality, array_merge($detected['observations'], $comparison['observations']));
        $status = $this->resolveStatus($confidence, $quality, $detected['code'] ?? null, $expectedType['code'] ?? null, $ocrText, $isImage, $photoMeta, $ocrAvailable);

        if (!$ocrAvailable) {
            $detected['observations'][] = 'OCR no disponible en el servidor. Resultado basado en reglas heurísticas.';
        }

        return [
            'engine_name' => $this->config['app']['analysis']['ocr_engine'] ?? 'heuristic-ocr',
            'mime' => $mime,
            'extension' => $extension,
            'ocr_text' => $ocrText,
            'quality_score' => $quality,
            'confidence' => round($confidence, 2),
            'status_code' => $status['code'],
            'status_label' => $status['label'],
            'state_code' => $status['code'],
            'detected_document_type_code' => $detected['code'] ?? null,
            'detected_document_type_name' => $detected['name'] ?? null,
            'detected_document_type_id' => null,
            'is_readable' => $quality >= 45,
            'is_match' => ($detected['code'] ?? null) === ($expectedType['code'] ?? null),
            'summary' => $summary,
            'observations' => $this->mergeObservations($detected['observations'], $comparison['observations']),
            'extracted_fields' => $fields,
            'analysis_payload' => [
                'mime' => $mime,
                'extension' => $extension,
                'ocr_available' => $ocrAvailable,
                'ocr_debug' => $this->lastOcrDebug,
                'structured_hints' => $this->lastStructuredHints,
                'comparison' => $comparison,
                'detected' => $detected,
                'photo_metadata' => $photoMeta,
                'quality_score' => $quality,
            ],
        ];
    }

    private function validateOcrResult(string $ocrResult, string $expectedCode): string
    {
        $normalized = $this->normalize($ocrResult);
        $score = strlen($normalized);
        if ($expectedCode === 'identity_document') {
            if (preg_match('/\b(cedula|cedula de ciudadania|republica de colombia|colombia)\b/i', $text)) {
                $score += 150;
            }
            if (preg_match('/\b\d{7,11}\b/', $text)) {
                $score += 120;
            }
            if (preg_match('/\b([0-3]?\d\s*(ENE|FEB|MAR|ABR|MAY|JUN|JUL|AGO|SEP|OCT|NOV|DIC)\s*(19|20)\d{2})\b/i', $text)) {
                $score += 80;
            }
            if (preg_match('/\b(MASCULINO|FEMENINO|SEXO|\bM\b|\bF\b)\b/i', $text)) {
                $score += 60;
            }
        }
        return $score;
    }

    private function runTesseract(string $binary, string $filePath, string $lang, int $psm, int $oem = 1): string
    {
        $command = $this->binaryCommand($binary) . ' ' . escapeshellarg($filePath) . ' stdout -l ' . escapeshellarg($lang) . ' --oem ' . $oem . ' --psm ' . $psm;
        $content = @shell_exec($command . ' 2>&1');
        $candidate = is_string($content) ? trim($content) : '';

        if ($this->seemsOcrErrorOutput($candidate)) {
            return '';
        }

        return $this->cleanOcrOutput($candidate);
    }

    private function seemsOcrErrorOutput(string $output): bool
    {
        $text = strtolower($output);
        $errorHints = [
            'error opening data file',
            'failed loading language',
            'tesseract couldn\'t load any languages',
            'usage: tesseract',
            'read_params_file',
        ];

        foreach ($errorHints as $hint) {
            if (str_contains($text, $hint)) {
                return true;
            }
        }

        return false;
    }

    private function cleanOcrOutput(string $output): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $output) ?: [];
        $filtered = [];
        foreach ($lines as $line) {
            $lower = strtolower(trim($line));
            if ($lower === '') {
                continue;
            }
            if (str_starts_with($lower, 'estimating resolution')) {
                continue;
            }
            if (str_starts_with($lower, 'warning:') || str_starts_with($lower, 'error:')) {
                continue;
            }
            $filtered[] = $line;
        }

        return trim(implode("\n", $filtered));
    }

    private function buildImageVariants(string $filePath): array
    {
        $variants = ['original' => $filePath];
        $image = $this->loadImageResource($filePath);
        if (!$image) {
            return $variants;
        }

        $scaled = $this->scaleImage($image, 1.6, 1800);
        $enhanced = $this->cloneImage($scaled);
        imagefilter($enhanced, IMG_FILTER_GRAYSCALE);
        imagefilter($enhanced, IMG_FILTER_CONTRAST, -25);
        imagefilter($enhanced, IMG_FILTER_BRIGHTNESS, 8);
        $this->applySharpen($enhanced);

        $threshold = $this->cloneImage($enhanced);
        $this->applyThreshold($threshold, 155);

        $debugDir = $this->getDebugDir();
        $prefix = $debugDir . DIRECTORY_SEPARATOR . 'ocr_' . date('Ymd_His') . '_' . substr(md5($filePath . microtime(true)), 0, 8);
        $enhancedPath = $prefix . '_enhanced.png';
        $thresholdPath = $prefix . '_threshold.png';

        imagepng($enhanced, $enhancedPath);
        imagepng($threshold, $thresholdPath);

        $deskewPath = $this->maybeDeskewImage($thresholdPath, $prefix . '_deskew.png');

        $variants['enhanced'] = $enhancedPath;
        $variants['threshold'] = $thresholdPath;
        if ($deskewPath !== null) {
            $variants['deskew'] = $deskewPath;
        }

        imagedestroy($image);
        imagedestroy($scaled);
        imagedestroy($enhanced);
        imagedestroy($threshold);
        return $variants;
    }

    private function runIdentityRegionOcr(string $binary, array $variants): array
    {
        $basePath = $variants['deskew'] ?? $variants['threshold'] ?? $variants['enhanced'] ?? $variants['original'];
        $size = @getimagesize($basePath);
        if (!$size) {
            return ['hints' => [], 'text' => ''];
        }

        [$w, $h] = $size;
        $regions = [
            'document_number' => [0.52, 0.03, 0.44, 0.16, 7],
            'full_name' => [0.08, 0.18, 0.84, 0.38, 6],
            'birth_date' => [0.22, 0.46, 0.34, 0.12, 7],
            'sex' => [0.62, 0.48, 0.12, 0.10, 7],
            'birth_place' => [0.10, 0.56, 0.80, 0.12, 6],
            'expedition_place' => [0.10, 0.70, 0.80, 0.16, 6],
        ];

        $allText = [];
        $hints = [];
        $debugRegions = [];

        foreach ($regions as $key => $def) {
            [$rx, $ry, $rw, $rh, $psm] = $def;
            $cropPath = $this->cropRegionToFile($basePath, (int)($w * $rx), (int)($h * $ry), (int)($w * $rw), (int)($h * $rh), $key);
            if (!$cropPath) {
                continue;
            }
            $text = $this->runTesseract($binary, $cropPath, 'spa+eng', $psm, 1);
            $allText[] = '[' . $key . '] ' . $text;
            $hints[$key] = trim($text);
            $debugRegions[$key] = ['image' => $cropPath, 'text' => $text, 'psm' => $psm];
        }

        $this->lastOcrDebug['regions'] = $debugRegions;

        return [
            'hints' => $hints,
            'text' => implode("\n", $allText),
        ];
    }

    private function cropRegionToFile(string $imagePath, int $x, int $y, int $w, int $h, string $label): ?string
    {
        $src = $this->loadImageResource($imagePath);
        if (!$src) {
            return null;
        }

        $imgW = imagesx($src);
        $imgH = imagesy($src);
        $x = max(0, min($x, $imgW - 1));
        $y = max(0, min($y, $imgH - 1));
        $w = max(20, min($w, $imgW - $x));
        $h = max(20, min($h, $imgH - $y));

        $crop = imagecreatetruecolor($w, $h);
        imagecopy($crop, $src, 0, 0, $x, $y, $w, $h);
        imagefilter($crop, IMG_FILTER_GRAYSCALE);
        imagefilter($crop, IMG_FILTER_CONTRAST, -20);
        $this->applyThreshold($crop, 145);

        $path = $this->getDebugDir() . DIRECTORY_SEPARATOR . 'ocr_region_' . $label . '_' . date('Ymd_His') . '_' . substr(md5((string)microtime(true)), 0, 6) . '.png';
        imagepng($crop, $path);

        imagedestroy($src);
        imagedestroy($crop);
        return $path;
    }

    private function scoreOcrCandidate(string $text, string $expectedCode): int
    {
        $normalized = $this->normalize($text);
        $score = strlen($normalized);
        if ($expectedCode === 'identity_document') {
            if (preg_match('/\b(cedula|cedula de ciudadania|republica de colombia|colombia)\b/i', $text)) {
                $score += 150;
            }
            if (preg_match('/\b\d{7,11}\b/', $text)) {
                $score += 120;
            }
            if (preg_match('/\b([0-3]?\d\s*(ENE|FEB|MAR|ABR|MAY|JUN|JUL|AGO|SEP|OCT|NOV|DIC)\s*(19|20)\d{2})\b/i', $text)) {
                $score += 80;
            }
            if (preg_match('/\b(MASCULINO|FEMENINO|SEXO|\bM\b|\bF\b)\b/i', $text)) {
                $score += 60;
            }
        }
        return $score;
    }

    private function getDebugDir(): string
    {
        $base = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'ocr_debug';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        return $base;
    }

    private function loadImageResource(string $path)
    {
        if (!function_exists('getimagesize') || !function_exists('imagecreatetruecolor')) {
            return null;
        }
        $info = @getimagesize($path);
        if (!is_array($info)) {
            return null;
        }

        return match ($info[2]) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : null,
            IMAGETYPE_PNG => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : null,
            default => null,
        };
    }

    private function scaleImage($image, float $factor, int $maxWidth)
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $newW = (int)min($maxWidth, max($w, $w * $factor));
        $newH = (int)max(1, round(($newW / max(1, $w)) * $h));
        $scaled = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $newW, $newH, $w, $h);
        return $scaled;
    }

    private function cloneImage($image)
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $clone = imagecreatetruecolor($w, $h);
        imagecopy($clone, $image, 0, 0, 0, 0, $w, $h);
        return $clone;
    }

    private function applySharpen($image): void
    {
        $matrix = [
            [-1, -1, -1],
            [-1, 16, -1],
            [-1, -1, -1],
        ];
        @imageconvolution($image, $matrix, 8, 0);
    }

    private function applyThreshold($image, int $threshold): void
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray = (int)(($r + $g + $b) / 3);
                imagesetpixel($image, $x, $y, $gray >= $threshold ? $white : $black);
            }
        }
    }

    private function maybeDeskewImage(string $sourcePath, string $targetPath): ?string
    {
        if (!class_exists('Imagick')) {
            return null;
        }

        try {
            $img = new \Imagick($sourcePath);
            $img->setImageColorspace(\Imagick::COLORSPACE_GRAY);
            $img->deskewImage(40);
            $img->writeImage($targetPath);
            $img->clear();
            $img->destroy();
            return is_file($targetPath) ? $targetPath : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveBinaryPath(string $tool): string
    {
        if ($tool === 'tesseract') {
            $configured = $this->config['app']['analysis']['tesseract_bin'] ?? 'tesseract';
            $candidates = [
                $configured,
                'C:\\Tesseract-OCR\\tesseract.exe',
                'tesseract',
            ];
            foreach ($candidates as $candidate) {
                if ($this->commandExists($candidate)) {
                    return $candidate;
                }
            }
            return $configured;
        }

        if ($tool === 'pdftoppm') {
            $configured = $this->config['app']['analysis']['pdftoppm_bin'] ?? 'pdftoppm';
            $candidates = [
                $configured,
                'C:\\poppler\\Library\\bin\\pdftoppm.exe',
                'pdftoppm',
            ];
            foreach ($candidates as $candidate) {
                if ($this->commandExists($candidate)) {
                    return $candidate;
                }
            }
            return $configured;
        }

        $configured = $this->config['app']['analysis']['pdftotext_bin'] ?? 'pdftotext';
        $candidates = [
            $configured,
            'C:\\poppler\\Library\\bin\\pdftotext.exe',
            'pdftotext',
        ];
        foreach ($candidates as $candidate) {
            if ($this->commandExists($candidate)) {
                return $candidate;
            }
        }

        return $configured;
    }

    private function binaryCommand(string $binary): string
    {
        if (str_contains($binary, '\\') || str_contains($binary, '/') || str_contains($binary, ' ')) {
            return escapeshellarg($binary);
        }
        return escapeshellcmd($binary);
    }

    private function commandExists(string $binary): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }

        if (preg_match('/[\\\\\/:]/', $binary)) {
            return is_file($binary);
        }

        $command = PHP_OS_FAMILY === 'Windows' ? 'where ' . $binary : 'command -v ' . $binary;
        $output = @shell_exec($command . ' 2>&1');
        return is_string($output) && trim($output) !== '';
    }

    private function evaluateQuality(string $filePath, string $extension, string $ocrText, array $photoMeta): int
    {
        $size = @filesize($filePath) ?: 0;
        $score = 0;

        if ($size > 0) {
            $score += min(30, (int)floor(($size / (1024 * 1024)) * 10));
        }

        if ($ocrText !== '') {
            $score += min(35, (int)floor(strlen($ocrText) / 40));
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            $score += $photoMeta['quality_bonus'];
        } elseif ($extension === 'pdf') {
            $score += 18;
            if (strlen($this->normalize($ocrText)) > 80) {
                $score += 10;
            }
        }

        return max(0, min(100, $score));
    }

    private function photoMetadata(string $filePath, bool $isImage, string $expectedTypeCode): array
    {
        if (!$isImage) {
            return [
                'dimensions' => null,
                'ratio' => null,
                'quality_bonus' => 0,
                'face_hint' => null,
            ];
        }

        $dimensions = @getimagesize($filePath);
        if (!is_array($dimensions)) {
            return [
                'dimensions' => null,
                'ratio' => null,
                'quality_bonus' => 0,
                'face_hint' => 'No fue posible leer las dimensiones de la imagen',
            ];
        }

        [$width, $height] = $dimensions;
        $ratio = $height > 0 ? $width / $height : 0;
        $bonus = 0;
        $hint = 'Imagen cargada correctamente';

        $isPortraitPhoto = $expectedTypeCode === 'portrait_photo';

        if (!$isPortraitPhoto) {
            if ($width >= 1200 && $height >= 700) {
                $bonus += 18;
            } elseif ($width >= 800 && $height >= 500) {
                $bonus += 12;
            } elseif ($width >= 600 && $height >= 400) {
                $bonus += 8;
            }

            return [
                'dimensions' => ['width' => $width, 'height' => $height],
                'ratio' => $ratio,
                'quality_bonus' => $bonus,
                'face_hint' => $hint,
            ];
        }

        if ($width < 600 || $height < 800) {
            $bonus -= 12;
            $hint = 'Dimensiones insuficientes para foto tipo carnet';
        } else {
            $bonus += 12;
        }

        if ($ratio >= 0.7 && $ratio <= 0.95) {
            $bonus += 10;
        } else {
            $bonus -= 6;
            $hint = 'La proporción no corresponde a una foto de rostro estándar';
        }

        return [
            'dimensions' => ['width' => $width, 'height' => $height],
            'ratio' => $ratio,
            'quality_bonus' => max(-15, $bonus),
            'face_hint' => $hint,
        ];
    }

    private function classify(array $expectedType, string $normalizedText, string $filenameText, int $quality, bool $isImage, array $photoMeta): array
    {
        $candidates = [];
        foreach ($this->profiles as $code => $profile) {
            $score = $this->scoreProfile($profile['keywords'], $normalizedText, $filenameText);
            if ($code === 'portrait_photo') {
                $score += $this->photoBonus($isImage, $normalizedText, $quality, $photoMeta);
            }
            $candidates[$code] = $score;
        }

        arsort($candidates);
        $bestCode = array_key_first($candidates) ?: $expectedType['code'];
        $bestScore = (float)($candidates[$bestCode] ?? 0);
        $confidence = min(99.0, max(20.0, $bestScore + ($quality * 0.25)));
        $expectedCode = $expectedType['code'] ?? null;
        $observations = [];

        if ($quality < 35) {
            $observations[] = 'La calidad del archivo es baja o el contenido no pudo leerse con precisión.';
        }

        $looksExpectedEnough = $expectedCode && $bestCode === $expectedCode && $quality >= 25;
        if ($bestScore < 20 && !$looksExpectedEnough) {
            $observations[] = 'No fue posible identificar con suficiente certeza el tipo documental.';
        }

        if ($expectedCode && $bestCode !== $expectedCode) {
            $observations[] = 'El documento parece corresponder a otro tipo distinto al solicitado.';
            $confidence = max(10.0, $confidence - 18.0);
        }

        if ($looksExpectedEnough && $confidence < 55.0) {
            $confidence = 55.0;
        }

        return [
            'code' => $bestCode,
            'name' => $this->humanizeCode($bestCode),
            'confidence' => $confidence,
            'observations' => $observations,
            'candidates' => $candidates,
        ];
    }

    private function scoreProfile(array $keywords, string $normalizedText, string $filenameText): float
    {
        $score = 0.0;
        foreach ($keywords as $keyword) {
            $normalizedKeyword = $this->normalize($keyword);
            if ($normalizedKeyword === '') {
                continue;
            }

            if (str_contains($normalizedText, $normalizedKeyword)) {
                $score += 18;
            }

            if (str_contains($filenameText, $normalizedKeyword)) {
                $score += 8;
            }
        }

        return min(100.0, $score);
    }

    private function photoBonus(bool $isImage, string $normalizedText, int $quality, array $photoMeta): float
    {
        if (!$isImage) {
            return 0.0;
        }

        $bonus = 0.0;
        if (($photoMeta['dimensions']['width'] ?? 0) >= 600 && ($photoMeta['dimensions']['height'] ?? 0) >= 800) {
            $bonus += 10.0;
        }

        if (strlen($normalizedText) < 180) {
            $bonus += 10.0;
        }

        if ($quality >= 50) {
            $bonus += 5.0;
        }

        return $bonus;
    }

    private function extractFields(string $documentCode, string $ocrText, array $user, array $photoMeta, array $hints = []): array
    {
        $fields = [];

        $commonName = $this->matchRegex($ocrText, '/nombre(?: completo)?[:\s]+([A-ZÁÉÍÓÚÑ ]{6,})/iu');
        $commonDoc = $this->matchRegex($ocrText, '/(?:documento|cedula|cédula)[^0-9]{0,20}([0-9]{6,15})/iu');
        $commonDate = $this->matchDate($ocrText);

        if ($documentCode === 'identity_document') {
            $identity = $this->extractIdentityDocumentFields($ocrText, $hints, $user);
            return [
                ['key' => 'full_name', 'label' => 'Nombre completo', 'value' => $identity['full_name'] ?? ($user['full_name'] ?? null), 'confidence' => $identity['full_name_confidence'] ?? 82, 'source' => 'ocr+regex'],
                ['key' => 'document_number', 'label' => 'Número de documento', 'value' => $identity['document_number'] ?? ($user['document_number'] ?? null), 'confidence' => $identity['document_number_confidence'] ?? 85, 'source' => 'ocr+regex'],
                ['key' => 'birth_date', 'label' => 'Fecha de nacimiento', 'value' => $identity['birth_date'] ?? null, 'confidence' => $identity['birth_date_confidence'] ?? 78, 'source' => 'ocr+regex'],
                ['key' => 'sex', 'label' => 'Sexo', 'value' => $identity['sex'] ?? null, 'confidence' => $identity['sex_confidence'] ?? 80, 'source' => 'ocr+regex'],
                ['key' => 'birth_place', 'label' => 'Lugar de nacimiento', 'value' => $identity['birth_place'] ?? null, 'confidence' => $identity['birth_place_confidence'] ?? 64, 'source' => 'ocr+regex'],
                ['key' => 'expedition_place', 'label' => 'Lugar de expedición', 'value' => $identity['expedition_place'] ?? null, 'confidence' => $identity['expedition_place_confidence'] ?? 62, 'source' => 'ocr+regex'],
            ];
        }

        $profiles = [
            'identity_document' => [
                ['key' => 'full_name', 'label' => 'Nombre completo', 'value' => $commonName ?? ($user['full_name'] ?? null), 'confidence' => 72],
                ['key' => 'document_number', 'label' => 'Número de documento', 'value' => $commonDoc ?? ($user['document_number'] ?? null), 'confidence' => 78],
                ['key' => 'birth_date', 'label' => 'Fecha de nacimiento', 'value' => $commonDate, 'confidence' => 55],
                ['key' => 'sex', 'label' => 'Sexo', 'value' => $this->matchRegex($ocrText, '/\b(MASCULINO|FEMENINO|M|F)\b/iu'), 'confidence' => 50],
                ['key' => 'expedition_place', 'label' => 'Lugar de expedición', 'value' => $this->matchRegex($ocrText, '/expedici[oó]n[:\s]+([A-ZÁÉÍÓÚÑ ,.-]{3,})/iu'), 'confidence' => 45],
            ],
            'graduation_act' => [
                ['key' => 'student_name', 'label' => 'Nombre del estudiante', 'value' => $commonName ?? ($user['full_name'] ?? null), 'confidence' => 72],
                ['key' => 'institution', 'label' => 'Institución educativa', 'value' => $this->matchRegex($ocrText, '/instituci[oó]n(?: educativa)?[:\s]+([A-ZÁÉÍÓÚÑ0-9 ,.-]{3,})/iu'), 'confidence' => 55],
                ['key' => 'graduation_date', 'label' => 'Fecha de graduación', 'value' => $commonDate, 'confidence' => 45],
                ['key' => 'degree_title', 'label' => 'Título obtenido', 'value' => $this->matchRegex($ocrText, '/t[ií]tulo[:\s]+([A-ZÁÉÍÓÚÑ0-9 ,.-]{3,})/iu'), 'confidence' => 55],
            ],
            'saber_11' => [
                ['key' => 'student_name', 'label' => 'Nombre del estudiante', 'value' => $commonName ?? ($user['full_name'] ?? null), 'confidence' => 72],
                ['key' => 'global_score', 'label' => 'Puntaje global', 'value' => $this->matchRegex($ocrText, '/puntaje global[:\s]+([0-9]{2,3}(?:[\.,][0-9]{1,2})?)/iu'), 'confidence' => 76],
                ['key' => 'results', 'label' => 'Resultados individuales', 'value' => $this->matchRegex($ocrText, '/(lectura critica|matematicas|sociales|naturales|ingles)/iu'), 'confidence' => 42],
                ['key' => 'presentation_year', 'label' => 'Año de presentación', 'value' => $this->matchRegex($ocrText, '/(20[0-9]{2})/iu'), 'confidence' => 52],
            ],
            'portrait_photo' => [
                ['key' => 'face_validity', 'label' => 'Validación de rostro', 'value' => $photoMeta['face_hint'] ?? 'No disponible', 'confidence' => 60],
                ['key' => 'image_dimensions', 'label' => 'Dimensiones', 'value' => $photoMeta['dimensions'] ? ($photoMeta['dimensions']['width'] . 'x' . $photoMeta['dimensions']['height']) : null, 'confidence' => 40],
                ['key' => 'quality', 'label' => 'Calidad mínima', 'value' => $photoMeta['quality_bonus'] >= 0 ? 'Apta preliminarmente' : 'Requiere revisión', 'confidence' => 55],
            ],
            'sisben_certificate' => [
                ['key' => 'sisben_score', 'label' => 'Puntaje SISBÉN', 'value' => $this->matchRegex($ocrText, '/puntaje[:\s]+([0-9]{1,3}(?:[\.,][0-9]{1,2})?)/iu'), 'confidence' => 70],
                ['key' => 'group', 'label' => 'Grupo', 'value' => $this->matchRegex($ocrText, '/grupo[:\s]+([ABCDEF][0-9]?)/iu'), 'confidence' => 72],
                ['key' => 'beneficiary_name', 'label' => 'Nombre del beneficiario', 'value' => $commonName ?? ($user['full_name'] ?? null), 'confidence' => 68],
            ],
            'utility_bill' => [
                ['key' => 'address', 'label' => 'Dirección', 'value' => $this->matchRegex($ocrText, '/direcci[oó]n[:\s]+([A-ZÁÉÍÓÚÑ0-9 #.-]{5,})/iu'), 'confidence' => 66],
                ['key' => 'stratum', 'label' => 'Estrato', 'value' => $this->matchRegex($ocrText, '/estrato[:\s]+([0-6])/iu'), 'confidence' => 72],
                ['key' => 'holder_name', 'label' => 'Nombre del titular', 'value' => $commonName ?? ($user['full_name'] ?? null), 'confidence' => 65],
            ],
        ];

        foreach ($profiles[$documentCode] ?? [] as $field) {
            $fields[] = $field;
        }

        if (!$fields) {
            $fields[] = [
                'key' => 'raw_text_excerpt',
                'label' => 'Extracto OCR',
                'value' => mb_substr($ocrText, 0, 240),
                'confidence' => 35,
                'source' => 'ocr',
            ];
        }

        return $fields;
    }

    private function extractIdentityDocumentFields(string $ocrText, array $hints, array $user): array
    {
        $text = strtoupper($ocrText);
        $result = [];

        $docCandidates = [];
        if (!empty($hints['document_number'])) {
            preg_match_all('/\b\d{7,11}\b/', $hints['document_number'], $matches);
            $docCandidates = array_merge($docCandidates, $matches[0] ?? []);
        }
        preg_match_all('/\b\d{7,11}\b/', $ocrText, $globalMatches);
        $docCandidates = array_merge($docCandidates, $globalMatches[0] ?? []);
        $docCandidates = array_values(array_unique(array_filter($docCandidates, fn($n) => strlen($n) >= 8)));
        usort($docCandidates, fn($a, $b) => strlen($b) <=> strlen($a));
        if (!empty($docCandidates)) {
            $result['document_number'] = $docCandidates[0];
            $result['document_number_confidence'] = 88;
        }

        $dateText = ($hints['birth_date'] ?? '') . "\n" . $text;
        if (preg_match('/\b([0-3]?\d\s*(?:ENE|FEB|MAR|ABR|MAY|JUN|JUL|AGO|SEP|OCT|NOV|DIC)\s*(?:19|20)\d{2})\b/u', $dateText, $m)) {
            $result['birth_date'] = preg_replace('/\s+/', ' ', trim($m[1]));
            $result['birth_date_confidence'] = 82;
        } elseif (preg_match('/\b([0-3]?\d[\/\-.][0-1]?\d[\/\-.](?:19|20)\d{2})\b/u', $dateText, $m)) {
            $result['birth_date'] = trim($m[1]);
            $result['birth_date_confidence'] = 72;
        }

        $sexText = strtoupper(($hints['sex'] ?? '') . ' ' . $text);
        if (preg_match('/\bSEXO\s*[:\-]?\s*(M|F|MASCULINO|FEMENINO)\b/u', $sexText, $m) || preg_match('/\b(MASCULINO|FEMENINO|\bM\b|\bF\b)\b/u', $sexText, $m)) {
            $sex = strtoupper(trim($m[1]));
            $result['sex'] = str_starts_with($sex, 'F') ? 'F' : 'M';
            $result['sex_confidence'] = 82;
        }

        $nameFromRegion = $this->cleanNameLine($hints['full_name'] ?? '');
        $nameFromGlobal = $this->extractUppercaseNameBlock($ocrText);
        $name = $nameFromRegion ?: $nameFromGlobal;
        if ($name !== '') {
            $result['full_name'] = $name;
            $result['full_name_confidence'] = $nameFromRegion ? 86 : 74;
        }

        $birthPlaceText = trim((string)($hints['birth_place'] ?? ''));
        if ($birthPlaceText !== '') {
            $result['birth_place'] = $this->cleanPlace($birthPlaceText);
            $result['birth_place_confidence'] = 68;
        } elseif (preg_match('/NACIMIENTO[^A-Z0-9]{0,15}([A-ZÁÉÍÓÚÑ ()\-]{4,})/u', $text, $m)) {
            $result['birth_place'] = $this->cleanPlace($m[1]);
            $result['birth_place_confidence'] = 62;
        }

        $expPlaceText = trim((string)($hints['expedition_place'] ?? ''));
        if ($expPlaceText !== '') {
            $result['expedition_place'] = $this->cleanPlace($expPlaceText);
            $result['expedition_place_confidence'] = 66;
        } elseif (preg_match('/EXPEDICION[^A-Z0-9]{0,15}([A-ZÁÉÍÓÚÑ ()\-]{4,})/u', $text, $m)) {
            $result['expedition_place'] = $this->cleanPlace($m[1]);
            $result['expedition_place_confidence'] = 60;
        }

        if (empty($result['full_name']) && !empty($user['full_name'])) {
            $result['full_name'] = (string)$user['full_name'];
            $result['full_name_confidence'] = 40;
        }

        return $result;
    }

    private function extractUppercaseNameBlock(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', strtoupper($text)) ?: [];
        $blacklist = ['REPUBLICA', 'COLOMBIA', 'CEDULA', 'CIUDADANIA', 'SEXO', 'NACIMIENTO', 'EXPEDICION', 'DOCUMENTO'];
        $best = '';

        foreach ($lines as $line) {
            $clean = trim(preg_replace('/[^A-ZÁÉÍÓÚÑ ]+/u', ' ', $line) ?? '');
            $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
            if ($clean === '') {
                continue;
            }

            $tokens = preg_split('/\s+/', $clean) ?: [];
            if (count($tokens) < 2 || count($tokens) > 5) {
                continue;
            }

            $isBlacklisted = false;
            foreach ($blacklist as $word) {
                if (str_contains($clean, $word)) {
                    $isBlacklisted = true;
                    break;
                }
            }

            if ($isBlacklisted) {
                continue;
            }

            if (strlen($clean) > strlen($best)) {
                $best = $clean;
            }
        }

        return $best;
    }

    private function cleanNameLine(string $line): string
    {
        $clean = strtoupper($line);
        $clean = preg_replace('/[^A-ZÁÉÍÓÚÑ ]+/u', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\s+/', ' ', trim($clean)) ?? $clean;
        return strlen($clean) >= 5 ? $clean : '';
    }

    private function cleanPlace(string $line): string
    {
        $clean = strtoupper($line);
        $clean = preg_replace('/[^A-ZÁÉÍÓÚÑ()\- ]+/u', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\s+/', ' ', trim($clean)) ?? $clean;
        return $clean;
    }

    private function compareWithUser(array $fields, array $user): array
    {
        $observations = [];
        $nameMatch = false;

        foreach ($fields as $field) {
            $key = $field['key'] ?? '';
            if (in_array($key, ['full_name', 'student_name', 'beneficiary_name'], true)) {
                $fieldValue = $this->normalize((string)($field['value'] ?? ''));
                $userName = $this->normalize((string)($user['full_name'] ?? ''));
                if ($fieldValue !== '' && $userName !== '' && $this->similarity($fieldValue, $userName) >= 0.72) {
                    $nameMatch = true;
                } elseif ($fieldValue !== '' && $userName !== '') {
                    $observations[] = 'El nombre extraído no coincide del todo con el perfil del estudiante.';
                }
            }

            if ($key === 'document_number' && !empty($field['value']) && !empty($user['document_number']) && (string)$field['value'] !== (string)$user['document_number']) {
                $observations[] = 'El número de documento no coincide con el usuario autenticado.';
            }
        }

        return [
            'name_match' => $nameMatch,
            'observations' => array_values(array_unique($observations)),
        ];
    }

    private function buildSummary(string $expected, string $detected, float $confidence, int $quality, array $observations): string
    {
        $parts = [
            'Esperado: ' . $expected,
            'Detectado: ' . $detected,
            'Confianza: ' . round($confidence, 2) . '%',
            'Calidad: ' . $quality . '/100',
        ];

        if ($observations) {
            $parts[] = 'Observaciones: ' . implode(' | ', $observations);
        }

        return implode(' · ', $parts);
    }

    private function resolveStatus(
        float $confidence,
        int $quality,
        ?string $detectedCode,
        ?string $expectedCode,
        string $ocrText,
        bool $isImage,
        array $photoMeta,
        bool $ocrAvailable
    ): array
    {
        if (!$ocrAvailable && trim($ocrText) === '') {
            return ['code' => 'requiere_revision', 'label' => 'Requiere revisión'];
        }

        if ($quality < 20 || (trim($ocrText) === '' && $ocrAvailable && $quality < 40)) {
            return ['code' => 'documento_ilegible', 'label' => 'Documento ilegible'];
        }

        if (trim($ocrText) === '' && $ocrAvailable) {
            return ['code' => 'requiere_revision', 'label' => 'Requiere revisión'];
        }

        if ($expectedCode && $detectedCode && $detectedCode !== $expectedCode) {
            return ['code' => 'documento_incorrecto', 'label' => 'Documento incorrecto'];
        }

        if ($detectedCode === 'portrait_photo' && !$isImage) {
            return ['code' => 'documento_incorrecto', 'label' => 'Documento incorrecto'];
        }

        if (($photoMeta['quality_bonus'] ?? 0) < 0 && $detectedCode === 'portrait_photo') {
            return ['code' => 'requiere_revision', 'label' => 'Requiere revisión'];
        }

        if ($confidence >= (float)($this->config['app']['analysis']['min_confidence'] ?? 70) && $quality >= 55) {
            return ['code' => 'validado_automaticamente', 'label' => 'Validado automáticamente'];
        }

        if ($confidence < 45 || $quality < 55) {
            return ['code' => 'requiere_revision', 'label' => 'Requiere revisión'];
        }

        return ['code' => 'pendiente', 'label' => 'Pendiente'];
    }

    private function mergeObservations(array $first, array $second): array
    {
        return array_values(array_unique(array_filter(array_merge($first, $second))));
    }

    private function normalize(string $text): string
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]+/i', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }

    private function humanizeCode(string $code): string
    {
        return ucwords(str_replace('_', ' ', $code));
    }

    private function matchRegex(string $text, string $pattern): ?string
    {
        if (preg_match($pattern, $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function matchDate(string $text): ?string
    {
        if (preg_match('/\b([0-3]?[0-9][\/\-.][0-1]?\d[\/\-.](?:19|20)?[0-9]{2})\b/u', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function similarity(string $a, string $b): float
    {
        similar_text($a, $b, $percent);
        return $percent / 100;
    }
}