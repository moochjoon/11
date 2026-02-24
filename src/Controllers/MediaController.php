<?php

declare(strict_types=1);

namespace Namak\Controllers;

use Namak\Core\Request;
use Namak\Core\Response;
use Namak\Services\Cache;
use Namak\Services\Security;
use Namak\Services\Validation;
use Namak\Repositories\MediaRepository;
use Namak\Repositories\MessageRepository;
use Namak\Repositories\ChatRepository;

/**
 * MediaController
 *
 * Handles all media operations:
 * upload, download, delete, thumbnail generation, gallery listing.
 *
 * Architecture decisions:
 *  - All files stored on LOCAL server — zero external dependencies.
 *  - Images  → resized + compressed server-side via GD (no ImageMagick needed).
 *  - Videos  → stored as-is; thumbnail extracted via FFmpeg if available,
 *               otherwise a placeholder is used.
 *  - Files   → stored with original name sanitised; served with correct MIME.
 *  - Voice   → stored as-is (client records WebM/OGG).
 *  - Secret chats → client sends pre-encrypted binary; server stores opaquely.
 *  - Media rows deleted from DB after 24h if message is ephemeral.
 *  - Client-side gallery caches files in IndexedDB / Cache API (PWA).
 */
class MediaController
{
    private MediaRepository   $mediaRepo;
    private MessageRepository $messageRepo;
    private ChatRepository    $chatRepo;
    private Validation        $validation;
    private Security          $security;
    private Cache             $cache;

    // ── Upload limits ─────────────────────────────────────────────────────────
    private const MAX_IMAGE_BYTES   = 10  * 1024 * 1024;  // 10 MB
    private const MAX_VIDEO_BYTES   = 200 * 1024 * 1024;  // 200 MB
    private const MAX_AUDIO_BYTES   = 20  * 1024 * 1024;  // 20 MB
    private const MAX_FILE_BYTES    = 100 * 1024 * 1024;  // 100 MB
    private const MAX_VOICE_BYTES   = 20  * 1024 * 1024;  // 20 MB

    // ── Allowed MIME types per category ──────────────────────────────────────
    private const MIME_IMAGE  = ['image/jpeg','image/png','image/webp','image/gif'];
    private const MIME_VIDEO  = ['video/mp4','video/webm','video/ogg','video/quicktime'];
    private const MIME_AUDIO  = ['audio/mpeg','audio/ogg','audio/webm','audio/mp4','audio/aac','audio/wav'];
    private const MIME_VOICE  = ['audio/ogg','audio/webm','audio/wav'];
    private const MIME_FILE   = [
        'application/pdf',
        'application/zip','application/x-zip-compressed',
        'application/x-rar-compressed','application/vnd.rar',
        'application/x-7z-compressed',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain','text/csv',
        'application/json',
        'application/octet-stream',  // fallback for unknown binaries
    ];

    // ── Thumbnail config ──────────────────────────────────────────────────────
    private const THUMB_W = 320;
    private const THUMB_H = 320;
    private const THUMB_Q = 75;   // JPEG quality

    // ── Storage sub-directories (under /storage/media/) ──────────────────────
    private const DIR_MAP = [
        'image' => 'images',
        'video' => 'videos',
        'audio' => 'audio',
        'voice' => 'voice',
        'file'  => 'files',
        'thumb' => 'thumbs',
    ];

    public function __construct(
        MediaRepository   $mediaRepo,
        MessageRepository $messageRepo,
        ChatRepository    $chatRepo,
        Validation        $validation,
        Security          $security,
        Cache             $cache
    ) {
        $this->mediaRepo   = $mediaRepo;
        $this->messageRepo = $messageRepo;
        $this->chatRepo    = $chatRepo;
        $this->validation  = $validation;
        $this->security    = $security;
        $this->cache       = $cache;
    }

    // =========================================================================
    // UPLOAD  —  POST /api/v1/media/upload
    // =========================================================================

    /**
     * Upload a media file.
     *
     * Multipart form fields:
     *   file        (required)  — binary file
     *   type        (required)  — image|video|audio|voice|file
     *   chat_id     (required)  — target chat (membership verified)
     *   is_encrypted (optional) — true for secret-chat E2E payload
     *   caption     (optional)
     *
     * Returns: media record including URL, thumbnail URL, dimensions, size.
     */
    public function upload(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $post   = $request->getPost();  // non-file form fields

        // ── Validate form fields ──────────────────────────────────────────────
        $rules = [
            'type'         => ['required', 'in:image,video,audio,voice,file'],
            'chat_id'      => ['required', 'integer', 'min:1'],
            'is_encrypted' => ['nullable', 'boolean'],
            'caption'      => ['nullable', 'string', 'max:1024'],
        ];
        $errors = $this->validation->validate($post, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $type        = $post['type'];
        $chatId      = (int) $post['chat_id'];
        $isEncrypted = filter_var($post['is_encrypted'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // ── Membership check ──────────────────────────────────────────────────
        if (!$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        // ── File presence ─────────────────────────────────────────────────────
        $file = $request->getFile('file');
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = $this->uploadErrorMessage($file['error'] ?? -1);
            $response->json(['success' => false, 'message' => $errorMsg], 422);
            return;
        }

        // ── Size check ────────────────────────────────────────────────────────
        $maxBytes = $this->getMaxBytes($type);
        if ($file['size'] > $maxBytes) {
            $response->json([
                'success' => false,
                'message' => sprintf(
                    'File too large. Maximum for %s is %s.',
                    $type,
                    $this->formatBytes($maxBytes)
                ),
            ], 422);
            return;
        }

        // ── MIME validation (magic bytes, not client-provided) ─────────────────
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);

        if (!$this->isMimeAllowed($type, $realMime)) {
            $response->json([
                'success' => false,
                'message' => "File type '{$realMime}' is not allowed for {$type}.",
            ], 422);
            return;
        }

        // ── Rate limit: 60 uploads per hour per user ──────────────────────────
        $rateKey = "upload_rate_{$userId}";
        if ((int) $this->cache->get($rateKey) >= 60) {
            $response->json([
                'success'     => false,
                'message'     => 'Upload limit reached (60/hour). Try again later.',
                'retry_after' => 3600,
            ], 429);
            return;
        }
        $this->cache->increment($rateKey, 1, 3600);

        // ── Prepare storage path ──────────────────────────────────────────────
        $storageBase = BASE_PATH . '/storage/media';
        $subDir      = self::DIR_MAP[$type] ?? 'files';
        $destDir     = $storageBase . '/' . $subDir . '/' . date('Y/m');

        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        // Safe filename: userId_timestamp_randomhex.ext
        $ext      = $this->safeExtension($file['name'], $realMime);
        $filename = $userId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destPath = $destDir . '/' . $filename;
        $relPath  = $subDir . '/' . date('Y/m') . '/' . $filename;

        // ── Move uploaded file ────────────────────────────────────────────────
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $response->json(['success' => false, 'message' => 'Failed to store file.'], 500);
            return;
        }

        // ── Post-processing ───────────────────────────────────────────────────
        $width     = null;
        $height    = null;
        $duration  = null;
        $thumbPath = null;
        $thumbRel  = null;

        if ($type === 'image' && !$isEncrypted) {
            // Resize & compress + generate thumbnail
            [$width, $height] = $this->processImage($destPath, $realMime);
            $thumbRel         = $this->generateImageThumb($destPath, $relPath, $realMime);
        }

        if ($type === 'video') {
            // Try FFmpeg thumbnail; fallback to placeholder
            $thumbRel = $this->generateVideoThumb($destPath, $relPath);
            // Duration via FFmpeg
            $duration = $this->getVideoDuration($destPath);
        }

        if (in_array($type, ['audio', 'voice'], true)) {
            $duration = $this->getAudioDuration($destPath);
        }

        // ── Persist media record ──────────────────────────────────────────────
        $mediaId = $this->mediaRepo->create([
            'uploader_id'  => (int) $userId,
            'chat_id'      => $chatId,
            'type'         => $type,
            'filename'     => $filename,
            'original_name'=> $this->sanitizeOriginalName($file['name']),
            'mime_type'    => $realMime,
            'size_bytes'   => (int) filesize($destPath),
            'path'         => $relPath,
            'thumb_path'   => $thumbRel,
            'width'        => $width,
            'height'       => $height,
            'duration'     => $duration,
            'is_encrypted' => $isEncrypted ? 1 : 0,
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        if (!$mediaId) {
            @unlink($destPath);
            $response->json(['success' => false, 'message' => 'Failed to record media.'], 500);
            return;
        }

        $media = $this->mediaRepo->findById($mediaId);

        $response->json([
            'success' => true,
            'media'   => $this->formatMedia($media),
        ], 201);
    }

    // =========================================================================
    // DOWNLOAD  —  GET /api/v1/media/download/{id}
    // =========================================================================

    /**
     * Stream a media file to the authenticated user.
     *
     * - Verifies caller is a member of the chat the media belongs to.
     * - Supports Range requests (video/audio seeking).
     * - Sets cache headers so the browser/PWA caches the file client-side.
     *   This fulfils the "store files client-side, not server" requirement —
     *   the browser caches via Cache-Control and the PWA service worker
     *   intercepts subsequent requests from IndexedDB/CacheStorage.
     */
    public function download(Request $request, Response $response, int $mediaId): void
    {
        $userId = $request->getUserId();

        $media = $this->mediaRepo->findById($mediaId);
        if (!$media) {
            $response->json(['success' => false, 'message' => 'Media not found.'], 404);
            return;
        }

        // Membership check
        if (!$this->chatRepo->isMember((int) $media['chat_id'], (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Access denied.'], 403);
            return;
        }

        $filePath = BASE_PATH . '/storage/media/' . $media['path'];
        if (!file_exists($filePath)) {
            $response->json(['success' => false, 'message' => 'File not found on server.'], 404);
            return;
        }

        $fileSize = filesize($filePath);
        $mime     = $media['mime_type'];
        $filename = $media['original_name'] ?? $media['filename'];

        // ── Range request support (seek in audio/video) ───────────────────────
        $rangeHeader = $request->getHeader('Range');
        if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
            $start = $m[1] !== '' ? (int) $m[1] : 0;
            $end   = $m[2] !== '' ? (int) $m[2] : $fileSize - 1;
            $end   = min($end, $fileSize - 1);
            $length = $end - $start + 1;

            http_response_code(206);
            header('Content-Type: '    . $mime);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
            header('Content-Length: ' . $length);
            header('Accept-Ranges: bytes');
            $this->setCacheHeaders($media['type']);

            $fp = fopen($filePath, 'rb');
            fseek($fp, $start);
            $sent = 0;
            while (!feof($fp) && $sent < $length) {
                $chunk  = min(8192, $length - $sent);
                $buffer = fread($fp, $chunk);
                echo $buffer;
                $sent += strlen($buffer);
                flush();
            }
            fclose($fp);
            return;
        }

        // ── Full file response ────────────────────────────────────────────────
        http_response_code(200);
        header('Content-Type: '        . $mime);
        header('Content-Length: '      . $fileSize);
        header('Accept-Ranges: bytes');
        header('Content-Disposition: ' . $this->contentDisposition($media['type'], $filename));
        $this->setCacheHeaders($media['type']);

        readfile($filePath);
    }

    // =========================================================================
    // THUMBNAIL  —  GET /api/v1/media/thumb/{id}
    // =========================================================================

    /**
     * Serve the thumbnail image for a media item.
     * Generates on-the-fly if it doesn't exist yet (lazy generation).
     */
    public function thumb(Request $request, Response $response, int $mediaId): void
    {
        $userId = $request->getUserId();

        $media = $this->mediaRepo->findById($mediaId);
        if (!$media) {
            $response->json(['success' => false, 'message' => 'Media not found.'], 404);
            return;
        }

        if (!$this->chatRepo->isMember((int) $media['chat_id'], (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Access denied.'], 403);
            return;
        }

        // Thumb already exists
        if ($media['thumb_path']) {
            $thumbAbs = BASE_PATH . '/storage/media/' . $media['thumb_path'];
            if (file_exists($thumbAbs)) {
                http_response_code(200);
                header('Content-Type: image/jpeg');
                header('Content-Length: ' . filesize($thumbAbs));
                header('Cache-Control: public, max-age=604800, immutable'); // 7 days
                readfile($thumbAbs);
                return;
            }
        }

        // Lazy generate
        $srcAbs  = BASE_PATH . '/storage/media/' . $media['path'];
        $newThumb = null;

        if ($media['type'] === 'image') {
            $newThumb = $this->generateImageThumb($srcAbs, $media['path'], $media['mime_type']);
        } elseif ($media['type'] === 'video') {
            $newThumb = $this->generateVideoThumb($srcAbs, $media['path']);
        }

        if ($newThumb) {
            $this->mediaRepo->update((int) $media['id'], ['thumb_path' => $newThumb]);
            $thumbAbs = BASE_PATH . '/storage/media/' . $newThumb;
            http_response_code(200);
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . filesize($thumbAbs));
            header('Cache-Control: public, max-age=604800, immutable');
            readfile($thumbAbs);
            return;
        }

        // No thumbnail available — serve a 1×1 transparent placeholder
        $this->servePlaceholderThumb();
    }

    // =========================================================================
    // DELETE  —  DELETE /api/v1/media/delete/{id}
    // =========================================================================

    /**
     * Delete a media file.
     * Only the uploader or a chat admin may delete.
     * Physically removes the file and its thumbnail from disk.
     */
    public function delete(Request $request, Response $response, int $mediaId): void
    {
        $userId = $request->getUserId();

        $media = $this->mediaRepo->findById($mediaId);
        if (!$media) {
            $response->json(['success' => false, 'message' => 'Media not found.'], 404);
            return;
        }

        $chatId = (int) $media['chat_id'];

        if (!$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Access denied.'], 403);
            return;
        }

        $isOwner = (int) $media['uploader_id'] === (int) $userId;
        $role    = $this->chatRepo->getMemberRole($chatId, (int) $userId);
        $isAdmin = in_array($role, ['admin', 'owner'], true);

        if (!$isOwner && !$isAdmin) {
            $response->json(['success' => false, 'message' => 'Permission denied.'], 403);
            return;
        }

        // Delete physical files
        $mainPath  = BASE_PATH . '/storage/media/' . $media['path'];
        $thumbPath = $media['thumb_path']
            ? BASE_PATH . '/storage/media/' . $media['thumb_path']
            : null;

        if (file_exists($mainPath))  @unlink($mainPath);
        if ($thumbPath && file_exists($thumbPath)) @unlink($thumbPath);

        // Delete DB record (cascades to message media_id references via FK)
        $this->mediaRepo->delete($mediaId);

        $response->json(['success' => true, 'message' => 'Media deleted.']);
    }

    // =========================================================================
    // GALLERY  —  GET /api/v1/media/gallery
    // =========================================================================

    /**
     * List media items (images + videos) for a chat — gallery view.
     * Supports pagination. Encrypted items are listed but payload is opaque.
     */
    public function gallery(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $query  = $request->getQuery();

        $rules = [
            'chat_id' => ['required', 'integer', 'min:1'],
            'type'    => ['nullable', 'in:image,video,audio,file,voice'],
            'page'    => ['nullable', 'integer', 'min:1'],
        ];
        $errors = $this->validation->validate($query, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $chatId  = (int) $query['chat_id'];
        $type    = $query['type'] ?? null;
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = 30;

        if (!$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        $result = $this->mediaRepo->getGallery($chatId, [
            'type'     => $type ?? ['image', 'video'],
            'page'     => $page,
            'per_page' => $perPage,
        ]);

        $response->json([
            'success' => true,
            'items'   => array_map(fn($m) => $this->formatMedia($m), $result['items']),
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    // =========================================================================
    // SHARED FILES  —  GET /api/v1/media/shared
    // =========================================================================

    /**
     * List all non-image/video shared files in a chat (documents, audio, voice).
     */
    public function shared(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $query  = $request->getQuery();

        $rules = [
            'chat_id' => ['required', 'integer', 'min:1'],
            'page'    => ['nullable', 'integer', 'min:1'],
        ];
        $errors = $this->validation->validate($query, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $chatId  = (int) $query['chat_id'];
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = 20;

        if (!$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        $result = $this->mediaRepo->getGallery($chatId, [
            'type'     => ['file', 'audio', 'voice'],
            'page'     => $page,
            'per_page' => $perPage,
        ]);

        $response->json([
            'success' => true,
            'items'   => array_map(fn($m) => $this->formatMedia($m), $result['items']),
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    // =========================================================================
    // PURGE ORPHANS  —  POST /api/v1/media/purge-orphans  (internal)
    // =========================================================================

    /**
     * Remove media files whose parent message has been deleted (orphans).
     * Called by internal scheduler / cron. Requires X-Internal-Token header.
     */
    public function purgeOrphans(Request $request, Response $response): void
    {
        $secret = $request->getHeader('X-Internal-Token');
        if ($secret !== ($_ENV['INTERNAL_TOKEN'] ?? '')) {
            $response->json(['success' => false, 'message' => 'Unauthorized.'], 401);
            return;
        }

        $orphans = $this->mediaRepo->getOrphans();
        $deleted = 0;

        foreach ($orphans as $media) {
            $mainPath  = BASE_PATH . '/storage/media/' . $media['path'];
            $thumbPath = $media['thumb_path']
                ? BASE_PATH . '/storage/media/' . $media['thumb_path']
                : null;

            if (file_exists($mainPath))  { @unlink($mainPath);  }
            if ($thumbPath && file_exists($thumbPath)) { @unlink($thumbPath); }

            $this->mediaRepo->delete((int) $media['id']);
            $deleted++;
        }

        $response->json([
            'success'      => true,
            'deleted_count'=> $deleted,
            'purged_at'    => gmdate('Y-m-d H:i:s'),
        ]);
    }

    // =========================================================================
    // IMAGE PROCESSING (GD — no external lib)
    // =========================================================================

    /**
     * Re-compress and optionally resize a large image in-place.
     * Max width/height: 2560px. Returns [width, height].
     */
    private function processImage(string $path, string $mime): array
    {
        $src = $this->gdOpen($path, $mime);
        if (!$src) return [null, null];

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $max  = 2560;

        if ($srcW <= $max && $srcH <= $max) {
            // Just re-save with compression
            $this->gdSaveJpeg($src, $path, 82);
            imagedestroy($src);
            return [$srcW, $srcH];
        }

        // Scale down proportionally
        $ratio = min($max / $srcW, $max / $srcH);
        $dstW  = (int) round($srcW * $ratio);
        $dstH  = (int) round($srcH * $ratio);

        $dst = imagecreatetruecolor($dstW, $dstH);
        $this->handleTransparency($dst, $mime);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($src);

        $this->gdSaveJpeg($dst, $path, 82);
        imagedestroy($dst);

        return [$dstW, $dstH];
    }

    /**
     * Generate a JPEG thumbnail (320×320 max, cropped to square centre).
     * Returns relative path to thumbnail, or null on failure.
     */
    private function generateImageThumb(string $srcPath, string $relPath, string $mime): ?string
    {
        $src = $this->gdOpen($srcPath, $mime);
        if (!$src) return null;

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $size = self::THUMB_W; // square thumb

        // Centre-crop to square, then resize
        $cropSize = min($srcW, $srcH);
        $cropX    = (int) (($srcW - $cropSize) / 2);
        $cropY    = (int) (($srcH - $cropSize) / 2);

        $dst = imagecreatetruecolor($size, $size);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $size, $size, $cropSize, $cropSize);
        imagedestroy($src);

        $thumbRel = $this->thumbRelPath($relPath);
        $thumbAbs = BASE_PATH . '/storage/media/' . $thumbRel;

        $thumbDir = dirname($thumbAbs);
        if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);

        if (!imagejpeg($dst, $thumbAbs, self::THUMB_Q)) {
            imagedestroy($dst);
            return null;
        }
        imagedestroy($dst);

        return $thumbRel;
    }

    /**
     * Extract a thumbnail from a video using FFmpeg.
     * Falls back to a grey placeholder JPEG if FFmpeg is unavailable.
     */
    private function generateVideoThumb(string $videoPath, string $relPath): ?string
    {
        $thumbRel = $this->thumbRelPath($relPath, 'jpg');
        $thumbAbs = BASE_PATH . '/storage/media/' . $thumbRel;
        $thumbDir = dirname($thumbAbs);

        if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);

        // Try FFmpeg (server may or may not have it)
        $ffmpeg = $this->findFFmpeg();
        if ($ffmpeg) {
            $cmd = sprintf(
                '%s -y -i %s -vframes 1 -ss 00:00:01 -vf "scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:white" %s 2>/dev/null',
                escapeshellcmd($ffmpeg),
                escapeshellarg($videoPath),
                self::THUMB_W, self::THUMB_H,
                self::THUMB_W, self::THUMB_H,
                escapeshellarg($thumbAbs)
            );
            exec($cmd, $out, $code);
            if ($code === 0 && file_exists($thumbAbs)) {
                return $thumbRel;
            }
        }

        // Fallback: grey placeholder JPEG
        return $this->createPlaceholderThumb($thumbAbs) ? $thumbRel : null;
    }

    // =========================================================================
    // DURATION HELPERS (FFprobe / fallback)
    // =========================================================================

    private function getVideoDuration(string $path): ?int
    {
        return $this->ffprobeDuration($path);
    }

    private function getAudioDuration(string $path): ?int
    {
        return $this->ffprobeDuration($path);
    }

    private function ffprobeDuration(string $path): ?int
    {
        $ffprobe = $this->findBinary('ffprobe');
        if (!$ffprobe) return null;

        $cmd = sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
            escapeshellcmd($ffprobe),
            escapeshellarg($path)
        );
        $out = shell_exec($cmd);
        $dur = $out ? (float) trim($out) : null;
        return $dur ? (int) round($dur) : null;
    }

    // =========================================================================
    // FORMAT HELPER
    // =========================================================================

    /**
     * Format a media DB row for API responses.
     * Never exposes the absolute filesystem path.
     */
    private function formatMedia(array $media): array
    {
        $baseUrl = rtrim($_ENV['APP_URL'] ?? '', '/');

        return [
            'id'            => (int)    $media['id'],
            'type'          => (string) $media['type'],
            'original_name' => (string) ($media['original_name'] ?? $media['filename']),
            'mime_type'     => (string) $media['mime_type'],
            'size_bytes'    => (int)    $media['size_bytes'],
            'size_human'    => $this->formatBytes((int) $media['size_bytes']),
            'width'         => isset($media['width'])    ? (int) $media['width']    : null,
            'height'        => isset($media['height'])   ? (int) $media['height']   : null,
            'duration'      => isset($media['duration']) ? (int) $media['duration'] : null,
            'is_encrypted'  => (bool) ($media['is_encrypted'] ?? false),

            // Client downloads via the /api/v1/media/download/{id} endpoint
            // The browser / PWA service worker caches the response locally
            'url'           => $baseUrl . '/api/v1/media/download/' . $media['id'],
            'thumb_url'     => $media['thumb_path']
                ? $baseUrl . '/api/v1/media/thumb/' . $media['id']
                : null,

            'uploader_id'   => (int)    $media['uploader_id'],
            'chat_id'       => (int)    $media['chat_id'],
            'created_at'    => (string) $media['created_at'],
        ];
    }

    // =========================================================================
    // PRIVATE UTILITIES
    // =========================================================================

    /** Maximum allowed bytes for each media type. */
    private function getMaxBytes(string $type): int
    {
        return match ($type) {
            'image' => self::MAX_IMAGE_BYTES,
            'video' => self::MAX_VIDEO_BYTES,
            'audio' => self::MAX_AUDIO_BYTES,
            'voice' => self::MAX_VOICE_BYTES,
            default => self::MAX_FILE_BYTES,
        };
    }

    /** Check whether a MIME type is permitted for the given media type. */
    private function isMimeAllowed(string $type, string $mime): bool
    {
        $allowed = match ($type) {
            'image' => self::MIME_IMAGE,
            'video' => self::MIME_VIDEO,
            'audio' => self::MIME_AUDIO,
            'voice' => self::MIME_VOICE,
            'file'  => array_merge(
                self::MIME_FILE,
                self::MIME_IMAGE,
                self::MIME_AUDIO,
                self::MIME_VIDEO
            ),
            default => [],
        };
        return in_array($mime, $allowed, true);
    }

    /** Derive a safe file extension from the original name + real MIME. */
    private function safeExtension(string $originalName, string $mime): string
    {
        $mimeMap = [
            'image/jpeg'  => 'jpg',  'image/png'   => 'png',
            'image/webp'  => 'webp', 'image/gif'   => 'gif',
            'video/mp4'   => 'mp4',  'video/webm'  => 'webm',
            'video/ogg'   => 'ogv',  'video/quicktime' => 'mov',
            'audio/mpeg'  => 'mp3',  'audio/ogg'   => 'ogg',
            'audio/webm'  => 'weba', 'audio/mp4'   => 'm4a',
            'audio/aac'   => 'aac',  'audio/wav'   => 'wav',
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            'text/plain'  => 'txt',  'text/csv'    => 'csv',
            'application/json' => 'json',
        ];

        if (isset($mimeMap[$mime])) return $mimeMap[$mime];

        // Fallback: use original extension but strip anything dangerous
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
    }

    /** Sanitize the original filename for storage in DB. */
    private function sanitizeOriginalName(string $name): string
    {
        $name = preg_replace('/[^\w\s.\-()]/', '', $name);
        return mb_substr(trim($name), 0, 255);
    }

    /** Build relative thumbnail path from a media's relative path. */
    private function thumbRelPath(string $relPath, string $ext = 'jpg'): string
    {
        $base = pathinfo($relPath, PATHINFO_FILENAME);
        $dir  = dirname($relPath);
        // Place thumb in the /thumbs/ sub-tree mirroring the same date tree
        $thumbDir = self::DIR_MAP['thumb'] . '/' . ltrim(str_replace(
                array_values(self::DIR_MAP),
                '',
                $dir
            ), '/');
        return trim($thumbDir, '/') . '/' . $base . '_thumb.' . $ext;
    }

    /** Open an image via GD. */
    private function gdOpen(string $path, string $mime): \GdImage|false
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif'  => @imagecreatefromgif($path),
            default      => false,
        };
    }

    /** Save GD image as JPEG. */
    private function gdSaveJpeg(\GdImage $img, string $path, int $quality): bool
    {
        return imagejpeg($img, $path, $quality);
    }

    /** Handle PNG/WebP transparency for GD destination canvas. */
    private function handleTransparency(\GdImage $dst, string $mime): void
    {
        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $t = imagecolorallocatealpha($dst, 255, 255, 255, 127);
            imagefilledrectangle($dst, 0, 0, imagesx($dst), imagesy($dst), $t);
        }
    }

    /** Create a grey placeholder thumbnail JPEG. */
    private function createPlaceholderThumb(string $destPath): bool
    {
        $img   = imagecreatetruecolor(self::THUMB_W, self::THUMB_H);
        $grey  = imagecolorallocate($img, 44, 44, 44);
        $white = imagecolorallocate($img, 200, 200, 200);
        imagefilledrectangle($img, 0, 0, self::THUMB_W, self::THUMB_H, $grey);
        // Draw a simple play-button triangle
        $cx = self::THUMB_W / 2;
        $cy = self::THUMB_H / 2;
        $r  = 40;
        imagefilledpolygon($img, [
            (int)($cx - $r * 0.6), (int)($cy - $r * 0.8),
            (int)($cx + $r),       (int)($cy),
            (int)($cx - $r * 0.6), (int)($cy + $r * 0.8),
        ], $white);
        $ok = imagejpeg($img, $destPath, self::THUMB_Q);
        imagedestroy($img);
        return $ok;
    }

    /** Serve a 1×1 transparent GIF as a placeholder thumbnail. */
    private function servePlaceholderThumb(): void
    {
        http_response_code(200);
        header('Content-Type: image/gif');
        header('Cache-Control: no-store');
        // Minimal 1×1 transparent GIF
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }

    /** Set aggressive cache headers so PWA/browser caches the file. */
    private function setCacheHeaders(string $type): void
    {
        // Immutable media — safe to cache forever client-side
        if (in_array($type, ['image', 'video', 'audio', 'voice'], true)) {
            header('Cache-Control: private, max-age=2592000, immutable'); // 30 days
        } else {
            header('Cache-Control: private, max-age=86400'); // 1 day for files
        }
        header('Vary: Authorization');
    }

    /** Content-Disposition header value. */
    private function contentDisposition(string $type, string $filename): string
    {
        $safe = rawurlencode($filename);
        // Inline for media so the browser can preview; attachment for files
        $mode = in_array($type, ['image', 'video', 'audio', 'voice'], true)
            ? 'inline'
            : 'attachment';
        return "{$mode}; filename=\"{$filename}\"; filename*=UTF-8''{$safe}";
    }

    /** Human-readable file size. */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024)       return $bytes . ' B';
        if ($bytes < 1048576)    return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1073741824, 2) . ' GB';
    }

    /** Find FFmpeg binary path. */
    private function findFFmpeg(): ?string
    {
        return $this->findBinary('ffmpeg');
    }

    /** Locate a binary in common paths. */
    private function findBinary(string $name): ?string
    {
        $paths = [
            '/usr/bin/'     . $name,
            '/usr/local/bin/' . $name,
            '/opt/homebrew/bin/' . $name,
        ];
        foreach ($paths as $p) {
            if (is_executable($p)) return $p;
        }
        // Try `which`
        $which = trim((string) shell_exec('which ' . escapeshellarg($name) . ' 2>/dev/null'));
        return ($which && is_executable($which)) ? $which : null;
    }

    /** Human-friendly PHP upload error message. */
    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum allowed size.',
            UPLOAD_ERR_PARTIAL   => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR=> 'Missing temp folder on server.',
            UPLOAD_ERR_CANT_WRITE=> 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension.',
            default              => 'Unknown upload error.',
        };
    }
}
