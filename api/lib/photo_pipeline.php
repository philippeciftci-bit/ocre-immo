<?php
// M/2026/04/29/3 — Pipeline photos : compression WebP + thumb 400x400 + stats.
// Audit #fix-23 : pipeline original ne convertissait PAS en WebP. Corrigé ici.
if (!function_exists('photo_pipeline_compress')) {

function photo_pipeline_ensure_stats(): void {
    static $done = false;
    if ($done) return;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS photo_compression_stats (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            client_id INT UNSIGNED NULL,
            original_size INT UNSIGNED NOT NULL,
            compressed_size INT UNSIGNED NULL,
            thumb_size INT UNSIGNED NULL,
            ratio_pct DECIMAL(5,2) NULL,
            duration_ms INT UNSIGNED NULL,
            engine VARCHAR(20) NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            error_message VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_success (success, created_at)
        ) CHARACTER SET utf8mb4");
    } catch (Throwable $e) {}
    $done = true;
}

function photo_pipeline_compress(string $srcPath, string $destBase, int $origSize): array {
    photo_pipeline_ensure_stats();
    $start = microtime(true);
    $result = ['compressed' => false, 'webp_name' => null, 'thumb_name' => null, 'ratio' => null, 'engine' => null];
    if (!is_file($srcPath)) return $result;

    $maxWidth = 1920;
    $quality = 80;
    $thumbSize = 400;

    $img = null;
    $engine = null;
    if (extension_loaded('imagick')) {
        try {
            $img = new \Imagick($srcPath);
            $img->setImageCompressionQuality($quality);
            $engine = 'imagick';
        } catch (Throwable $e) { $img = null; }
    }
    if (!$img && function_exists('imagecreatefromjpeg')) {
        $info = @getimagesize($srcPath);
        if (!$info) return _photo_log_fail($result, $origSize, 'getimagesize_failed');
        $mime = $info['mime'];
        $src = null;
        if ($mime === 'image/jpeg') $src = @imagecreatefromjpeg($srcPath);
        elseif ($mime === 'image/png') $src = @imagecreatefrompng($srcPath);
        if (!$src) return _photo_log_fail($result, $origSize, 'gd_create_failed');
        $w = imagesx($src); $h = imagesy($src);
        if ($w > $maxWidth) {
            $newH = (int) ($h * $maxWidth / $w);
            $resized = imagecreatetruecolor($maxWidth, $newH);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $src, 0, 0, 0, 0, $maxWidth, $newH, $w, $h);
            imagedestroy($src);
            $src = $resized;
            $w = $maxWidth; $h = $newH;
        }
        $webpPath = $destBase . '.webp';
        if (!@imagewebp($src, $webpPath, $quality)) {
            imagedestroy($src);
            return _photo_log_fail($result, $origSize, 'imagewebp_failed');
        }
        // Thumb : redim vers thumbSize × thumbSize cropping centre.
        $minDim = min($w, $h);
        $thumb = imagecreatetruecolor($thumbSize, $thumbSize);
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagecopyresampled($thumb, $src, 0, 0, (int)(($w - $minDim)/2), (int)(($h - $minDim)/2), $thumbSize, $thumbSize, $minDim, $minDim);
        $thumbPath = $destBase . '_thumb.webp';
        @imagewebp($thumb, $thumbPath, 75);
        imagedestroy($thumb);
        imagedestroy($src);
        $engine = 'gd';
        $result['webp_name'] = basename($webpPath);
        $result['thumb_name'] = basename($thumbPath);
    } elseif ($img) {
        // Imagick path
        $g = $img->getImageGeometry();
        if ($g['width'] > $maxWidth) $img->thumbnailImage($maxWidth, 0);
        $img->setImageFormat('webp');
        $img->setImageCompressionQuality($quality);
        $webpPath = $destBase . '.webp';
        $img->writeImage($webpPath);
        $thumbImg = clone $img;
        $thumbImg->cropThumbnailImage($thumbSize, $thumbSize);
        $thumbImg->setImageCompressionQuality(75);
        $thumbPath = $destBase . '_thumb.webp';
        $thumbImg->writeImage($thumbPath);
        $thumbImg->clear();
        $img->clear();
        $result['webp_name'] = basename($webpPath);
        $result['thumb_name'] = basename($thumbPath);
    } else {
        return _photo_log_fail($result, $origSize, 'no_image_engine');
    }

    $webpSize = @filesize($destBase . '.webp') ?: 0;
    $thumbSize2 = @filesize($destBase . '_thumb.webp') ?: 0;
    $ratio = $origSize > 0 ? round(100 * (1 - $webpSize / $origSize), 2) : 0;
    $duration = (int) ((microtime(true) - $start) * 1000);
    try {
        db()->prepare(
            "INSERT INTO photo_compression_stats (original_size, compressed_size, thumb_size, ratio_pct, duration_ms, engine, success) VALUES (?, ?, ?, ?, ?, ?, 1)"
        )->execute([$origSize, $webpSize, $thumbSize2, $ratio, $duration, $engine]);
    } catch (Throwable $e) {}
    $result['compressed'] = true;
    $result['ratio'] = $ratio;
    $result['engine'] = $engine;
    return $result;
}

function _photo_log_fail(array $result, int $origSize, string $err): array {
    try {
        db()->prepare("INSERT INTO photo_compression_stats (original_size, success, error_message) VALUES (?, 0, ?)")
            ->execute([$origSize, $err]);
    } catch (Throwable $e) {}
    return $result;
}

function photo_pipeline_stats_7d(): array {
    photo_pipeline_ensure_stats();
    try {
        $r = db()->query(
            "SELECT
                COUNT(*) total,
                SUM(success) success_count,
                AVG(CASE WHEN success=1 THEN ratio_pct END) avg_ratio,
                AVG(CASE WHEN success=1 THEN duration_ms END) avg_duration_ms,
                AVG(CASE WHEN success=1 THEN original_size END) avg_orig_size,
                AVG(CASE WHEN success=1 THEN compressed_size END) avg_comp_size
             FROM photo_compression_stats WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetch(PDO::FETCH_ASSOC);
        return $r ?: [];
    } catch (Throwable $e) { return []; }
}

}
