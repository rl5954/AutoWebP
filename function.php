<?php
if (!defined('ZBP_PATH')) {
    exit;
}

/**
 * AutoWebP 插件函数库。
 *
 * 文件职责：
 * - 配置读写与自愈初始化（Defaults / GetConfig / SaveConfig / InitConfig）；
 * - 图片识别与转换（Detect / Convert，Imagick 优先、GD 兜底，支持 EXIF 方向与透明通道）；
 * - 上传落盘（HandleUpload：先保存原图，再转换 WebP，并为原图创建附件记录）。
 *
 * 设计原则：
 * - 转换顺序固定为 Imagick → GD，任一不可用则自动降级；
 * - 任何失败都不中断上传，系统按默认流程保存原图；
 * - 异常信息写入配置项 last_error，在后台设置页展示，便于排查。
 */

/* --------------------------------------------------------------------------
 * 配置
 * -------------------------------------------------------------------------- */

/**
 * 插件默认配置。
 *
 * @return array
 */
function AutoWebP_Defaults()
{
    return array(
        'enable' => 1,          // 总开关：1 = 上传时自动转换
        'quality' => 82,        // WebP 压缩质量，0-100
        'max_edge' => 0,        // 最长边上限（像素），0 = 不缩放
        'keep_original' => 1,   // 1 = 保留原图并写入附件管理
    );
}

/**
 * 读取插件配置；缺失项用默认值补齐，并做类型收敛（转整型、限范围）。
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
 * 保存插件配置（写入内存后必须调用 SaveConfig 才会持久化）。
 *
 * @param array $values 键值对，键必须属于 Defaults
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
 * 补齐缺失的配置项（自愈式初始化）。
 *
 * 在激活函数中每次请求都会调用：InstallPlugin 回调不保证执行，
 * 这里只补写缺失键，不覆盖用户已保存的值。
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
 * 1.1.0 之前版本遗留、现已废弃的配置键（engine/cwebp/批量/日志/跳过条件等）。
 *
 * @return array
 */
function AutoWebP_DeprecatedKeys()
{
    return array(
        'engine', 'cwebp_path', 'on_fail', 'del_config', 'format',
        'resize_enable', 'max_width', 'max_height',
        'skip_small_kb', 'skip_webp', 'skip_gif',
        'log_enable', 'batch_last_id',
    );
}

/**
 * 清理历史版本遗留的废弃配置键（幂等：键不存在时无任何写入）。
 *
 * 由 InstallPlugin 在安装/升级时调用，老站点的死键会被清掉。
 */
function AutoWebP_CleanLegacyConfig()
{
    global $zbp;

    $changed = false;
    foreach (AutoWebP_DeprecatedKeys() as $key) {
        if ($zbp->Config('AutoWebP')->HasKey($key)) {
            $zbp->Config('AutoWebP')->Del($key);
            $changed = true;
        }
    }
    if ($changed) {
        $zbp->SaveConfig('AutoWebP');
    }
}

/**
 * 记录最近一次异常信息，供后台设置页「最近一次异常」展示。
 *
 * 仅在内容变化时写盘，避免每次上传都产生配置文件写入。
 *
 * @param string $message 异常描述，空字符串表示清除
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
 * 给当前钩子回调设置 PLUGIN_EXITSIGNAL_RETURN 信号，
 * 让系统跳过后续的默认保存动作（目录创建、扩展名检查、move_uploaded_file）。
 *
 * 仅在 Z-BlogPHP 1.7.0+ 环境下会被调用（HandleUpload 有版本门槛）：
 * 信号机制（SetPluginSignal）自 1.7 引入，无需兼容分支。
 *
 * @param string $hook 钩子名
 * @param string $func 当前回调函数名
 */
function AutoWebP_SetReturnSignal($hook, $func)
{
    SetPluginSignal($hook, $func, PLUGIN_EXITSIGNAL_RETURN);
}

/* --------------------------------------------------------------------------
 * 图片识别与转换
 * -------------------------------------------------------------------------- */

/**
 * 受支持的图片类型映射（getimagesize 的类型常量 => [扩展名, MIME]）。
 *
 * IMAGETYPE_WEBP 常量 PHP 7.1 起才有定义，需判断 defined 兼容 PHP 7.0。
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
 * 按文件内容（而非扩展名）识别图片。
 *
 * @param string $path 文件路径
 *
 * @return array|false 成功返回 ['type','ext','mime','width','height']；非图片或不可读返回 false
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
 * 是否为动图 GIF。
 *
 * 通过统计图形控制扩展块（0x21 0xF9 0x04）数量判断：多帧动图该块会重复出现。
 * 动图转 WebP 只能保留第一帧，调用方应据此直接跳过。
 *
 * 只读文件头部 64KB：图形控制扩展块集中在前部（首帧 + 控制序列），
 * 足以识别绝大多数动图，避免大文件全量读入内存；
 * 极端情况下（前 64KB 仅含单帧的巨型多帧图）会误判为静图，
 * 后续转换只取第一帧，不影响正确性。
 *
 * @param string $path
 *
 * @return bool
 */
function AutoWebP_IsAnimatedGif($path)
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return false;
    }
    $data = (string) @fread($fh, 65536);
    @fclose($fh);

    return substr_count($data, "\x21\xF9\x04") > 1;
}

/**
 * 检测当前可用的图片处理引擎。
 *
 * @return array ['imagick'=>bool, 'gd'=>bool]
 */
function AutoWebP_Engines()
{
    return array(
        // Imagick 扩展已加载且 Imagick 类存在
        'imagick' => extension_loaded('imagick') && class_exists('Imagick'),
        // GD 需要提供 imagewebp 函数才支持 WebP 输出
        'gd' => function_exists('imagewebp') && function_exists('imagecreatetruecolor'),
    );
}

/**
 * 按最长边限制计算等比缩放后的尺寸（只缩小，不放大）。
 *
 * @param int $w       原始宽
 * @param int $h       原始高
 * @param int $maxEdge 最长边上限，0 表示不限制
 *
 * @return int[] [宽, 高]
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
 * 转换入口：把 $src 转换为 WebP 并写入 $dest。
 *
 * 引擎顺序固定为 Imagick → GD，前者失败自动尝试后者；
 * 超大图片（约 40MP 以上）直接放弃，避免内存耗尽。
 *
 * @param string $src     源图片路径
 * @param string $dest    目标 WebP 路径
 * @param array  $info    AutoWebP_Detect() 的结果
 * @param int    $quality 压缩质量 0-100
 * @param int    $maxEdge 最长边上限，0 = 不缩放
 *
 * @return bool 是否成功（成功时 $dest 必为有效 WebP 文件）
 */
function AutoWebP_Convert($src, $dest, $info, $quality, $maxEdge)
{
    // 超大图片保护：约 40MP 以上不处理，避免内存耗尽
    if ($info['width'] * $info['height'] > 40000000) {
        return false;
    }

    $engines = AutoWebP_Engines();
    if ($engines['imagick'] && AutoWebP_ConvertImagick($src, $dest, $quality, $maxEdge)) {
        return true;
    }
    if ($engines['gd'] && AutoWebP_ConvertGd($src, $dest, $info, $quality, $maxEdge)) {
        return true;
    }

    return false;
}

/**
 * 用 Imagick 转换：自动纠正 EXIF 方向、保留透明通道、剥离元信息。
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
            $im->setFirstIterator(); // 多帧图只取第一帧
        }
        if (method_exists($im, 'autoOrient')) {
            $im->autoOrient(); // 按 EXIF 方向自动转正（Imagick 3.3+）
        }

        // 需要缩放时用 Lanczos 滤镜等比缩小
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $size = AutoWebP_CalcSize($w, $h, $maxEdge);
        if ($size[0] !== $w || $size[1] !== $h) {
            $im->resizeImage($size[0], $size[1], Imagick::FILTER_LANCZOS, 1);
        }

        // webp:method 为编码器压缩努力程度，4 是质量与速度的均衡值
        $im->setImageFormat('WEBP');
        $im->setImageCompressionQuality($quality);
        $im->setOption('webp:method', '4');
        $im->stripImage(); // 去掉 EXIF 等元信息，进一步减小体积
        $ok = $im->writeImage($dest);
        $im->clear();
        $im->destroy();

        return (bool) $ok;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 用 GD 转换：GD 不支持无损 WebP，只能按质量参数压缩。
 *
 * @param string $src
 * @param string $dest
 * @param array  $info    AutoWebP_Detect() 的结果
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

    // 需要缩放时新建画布并重采样，透明 PNG 用 alpha 填充底色
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

    // 关闭 alpha 混合并保存 alpha 通道，避免透明区域变黑
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $ok = @imagewebp($dst, $dest, max(0, min(100, (int) $quality)));
    imagedestroy($dst);

    return (bool) $ok;
}

/**
 * 按图片类型调用对应的 imagecreatefromxxx 创建 GD 资源。
 *
 * PNG 调色板图先转 truecolor，否则 alpha 通道会丢失。
 *
 * @param string $src
 * @param int    $type IMAGETYPE_* 常量
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
 * 按 JPEG EXIF Orientation（1-8）旋转/翻转 GD 资源，使照片方向转正。
 *
 * @param resource $im   GD 图像资源（会被原地修改或替换）
 * @param string   $src  原文件路径（读取 EXIF 用）
 * @param int      $type 图片类型，仅处理 JPEG
 *
 * @return resource 处理后的 GD 资源
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

    // EXIF 方向值 → 旋转角度 / 翻转方式
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
            return $im; // 1 = 正常方向，无需处理
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
 * 提取安全的文件名主体（不含扩展名）。
 *
 * 去掉路径分隔符与危险字符，保留中文等 Unicode 字符，限长 80 字符；
 * 清洗后为空则生成随机名兜底。
 *
 * @param string $name 原始文件名
 *
 * @return string
 */
function AutoWebP_BaseName($name)
{
    // 去掉路径分隔符与空字节，防止路径穿越
    $name = str_replace(array("\0", '/', '\\'), '_', (string) $name);
    $base = pathinfo($name, PATHINFO_FILENAME);
    // 仅保留字母/数字/点/下划线/连字符（\p{L} 含中文）
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
 * 与系统上传的重名检查口径一致（同月内 ul_Name 唯一）。
 *
 * @param string $name 完整文件名（含扩展名）
 *
 * @return bool 查询出错时按不存在处理
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
 * 生成不冲突的目标文件名。
 *
 * 同名文件已存在磁盘或本月附件记录时，追加 6 位随机后缀。
 *
 * @param string $dir  目标目录（以 / 结尾）
 * @param string $base 文件名主体
 * @param string $ext  扩展名（不含点）
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
 * 为原图创建附件数据库记录，使其显示在后台附件管理中。
 *
 * PostTime 沿用当前附件的值：附件目录由 PostTime 动态计算，
 * 两者一致才能保证记录指向的目录与文件实际所在目录相同。
 *
 * @param string $name  原图文件名（含扩展名）
 * @param string $path  原图完整路径
 * @param array  $info  AutoWebP_Detect() 的结果（取 mime）
 * @param Upload $upload 当前上传的附件对象
 *
 * @return Upload|null 成功返回记录对象，失败返回 null
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
 * 复制文件；copy() 失败时用 file_put_contents + file_get_contents 兜底。
 *
 * @param string $src  源路径
 * @param string $dest 目标路径
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
 * 创建目录并确认它位于 zb_users 之内（防路径穿越）。
 *
 * @param string $dir 目录路径（$zbp->usersdir 下的相对子路径）
 *
 * @return bool
 */
function AutoWebP_EnsureDir($dir)
{
    global $zbp;

    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    // realpath 归一化后校验目录必须位于 zb_users 内
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
 * Filter_Plugin_Upload_SaveFile 钩子回调（普通文件上传）。
 *
 * 系统在移动临时文件、创建目录、检查扩展名之前触发本回调；
 * 转换成功时通过返回信号接管保存，因此不依赖系统的 webp 扩展名白名单。
 *
 * @param string $tmp    上传临时文件路径
 * @param Upload $upload 附件对象
 *
 * @return bool
 */
function AutoWebP_Upload_SaveFile($tmp, &$upload)
{
    return AutoWebP_HandleUpload($upload, $tmp, false);
}

/**
 * Filter_Plugin_Upload_SaveBase64File 钩子回调（编辑器粘贴/拖拽的 base64 图片）。
 *
 * 把 base64 解码写入临时文件后，走与普通上传相同的处理流程。
 *
 * @param string $str64  base64 数据（可能带 data:image/...;base64, 前缀）
 * @param Upload $upload 附件对象
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
 * 上传处理主流程：判断是否转换 → 先保存原图 → 转换 WebP → 接管保存。
 *
 * 处理顺序说明：
 * 1. 原图必须在转换之前复制落盘，不依赖转换结果；
 * 2. 转换后做纯收益检查，WebP 不小于原图时放弃转换；
 * 3. 转换失败时删除已复制的原图及附件记录，回退为系统默认保存原图；
 * 4. 转换成功时改写 $upload 的文件名/类型/大小，并设置返回信号接管保存。
 *
 * @param Upload $upload     附件对象
 * @param string $tmp        源图片的临时文件路径
 * @param bool   $fromBase64 是否来自 base64 上传（决定接管哪个钩子）
 *
 * @return bool 恒为 true，失败场景由系统按原图继续保存
 */
function AutoWebP_HandleUpload(&$upload, $tmp, $fromBase64)
{
    global $zbp;

    // 版本门槛：接管保存依赖 1.7.0+ 的信号机制，更低版本直接不处理，
    // 系统按原图保存，用户可从「没有生成 WebP」一眼看出插件未生效
    if (!defined('ZBP_VERSION') || version_compare(ZBP_VERSION, '1.7.0', '<')) {
        return true;
    }

    // 开关关闭或临时文件不可用时，不处理
    $cfg = AutoWebP_GetConfig();
    if (empty($cfg['enable']) || !is_file($tmp) || !is_readable($tmp) || (int) @filesize($tmp) <= 0) {
        return true;
    }

    // 按内容识别：非图片、已是 WebP 直接跳过；动图 GIF 跳过（只转第一帧会丢帧）
    $info = AutoWebP_Detect($tmp);
    if ($info === false || $info['ext'] === 'webp') {
        return true;
    }
    if ($info['ext'] === 'gif' && AutoWebP_IsAnimatedGif($tmp)) {
        return true;
    }

    // 目标目录：附件所在目录，原图与 WebP 存放同一目录
    $dir = $zbp->usersdir . $upload->Dir;
    if (!AutoWebP_EnsureDir($dir)) {
        AutoWebP_SetLastError('上传目录不可用：' . $dir);

        return true;
    }

    $base = AutoWebP_BaseName($upload->Name);

    // 第一步：保存原图（与 WebP 同名不同扩展名），并写入附件记录
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

    // 第二步：转换为 WebP 并写入附件目录
    $webpTmp = tempnam(sys_get_temp_dir(), 'awp');
    if ($webpTmp === false) {
        AutoWebP_SetLastError('无法创建临时文件，已按原图保存');

        return true;
    }

    // 纯收益检查：WebP 不比原图小就放弃转换
    // （5KB 级小图标转 WebP 经常反而变大，此时保存原图更划算）
    $failReason = '';
    $ok = AutoWebP_Convert($tmp, $webpTmp, $info, $cfg['quality'], $cfg['max_edge']);
    if ($ok) {
        $webpSize = (int) @filesize($webpTmp);
        $origSize = (int) @filesize($tmp);
        if ($webpSize <= 0 || $webpSize >= $origSize) {
            $ok = false;
            $failReason = '转换后体积未减小（WebP ' . $webpSize . 'B / 原图 ' . $origSize . 'B）';
        }
    }

    $newName = AutoWebP_UniqueName($dir, $base, 'webp');
    if (!$ok || !AutoWebP_CopyFile($webpTmp, $dir . $newName)) {
        @unlink($webpTmp);
        // 回退：清理已保存的原图与记录，原图交给系统默认 move_uploaded_file
        if ($origPath !== '') {
            @unlink($origPath);
            if ($origRecord !== null && $origRecord->ID) {
                try {
                    $origRecord->Del();
                } catch (Exception $e) {
                }
            }
        }
        AutoWebP_SetLastError(($failReason !== '' ? $failReason . '，' : 'WebP 转换或写入失败，') . '已按原图保存：' . $upload->Name);

        return true;
    }
    @unlink($webpTmp);

    // 原图与 WebP 均已落盘，清除历史异常
    if ($origPath !== '') {
        AutoWebP_SetLastError('');
    }

    // 第三步：改写附件信息为 WebP，并接管保存
    $upload->Name = $newName;
    $upload->MimeType = 'image/webp';
    $upload->Size = (int) @filesize($dir . $newName);

    if ($fromBase64) {
        AutoWebP_SetReturnSignal('Filter_Plugin_Upload_SaveBase64File', 'AutoWebP_Upload_SaveBase64File');
    } else {
        AutoWebP_SetReturnSignal('Filter_Plugin_Upload_SaveFile', 'AutoWebP_Upload_SaveFile');
    }

    return true;
}
