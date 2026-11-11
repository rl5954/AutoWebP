<?php
if (!defined('ZBP_PATH')) {
    exit;
}

require_once __DIR__ . '/function.php';

// 注册插件
RegisterPlugin('AutoWebP', 'ActivePlugin_AutoWebP');

/**
 * 挂载接口。
 */
function ActivePlugin_AutoWebP()
{
    Add_Filter_Plugin('Filter_Plugin_Upload_SaveFile', 'AutoWebP_Upload_SaveFile');
    Add_Filter_Plugin('Filter_Plugin_Upload_SaveBase64File', 'AutoWebP_Upload_SaveBase64File');
}

/**
 * 启用插件时初始化配置（保留已有配置，便于升级）。
 */
function InstallPlugin_AutoWebP()
{
    global $zbp;

    if (!$zbp->HasConfig('AutoWebP')) {
        foreach (AutoWebP_Defaults() as $key => $value) {
            $zbp->Config('AutoWebP')->$key = $value;
        }
        $zbp->Config('AutoWebP')->install_time = date('Y-m-d H:i:s');
        $zbp->SaveConfig('AutoWebP');

        return;
    }

    // 升级：补齐新增配置项，不覆盖用户已有设置
    $defaults = AutoWebP_Defaults();
    $changed = false;
    foreach ($defaults as $key => $value) {
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
 * 停用插件：默认保留配置，仅在用户勾选「删除配置」时清除。
 */
function UninstallPlugin_AutoWebP()
{
    global $zbp;

    if ($zbp->Config('AutoWebP')->HasKey('del_config') && $zbp->Config('AutoWebP')->del_config == 1) {
        $zbp->DelConfig('AutoWebP');
    }
}

/**
 * 更新插件时执行（兼容旧版本的回调命名）。
 */
function UpdatePlugin_AutoWebP()
{
    InstallPlugin_AutoWebP();
}

/**
 * 旧版兼容回调。
 */
function AutoWebP_Updated()
{
    UpdatePlugin_AutoWebP();
}
