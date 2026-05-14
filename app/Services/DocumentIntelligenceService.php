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
                'keywords' => ['acta de grado', 'diploma', 'graduacion', 'graduación', 'titulo', 'título', 'institución educativa', 'otorga', 'bachiller'],
            ],
            'saber_11' => [
                'keywords' => ['saber 11', 'icfes', 'puntaje global', 'resultado', 'competencias', 'presentacion', 'presentación', 'áreas evaluadas'],
            ],
            'portrait_photo' => [
                'keywords' => ['foto', 'photograph', 'selfie', 'carnet', 'rostro'],
            ],
            'sisben_certificate' => [
                'keywords' => ['sisben', 'sisbén', 'grupo', 'puntaje', 'beneficiario', 'municipio'],
            ],
            'utility_bill' => [
                'keywords' => ['factura', 'servicios publicos', 'servicios públicos', 'direccion', 'dirección', 'estrato', 'titular', 'suscriptor', 'empresa de servicios'],
            ],
        ];
    }

    public function analyze(string $filePath, array $expectedType, array $user = []): array
    {
        try {
            if (function_exists('set_time_limit')) { @set_time_limit(120); }
            $this->lastOcrDebug = [];
            $this->lastStructuredHints = [];
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mime = $this->detectMime($filePath, $extension);
            $isImage = in_array($extension, ['jpg', 'jpeg', 'png'], true);
            $ocrAvailable = $this->isOcrAvailableForExtension($extension);
            $expectedCode = (string)($expectedType['code'] ?? '');
            $ocrText = '';
            try {
                $ocrText = $this->extractText($filePath, $extension, $expectedCode);
            } catch (\Throwable $ocrEx) {
                $this->lastOcrDebug['ocr_error'] = 'OCR error: ' . $ocrEx->getMessage();
                $ocrText = '';
            }
            $normalizedText = $this->normalize($ocrText);
            $filenameText = $this->normalize(basename($filePath));
            $photoMeta = $this->photoMetadata($filePath, $isImage, (string)($expectedType['code'] ?? ''));
            $quality = $this->evaluateQuality($filePath, $extension, $ocrText, $photoMeta);

            $detected = $this->classify($expectedType, $normalizedText, $filenameText, $quality, $isImage, $photoMeta);
            $fields = $this->extractFields($detected['code'] ?? $expectedType['code'], $ocrText, $user, $photoMeta, $this->lastStructuredHints);
            $comparison = $this->compareWithUser($fields, $user);
            $scoreBreakdown = $this->computeIntelligentScore(
                (string)($expectedType['code'] ?? ''),
                (string)($detected['code'] ?? ''),
                $detected['candidates'] ?? [],
                $fields,
                $quality,
                $comparison,
                $normalizedText
            );

            $confidence = (float)$scoreBreakdown['score'];
            $detected['observations'] = $this->mergeObservations($detected['observations'], $scoreBreakdown['observations']);

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
                    'score_breakdown' => $scoreBreakdown,
                    'detected' => $detected,
                    'photo_metadata' => $photoMeta,
                    'quality_score' => $quality,
                ],
            ];
        } catch (\Throwable $ex) {
            // Fallback seguro: nunca romper el sistema
            if (!isset($this->lastOcrDebug['ocr_error'])) {
                $this->lastOcrDebug['ocr_error'] = 'Critical error: ' . $ex->getMessage();
            }
            return [
                'engine_name' => $this->config['app']['analysis']['ocr_engine'] ?? 'heuristic-ocr',
                'mime' => null,
                'extension' => null,
                'ocr_text' => '',
                'quality_score' => 0,
                'confidence' => 0,
                'status_code' => 'ocr_error',
                'status_label' => 'Error OCR',
                'state_code' => 'ocr_error',
                'detected_document_type_code' => null,
                'detected_document_type_name' => null,
                'detected_document_type_id' => null,
                'is_readable' => false,
                'is_match' => false,
                'summary' => 'Error en OCR: ' . $ex->getMessage(),
                'observations' => ['Error en OCR: ' . $ex->getMessage()],
                'extracted_fields' => [],
                'analysis_payload' => [
                    'ocr_debug' => $this->lastOcrDebug,
                ],
            ];
        }
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
        } catch (\Throwable $e) {
            $this->lastOcrDebug['pdf_ocr_error'] = $e->getMessage();
            return '';
        }
    }

    private function extractImageText(string $filePath, string $expectedCode = ''): string
    {
        try {
            $binary = $this->resolveBinaryPath('tesseract');
            if (!$this->commandExists($binary)) {
                $this->lastOcrDebug['ocr_error'] = 'tesseract no disponible';
                return '';
            }

            $variants = $this->buildImageVariants($filePath);
            $psms = [6, 4];
            $startedAt = microtime(true);
            $timeBudget = 25.0;
            $maxAttempts = 12;
            $attempts = 0;
            $bestText = '';
            $bestScore = -1;
            $debugCandidates = [];

            foreach ($variants as $variantName => $variantPath) {
                foreach ($psms as $psm) {
                    if ($attempts >= $maxAttempts || (microtime(true) - $startedAt) > $timeBudget) {
                        break 2;
                    }

                    $candidate = $this->runTesseract($binary, $variantPath, 'spa+eng', $psm, 1);
                    $attempts++;
                    $score = $this->scoreOcrCandidate($candidate, $expectedCode);
                    $debugCandidates[$variantName]['psm' . $psm] = [
                        'score' => $score,
                        'text' => $candidate,
                    ];

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestText = $candidate;
                    }
                }
            }

            if ($expectedCode === 'identity_document' && (microtime(true) - $startedAt) <= $timeBudget) {
                $region = $this->runIdentityRegionOcr($binary, $variants);
                $regionText = trim((string)($region['text'] ?? ''));
                $regionScore = $this->scoreOcrCandidate($regionText, $expectedCode);
                $this->lastStructuredHints = is_array($region['hints'] ?? null) ? $region['hints'] : [];

                if ($regionScore > $bestScore) {
                    $bestScore = $regionScore;
                    $bestText = $regionText;
                }
            }

            $this->lastOcrDebug['ocr_candidates'] = $debugCandidates;
            $this->lastOcrDebug['ocr_best_score'] = $bestScore;
            $this->lastOcrDebug['ocr_best_text_length'] = strlen($bestText);
            $this->lastOcrDebug['ocr_attempts'] = $attempts;
            $this->lastOcrDebug['ocr_elapsed_ms'] = (int)round((microtime(true) - $startedAt) * 1000);
            $this->lastOcrDebug['ocr_truncated_by_budget'] = ($attempts >= $maxAttempts) || ((microtime(true) - $startedAt) > $timeBudget);

            return trim($bestText);
        } catch (\Throwable $e) {
            $this->lastOcrDebug['ocr_error'] = $e->getMessage();
            return '';
        }
    }

    private function runOcr(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            return $this->extractImageText($filePath, '');
        }

        if ($extension !== 'pdf') {
            return '';
        }

        $pdftoppm = $this->resolveBinaryPath('pdftoppm');
        if (!$this->commandExists($pdftoppm)) {
            $this->lastOcrDebug['pdf_ocr_error'] = 'pdftoppm no disponible';
            return '';
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'sicau_pdf_img_');
        if ($tmpBase === false) {
            return '';
        }

        @unlink($tmpBase);
        $outputPrefix = $tmpBase;
        $command = $this->binaryCommand($pdftoppm) . ' -f 1 -singlefile -png ' . escapeshellarg($filePath) . ' ' . escapeshellarg($outputPrefix);
        @shell_exec($command . ' 2>&1');

        $imagePath = $outputPrefix . '.png';
        if (!is_file($imagePath)) {
            $this->lastOcrDebug['pdf_ocr_error'] = 'No se pudo rasterizar PDF para OCR';
            return '';
        }

        $text = $this->extractImageText($imagePath, '');
        @unlink($imagePath);

        return $text;
    }

    private function validateOcrResult(string $ocrResult, string $expectedCode): string
    {
        $text = trim($ocrResult);
        if ($text === '') {
            return '';
        }

        $score = $this->scoreOcrCandidate($text, $expectedCode);
        $minScore = $expectedCode === 'identity_document' ? 45 : 20;

        return $score >= $minScore ? $text : '';
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

        $cleaned = trim(implode("\n", $filtered));
        return $this->normalizeOcrText($cleaned);
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
            $structure = $this->analyzeDocumentStructure($code, $normalizedText);
            $score += $structure['score'];
            if ($code === 'portrait_photo') {
                $score += $this->photoBonus($isImage, $normalizedText, $quality, $photoMeta);
            }
            $candidates[$code] = $score;
        }

        arsort($candidates);
        $bestCode = array_key_first($candidates) ?: $expectedType['code'];
        $bestScore = (float)($candidates[$bestCode] ?? 0);
        $confidence = min(99.0, max(20.0, $bestScore + ($quality * 0.15)));
        $expectedCode = $expectedType['code'] ?? null;
        $observations = [];

        if ($expectedCode) {
            $expectedScore = (float)($candidates[$expectedCode] ?? 0.0);
            if ($expectedScore >= 35.0 && ($bestScore - $expectedScore) <= 12.0) {
                $bestCode = $expectedCode;
                $bestScore = $expectedScore;
            }
        }

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
                $score += 12;
            } elseif ($this->fuzzyContains($normalizedText, $normalizedKeyword, 0.82)) {
                $score += 8;
            }

            if (str_contains($filenameText, $normalizedKeyword)) {
                $score += 5;
            } elseif ($this->fuzzyContains($filenameText, $normalizedKeyword, 0.8)) {
                $score += 3;
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
        $ocrText = $this->normalizeOcrText($ocrText);

        // Extracción común
        $commonName = $this->matchRegex($ocrText, '/nombre(?: completo)?[:\s]+([A-ZÁÉÍÓÚÑ ]{6,})/iu');
        $commonDate = $this->matchDate($ocrText);

        // ACTA DE GRADO
        if ($documentCode === 'graduation_act') {
            $fields[] = [
                'key' => 'student_name',
                'label' => 'Nombre del estudiante',
                'value' => $commonName ?? $this->matchRegex($ocrText, '/egresado[:\s]+([A-ZÁÉÍÓÚÑ ]{6,})/iu') ?? ($user['full_name'] ?? null),
                'confidence' => 75,
            ];
            $fields[] = [
                'key' => 'institution',
                'label' => 'Institución educativa',
                'value' => $this->matchRegex($ocrText, '/instituci[oó]n(?: educativa)?[:\s]+([A-ZÁÉÍÓÚÑ0-9 ,.-]{3,})/iu'),
                'confidence' => 60,
            ];
            $fields[] = [
                'key' => 'graduation_date',
                'label' => 'Fecha de graduación',
                'value' => $commonDate ?? $this->matchRegex($ocrText, '/fecha[:\s]+([0-3]?[0-9][\/\-.][0-1]?\d[\/\-.](?:19|20)?[0-9]{2})/iu'),
                'confidence' => 60,
            ];
            $fields[] = [
                'key' => 'degree_title',
                'label' => 'Título obtenido',
                'value' => $this->matchRegex($ocrText, '/t[ií]tulo[:\s]+([A-ZÁÉÍÓÚÑ0-9 ,.-]{3,})/iu') ?? $this->matchRegex($ocrText, '/bachiller(?: académico| técnico)?/iu'),
                'confidence' => 60,
            ];
            $fields[] = [
                'key' => 'city',
                'label' => 'Ciudad',
                'value' => $this->matchRegex($ocrText, '/ciudad[:\s]+([A-ZÁÉÍÓÚÑ ]{3,})/iu'),
                'confidence' => 50,
            ];
            return $fields;
        }

        // PRUEBAS SABER 11
        if ($documentCode === 'saber_11') {
            $globalScore = $this->extractSaberGlobalScore($ocrText);
            $presentationYear = $this->extractLikelyYear($ocrText);
            $fields[] = [
                'key' => 'student_name',
                'label' => 'Nombre del estudiante',
                'value' => $commonName ?? ($user['full_name'] ?? null),
                'confidence' => 75,
            ];
            $fields[] = [
                'key' => 'global_score',
                'label' => 'Puntaje global',
                'value' => $globalScore,
                'confidence' => $globalScore !== null ? 90 : 65,
            ];
            $fields[] = [
                'key' => 'areas',
                'label' => 'Áreas evaluadas',
                'value' => implode(', ', array_filter([
                    $this->containsKeyword($ocrText, 'lectura critica') ? 'Lectura Critica' : null,
                    $this->containsKeyword($ocrText, 'matematicas') ? 'Matematicas' : null,
                    $this->containsKeyword($ocrText, 'sociales') ? 'Sociales y Ciudadanas' : null,
                    $this->containsKeyword($ocrText, 'naturales') ? 'Ciencias Naturales' : null,
                    $this->containsKeyword($ocrText, 'ingles') ? 'Ingles' : null,
                ])),
                'confidence' => 55,
            ];
            $fields[] = [
                'key' => 'presentation_year',
                'label' => 'Año de presentación',
                'value' => $presentationYear,
                'confidence' => 60,
            ];
            return $fields;
        }

        // FOTO TIPO CARNET
        if ($documentCode === 'portrait_photo') {
            $fields[] = [
                'key' => 'is_image',
                'label' => 'Archivo es imagen',
                'value' => $photoMeta['dimensions'] !== null ? 'Sí' : 'No',
                'confidence' => 99,
            ];
            $fields[] = [
                'key' => 'min_resolution',
                'label' => 'Resolución mínima',
                'value' => ($photoMeta['dimensions']['width'] ?? 0) >= 600 && ($photoMeta['dimensions']['height'] ?? 0) >= 800 ? 'Cumple' : 'No cumple',
                'confidence' => 90,
            ];
            $fields[] = [
                'key' => 'vertical_ratio',
                'label' => 'Proporción vertical',
                'value' => ($photoMeta['ratio'] ?? 0) >= 0.7 && ($photoMeta['ratio'] ?? 0) <= 0.95 ? 'Correcta' : 'Incorrecta',
                'confidence' => 80,
            ];
            $fields[] = [
                'key' => 'face_hint',
                'label' => 'Rostro visible (básico)',
                'value' => $photoMeta['face_hint'] ?? 'No detectado',
                'confidence' => 60,
            ];
            return $fields;
        }

        // CERTIFICADO SISBEN
        if ($documentCode === 'sisben_certificate') {
            $fields[] = [
                'key' => 'nombre',
                'label' => 'Nombre',
                'value' => $commonName ?? ($user['full_name'] ?? null),
                'confidence' => 75,
            ];
            $fields[] = [
                'key' => 'puntaje',
                'label' => 'Puntaje SISBEN',
                'value' => $this->matchRegex($ocrText, '/puntaje[:\s]+([0-9]{1,3}(?:[\.,][0-9]{1,2})?)/iu'),
                'confidence' => 80,
            ];
            $fields[] = [
                'key' => 'grupo',
                'label' => 'Grupo',
                'value' => $this->matchRegex($ocrText, '/grupo[:\s]+([ABCDEF][0-9]?)/iu'),
                'confidence' => 80,
            ];
            $fields[] = [
                'key' => 'municipio',
                'label' => 'Municipio',
                'value' => $this->matchRegex($ocrText, '/municipio[:\s]+([A-ZÁÉÍÓÚÑ ]{3,})/iu'),
                'confidence' => 60,
            ];
            return $fields;
        }

        // SERVICIOS PÚBLICOS
        if ($documentCode === 'utility_bill') {
            $direccion = $this->extractUtilityAddress($ocrText);
            $barrio = $this->extractUtilityNeighborhood($ocrText);
            $total = $this->extractUtilityTotalAmount($ocrText);
            $fields[] = [
                'key' => 'direccion',
                'label' => 'Dirección',
                'value' => $direccion,
                'confidence' => $direccion !== null ? 85 : 60,
            ];
            $fields[] = [
                'key' => 'estrato',
                'label' => 'Estrato',
                'value' => $this->matchRegex($ocrText, '/estrato[:\s]+([0-6])/iu'),
                'confidence' => 80,
            ];
            $fields[] = [
                'key' => 'barrio',
                'label' => 'Barrio',
                'value' => $barrio,
                'confidence' => $barrio !== null ? 75 : 45,
            ];
            $fields[] = [
                'key' => 'total_factura',
                'label' => 'Valor total a pagar',
                'value' => $total,
                'confidence' => $total !== null ? 92 : 55,
            ];
            $fields[] = [
                'key' => 'nombre_titular',
                'label' => 'Nombre titular',
                'value' => $commonName ?? ($user['full_name'] ?? null),
                'confidence' => 70,
            ];
            $fields[] = [
                'key' => 'empresa_servicios',
                'label' => 'Empresa de servicios',
                'value' => $this->matchRegex($ocrText, '/empresa[:\s]+([A-ZÁÉÍÓÚÑ0-9 ]{3,})/iu') ?? $this->matchRegex($ocrText, '/(acueducto|energ[íi]a|gas|emcali|epm|codensa|enel|emcartago|emserpa|emdupar|emdupar|emdupar|emdupar)/iu'),
                'confidence' => 60,
            ];
            return $fields;
        }

        // Documento de identidad (por compatibilidad)
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

        // Fallback
        $fields[] = [
            'key' => 'raw_text_excerpt',
            'label' => 'Extracto OCR',
            'value' => mb_substr($ocrText, 0, 240),
            'confidence' => 35,
            'source' => 'ocr',
        ];
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
                $fieldValue = $this->normalizePersonLikeValue((string)($field['value'] ?? ''));
                $userName = $this->normalizePersonLikeValue((string)($user['full_name'] ?? ''));
                if ($fieldValue !== '' && $userName !== '' && $this->similarity($fieldValue, $userName) >= 0.62) {
                    $nameMatch = true;
                } elseif ($fieldValue !== '' && $userName !== '') {
                    $observations[] = 'El nombre extraído no coincide del todo con el perfil del estudiante.';
                }
            }

            if ($key === 'document_number' && !empty($field['value']) && !empty($user['document_number'])) {
                $ocrDoc = preg_replace('/\D+/', '', (string)$field['value']) ?? '';
                $userDoc = preg_replace('/\D+/', '', (string)$user['document_number']) ?? '';
                $sameDoc = ($ocrDoc !== '' && $ocrDoc === $userDoc)
                    || ($ocrDoc !== '' && $userDoc !== '' && str_ends_with($ocrDoc, substr($userDoc, -6)))
                    || ($ocrDoc !== '' && $userDoc !== '' && str_ends_with($userDoc, substr($ocrDoc, -6)));
                if (!$sameDoc) {
                $observations[] = 'El número de documento no coincide con el usuario autenticado.';
                }
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

        if ($confidence >= 80.0 && $quality >= 45) {
            return ['code' => 'validado_automaticamente', 'label' => 'Validado automáticamente'];
        }

        if ($confidence >= 50.0) {
            return ['code' => 'requiere_revision', 'label' => 'Requiere revisión'];
        }

        return ['code' => 'documento_ilegible', 'label' => 'Documento ilegible'];
    }

    private function mergeObservations(array $first, array $second): array
    {
        return array_values(array_unique(array_filter(array_merge($first, $second))));
    }

    private function normalize(string $text): string
    {
        $text = $this->normalizeOcrText($text);
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]+/i', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }

    private function normalizeOcrText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', ' ', $text) ?? $text;
        $text = str_replace(['|', '¦', '¨', '´', '`'], ' ', $text);

        // Correcciones OCR comunes para tokens alfabéticos.
        $text = preg_replace_callback('/\b[\p{L}\d]{2,}\b/u', function (array $m): string {
            $token = $m[0];
            $letters = preg_match_all('/[\p{L}]/u', $token) ?: 0;
            $digits = preg_match_all('/\d/u', $token) ?: 0;
            if ($digits === 0 || $letters === 0 || $digits > $letters) {
                return $token;
            }

            return strtr($token, [
                '0' => 'O',
                '1' => 'I',
                '4' => 'A',
                '5' => 'S',
            ]);
        }, $text) ?? $text;

        $text = preg_replace('/[ ]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function normalizePersonLikeValue(string $text): string
    {
        $text = strtoupper($this->normalizeOcrText($text));
        $text = preg_replace('/[^A-ZÁÉÍÓÚÑ ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }

    private function fuzzyContains(string $text, string $keyword, float $threshold = 0.82): bool
    {
        if ($keyword === '' || $text === '') {
            return false;
        }

        if (str_contains($text, $keyword)) {
            return true;
        }

        $keywordTokens = preg_split('/\s+/', $keyword) ?: [];
        $textTokens = preg_split('/\s+/', $text) ?: [];
        if (empty($keywordTokens) || empty($textTokens)) {
            return false;
        }

        foreach ($keywordTokens as $needle) {
            if ($needle === '' || strlen($needle) < 4) {
                continue;
            }

            foreach ($textTokens as $token) {
                if (abs(strlen($token) - strlen($needle)) > 2) {
                    continue;
                }

                if ($this->similarity($token, $needle) >= $threshold) {
                    return true;
                }
            }
        }

        return false;
    }

    private function analyzeDocumentStructure(string $code, string $normalizedText): array
    {
        $rules = [
            'identity_document' => ['cedula', 'republica de colombia', 'sexo', 'nacimiento', 'expedicion'],
            'saber_11' => ['icfes', 'saber 11', 'puntaje', 'resultado', 'percentil'],
            'utility_bill' => ['factura', 'estrato', 'direccion', 'suscriptor', 'servicios'],
            'sisben_certificate' => ['sisben', 'grupo', 'puntaje', 'municipio', 'beneficiario'],
            'graduation_act' => ['acta de grado', 'institucion educativa', 'bachiller', 'titulo', 'otorga'],
        ];

        $keywords = $rules[$code] ?? [];
        if (empty($keywords)) {
            return ['score' => 0.0, 'signals' => []];
        }

        $hits = 0;
        $signals = [];
        foreach ($keywords as $kw) {
            $needle = $this->normalize($kw);
            if ($this->fuzzyContains($normalizedText, $needle, 0.8)) {
                $hits++;
                $signals[] = $kw;
            }
        }

        $score = min(28.0, $hits * 6.0);
        return ['score' => $score, 'signals' => $signals];
    }

    private function computeIntelligentScore(
        string $expectedCode,
        string $detectedCode,
        array $candidates,
        array $fields,
        int $quality,
        array $comparison,
        string $normalizedText
    ): array {
        $score = 0.0;
        $observations = [];
        $parts = [];

        if ($expectedCode !== '' && $detectedCode === $expectedCode) {
            $score += 40;
            $parts['document_type'] = 40;
        } elseif ($expectedCode !== '' && (float)($candidates[$expectedCode] ?? 0) >= 35.0) {
            $score += 22;
            $parts['document_type'] = 22;
            $observations[] = 'Tipo documental compatible de forma parcial.';
        } else {
            $parts['document_type'] = 0;
            $observations[] = 'Tipo documental con baja evidencia.';
        }

        $namePoints = !empty($comparison['name_match']) ? 20 : 0;
        $score += $namePoints;
        $parts['name_match'] = $namePoints;

        $fieldMap = [];
        foreach ($fields as $field) {
            $fieldMap[(string)($field['key'] ?? '')] = $field['value'] ?? null;
        }

        $validDoc = false;
        if (!empty($fieldMap['document_number'])) {
            $digits = preg_replace('/\D+/', '', (string)$fieldMap['document_number']) ?? '';
            $validDoc = strlen($digits) >= 7;
        }
        $docPoints = $validDoc ? 20 : 0;
        $score += $docPoints;
        $parts['document_number'] = $docPoints;

        $validDate = !empty($fieldMap['birth_date']) || !empty($fieldMap['graduation_date']) || !empty($this->matchDate($normalizedText));
        $datePoints = $validDate ? 10 : 0;
        $score += $datePoints;
        $parts['date'] = $datePoints;

        $keywordsPoints = 0;
        $mainKeywords = ['icfes', 'saber 11', 'cedula', 'factura', 'sisben', 'estrato', 'puntaje', 'grupo'];
        foreach ($mainKeywords as $kw) {
            if ($this->fuzzyContains($normalizedText, $this->normalize($kw), 0.8)) {
                $keywordsPoints += 2;
            }
        }
        $keywordsPoints = min(10, $keywordsPoints);
        $score += $keywordsPoints;
        $parts['keywords'] = $keywordsPoints;

        $qualityPoints = (int)round(max(0, min(10, $quality / 10)));
        $score += $qualityPoints;
        $parts['ocr_quality'] = $qualityPoints;

        $score = max(0.0, min(100.0, $score));

        return [
            'score' => round($score, 2),
            'parts' => $parts,
            'observations' => array_values(array_unique($observations)),
        ];
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

        $normalized = $this->normalizeOcrText($text);
        if ($normalized !== $text && preg_match($pattern, $normalized, $matches)) {
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

    private function containsKeyword(string $text, string $keyword): bool
    {
        $normalizedText = $this->normalize($text);
        $normalizedKeyword = $this->normalize($keyword);
        return str_contains($normalizedText, $normalizedKeyword) || $this->fuzzyContains($normalizedText, $normalizedKeyword, 0.8);
    }

    private function extractSaberGlobalScore(string $text): ?string
    {
        $normalized = $this->normalizeOcrText($text);

        if (preg_match('/\b(\d{2,3})\s*\/\s*500\b/u', $normalized, $m)) {
            return $m[1];
        }

        if (preg_match('/puntaj\s*e?\s*global[^\d]{0,40}(\d{2,3})\b/iu', $normalized, $m)) {
            return $m[1];
        }

        if (preg_match('/\bglobal[^\d]{0,20}(\d{2,3})\b/iu', $normalized, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractUtilityAddress(string $text): ?string
    {
        $normalized = $this->normalizeOcrText($text);

        $address = $this->matchRegex($normalized, '/direcci[oó]n\s+(?:prestaci[oó]n\s+servicio|de\s+cobro)[:\s\-]*([^\n]{8,120})/iu');
        if ($address !== null) {
            $address = preg_replace('/\s+municipio\s*:.*/iu', '', $address) ?? $address;
            return trim($address);
        }

        $address = $this->matchRegex($normalized, '/\b((?:cr|cra|cl|calle|carrera)\s*[0-9a-z\-\s#]{6,80})/iu');
        return $address !== null ? trim($address) : null;
    }

    private function extractUtilityNeighborhood(string $text): ?string
    {
        $normalized = $this->normalizeOcrText($text);

        $barrio = $this->matchRegex($normalized, '/barrio[:\s\-]*([a-záéíóúñ0-9\-\s]{3,60})/iu');
        if ($barrio !== null) {
            return trim($barrio);
        }

        return null;
    }

    private function extractUtilityTotalAmount(string $text): ?string
    {
        $normalized = $this->normalizeOcrText($text);

        preg_match_all('/(?:valor\s+total\s+a\s+pagar|total\s+a\s+pagar)/iu', $normalized, $anchors, PREG_OFFSET_CAPTURE);
        preg_match_all('/\$\s*([0-9][0-9\.,]{2,})/u', $normalized, $amounts, PREG_OFFSET_CAPTURE);

        $anchorOffsets = array_map(static fn(array $m): int => (int)$m[1], $anchors[0] ?? []);
        $amountMatches = $amounts[1] ?? [];

        $bestValue = null;
        $bestDistance = PHP_INT_MAX;
        $bestAmount = -1.0;

        foreach ($amountMatches as $amountMatch) {
            $raw = (string)$amountMatch[0];
            $offset = (int)$amountMatch[1];
            $money = $this->normalizeMoneyValue($raw);
            $numeric = $this->moneyToFloat($money);
            if ($numeric <= 0) {
                continue;
            }

            $distance = PHP_INT_MAX;
            foreach ($anchorOffsets as $anchor) {
                $d = abs($offset - $anchor);
                if ($d < $distance) {
                    $distance = $d;
                }
            }

            if (empty($anchorOffsets)) {
                $distance = 99999;
            }

            if ($distance <= 260) {
                if ($distance < $bestDistance || ($distance === $bestDistance && $numeric > $bestAmount)) {
                    $bestDistance = $distance;
                    $bestAmount = $numeric;
                    $bestValue = $money;
                }
            }
        }

        if ($bestValue !== null) {
            return $bestValue;
        }

        $candidateLines = preg_split('/\r\n|\r|\n/', $normalized) ?: [];
        $bestValue = null;
        $bestScore = -1;

        foreach ($candidateLines as $line) {
            $lineNorm = $this->normalize($line);
            if (!str_contains($lineNorm, 'total a pagar') && !str_contains($lineNorm, 'valor total a pagar')) {
                continue;
            }

            if (preg_match('/\$\s*([0-9][0-9\.,]{2,})/u', $line, $m)) {
                $money = $this->normalizeMoneyValue($m[1]);
                $numeric = $this->moneyToFloat($money);
                if ($numeric > $bestScore) {
                    $bestScore = $numeric;
                    $bestValue = $money;
                }
            }
        }

        if ($bestValue !== null) {
            return $bestValue;
        }

        return null;
    }

    private function normalizeMoneyValue(string $value): string
    {
        $value = preg_replace('/[^0-9\.,]/', '', $value) ?? $value;
        $value = trim($value);
        if (preg_match('/^\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?$/', $value)) {
            return str_replace(',', '.', $value);
        }

        if (preg_match('/^\d+(?:,\d{1,2})$/', $value)) {
            return str_replace(',', '.', $value);
        }

        return $value;
    }

    private function moneyToFloat(string $value): float
    {
        $raw = str_replace('.', '', $value);
        $raw = str_replace(',', '.', $raw);
        return (float)$raw;
    }

    private function extractLikelyYear(string $text): ?string
    {
        $normalized = $this->normalizeOcrText($text);
        preg_match_all('/\b(20\d{2})\b/u', $normalized, $matches);
        $years = array_map('intval', $matches[1] ?? []);
        $years = array_filter($years, static fn(int $y): bool => $y >= 2010 && $y <= 2100);
        if (empty($years)) {
            return null;
        }

        return (string)max($years);
    }

    private function similarity(string $a, string $b): float
    {
        similar_text($a, $b, $percent);
        return $percent / 100;
    }
}