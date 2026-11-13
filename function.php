<?php
if (!defined('ZBP_PATH')) {
    exit;
}

/**
 * 本插件只做一件事：把上传的图片转成 WebP。
 *
 * 转换顺序固定为 Imagick → GD，任一不可用则自动降级；
 * 任何失败都不影响上传，原图按系统默认流程正常入库。
 */

/**
 * 默认配置。
 *
 * @return array
 */
function AutoWebP_Defaults()
{
    return array(
        'enable' => 1,          // 总开关
        'quality' => 82,        // 0-100
        'max_edge' => 0,        // 最长边上限，0 = 不缩放
        'keep_original' => 1,   // 是否保留原图
    );
}

/**
 * 读取配置（缺失项用默认值补齐并做类型收敛）。
 *
 * @return array
 */
function AutoWebP_GetConfig()
{
    global $zbp;

    $raw = $zbp->Config('AutoWebP');
    $cfg = array();
    foreach (AutoWebP_Defaults() as $key => $value) {
        $cfg[$key] = $raw->HasKey($key) ? $raw->$key : $value;
    }

    $cfg['enable'] = (int) $cfg['enable'];
    $cfg['keep_original'] = (int) $cfg['keep_original'];
    $cfg['quality'] = max(0, min(100, (int) $cfg['quality']));
    $cfg['max_edge'] = max(0, (int) $cfg['max_edge']);

    return $cfg;
}

/**
 * 保存配置。
 *
 * @param array $values
 */
function AutoWebP_SaveConfig($values)
{
    global $zbp;

    foreach ((array) $values as $key => $value) {
        $zbp->Config('AutoWebP')->$key = $value;
    }
    $zbp->SaveConfig('AutoWebP');
}

/**
 * 补齐缺失配置项（激活时执行，可重复调用）。
 */
function AutoWebP_InitConfig()
{
    global $zbp;

    $changed = false;
    foreach (AutoWebP_Defaults() as $key => $value) {
        if (!$zbp->Config('AutoWebP')->HasKey($key)) {
            $zbp->Config('AutoWebP')->$key = $value;
            $changed = true;
        }
    }
    if ($changed) {
        $zbp->SaveConfig('AutoWebP');
    }
}

/**
 * 记录最近一次异常信息（供后台设置页展示，仅在内容变化时写盘）。
 *
 * @param string $message
 */
function AutoWebP_SetLastError($message)
{
    global $zbp;

    $raw = $zbp->Config('AutoWebP');
    $old = $raw->HasKey('last_error') ? (string) $raw->last_error : '';
    if ($old === (string) $message) {
        return;
    }
    AutoWebP_SaveConfig(array('last_error' => (string) $message));
}

/**
 * 让当前回调返回信号，从而跳过系统后续的保存动作。
 *
 * @param string $hook
 * @param string $func
 */
function AutoWebP_SetReturnSignal($hook, $func)
{
    if (function_exists('SetPluginSignal')) {
        SetPluginSignal($hook, $func, PLUGIN_EXITSIGNAL_RETURN);

        return;
    }
    if (isset($GLOBALS['hooks'][$hook][$func])) {
        $GLOBALS['hooks'][$hook][$func] = PLUGIN_EXITSIGNAL_RETURN;
    }
}

/* --------------------------------------------------------------------------
 * 图片识别与转换
 * -------------------------------------------------------------------------- */

/**
 * 受支持的图片类型（MIME 常量 => [扩展名, MIME]）。
 *
 * @return array
 */
function AutoWebP_TypeMap()
{
    $map = array(
        IMAGETYPE_JPEG => array('jpg', 'image/jpeg'),
        IMAGETYPE_PNG => array('png', 'image/png'),
        IMAGETYPE_GIF => array('gif', 'image/gif'),
        IMAGETYPE_BMP => array('bmp', 'image/bmp'),
    );
    if (defined('IMAGETYPE_WEBP')) {
        $map[IMAGETYPE_WEBP] = array('webp', 'image/webp');
    }

    return $map;
}

/**
 * 识别图片。
 *
 * @param string $path
 *
 * @return array|false ['type','ext','mime','width','height']
 */
function AutoWebP_Detect($path)
{
    if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
        return false;
    }
    $info = @getimagesize($path);
    if (!is_array($info) || empty($info[2])) {
        return false;
    }
    $map = AutoWebP_TypeMap();
    $type = (int) $info[2];
    if (!isset($map[$type])) {
        return false;
    }

    return array(
        'type' => $type,
        'ext' => $map[$type][0],
        'mime' => $map[$type][1],
        'width' => (int) $info[0],
        'height' => (int) $info[1],
    );
}

/**
 * 是否为动图 GIF（多帧只转第一帧会丢帧，直接跳过）。
 *
 * @param string $path
 *
 * @return bool
 */
function AutoWebP_IsAnimatedGif($path)
{
    $data = @file_get_contents($path);

    return $data !== false && substr_count($data, "\x21\xF9\x04") > 1;
}

/**
 * 可用的处理引擎。
 *
 * @return array ['imagick'=>bool,'gd'=>bool]
 */
function AutoWebP_Engines()
{
    return array(
        'imagick' => extension_loaded('imagick') && class_exists('Imagick'),
        'gd' => function_exists('imagewebp') && function_exists('imagecreatetruecolor'),
    );
}

/**
 * 等比缩放尺寸（只缩小，不放大）。
 *
 * @param int $w
 * @param int $h
 * @param int $maxEdge
 *
 * @return int[] [w, h]
 */
function AutoWebP_CalcSize($w, $h, $maxEdge)
{
    if ($w <= 0 || $h <= 0 || $maxEdge <= 0 || max($w, $h) <= $maxEdge) {
        return array($w, $h);
    }
    $ratio = $maxEdge / max($w, $h);

    return array(max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio)));
}

/**
 * 转换为 WebP（Imagick 优先，失败再用 GD）。
 *
 * @param string $src
 * @param string $dest
 * @param array  $info  AutoWebP_Detect() 的结果
 * @param int    $quality
 * @param int    $maxEdge
 *
 * @return bool
 */
function AutoWebP_Convert($src, $dest, $info, $quality, $maxEdge)
{
    $engines = AutoWebP_Engines();
    // 超大图片保护：约 40MP 以上不处理，避免内存耗尽
    if ($info['width'] * $info['height'] > 40000000) {
        return false;
    }

    if ($engines['imagick'] && AutoWebP_ConvertImagick($src, $dest, $quality, $maxEdge)) {
        return true;
    }
    if ($engines['gd'] && AutoWebP_ConvertGd($src, $dest, $info, $quality, $maxEdge)) {
        return true;
    }

    return false;
}

/**
 * Imagick 转换（自动纠正 EXIF 方向、保留透明通道）。
 *
 * @param string $src
 * @param string $dest
 * @param int    $quality
 * @param int    $maxEdge
 *
 * @return bool
 */
function AutoWebP_ConvertImagick($src, $dest, $quality, $maxEdge)
{
    try {
        $im = new Imagick($src);
        if ($im->getNumberImages() > 1) {
            $im->setFirstIterator();
        }
        if (method_exists($im, 'autoOrient')) {
            $im->autoOrient();
        }

        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $size = AutoWebP_CalcSize($w, $h, $maxEdge);
        if ($size[0] !== $w || $size[1] !== $h) {
            $im->resizeImage($size[0], $size[1], Imagick::FILTER_LANCZOS, 1);
        }

        $im->setImageFormat('WEBP');
        $im->setImageCompressionQuality($quality);
        $im->setOption('webp:method', '4');
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
 * GD 转换（GD 不支持无损 WebP，只能按质量压缩）。
 *
 * @param string $src
 * @param string $dest
 * @param array  $info
 * @param int    $quality
 * @param int    $maxEdge
 *
 * @return bool
 */
function AutoWebP_ConvertGd($src, $dest, $info, $quality, $maxEdge)
{
    $im = AutoWebP_GdCreate($src, $info['type']);
    if ($im === false) {
        return false;
    }
    $im = AutoWebP_GdOrient($im, $src, $info['type']);

    $w = imagesx($im);
    $h = imagesy($im);
    $size = AutoWebP_CalcSize($w, $h, $maxEdge);
    $dst = $im;
    if ($size[0] !== $w || $size[1] !== $h) {
        $dst = imagecreatetruecolor($size[0], $size[1]);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefilledrectangle($dst, 0, 0, $size[0], $size[1], imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $size[0], $size[1], $w, $h);
        imagedestroy($im);
    }

    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $ok = @imagewebp($dst, $dest, max(0, min(100, (int) $quality)));
    imagedestroy($dst);

    return (bool) $ok;
}

/**
 * 由文件创建 GD 资源。
 *
 * @param string $src
 * @param int    $type
 *
 * @return resource|false
 */
function AutoWebP_GdCreate($src, $type)
{
    switch ($type) {
        case IMAGETYPE_JPEG:
            return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($src) : false;
        case IMAGETYPE_PNG:
            $im = function_exists('imagecreatefrompng') ? @imagecreatefrompng($src) : false;
            if ($im !== false && function_exists('imagepalettetotruecolor')) {
                imagepalettetotruecolor($im);
            }

            return $im;
        case IMAGETYPE_GIF:
            return function_exists('imagecreatefromgif') ? @imagecreatefromgif($src) : false;
        case IMAGETYPE_BMP:
            return function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($src) : false;
    }

    return false;
}

/**
 * 按 EXIF 方向纠正 GD 资源。
 *
 * @param resource $im
 * @param string   $src
 * @param int      $type
 *
 * @return resource
 */
function AutoWebP_GdOrient($im, $src, $type)
{
    if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
        return $im;
    }
    $exif = @exif_read_data($src);
    if (!is_array($exif) || empty($exif['Orientation'])) {
        return $im;
    }

    switch ((int) $exif['Orientation']) {
        case 2:
            $angle = 0;
            $flip = IMG_FLIP_HORIZONTAL;
            break;
        case 3:
            $angle = 180;
            $flip = 0;
            break;
        case 4:
            $angle = 0;
            $flip = IMG_FLIP_VERTICAL;
            break;
        case 5:
            $angle = -90;
            $flip = IMG_FLIP_HORIZONTAL;
            break;
        case 6:
            $angle = -90;
            $flip = 0;
            break;
        case 7:
            $angle = 90;
            $flip = IMG_FLIP_HORIZONTAL;
            break;
        case 8:
            $angle = 90;
            $flip = 0;
            break;
        default:
            return $im;
    }

    if ($angle !== 0 && function_exists('imagerotate')) {
        $rotated = @imagerotate($im, $angle, imagecolorallocatealpha($im, 0, 0, 0, 127));
        if ($rotated !== false) {
            imagedestroy($im);
            $im = $rotated;
            imagealphablending($im, false);
            imagesavealpha($im, true);
        }
    }
    if ($flip !== 0 && function_exists('imageflip')) {
        imageflip($im, $flip);
    }

    return $im;
}

/* --------------------------------------------------------------------------
 * 上传落盘
 * -------------------------------------------------------------------------- */

/**
 * 文件名主体：去掉路径与危险字符，保留中文。
 *
 * @param string $name
 *
 * @return string
 */
function AutoWebP_BaseName($name)
{
    $name = str_replace(array("\0", '/', '\\'), '_', (string) $name);
    $base = pathinfo($name, PATHINFO_FILENAME);
    $base = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', (string) $base);
    $base = trim((string) $base, '._-');
    if ($base === '') {
        $base = 'image_' . substr(md5(uniqid('', true)), 0, 8);
    }
    if (function_exists('mb_substr')) {
        $base = mb_substr($base, 0, 80, 'UTF-8');
    } else {
        $base = substr($base, 0, 80);
    }

    return $base;
}

/**
 * 本月附件记录中是否已存在该文件名。
 *
 * @param string $name
 *
 * @return bool
 */
function AutoWebP_UploadNameExists($name)
{
    global $zbp;

    try {
        $where = array(
            array('=', 'ul_Name', $name),
            array('>=', 'ul_PostTime', strtotime(date('Y-m-01'))),
            array('<=', 'ul_PostTime', strtotime(date('Y-m-01') . ' +1 month -1 day')),
        );
        $list = $zbp->GetUploadList('*', $where, null, array(0, 1), null);

        return is_array($list) && count($list) > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 生成不冲突的文件名（同时避开磁盘同名文件与本月附件记录）。
 *
 * @param string $dir
 * @param string $base
 * @param string $ext
 *
 * @return string
 */
function AutoWebP_UniqueName($dir, $base, $ext)
{
    $name = $base . '.' . $ext;
    if (file_exists($dir . $name) || AutoWebP_UploadNameExists($name)) {
        $name = $base . '-' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
    }

    return $name;
}

/**
 * 为原图创建附件记录，使其显示在附件管理中。
 *
 * @param string $name  原图文件名
 * @param string $path  原图完整路径
 * @param array  $info  AutoWebP_Detect() 的结果
 * @param Upload $upload 当前上传的附件对象
 *
 * @return Upload|null
 */
function AutoWebP_AddUploadRecord($name, $path, $info, $upload)
{
    try {
        $orig = new Upload();
        $orig->Name = $name;
        $orig->SourceName = $upload->SourceName;
        $orig->MimeType = isset($info['mime']) ? $info['mime'] : 'application/octet-stream';
        $orig->Size = (int) @filesize($path);
        $orig->AuthorID = $upload->AuthorID;
        $orig->PostTime = $upload->PostTime;
        $orig->Save();

        return $orig;
    } catch (Exception $e) {
        AutoWebP_SetLastError('原图附件记录创建失败：' . $name);

        return null;
    }
}

/**
 * 复制文件（copy 失败时用 file_put_contents 兜底）。
 *
 * @param string $src
 * @param string $dest
 *
 * @return bool
 */
function AutoWebP_CopyFile($src, $dest)
{
    if (@copy($src, $dest) && is_file($dest)) {
        return true;
    }
    $data = @file_get_contents($src);
    if ($data === false || @file_put_contents($dest, $data) === false) {
        return false;
    }

    return is_file($dest);
}

/**
 * 建目录并确认它位于 zb_users 之内（防路径穿越）。
 *
 * @param string $dir
 *
 * @return bool
 */
function AutoWebP_EnsureDir($dir)
{
    global $zbp;

    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $real = realpath($dir);
    $root = realpath($zbp->usersdir);
    if ($real === false || $root === false) {
        return false;
    }
    $real = str_replace('\\', '/', $real) . '/';
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';

    return strpos($real, $root) === 0;
}

/**
 * Filter_Plugin_Upload_SaveFile 回调。
 *
 * @param string $tmp
 * @param Upload $upload
 *
 * @return bool
 */
function AutoWebP_Upload_SaveFile($tmp, &$upload)
{
    return AutoWebP_HandleUpload($upload, $tmp, false);
}

/**
 * Filter_Plugin_Upload_SaveBase64File 回调（编辑器粘贴/拖拽的图片）。
 *
 * @param string $str64
 * @param Upload $upload
 *
 * @return bool
 */
function AutoWebP_Upload_SaveBase64File($str64, &$upload)
{
    if (!is_string($str64) || $str64 === '') {
        return true;
    }
    if (strpos($str64, 'base64,') !== false) {
        $str64 = substr($str64, strpos($str64, 'base64,') + 7);
    }
    $raw = @base64_decode($str64, true);
    if ($raw === false || $raw === '') {
        return true;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'awp');
    if ($tmp === false || @file_put_contents($tmp, $raw) === false) {
        @unlink($tmp);

        return true;
    }
    $ok = AutoWebP_HandleUpload($upload, $tmp, true);
    @unlink($tmp);

    return $ok;
}

/**
 * 判断是否转换，成功则写入 WebP 并接管保存。
 *
 * @param Upload $upload
 * @param string $tmp
 * @param bool   $fromBase64
 *
 * @return bool
 */
function AutoWebP_HandleUpload(&$upload, $tmp, $fromBase64)
{
    global $zbp;

    $cfg = AutoWebP_GetConfig();
    if (empty($cfg['enable']) || !is_file($tmp) || !is_readable($tmp) || (int) @filesize($tmp) <= 0) {
        return true;
    }

    $info = AutoWebP_Detect($tmp);
    if ($info === false || $info['ext'] === 'webp') {
        return true; // 非图片或已是 WebP，走系统默认流程
    }
    if ($info['ext'] === 'gif' && AutoWebP_IsAnimatedGif($tmp)) {
        return true; // 动图跳过，避免只留第一帧
    }

    // 目标目录：附件所在目录，原图与 WebP 存放同一目录
    $dir = $zbp->usersdir . $upload->Dir;
    if (!AutoWebP_EnsureDir($dir)) {
        AutoWebP_SetLastError('上传目录不可用：' . $dir);

        return true;
    }

    $base = AutoWebP_BaseName($upload->Name);

    // 先保存原图（与 WebP 同名不同扩展名），必须在转换之前完成
    $origPath = '';
    $origRecord = null;
    if (!empty($cfg['keep_original'])) {
        $origName = AutoWebP_UniqueName($dir, $base, $info['ext']);
        $origPath = $dir . $origName;
        if (AutoWebP_CopyFile($tmp, $origPath)) {
            $origRecord = AutoWebP_AddUploadRecord($origName, $origPath, $info, $upload);
        } else {
            AutoWebP_SetLastError('原图保存失败：' . $origPath);
            $origPath = '';
        }
    }

    // 转换为 WebP
    $webpTmp = tempnam(sys_get_temp_dir(), 'awp');
    if ($webpTmp === false) {
        AutoWebP_SetLastError('无法创建临时文件，已按原图保存');

        return true;
    }
    $ok = AutoWebP_Convert($tmp, $webpTmp, $info, $cfg['quality'], $cfg['max_edge']);
    $newName = AutoWebP_UniqueName($dir, $base, 'webp');
    if (!$ok || !AutoWebP_CopyFile($webpTmp, $dir . $newName)) {
        @unlink($webpTmp);
        if ($origPath !== '') {
            @unlink($origPath); // 回退：原图交给系统默认保存，避免重复文件
            if ($origRecord !== null && $origRecord->ID) {
                try {
                    $origRecord->Del(); // 同步删除刚建的原图附件记录
                } catch (Exception $e) {
                }
            }
        }
        AutoWebP_SetLastError('WebP 转换或写入失败，已按原图保存：' . $upload->Name);

        return true;
    }
    @unlink($webpTmp);

    if ($origPath !== '') {
        AutoWebP_SetLastError(''); // 原图与 WebP 均已落盘，清除历史异常
    }

    $upload->Name = $newName;
    $upload->MimeType = 'image/webp';
    $upload->Size = (int) @filesize($dir . $newName);

    // 接管保存：跳过系统的目录创建、扩展名检查与 move_uploaded_file
    if ($fromBase64) {
        AutoWebP_SetReturnSignal('Filter_Plugin_Upload_SaveBase64File', 'AutoWebP_Upload_SaveBase64File');
    } else {
        AutoWebP_SetReturnSignal('Filter_Plugin_Upload_SaveFile', 'AutoWebP_Upload_SaveFile');
    }

    return true;
}
