<?php
/**
 * Выгрузка план-графика из JSON в XLSX из командной строки.
 *   php bin/export-cli.php plan.json [out.xlsx]
 * В JSON допускаются как даты, так и смещения в днях (пресеты из каталога presets/).
 * Для пресета без startDate дата начала берётся из аргумента --start=YYYY-MM-DD
 * (по умолчанию — ближайший понедельник).
 */
require __DIR__ . '/../lib/XlsxWriter.php';
require __DIR__ . '/../lib/PlanSchedule.php';

$args = array_slice($argv, 1);
$opts = array();
foreach ($args as $k => $a) {
    if (strpos($a, '--') === 0) {
        $pair = explode('=', substr($a, 2), 2);
        $opts[$pair[0]] = isset($pair[1]) ? $pair[1] : true;
        unset($args[$k]);
    }
}
$args = array_values($args);
if (!count($args)) {
    fwrite(STDERR, "Использование: php bin/export-cli.php plan.json [out.xlsx] [--start=YYYY-MM-DD]\n");
    exit(1);
}
$json = file_get_contents($args[0]);
$plan = json_decode($json, true);
if (!is_array($plan)) {
    fwrite(STDERR, "Не удалось прочитать JSON: " . $args[0] . "\n");
    exit(1);
}
if (empty($plan['startDate'])) {
    $plan['startDate'] = isset($opts['start']) ? $opts['start'] : date('Y-m-d', strtotime('next monday'));
}
$ps = new PlanSchedule($plan);
foreach ($ps->getErrors() as $e) {
    fwrite(STDERR, "Предупреждение: $e\n");
}
$out = isset($args[1]) ? $args[1] : $ps->fileName();
$ps->build()->save($out);
echo "Записан файл: $out\n";
