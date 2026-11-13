<?php
if (!defined('ZBP_PATH')) {
    exit;
}

require_once __DIR__ . '/function.php';

RegisterPlugin('AutoWebP', 'ActivePlugin_AutoWebP');

/**
 * 挂载上传钩子，并补齐可能缺失的配置项。
 */
function ActivePlugin_AutoWebP()
{
    Add_Filter_Plugin('Filter_Plugin_Upload_SaveFile', 'AutoWebP_Upload_SaveFile');
    Add_Filter_Plugin('Filter_Plugin_Upload_SaveBase64File', 'AutoWebP_Upload_SaveBase64File');
    AutoWebP_InitConfig();
}

/**
 * 启用插件：初始化配置。
 */
function InstallPlugin_AutoWebP()
{
    AutoWebP_InitConfig();
}
