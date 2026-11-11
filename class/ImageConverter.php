<?php
if (!defined('ZBP_PATH')) {
    exit;
}

/**
 * 图片转 WebP 转换器（Imagick / GD / cwebp）。
 *
 * 设计原则：
 * - 不抛致命错误，所有失败以数组形式返回；
 * - 优先 Imagick（可保留 alpha、可自动处理 EXIF 方向、支持无损）；
 * - 其次 GD（imagewebp），不支持无损 WebP，将退化为质量 100；
 * - 最后可选 cwebp 命令行（需在插件配置中填写可执行文件路径）。
 */
class AutoWebP_ImageConverter
{
    /** 超大图片保护阈值（像素），约 40MP */
    const MAX_PIXELS = 40000000;

    /**
     * 识别图片类型。
     *
     * @param string $path
     *
     * @return array|false ['ext','mime','width','height','type']
     */
    public static function detect($path)
    {
        if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
            return false;
        }
        $info = @getimagesize($path);
        if (!is_array($info) || empty($info[2])) {
            return false;
        }

        $type = (int) $info[2];
        $map = array(
            IMAGETYPE_JPEG => array('jpg', 'image/jpeg'),
            IMAGETYPE_PNG => array('png', 'image/png'),
            IMAGETYPE_GIF => array('gif', 'image/gif'),
            IMAGETYPE_BMP => array('bmp', 'image/bmp'),
        );
        if (defined('IMAGETYPE_WEBP')) {
            $map[IMAGETYPE_WEBP] = array('webp', 'image/webp');
        }
        if (!isset($map[$type])) {
            return false;
        }

        return array(
            'ext' => $map[$type][0],
            'mime' => $map[$type][1],
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'type' => $type,
        );
    }

    /**
     * 判断是否为动图 GIF（含多帧 GCE 块）。
     *
     * @param string $path
     *
     * @return bool
     */
    public static function isAnimatedGif($path)
    {
        if (extension_loaded('imagick') && class_exists('Imagick')) {
            try {
                $im = new Imagick($path);
                $n = $im->getNumberImages();
                $im->clear();
                $im->destroy();

                return $n > 1;
            } catch (Exception $e) {
                // 继续用字节探测
            }
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            return false;
        }

        // 每一帧通常带有一个图形控制扩展块（0x21 0xF9 0x04）
        return substr_count($data, "\x21\xF9\x04") > 1;
    }

    /**
     * 环境检测。
     *
     * @param string $cwebpPath
     *
     * @return array
     */
    public static function engines($cwebpPath = '')
    {
        $result = array(
            'imagick' => false,
            'imagick_version' => '',
            'gd' => false,
            'gd_version' => '',
            'cwebp' => false,
            'cwebp_version' => '',
        );

        if (extension_loaded('imagick') && class_exists('Imagick')) {
            $result['imagick'] = true;
            if (defined('Imagick::IMAGICK_VERSION')) {
                $result['imagick_version'] = Imagick::IMAGICK_VERSION;
            } elseif (method_exists('Imagick', 'getVersion')) {
                $v = Imagick::getVersion();
                $result['imagick_version'] = isset($v['versionString']) ? $v['versionString'] : '';
            }
        }

        if (function_exists('imagewebp') && function_exists('imagecreatetruecolor')) {
            $result['gd'] = true;
            if (function_exists('gd_info')) {
                $g = gd_info();
                $result['gd_version'] = isset($g['GD Version']) ? $g['GD Version'] : '';
            }
        }

        if ($cwebpPath !== '' && is_file($cwebpPath) && self::canExec()) {
            $result['cwebp'] = true;
            $out = array();
            $code = 1;
            @exec(escapeshellarg($cwebpPath) . ' -version 2>&1', $out, $code);
            if (!empty($out)) {
                $result['cwebp_version'] = trim(implode(' ', array_slice($out, 0, 1)));
            }
        }

        return $result;
    }

    /**
     * exec 是否可用。
     *
     * @return bool
     */
    public static function canExec()
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = ini_get('disable_functions');
        if ($disabled === false || $disabled === '') {
            return true;
        }
        $list = array_map('trim', explode(',', strtolower($disabled)));

        return !in_array('exec', $list, true);
    }

    /**
     * 转换入口。
     *
     * @param string $src     源文件
     * @param string $dest    目标文件（.webp）
     * @param array  $options quality/lossless/max_width/max_height/engine/cwebp_path
     *
     * @return array ['ok'=>bool,'engine'=>string,'error'=>string]
     */
    public static function convert($src, $dest, $options = array())
    {
        $opt = array_merge(array(
            'quality' => 82,
            'lossless' => false,
            'max_width' => 0,
            'max_height' => 0,
            'engine' => 'auto',
            'cwebp_path' => '',
        ), (array) $options);

        $info = self::detect($src);
        if ($info === false) {
            return array('ok' => false, 'engine' => '', 'error' => '不是受支持的图片格式');
        }
        if ($info['width'] > 0 && $info['height'] > 0 && ($info['width'] * $info['height']) > self::MAX_PIXELS) {
            return array('ok' => false, 'engine' => '', 'error' => '图片像素过大（超过 ' . self::MAX_PIXELS . ' 像素），已跳过');
        }

        $quality = max(0, min(100, (int) $opt['quality']));
        $lossless = (bool) $opt['lossless'];
        $maxW = max(0, (int) $opt['max_width']);
        $maxH = max(0, (int) $opt['max_height']);

        $order = self::engineOrder((string) $opt['engine']);
        $errors = array();

        foreach ($order as $engine) {
            if (!self::isAvailable($engine, (string) $opt['cwebp_path'])) {
                continue;
            }
            $method = 'convertWith' . ucfirst($engine);
            $ok = self::$method($src, $dest, $info, $quality, $lossless, $maxW, $maxH, (string) $opt['cwebp_path']);
            if ($ok === true && is_file($dest) && filesize($dest) > 0) {
                return array('ok' => true, 'engine' => $engine, 'error' => '');
            }
            $errors[] = $engine . ' 转换失败';
            @unlink($dest);
        }

        return array(
            'ok' => false,
            'engine' => '',
            'error' => empty($errors) ? '未找到可用的图片处理扩展（Imagick / GD / cwebp）' : implode('；', $errors),
        );
    }

    /**
     * 按配置生成引擎尝试顺序。
     *
     * @param string $engine auto|imagick|gd|cwebp
     *
     * @return string[]
     */
    public static function engineOrder($engine)
    {
        $all = array('imagick', 'gd', 'cwebp');
        if (in_array($engine, $all, true)) {
            return array($engine, 'imagick', 'gd', 'cwebp');
        }

        return $all;
    }

    /**
     * 引擎是否可用。
     *
     * @param string $engine
     * @param string $cwebpPath
     *
     * @return bool
     */
    public static function isAvailable($engine, $cwebpPath = '')
    {
        if ($engine === 'imagick') {
            return extension_loaded('imagick') && class_exists('Imagick');
        }
        if ($engine === 'gd') {
            return function_exists('imagewebp') && function_exists('imagecreatetruecolor');
        }
        if ($engine === 'cwebp') {
            return $cwebpPath !== '' && is_file($cwebpPath) && self::canExec();
        }

        return false;
    }

    /**
     * 计算等比缩放后的尺寸。
     *
     * @param int $w
     * @param int $h
     * @param int $maxW
     * @param int $maxH
     *
     * @return int[] [w, h]
     */
    public static function calcSize($w, $h, $maxW, $maxH)
    {
        if ($w <= 0 || $h <= 0) {
            return array($w, $h);
        }
        $ratio = 1.0;
        if ($maxW > 0 && $w > $maxW) {
            $ratio = min($ratio, $maxW / $w);
        }
        if ($maxH > 0 && $h > $maxH) {
            $ratio = min($ratio, $maxH / $h);
        }
        if ($ratio >= 1.0) {
            return array($w, $h);
        }

        return array(max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio)));
    }

    /**
     * 读取 JPEG EXIF 方向。
     *
     * @param string $src
     * @param int    $type
     *
     * @return int 1-8
     */
    private static function exifOrientation($src, $type)
    {
        if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($src);
        if (!is_array($exif) || empty($exif['Orientation'])) {
            return 1;
        }
        $o = (int) $exif['Orientation'];

        return ($o >= 1 && $o <= 8) ? $o : 1;
    }

    /* ------------------------------------------------------------------
     * Imagick
     * ------------------------------------------------------------------ */

    private static function convertWithImagick($src, $dest, $info, $quality, $lossless, $maxW, $maxH, $cwebpPath)
    {
        try {
            $im = new Imagick($src);
            if ($im->getNumberImages() > 1) {
                // 动图仅取第一帧
                $im->setFirstIterator();
                $im->setIteratorIndex(0);
            }

            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            } else {
                $orientation = self::exifOrientation($src, $info['type']);
                self::rotateImagick($im, $orientation);
            }

            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            $size = self::calcSize($w, $h, $maxW, $maxH);
            if ($size[0] !== $w || $size[1] !== $h) {
                $im->resizeImage($size[0], $size[1], Imagick::FILTER_LANCZOS, 1);
            }

            $im->setImageFormat('WEBP');
            $im->setImageCompressionQuality($quality);
            $im->setOption('webp:method', '4');
            $im->setOption('webp:lossless', $lossless ? 'true' : 'false');
            $im->stripImage();
            $ok = $im->writeImage($dest);
            $im->clear();
            $im->destroy();

            return (bool) $ok;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Imagick 手动方向纠正（autoOrient 不可用时）。
     *
     * @param Imagick $im
     * @param int     $orientation
     */
    private static function rotateImagick($im, $orientation)
    {
        switch ($orientation) {
            case 2:
                $im->flopImage();
                break;
            case 3:
                $im->rotateImage('none', 180);
                break;
            case 4:
                $im->flipImage();
                break;
            case 5:
                $im->flopImage();
                $im->rotateImage('none', 90);
                break;
            case 6:
                $im->rotateImage('none', 90);
                break;
            case 7:
                $im->flopImage();
                $im->rotateImage('none', -90);
                break;
            case 8:
                $im->rotateImage('none', -90);
                break;
            default:
                break;
        }
    }

    /* ------------------------------------------------------------------
     * GD
     * ------------------------------------------------------------------ */

    private static function convertWithGd($src, $dest, $info, $quality, $lossless, $maxW, $maxH, $cwebpPath)
    {
        if (!function_exists('imagewebp')) {
            return false;
        }

        $im = self::createGdImage($src, $info['type']);
        if ($im === false) {
            return false;
        }

        $orientation = self::exifOrientation($src, $info['type']);
        $im = self::orientGd($im, $orientation);

        $w = imagesx($im);
        $h = imagesy($im);
        $size = self::calcSize($w, $h, $maxW, $maxH);

        $dst = $im;
        if ($size[0] !== $w || $size[1] !== $h) {
            $dst = imagecreatetruecolor($size[0], $size[1]);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $size[0], $size[1], $transparent);
            imagecopyresampled($dst, $im, 0, 0, 0, 0, $size[0], $size[1], $w, $h);
            imagedestroy($im);
        }

        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        // GD 不支持无损 WebP，使用质量 100 近似
        $q = $lossless ? 100 : max(0, min(100, $quality));
        $ok = @imagewebp($dst, $dest, $q);
        imagedestroy($dst);

        return (bool) $ok;
    }

    /**
     * 从文件创建 GD 资源。
     *
     * @param string $src
     * @param int    $type
     *
     * @return resource|false
     */
    private static function createGdImage($src, $type)
    {
        $im = false;
        if ($type === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) {
            $im = @imagecreatefromjpeg($src);
        } elseif ($type === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) {
            $im = @imagecreatefrompng($src);
            if ($im !== false && function_exists('imagepalettetotruecolor')) {
                imagepalettetotruecolor($im);
            }
        } elseif ($type === IMAGETYPE_GIF && function_exists('imagecreatefromgif')) {
            $im = @imagecreatefromgif($src);
        } elseif (defined('IMAGETYPE_WEBP') && $type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
            $im = @imagecreatefromwebp($src);
        } elseif ($type === IMAGETYPE_BMP && function_exists('imagecreatefrombmp')) {
            $im = @imagecreatefrombmp($src);
        }

        return $im === false ? false : $im;
    }

    /**
     * GD 方向纠正。
     *
     * @param resource $im
     * @param int      $orientation
     *
     * @return resource
     */
    private static function orientGd($im, $orientation)
    {
        if ($orientation < 2 || $orientation > 8) {
            return $im;
        }

        $angle = 0;
        $flip = '';
        switch ($orientation) {
            case 2:
                $flip = 'h';
                break;
            case 3:
                $angle = 180;
                break;
            case 4:
                $flip = 'v';
                break;
            case 5:
                $angle = -90;
                $flip = 'h';
                break;
            case 6:
                $angle = -90;
                break;
            case 7:
                $angle = 90;
                $flip = 'h';
                break;
            case 8:
                $angle = 90;
                break;
        }

        if ($angle !== 0 && function_exists('imagerotate')) {
            $bg = imagecolorallocatealpha($im, 0, 0, 0, 127);
            $rotated = @imagerotate($im, $angle, $bg);
            if ($rotated !== false) {
                imagealphablending($rotated, false);
                imagesavealpha($rotated, true);
                imagedestroy($im);
                $im = $rotated;
            }
        }

        if ($flip !== '' && function_exists('imageflip')) {
            imageflip($im, $flip === 'h' ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        }

        return $im;
    }

    /* ------------------------------------------------------------------
     * cwebp
     * ------------------------------------------------------------------ */

    private static function convertWithCwebp($src, $dest, $info, $quality, $lossless, $maxW, $maxH, $cwebpPath)
    {
        if ($cwebpPath === '' || !is_file($cwebpPath) || !self::canExec()) {
            return false;
        }

        $cmd = escapeshellarg($cwebpPath);
        if ($lossless) {
            $cmd .= ' -lossless -z 9';
        } else {
            $cmd .= ' -q ' . (int) $quality . ' -m 4';
        }
        $size = self::calcSize($info['width'], $info['height'], $maxW, $maxH);
        if ($size[0] !== $info['width'] || $size[1] !== $info['height']) {
            $cmd .= ' -resize ' . (int) $size[0] . ' ' . (int) $size[1];
        }
        $cmd .= ' -quiet ' . escapeshellarg($src) . ' -o ' . escapeshellarg($dest);

        $output = array();
        $code = 1;
        @exec($cmd . ' 2>&1', $output, $code);

        return $code === 0 && is_file($dest) && filesize($dest) > 0;
    }
}

