<?php
/**
 * 統一錯誤頁：production 模式下 display_errors 是關的（避免對外洩漏路徑/結構），
 * 但這是登入後才看得到的內部後台，所以改成顯示白話的錯誤說明，方便回報，
 * 完整技術細節仍然會照舊寫進 PHP 的 error_log（docker logs 看得到）。
 */

function rm_render_error(string $message, string $file = '', int $line = 0): void
{
    http_response_code(500);

    $isApi = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false)
        || (isset($_GET['p']) && strpos($_GET['p'], 'api/') === 0)
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

    if ($isApi) {
        header('Content-Type: application/json');
        echo json_encode([
            'status'  => 'error',
            'message' => $message,
        ]);
        return;
    }

    $time = date('Y-m-d H:i:s');
    echo '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>系統發生錯誤</title></head>'
        . '<body style="font-family:-apple-system,sans-serif;max-width:640px;margin:60px auto;padding:0 20px;color:#333;">';
    echo '<h2 style="color:#c0392b;">⚠️ 系統發生錯誤</h2>';
    echo '<p>操作沒有完成，請把這個畫面截圖回報。</p>';
    echo '<div style="background:#f8f9fa;border:1px solid #ddd;border-radius:6px;padding:14px 18px;margin:16px 0;font-size:0.9rem;">';
    echo '<div><strong>錯誤訊息：</strong>' . htmlspecialchars($message) . '</div>';
    if ($file !== '') {
        echo '<div style="margin-top:6px;"><strong>位置：</strong>' . htmlspecialchars($file) . ':' . $line . '</div>';
    }
    echo '<div style="margin-top:6px;color:#888;"><strong>時間：</strong>' . $time . '</div>';
    echo '</div>';
    echo '<p><a href="index.php">返回首頁</a></p>';
    echo '</body></html>';
}

set_exception_handler(function (Throwable $e) {
    error_log('[Uncaught] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    rm_render_error($e->getMessage(), $e->getFile(), $e->getLine());
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        rm_render_error($err['message'], $err['file'], $err['line']);
    }
});
