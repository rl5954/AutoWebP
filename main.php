<?php
require '../../../zb_system/function/c_system_base.php';
require '../../../zb_system/function/c_system_admin.php';

$zbp->Load();

$action = 'root';
if (!$zbp->CheckRights($action)) {
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

function AutoWebP_Checked($value)
{
    return ((int) $value === 1) ? ' checked="checked"' : '';
}

function AutoWebP_BatchSummary($cfg)
{
    if (empty($cfg['last_batch_result'])) {
        return null;
    }
    $data = json_decode($cfg['last_batch_result'], true);

    return is_array($data) ? $data : null;
}

$cfg = AutoWebP_GetConfig();
$engines = AutoWebP_ImageConverter::engines($cfg['cwebp_path']);
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['autowebp_action'])) {
    if (function_exists('CheckIsRefererValid')) {
        CheckIsRefererValid();
    }
    $act = (string) $_POST['autowebp_action'];

    if ($act === 'batch') {
        $limit = isset($_POST['batch_limit']) ? (int) $_POST['batch_limit'] : $cfg['batch_limit'];
        $limit = max(1, min(200, $limit));
        AutoWebP_SaveConfig(array('batch_limit' => $limit));
        $result = AutoWebP_BatchConvert($limit);
        $zbp->SetHint($result['converted'] > 0 ? 'good' : 'bad', $result['message']);
        Redirect('./main.php');
    }

    if ($act === 'save') {
        $new = array();
        $new['enable'] = isset($_POST['enable']) ? 1 : 0;
        $new['format'] = (isset($_POST['format']) && $_POST['format'] === 'lossless') ? 'lossless' : 'lossy';
        $new['quality'] = isset($_POST['quality']) ? (int) $_POST['quality'] : 82;
        $new['quality'] = max(0, min(100, $new['quality']));
        $new['keep_original'] = isset($_POST['keep_original']) ? 1 : 0;
        $new['resize_enable'] = isset($_POST['resize_enable']) ? 1 : 0;
        $new['max_width'] = isset($_POST['max_width']) ? max(0, (int) $_POST['max_width']) : 0;
        $new['max_height'] = isset($_POST['max_height']) ? max(0, (int) $_POST['max_height']) : 0;
        $new['skip_small_kb'] = isset($_POST['skip_small_kb']) ? max(0, (int) $_POST['skip_small_kb']) : 0;
        $new['skip_webp'] = isset($_POST['skip_webp']) ? 1 : 0;
        $new['skip_gif'] = isset($_POST['skip_gif']) ? 1 : 0;
        $new['on_fail'] = (isset($_POST['on_fail']) && $_POST['on_fail'] === 'fallback_silent') ? 'fallback_silent' : 'fallback';
        $new['engine'] = isset($_POST['engine']) ? (string) $_POST['engine'] : 'auto';
        if (!in_array($new['engine'], array('auto', 'imagick', 'gd', 'cwebp'), true)) {
            $new['engine'] = 'auto';
        }
        $new['cwebp_path'] = isset($_POST['cwebp_path']) ? trim((string) $_POST['cwebp_path']) : '';
        $new['log_enable'] = isset($_POST['log_enable']) ? 1 : 0;
        $new['batch_limit'] = $cfg['batch_limit'];
        $new['del_config'] = isset($_POST['del_config']) ? 1 : 0;

        AutoWebP_SaveConfig($new);
        $zbp->SetHint('good');
        Redirect('./main.php');
    }
}

$batch = AutoWebP_BatchSummary($cfg);
$logTail = AutoWebP_LogTail(15);

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
        <td><?php echo AutoWebP_H(PHP_VERSION); ?>（要求 7.0+，推荐 7.4 / 8.x）</td>
      </tr>
      <tr>
        <td>Imagick</td>
        <td><?php echo $engines['imagick'] ? '可用' : '不可用'; ?><?php echo $engines['imagick_version'] !== '' ? '（' . AutoWebP_H($engines['imagick_version']) . '）' : ''; ?></td>
      </tr>
      <tr>
        <td>GD（imagewebp）</td>
        <td><?php echo $engines['gd'] ? '可用' : '不可用'; ?><?php echo $engines['gd_version'] !== '' ? '（' . AutoWebP_H($engines['gd_version']) . '）' : ''; ?></td>
      </tr>
      <tr>
        <td>cwebp 命令行</td>
        <td><?php echo $engines['cwebp'] ? '可用' : '未配置或不可用'; ?><?php echo $engines['cwebp_version'] !== '' ? '（' . AutoWebP_H($engines['cwebp_version']) . '）' : ''; ?></td>
      </tr>
      <tr>
        <td>当前生效引擎</td>
        <td>
          <?php
          if (empty($engines['imagick']) && empty($engines['gd']) && empty($engines['cwebp'])) {
              echo '<b style="color:#c00;">无可用引擎：上传时不会转换，系统会继续保存原图（不会报致命错误）。</b>';
          } else {
              $names = array();
              if ($engines['imagick']) {
                  $names[] = 'Imagick';
              }
              if ($engines['gd']) {
                  $names[] = 'GD';
              }
              if ($engines['cwebp']) {
                  $names[] = 'cwebp';
              }
              echo AutoWebP_H(implode(' → ', $names)) . '（按配置顺序自动回退）';
          }
          ?>
        </td>
      </tr>
    </table>

    <form id="edit" name="edit" method="post" action="./main.php">
      <input type="hidden" name="csrfToken" value="<?php echo $zbp->GetCSRFToken(); ?>">
      <input type="hidden" name="autowebp_action" value="save">

      <table border="1" class="tableFull tableBorder tableBorder-thcenter">
        <tr>
          <th class="td25">配置项</th>
          <th>说明 / 值</th>
        </tr>
        <tr>
          <td>启用自动转换</td>
          <td><label><input type="checkbox" name="enable" value="1"<?php echo AutoWebP_Checked($cfg['enable']); ?>> 上传图片时自动转换为 WebP</label></td>
        </tr>
        <tr>
          <td>目标格式 / 质量</td>
          <td>
            <select name="format">
              <option value="lossy"<?php echo $cfg['format'] === 'lossy' ? ' selected="selected"' : ''; ?>>WebP 有损（推荐）</option>
              <option value="lossless"<?php echo $cfg['format'] === 'lossless' ? ' selected="selected"' : ''; ?>>WebP 无损（需 Imagick 或 cwebp）</option>
            </select>
            质量：<input type="number" name="quality" min="0" max="100" step="1" value="<?php echo AutoWebP_H($cfg['quality']); ?>" style="width:80px;">
            <span class="note">范围 0-100，建议 75-85；无损模式下质量参数无效。</span>
          </td>
        </tr>
        <tr>
          <td>保留原图</td>
          <td>
            <label><input type="checkbox" name="keep_original" value="1"<?php echo AutoWebP_Checked($cfg['keep_original']); ?>> 保留并另存原图（同名不同扩展名）</label>
            <div class="note">勾选：原图另存到同目录，文章引用 WebP；不勾选：仅保留 WebP。批量转换始终保留原文件，避免旧文章链接失效。</div>
          </td>
        </tr>
        <tr>
          <td>缩放</td>
          <td>
            <label><input type="checkbox" name="resize_enable" value="1"<?php echo AutoWebP_Checked($cfg['resize_enable']); ?>> 启用等比缩放</label>
            最大宽：<input type="number" name="max_width" min="0" max="20000" value="<?php echo AutoWebP_H($cfg['max_width']); ?>" style="width:90px;">
            最大高：<input type="number" name="max_height" min="0" max="20000" value="<?php echo AutoWebP_H($cfg['max_height']); ?>" style="width:90px;">
            <div class="note">填 0 表示该方向不限制；仅在超过限制时等比缩小，不放大。</div>
          </td>
        </tr>
        <tr>
          <td>跳过条件</td>
          <td>
            小于 <input type="number" name="skip_small_kb" min="0" max="102400" value="<?php echo AutoWebP_H($cfg['skip_small_kb']); ?>" style="width:80px;"> KB 不转换（0 = 不限制）<br>
            <label><input type="checkbox" name="skip_webp" value="1"<?php echo AutoWebP_Checked($cfg['skip_webp']); ?>> 已经是 WebP 的图片跳过</label><br>
            <label><input type="checkbox" name="skip_gif" value="1"<?php echo AutoWebP_Checked($cfg['skip_gif']); ?>> GIF 动图跳过（否则仅转换第一帧）</label><br>
            <span class="note">非图片类型（PDF、压缩包等）始终跳过。</span>
          </td>
        </tr>
        <tr>
          <td>转换失败时的行为</td>
          <td>
            <select name="on_fail">
              <option value="fallback"<?php echo $cfg['on_fail'] === 'fallback' ? ' selected="selected"' : ''; ?>>回退保留原图，并写错误日志</option>
              <option value="fallback_silent"<?php echo $cfg['on_fail'] === 'fallback_silent' ? ' selected="selected"' : ''; ?>>回退保留原图，不写日志</option>
            </select>
            <div class="note">任何情况下都不会中断上传；失败时按原图正常入库。</div>
          </td>
        </tr>
        <tr>
          <td>处理引擎</td>
          <td>
            <select name="engine">
              <option value="auto"<?php echo $cfg['engine'] === 'auto' ? ' selected="selected"' : ''; ?>>自动（Imagick → GD → cwebp）</option>
              <option value="imagick"<?php echo $cfg['engine'] === 'imagick' ? ' selected="selected"' : ''; ?>>优先 Imagick</option>
              <option value="gd"<?php echo $cfg['engine'] === 'gd' ? ' selected="selected"' : ''; ?>>优先 GD</option>
              <option value="cwebp"<?php echo $cfg['engine'] === 'cwebp' ? ' selected="selected"' : ''; ?>>优先 cwebp</option>
            </select>
            <div class="note">选择的引擎不可用时，会自动回退到其它可用引擎。</div>
          </td>
        </tr>
        <tr>
          <td>cwebp 路径（可选）</td>
          <td>
            <input type="text" name="cwebp_path" value="<?php echo AutoWebP_H($cfg['cwebp_path']); ?>" style="width:90%;" placeholder="例如 /usr/local/bin/cwebp 或 C:\webp\bin\cwebp.exe">
            <div class="note">仅当 Imagick / GD 都不可用或手动指定 cwebp 时使用；EXIF 方向需由 Imagick/GD 处理。</div>
          </td>
        </tr>
        <tr>
          <td>日志</td>
          <td>
            <label><input type="checkbox" name="log_enable" value="1"<?php echo AutoWebP_Checked($cfg['log_enable']); ?>> 记录成功转换与无引擎提示（失败日志受上一项控制）</label>
            <div class="note">日志文件：zb_users/plugin/AutoWebP/auto-webp.log，超过 512KB 自动轮转。</div>
          </td>
        </tr>
        <tr>
          <td>卸载行为</td>
          <td><label><input type="checkbox" name="del_config" value="1"<?php echo AutoWebP_Checked($cfg['del_config']); ?>> 停用/卸载插件时删除本插件配置</label></td>
        </tr>
      </table>

      <p>
        <input type="submit" class="button" value="保存配置">
      </p>
    </form>

    <div class="divHeader" style="margin-top:20px;">批量转换已有附件</div>
    <form method="post" action="./main.php" onsubmit="return window.confirm('将处理最多若干条已有图片附件，期间可能占用较多 CPU，是否继续？');">
      <input type="hidden" name="csrfToken" value="<?php echo $zbp->GetCSRFToken(); ?>">
      <input type="hidden" name="autowebp_action" value="batch">
      <p>
        每次处理条数：<input type="number" name="batch_limit" min="1" max="200" value="<?php echo AutoWebP_H($cfg['batch_limit']); ?>" style="width:80px;">
        <input type="submit" class="button" value="立即执行">
        <span class="note">可多次点击分批处理（自动记录进度，处理到最旧一条后自动重置）；只处理 image/* 类型的附件，跳过小于阈值、WebP 与动图（按上方配置）。当前进度：附件 ID ≤ <?php echo (int) $cfg['batch_last_id']; ?>。</span>
      </p>
    </form>

    <?php if ($batch !== null) { ?>
      <table border="1" class="tableFull tableBorder tableBorder-thcenter">
        <tr>
          <th class="td25">上次批量执行</th>
          <th>结果</th>
        </tr>
        <tr>
          <td><?php echo AutoWebP_H(isset($cfg['last_batch_time']) ? $cfg['last_batch_time'] : ''); ?></td>
          <td>
            扫描 <?php echo (int) $batch['scanned']; ?>，
            成功 <?php echo (int) $batch['converted']; ?>，
            跳过 <?php echo (int) $batch['skipped']; ?>，
            失败 <?php echo (int) $batch['failed']; ?>；
            体积 <?php echo (int) $batch['before']; ?> → <?php echo (int) $batch['after']; ?> 字节。
            <?php if (!empty($batch['message'])) { ?><br><span class="note"><?php echo AutoWebP_H($batch['message']); ?></span><?php } ?>
          </td>
        </tr>
      </table>
    <?php } ?>

    <?php if (!empty($logTail)) { ?>
      <div class="divHeader" style="margin-top:20px;">最近日志</div>
      <div style="background:#fff;border:1px solid #ddd;padding:8px;font-family:Consolas,monospace;font-size:12px;white-space:pre-wrap;max-height:240px;overflow:auto;"><?php
        foreach ($logTail as $line) {
            echo AutoWebP_H($line) . "\n";
        }
      ?></div>
    <?php } ?>

    <div style="margin-top:20px;padding:12px 14px;background:#f7f8fa;border:1px solid #e2e6ea;border-radius:4px;font-size:12px;color:#666;line-height:1.9;">
      <div style="font-size:13px;color:#333;font-weight:bold;margin-bottom:4px;">关于本插件</div>
      作者：青春记忆<br>
      作者网站：<a href="https://www.qchun.com" target="_blank" rel="noopener noreferrer" style="color:#2a6fbd;text-decoration:none;">https://www.qchun.com</a><br>
      <span style="color:#999;">图片自动转 WebP v1.0.0　感谢使用，欢迎反馈建议。</span>
    </div>

    <script type="text/javascript">ActiveLeftMenu("aPluginMng");</script>
    <script type="text/javascript">AddHeaderIcon("<?php echo $bloghost . 'zb_users/plugin/AutoWebP/logo.png'; ?>");</script>
  </div>
</div>
<?php
require $blogpath . 'zb_system/admin/admin_footer.php';

RunTime();
