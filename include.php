<?php
if (!defined('ZBP_PATH')) {
    exit;
}

/**
 * AutoWebP 插件入口。
 *
 * 职责：
 * - 加载函数库；
 * - 注册插件并挂载上传钩子；
 * - 提供启用时的自愈式配置初始化。
 *
 * 注意：本文件在每次请求的引导阶段加载，只做注册和定义，不输出任何内容。
 */

require_once __DIR__ . '/function.php';

RegisterPlugin('AutoWebP', 'ActivePlugin_AutoWebP');

/**
 * 激活插件：挂载上传钩子，并补齐可能缺失的配置项。
 *
 * 挂载点说明：
 * - Filter_Plugin_Upload_SaveFile：普通文件上传，在系统移动临时文件之前触发；
 * - Filter_Plugin_Upload_SaveBase64File：编辑器粘贴/拖拽的 base64 图片。
 *
 * 两个钩子都在系统保存动作之前执行，转换成功时本插件通过
 * PLUGIN_EXITSIGNAL_RETURN 信号接管保存，改写附件为 WebP。
 */
function ActivePlugin_AutoWebP()
{
    Add_Filter_Plugin('Filter_Plugin_Upload_SaveFile', 'AutoWebP_Upload_SaveFile');
    Add_Filter_Plugin('Filter_Plugin_Upload_SaveBase64File', 'AutoWebP_Upload_SaveBase64File');
    AutoWebP_InitConfig();
}

/**
 * 启用/安装插件：初始化配置，并清理历史版本遗留的废弃配置键
 * （Del 幂等，重复执行无副作用）。
 */
function InstallPlugin_AutoWebP()
{
    AutoWebP_InitConfig();
    AutoWebP_CleanLegacyConfig();
}
