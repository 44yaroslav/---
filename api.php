<?php
/**
 * JSON API конструктора: пресеты и сохранённые планы (каталог data/).
 *   GET  api.php?action=presets          — список пресетов
 *   GET  api.php?action=preset&id=...    — содержимое пресета
 *   GET  api.php?action=list             — список сохранённых планов
 *   GET  api.php?action=load&id=...      — загрузить план
 *   POST api.php?action=save  (id?, plan) — сохранить план (id — необязательный)
 *   POST api.php?action=delete (id)      — удалить план
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

define('PRESET_DIR', __DIR__ . '/presets');
define('DATA_DIR', __DIR__ . '/data');

function respond($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail($msg, $code = 400)
{
    respond(array('error' => $msg), $code);
}

function safeId($id)
{
    $id = (string)$id;
    return preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $id) ? $id : null;
}

function listDir($dir)
{
    $out = array();
    foreach (glob($dir . '/*.json') as $file) {
        $json = json_decode(file_get_contents($file), true);
        if (!is_array($json)) continue;
        $out[] = array(
            'id' => basename($file, '.json'),
            'name' => isset($json['name']) ? $json['name'] : basename($file, '.json'),
            'issuer' => isset($json['issuer']) ? $json['issuer'] : '',
            'updated' => date('d.m.Y H:i', filemtime($file)),
            'mtime' => filemtime($file),
        );
    }
    return $out;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

switch ($action) {
    case 'presets':
        $list = listDir(PRESET_DIR);
        usort($list, function ($a, $b) { return strcmp($a['id'], $b['id']); });
        respond(array('items' => $list));
        break;

    case 'preset':
        $id = safeId(isset($_GET['id']) ? $_GET['id'] : '');
        if ($id === null || !is_file(PRESET_DIR . '/' . $id . '.json')) fail('Пресет не найден', 404);
        readfile(PRESET_DIR . '/' . $id . '.json');
        exit;

    case 'list':
        $list = listDir(DATA_DIR);
        usort($list, function ($a, $b) { return $b['mtime'] - $a['mtime']; });
        respond(array('items' => $list));
        break;

    case 'load':
        $id = safeId(isset($_GET['id']) ? $_GET['id'] : '');
        if ($id === null || !is_file(DATA_DIR . '/' . $id . '.json')) fail('План не найден', 404);
        readfile(DATA_DIR . '/' . $id . '.json');
        exit;

    case 'save':
        if (!$isPost) fail('Требуется POST', 405);
        $plan = json_decode(isset($_POST['plan']) ? $_POST['plan'] : '', true);
        if (!is_array($plan)) fail('Некорректный JSON плана');
        if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0775, true)) fail('Каталог data/ недоступен для записи', 500);
        if (!is_writable(DATA_DIR)) fail('Каталог data/ недоступен для записи', 500);
        $id = safeId(isset($_POST['id']) ? $_POST['id'] : '');
        if ($id === null) {
            $id = date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6);
        }
        if (empty($plan['name'])) {
            $plan['name'] = !empty($plan['issuer']) ? 'План-график ' . $plan['issuer'] : 'План-график от ' . date('d.m.Y');
        }
        $ok = file_put_contents(DATA_DIR . '/' . $id . '.json', json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        if ($ok === false) fail('Не удалось сохранить файл', 500);
        respond(array('id' => $id, 'name' => $plan['name']));
        break;

    case 'delete':
        if (!$isPost) fail('Требуется POST', 405);
        $id = safeId(isset($_POST['id']) ? $_POST['id'] : '');
        if ($id === null || !is_file(DATA_DIR . '/' . $id . '.json')) fail('План не найден', 404);
        @unlink(DATA_DIR . '/' . $id . '.json');
        respond(array('ok' => true));
        break;

    default:
        fail('Неизвестное действие');
}
