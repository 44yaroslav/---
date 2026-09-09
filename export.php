<?php
/**
 * Выгрузка план-графика в XLSX.
 * Принимает POST-параметр plan (JSON) либо JSON в теле запроса.
 */
require __DIR__ . '/lib/XlsxWriter.php';
require __DIR__ . '/lib/PlanSchedule.php';

header('X-Content-Type-Options: nosniff');

$raw = isset($_POST['plan']) ? $_POST['plan'] : file_get_contents('php://input');
$plan = json_decode($raw, true);
if (!is_array($plan)) {
    header('Content-Type: text/plain; charset=utf-8', true, 400);
    echo 'Некорректные данные плана (ожидается JSON)';
    exit;
}

$ps = new PlanSchedule($plan);
$errors = $ps->getErrors();

// Режим проверки: только валидация, без формирования файла
if (isset($_GET['validate'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(count($errors) ? array('errors' => $errors) : array('ok' => true), JSON_UNESCAPED_UNICODE);
    exit;
}
if (count($errors) && empty($plan['force'])) {
    header('Content-Type: text/html; charset=utf-8', true, 422);
    echo '<!DOCTYPE html><meta charset="utf-8"><title>План-график: замечания</title>'
        . '<body style="font-family:Arial,sans-serif;padding:24px"><h2>Файл не сформирован</h2><ul>';
    foreach ($errors as $e) {
        echo '<li>' . htmlspecialchars($e, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '</ul><p>Исправьте замечания в конструкторе или повторите выгрузку с подтверждением.</p></body>';
    exit;
}

try {
    $data = $ps->build()->toString();
} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    echo 'Ошибка формирования файла: ' . $e->getMessage();
    exit;
}

$name = $ps->fileName();
$ascii = PlanSchedule::translit($name);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . strlen($data));
header('Cache-Control: no-store');
echo $data;
