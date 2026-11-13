<?php
require '../../../zb_system/function/c_system_base.php';
require '../../../zb_system/function/c_system_admin.php';

$zbp->Load();

if (!$zbp->CheckRights('root')) {
    $zbp->ShowError(6);
    die();
}
if (!$zbp->CheckPlugin('AutoWebP')) {
    $zbp->ShowError(48);
    die();
}

$blogtitle = '图片自动转 WebP';

function AutoWebP_H($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['autowebp_save'])) {
    if (function_exists('CheckIsRefererValid')) {
        CheckIsRefererValid();
    }
    AutoWebP_SaveConfig(array(
        'enable' => isset($_POST['enable']) ? 1 : 0,
        'quality' => isset($_POST['quality']) ? max(0, min(100, (int) $_POST['quality'])) : 82,
        'max_edge' => isset($_POST['max_edge']) ? max(0, (int) $_POST['max_edge']) : 0,
        'keep_original' => isset($_POST['keep_original']) ? 1 : 0,
    ));
    $zbp->SetHint('good');
    Redirect('./main.php');
}

$cfg = AutoWebP_GetConfig();
$engines = AutoWebP_Engines();
$rawCfg = $zbp->Config('AutoWebP');
$lastError = $rawCfg->HasKey('last_error') ? (string) $rawCfg->last_error : '';

require $blogpath . 'zb_system/admin/admin_header.php';
require $blogpath . 'zb_system/admin/admin_top.php';
?>
<div id="divMain">
  <div class="divHeader"><?php echo AutoWebP_H($blogtitle); ?></div>
  <div class="SubMenu"></div>
  <div id="divMain2">

    <table border="1" class="tableFull tableBorder tableBorder-thcenter">
      <tr>
        <th class="td25">运行环境</th>
        <th>状态</th>
      </tr>
      <tr>
        <td>PHP 版本</td>
        <td><?php echo AutoWebP_H(PHP_VERSION); ?>（要求 7.0+）</td>
      </tr>
      <tr>
        <td>Imagick</td>
        <td><?php echo $engines['imagick'] ? '可用（推荐）' : '不可用'; ?></td>
      </tr>
      <tr>
        <td>GD（imagewebp）</td>
        <td><?php echo $engines['gd'] ? '可用' : '不可用'; ?></td>
      </tr>
      <tr>
        <td>转换是否生效</td>
        <td>
          <?php if ($engines['imagick'] || $engines['gd']): ?>
            <?php echo $engines['imagick'] ? '优先使用 Imagick，失败自动改用 GD' : '使用 GD 转换'; ?>
          <?php else: ?>
            <b>无可用扩展：上传时不会转换，系统会继续保存原图（不会报错）。请安装 Imagick 或启用 GD 的 WebP 支持。</b>
          <?php endif; ?>
        </td>
      </tr>
      <?php if ($lastError !== ''): ?>
      <tr>
        <td>最近一次异常</td>
        <td><b><?php echo AutoWebP_H($lastError); ?></b>（转换或保存原图出现异常时记录于此，供排查）</td>
      </tr>
      <?php endif; ?>
    </table>

    <form method="post" action="./main.php">
      <input type="hidden" name="csrfToken" value="<?php echo $zbp->GetCSRFToken(); ?>">
      <input type="hidden" name="autowebp_save" value="1">

      <table border="1" class="tableFull tableBorder tableBorder-thcenter">
        <tr>
          <th class="td25">配置项</th>
          <th>说明 / 值</th>
        </tr>
        <tr>
          <td>启用转换</td>
          <td><label><input type="checkbox" name="enable" value="1"<?php echo $cfg['enable'] ? ' checked="checked"' : ''; ?>> 上传图片时自动转换为 WebP</label></td>
        </tr>
        <tr>
          <td>压缩质量</td>
          <td>
            <input type="number" name="quality" min="0" max="100" step="1" value="<?php echo AutoWebP_H($cfg['quality']); ?>" style="width:80px;">
            <span class="note">0-100，建议 75-85；数值越大画质越好、体积越大。</span>
          </td>
        </tr>
        <tr>
          <td>缩放</td>
          <td>
            最长边不超过 <input type="number" name="max_edge" min="0" max="20000" step="1" value="<?php echo AutoWebP_H($cfg['max_edge']); ?>" style="width:90px;"> 像素
            <div class="note">填 0 表示不缩放；超过限制时等比缩小，不放大。</div>
          </td>
        </tr>
        <tr>
          <td>保留原图</td>
          <td>
            <label><input type="checkbox" name="keep_original" value="1"<?php echo $cfg['keep_original'] ? ' checked="checked"' : ''; ?>> 转换后把原图另存一份（同名不同扩展名）</label>
            <div class="note">勾选保存原图，不勾选则不保留原格式图片。</div>
          </td>
        </tr>
      </table>

      <p>
        <input type="submit" class="button" value="保存配置">
        <span class="note">GIF 动图、已是 WebP 的图片和 PDF 等非图片文件会自动跳过。</span>
      </p>
    </form>

    <div style="margin-top:20px;padding:12px 14px;background:#f7f8fa;border:1px solid #e2e6ea;border-radius:4px;font-size:12px;color:#666;line-height:1.9;">
      <div style="font-size:13px;color:#333;font-weight:bold;margin-bottom:4px;">关于本插件</div>
      作者：青春记忆<br>
      作者网站：<a href="https://www.qchun.com" target="_blank" rel="noopener noreferrer" style="color:#2a6fbd;text-decoration:none;">https://www.qchun.com</a><br>
      <span style="color:#999;">图片自动转 WebP v1.1.0</span>
    </div>

    <script type="text/javascript">ActiveLeftMenu("aPluginMng");</script>
    <script type="text/javascript">AddHeaderIcon("<?php echo $bloghost . 'zb_users/plugin/AutoWebP/logo.png'; ?>");</script>
  </div>
</div>
<?php
require $blogpath . 'zb_system/admin/admin_footer.php';

RunTime();
