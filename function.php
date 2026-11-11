<?php
if (!defined('ZBP_PATH')) {
    exit;
}

require_once __DIR__ . '/class/ImageConverter.php';

/**
 * 插件默认配置。
 *
 * @return array
 */
function AutoWebP_Defaults()
{
    return array(
        'enable' => 1,
        'format' => 'lossy',        // lossy | lossless
        'quality' => 82,
        'keep_original' => 1,
        'resize_enable' => 0,
        'max_width' => 1600,
        'max_height' => 1600,
        'skip_small_kb' => 0,
        'skip_webp' => 1,
        'skip_gif' => 1,
        'on_fail' => 'fallback',    // fallback | fallback_silent
        'engine' => 'auto',
        'cwebp_path' => '',
        'log_enable' => 0,
        'batch_limit' => 50,
        'batch_last_id' => 0,
        'del_config' => 0,
        'last_batch_time' => '',
        'last_batch_result' => '',
    );
}

/**
 * 读取配置（缺失项自动使用默认值）。
 *
 * @return array
 */
function AutoWebP_GetConfig()
{
    global $zbp;

    $defaults = AutoWebP_Defaults();
    $cfg = $zbp->Config('AutoWebP');
    $out = array();
    foreach ($defaults as $key => $value) {
        if (is_object($cfg) && method_exists($cfg, 'HasKey') && $cfg->HasKey($key)) {
            $out[$key] = $cfg->$key;
        } else {
            $out[$key] = $value;
        }
    }

    $out['enable'] = (int) $out['enable'];
    $out['quality'] = max(0, min(100, (int) $out['quality']));
    $out['keep_original'] = (int) $out['keep_original'];
    $out['resize_enable'] = (int) $out['resize_enable'];
    $out['max_width'] = max(0, (int) $out['max_width']);
    $out['max_height'] = max(0, (int) $out['max_height']);
    $out['skip_small_kb'] = max(0, (int) $out['skip_small_kb']);
    $out['skip_webp'] = (int) $out['skip_webp'];
    $out['skip_gif'] = (int) $out['skip_gif'];
    $out['log_enable'] = (int) $out['log_enable'];
    $out['batch_limit'] = max(1, min(200, (int) $out['batch_limit']));
    $out['batch_last_id'] = max(0, (int) $out['batch_last_id']);

    return $out;
}

/**
 * 保存配置（由 main.php 调用）。
 *
 * @param array $values
 */
function AutoWebP_SaveConfig($values)
{
    global $zbp;

    foreach ($values as $key => $value) {
        $zbp->Config('AutoWebP')->$key = $value;
    }
    $zbp->SaveConfig('AutoWebP');
}

/**
 * 设置钩子返回信号（兼容不同版本）。
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
 * 日志
 * -------------------------------------------------------------------------- */

/**
 * 写日志（自动截断，避免无限增长）。
 *
 * @param string $message
 * @param string $level
 */
function AutoWebP_Log($message, $level = 'info')
{
    global $zbp;

    $dir = $zbp->usersdir . 'plugin/AutoWebP/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . 'auto-webp.log';
    if (is_file($file) && filesize($file) > 512 * 1024) {
        @rename($file, $dir . 'auto-webp.old.log');
    }
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . str_replace(array("\r", "\n"), ' ', $message) . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/**
 * 读取日志末尾若干行。
 *
 * @param int $lines
 *
 * @return array
 */
function AutoWebP_LogTail($lines = 12)
{
    global $zbp;

    $file = $zbp->usersdir . 'plugin/AutoWebP/auto-webp.log';
    if (!is_file($file) || !is_readable($file)) {
        return array();
    }
    $content = @file_get_contents($file);
    if ($content === false) {
        return array();
    }
    $all = preg_split('/\r\n|\r|\n/', trim($content));

    return array_slice($all, -$lines);
}

/* --------------------------------------------------------------------------
 * 文件名与目录安全
 * -------------------------------------------------------------------------- */

/**
 * 清洗文件名：去掉路径分隔符、控制字符与危险符号。
 *
 * @param string $name
 *
 * @return string
 */
function AutoWebP_SanitizeFileName($name)
{
    $name = str_replace(array("\0", '/', '\\', ':', '*', '?', '"', '<', '>', '|'), '_', (string) $name);
    $name = basename($name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
    $name = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $name);
    $name = trim($name, '._-');
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'image_' . substr(md5(uniqid('', true)), 0, 8);
    }
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 80, 'UTF-8');
    } else {
        $name = substr($name, 0, 80);
    }

    return $name;
}

/**
 * 生成 .webp 文件名。
 *
 * @param string $originalName
 *
 * @return string
 */
function AutoWebP_BuildWebpName($originalName)
{
    $safe = AutoWebP_SanitizeFileName($originalName);
    $base = pathinfo($safe, PATHINFO_FILENAME);
    if ($base === '' || $base === false) {
        $base = 'image_' . substr(md5(uniqid('', true)), 0, 8);
    }

    return $base . '.webp';
}

/**
 * 确保目录存在且位于 zb_users 之内（防路径穿越）。
 *
 * @param string $dir
 *
 * @return bool
 */
function AutoWebP_EnsureDir($dir)
{
    global $zbp;

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) {
        return false;
    }
    $real = realpath($dir);
    $root = realpath($zbp->usersdir);
    if ($real === false || $root === false) {
        return false;
    }
    $real = str_replace('\\', '/', $real);
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';

    return strpos($real . '/', $root) === 0;
}

/**
 * 同月附件记录中是否已存在该文件名。
 *
 * @param string $name
 *
 * @return bool
 */
function AutoWebP_UploadNameExists($name)
{
    global $zbp;

    try {
        $d1 = strtotime(date('Y-m-01', time()));
        $d2 = strtotime(date('Y-m-01', time()) . ' +1 month -1 day');
        $where = array(
            array('=', 'ul_Name', $name),
            array('>=', 'ul_PostTime', $d1),
            array('<=', 'ul_PostTime', $d2),
        );
        $list = $zbp->GetUploadList('*', $where, null, array(0, 1), null);

        return is_array($list) && count($list) > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 生成不冲突的文件名。
 *
 * @param string $name
 * @param string $dir
 *
 * @return string
 */
function AutoWebP_MakeUniqueName($name, $dir)
{
    $candidate = $name;
    $i = 0;
    while (file_exists($dir . $candidate) || AutoWebP_UploadNameExists($candidate)) {
        ++$i;
        if ($i > 20) {
            $candidate = pathinfo($name, PATHINFO_FILENAME) . '_' . substr(md5(uniqid('', true)), 0, 6) . '.webp';
            break;
        }
        $candidate = pathinfo($name, PATHINFO_FILENAME) . '_' . $i . '.webp';
    }

    return $candidate;
}

/* --------------------------------------------------------------------------
 * 上传钩子
 * -------------------------------------------------------------------------- */

/**
 * Filter_Plugin_Upload_SaveFile 回调。
 *
 * 说明：该接口在系统移动临时文件、创建目录、检查扩展名之前触发。
 * 转换成功时由本插件接管保存（PLUGIN_EXITSIGNAL_RETURN），因此不依赖
 * ZC_UPLOAD_FILETYPE 是否包含 webp。
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
 * Filter_Plugin_Upload_SaveBase64File 回调（编辑器粘贴/拖拽 base64 图片）。
 *
 * @param string $str64
 * @param Upload $upload
 *
 * @return bool
 */
function AutoWebP_Upload_SaveBase64File($str64, &$upload)
{
    global $zbp;

    $cfg = AutoWebP_GetConfig();
    if (empty($cfg['enable'])) {
        return true;
    }

    if (!is_string($str64) || $str64 === '' || strlen($str64) > 30 * 1024 * 1024) {
        return true;
    }
    if (strpos($str64, 'base64,') !== false) {
        $str64 = substr($str64, strpos($str64, 'base64,') + 7);
    }
    $raw = @base64_decode($str64, true);
    if ($raw === false || strlen($raw) < 1) {
        return true;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'awpsrc');
    if ($tmp === false) {
        return true;
    }
    if (@file_put_contents($tmp, $raw) === false) {
        @unlink($tmp);

        return true;
    }

    $ok = AutoWebP_HandleUpload($upload, $tmp, true);
    @unlink($tmp);

    return $ok;
}

/**
 * 统一处理上传文件：判断是否转换，成功则接管保存。
 *
 * @param Upload $upload
 * @param string $tmp
 * @param bool   $fromBase64
 *
 * @return bool
 */
function AutoWebP_HandleUpload(&$upload, $tmp, $fromBase64 = false)
{
    global $zbp;

    $cfg = AutoWebP_GetConfig();
    if (empty($cfg['enable'])) {
        return true;
    }
    if (!is_string($tmp) || $tmp === '' || !is_file($tmp) || !is_readable($tmp)) {
        return true;
    }

    $size = @filesize($tmp);
    if ($size === false || $size <= 0) {
        return true;
    }
    if ($cfg['skip_small_kb'] > 0 && $size < $cfg['skip_small_kb'] * 1024) {
        return true;
    }

    $info = AutoWebP_ImageConverter::detect($tmp);
    if ($info === false) {
        return true; // 非图片，交给系统默认流程
    }
    if ($info['ext'] === 'webp' && !empty($cfg['skip_webp'])) {
        return true;
    }
    if ($cfg['skip_gif'] && $info['ext'] === 'gif' && AutoWebP_ImageConverter::isAnimatedGif($tmp)) {
        return true;
    }

    $engines = AutoWebP_ImageConverter::engines($cfg['cwebp_path']);
    if (empty($engines['imagick']) && empty($engines['gd']) && empty($engines['cwebp'])) {
        AutoWebP_NoticeNoEngine();

        return true;
    }

    $options = array(
        'quality' => $cfg['quality'],
        'lossless' => ($cfg['format'] === 'lossless'),
        'max_width' => $cfg['resize_enable'] ? $cfg['max_width'] : 0,
        'max_height' => $cfg['resize_enable'] ? $cfg['max_height'] : 0,
        'engine' => $cfg['engine'],
        'cwebp_path' => $cfg['cwebp_path'],
    );

    $webpTmp = tempnam(sys_get_temp_dir(), 'awpwebp');
    if ($webpTmp === false) {
        AutoWebP_FailLog('无法创建临时文件', $cfg);

        return true;
    }

    $result = AutoWebP_ImageConverter::convert($tmp, $webpTmp, $options);
    if (empty($result['ok'])) {
        @unlink($webpTmp);
        AutoWebP_FailLog('转换失败：' . $result['error'] . '，文件：' . $upload->Name, $cfg);

        return true; // 回退：保留原图，走系统默认保存流程
    }

    $dir = $zbp->usersdir . $upload->Dir;
    if (!AutoWebP_EnsureDir($dir)) {
        @unlink($webpTmp);
        AutoWebP_FailLog('上传目录不可用或越界：' . $dir, $cfg);

        return true;
    }

    $newName = AutoWebP_MakeUniqueName(AutoWebP_BuildWebpName($upload->Name), $dir);
    $target = $dir . $newName;

    if (!@copy($webpTmp, $target)) {
        @unlink($webpTmp);
        AutoWebP_FailLog('写入 WebP 文件失败：' . $target, $cfg);

        return true;
    }
    @unlink($webpTmp);

    // 保留原图：以「同名不同扩展名」另存，系统附件记录仍指向 WebP
    $originalName = '';
    if (!empty($cfg['keep_original'])) {
        $originalName = AutoWebP_SanitizeFileName($upload->Name);
        if (pathinfo($originalName, PATHINFO_EXTENSION) === '') {
            $originalName .= '.' . $info['ext'];
        }
        $origPath = $dir . $originalName;
        if (!file_exists($origPath)) {
            @copy($tmp, $origPath);
        }
    }

    $upload->Name = $newName;
    $upload->MimeType = 'image/webp';
    $upload->Size = (int) @filesize($target);

    if ($cfg['log_enable']) {
        AutoWebP_Log(
            '已转换：' . $upload->SourceName . ' -> ' . $newName
            . '（引擎 ' . $result['engine'] . '，' . $size . ' -> ' . $upload->Size . ' 字节）',
            'info'
        );
    }

    // 接管保存：跳过系统默认的目录创建 / 扩展名检查 / move_uploaded_file
    if ($fromBase64) {
        AutoWebP_SetReturnSignal('Filter_Plugin_Upload_SaveBase64File', 'AutoWebP_Upload_SaveBase64File');
    } else {
        AutoWebP_SetReturnSignal('Filter_Plugin_Upload_SaveFile', 'AutoWebP_Upload_SaveFile');
    }

    return true;
}

/**
 * 转换失败时的日志与提示（受 on_fail 配置控制）。
 *
 * @param string $message
 * @param array  $cfg
 */
function AutoWebP_FailLog($message, $cfg)
{
    if ($cfg['on_fail'] === 'fallback_silent') {
        return;
    }
    AutoWebP_Log($message, 'error');
}

/**
 * 无可用图片处理扩展时，只在后台给一次提示。
 */
function AutoWebP_NoticeNoEngine()
{
    static $logged = false;

    if ($logged) {
        return;
    }
    $logged = true;
    $cfg = AutoWebP_GetConfig();
    if ($cfg['log_enable']) {
        AutoWebP_Log('未检测到 Imagick / GD(imagewebp) / cwebp，已自动降级为不转换', 'warning');
    }
}

/* --------------------------------------------------------------------------
 * 批量转换
 * -------------------------------------------------------------------------- */

/**
 * 批量转换已有附件（每次最多处理 $limit 条）。
 *
 * 安全策略：批量模式不删除原文件（即使关闭「保留原图」），以免旧文章
 * 中已写入的图片地址失效；原文件保留时旧链接仍然可用。
 *
 * @param int $limit
 *
 * @return array
 */
function AutoWebP_BatchConvert($limit = 50)
{
    global $zbp;

    $cfg = AutoWebP_GetConfig();
    $result = array(
        'scanned' => 0,
        'converted' => 0,
        'skipped' => 0,
        'failed' => 0,
        'before' => 0,
        'after' => 0,
        'limit' => (int) $limit,
        'message' => '',
    );

    if (empty($cfg['enable'])) {
        $result['message'] = '自动转换总开关未启用，已停止批量转换。';

        return $result;
    }

    $engines = AutoWebP_ImageConverter::engines($cfg['cwebp_path']);
    if (empty($engines['imagick']) && empty($engines['gd']) && empty($engines['cwebp'])) {
        $result['message'] = '未检测到 Imagick / GD(imagewebp) / cwebp，无法执行批量转换。';

        return $result;
    }

    $where = array(array('LIKE', 'ul_MimeType', 'image/%'));
    if ($cfg['batch_last_id'] > 0) {
        $where[] = array('<', 'ul_ID', $cfg['batch_last_id']);
    }
    $list = $zbp->GetUploadList('*', $where, array('ul_ID' => 'DESC'), array(0, (int) $limit), null);
    if (!is_array($list)) {
        $result['message'] = '查询附件失败。';

        return $result;
    }
    if (count($list) === 0 && $cfg['batch_last_id'] > 0) {
        AutoWebP_SaveConfig(array('batch_last_id' => 0));
        $result['message'] = '已处理完一轮，进度已重置；本次未发现待处理附件。';

        return $result;
    }

    $options = array(
        'quality' => $cfg['quality'],
        'lossless' => ($cfg['format'] === 'lossless'),
        'max_width' => $cfg['resize_enable'] ? $cfg['max_width'] : 0,
        'max_height' => $cfg['resize_enable'] ? $cfg['max_height'] : 0,
        'engine' => $cfg['engine'],
        'cwebp_path' => $cfg['cwebp_path'],
    );

    $minId = 0;
    foreach ($list as $upload) {
        ++$result['scanned'];
        $id = (int) $upload->ID;
        if ($minId === 0 || ($id > 0 && $id < $minId)) {
            $minId = $id;
        }

        $src = $upload->FullFile;
        if (!is_file($src) || !is_readable($src)) {
            ++$result['skipped'];
            continue;
        }

        $info = AutoWebP_ImageConverter::detect($src);
        if ($info === false || ($info['ext'] === 'webp' && !empty($cfg['skip_webp']))) {
            ++$result['skipped'];
            continue;
        }

        $size = (int) @filesize($src);
        if ($cfg['skip_small_kb'] > 0 && $size < $cfg['skip_small_kb'] * 1024) {
            ++$result['skipped'];
            continue;
        }
        if ($cfg['skip_gif'] && $info['ext'] === 'gif' && AutoWebP_ImageConverter::isAnimatedGif($src)) {
            ++$result['skipped'];
            continue;
        }

        $dir = dirname($src) . '/';
        if (!AutoWebP_EnsureDir($dir)) {
            ++$result['failed'];
            continue;
        }

        $newName = AutoWebP_MakeUniqueName(AutoWebP_BuildWebpName($upload->Name), $dir);
        $target = $dir . $newName;
        $tmp = tempnam(sys_get_temp_dir(), 'awpbatch');
        if ($tmp === false) {
            ++$result['failed'];
            continue;
        }

        $conv = AutoWebP_ImageConverter::convert($src, $tmp, $options);
        if (empty($conv['ok']) || !@copy($tmp, $target)) {
            @unlink($tmp);
            ++$result['failed'];
            AutoWebP_FailLog('批量转换失败：' . $upload->Name . ' - ' . $conv['error'], $cfg);
            continue;
        }
        @unlink($tmp);

        $result['before'] += $size;
        $result['after'] += (int) @filesize($target);

        $upload->Name = $newName;
        $upload->MimeType = 'image/webp';
        $upload->Size = (int) @filesize($target);
        try {
            $upload->Save();
        } catch (Exception $e) {
            ++$result['failed'];
            AutoWebP_FailLog('附件记录更新失败：' . $upload->ID . ' - ' . $e->getMessage(), $cfg);
            continue;
        }

        ++$result['converted'];
    }

    $result['message'] = '本次处理 ' . $result['scanned'] . ' 条：成功 ' . $result['converted']
        . '，跳过 ' . $result['skipped'] . '，失败 ' . $result['failed']
        . '（批量模式保留原文件）';

    AutoWebP_SaveConfig(array(
        'last_batch_time' => date('Y-m-d H:i:s'),
        'last_batch_result' => json_encode($result, JSON_UNESCAPED_UNICODE),
        'batch_last_id' => $minId > 0 ? $minId : 0,
    ));

    return $result;
}
