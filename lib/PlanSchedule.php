<?php
/**
 * План-график размещения: нормализация данных и построение листа XLSX
 * в дизайне дорожной карты (ГК Сервис-Телеком).
 *
 * Структура плана (массив):
 *  issuer       — наименование эмитента (для имени файла и заголовка)
 *  scale        — 'day' (ежедневная шкала с датами) | 'week' (недели, как в шаблоне)
 *  startDate    — 'YYYY-MM-DD', первая колонка шкалы
 *  endDate      — 'YYYY-MM-DD' | null (авто: конец недели последнего мероприятия)
 *  numbering    — 'section' (в каждом разделе с 1) | 'global' (сквозная)
 *  showComments — bool, выводить столбец "Комментарий"
 *  holidays     — массив 'YYYY-MM-DD' нерабочих дней (кроме субботы/воскресенья)
 *  sections[]   — { title, tasks[] }
 *  tasks[]      — { name, responsible, comment, periods[] }
 *  periods[]    — { type: bar|milestone|tentative, start, end }
 *                 start/end — дата 'YYYY-MM-DD' или целое смещение в днях от startDate
 */
class PlanSchedule
{
    // Палитра (из файла-примера)
    const COLOR_HEADER      = '0070C0'; // заголовок таблицы
    const COLOR_HEADER_WKND = 'D5EDFF'; // заголовок выходного дня
    const COLOR_HEADER_WKND_FONT = '002060';
    const COLOR_SECTION     = 'DDEBF7'; // строка раздела (Accent 1, светлее 80%)
    const COLOR_WEEKEND     = 'DDEBF7'; // выходной в теле таблицы
    const COLOR_BAR         = 'BDD7EE'; // мероприятие (Accent 1, светлее 60%)
    const COLOR_MILESTONE   = '00B050'; // ключевое событие / веха
    const COLOR_BORDER      = 'BFBFBF'; // границы (белый, темнее 25%)
    const FONT              = 'Arial Narrow';
    const FONT_SIZE         = 9;

    const PERIOD_TYPES = 'bar,milestone,tentative';

    /** @var array */
    private $plan;
    /** @var array список ошибок валидации */
    private $errors = array();

    public function __construct(array $plan)
    {
        $this->plan = $this->normalize($plan);
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function getPlan()
    {
        return $this->plan;
    }

    // ------------------------------------------------------------ нормализация

    private static function str($v)
    {
        return trim(preg_replace('/\r\n?/', "\n", (string)$v));
    }

    private static function isDate($s)
    {
        return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s . ' UTC') !== false;
    }

    private static function addDays($ymd, $days)
    {
        return gmdate('Y-m-d', strtotime($ymd . ' UTC') + (int)$days * 86400);
    }

    private static function diffDays($from, $to)
    {
        return (int)round((strtotime($to . ' UTC') - strtotime($from . ' UTC')) / 86400);
    }

    /** 1 = понедельник ... 7 = воскресенье */
    private static function weekday($ymd)
    {
        return (int)gmdate('N', strtotime($ymd . ' UTC'));
    }

    private function normalize(array $p)
    {
        $out = array();
        $out['issuer'] = isset($p['issuer']) ? self::str($p['issuer']) : '';
        $out['scale'] = (isset($p['scale']) && $p['scale'] === 'week') ? 'week' : 'day';
        $out['numbering'] = (isset($p['numbering']) && $p['numbering'] === 'global') ? 'global' : 'section';
        $out['showComments'] = !empty($p['showComments']);

        if (isset($p['startDate']) && self::isDate($p['startDate'])) {
            $out['startDate'] = $p['startDate'];
        } else {
            $this->errors[] = 'Не указана дата начала плана';
            $out['startDate'] = gmdate('Y-m-d');
        }
        $out['endDate'] = (isset($p['endDate']) && self::isDate($p['endDate'])) ? $p['endDate'] : null;

        $out['holidays'] = array();
        if (!empty($p['holidays']) && is_array($p['holidays'])) {
            foreach ($p['holidays'] as $h) {
                $h = self::str($h);
                if (self::isDate($h)) {
                    $out['holidays'][$h] = true;
                }
            }
        }

        $out['sections'] = array();
        $sections = (isset($p['sections']) && is_array($p['sections'])) ? $p['sections'] : array();
        foreach ($sections as $si => $s) {
            if (!is_array($s)) continue;
            $sec = array('title' => isset($s['title']) ? self::str($s['title']) : '', 'tasks' => array());
            $tasks = (isset($s['tasks']) && is_array($s['tasks'])) ? $s['tasks'] : array();
            foreach ($tasks as $ti => $t) {
                if (!is_array($t)) continue;
                $task = array(
                    'name'        => isset($t['name']) ? self::str($t['name']) : '',
                    'responsible' => isset($t['responsible']) ? self::str($t['responsible']) : '',
                    'comment'     => isset($t['comment']) ? self::str($t['comment']) : '',
                    'periods'     => array(),
                );
                if ($task['name'] === '') {
                    $this->errors[] = sprintf('Раздел «%s»: мероприятие №%d без названия', $sec['title'], $ti + 1);
                }
                $periods = (isset($t['periods']) && is_array($t['periods'])) ? $t['periods'] : array();
                foreach ($periods as $pi => $per) {
                    if (!is_array($per)) continue;
                    $type = isset($per['type']) ? $per['type'] : 'bar';
                    if (!in_array($type, explode(',', self::PERIOD_TYPES), true)) {
                        $type = 'bar';
                    }
                    $start = $this->resolveDate(isset($per['start']) ? $per['start'] : null, $out['startDate']);
                    $end = $this->resolveDate(isset($per['end']) ? $per['end'] : null, $out['startDate']);
                    if ($start === null && $end === null) {
                        continue;
                    }
                    if ($start === null) $start = $end;
                    if ($end === null) $end = $start;
                    if ($start > $end) {
                        list($start, $end) = array($end, $start);
                    }
                    $task['periods'][] = array('type' => $type, 'start' => $start, 'end' => $end);
                }
                $sec['tasks'][] = $task;
            }
            $out['sections'][] = $sec;
        }
        if (!count($out['sections'])) {
            $this->errors[] = 'План не содержит ни одного раздела';
        }

        // Границы шкалы
        $minStart = $out['startDate'];
        $maxEnd = null;
        foreach ($out['sections'] as $s) {
            foreach ($s['tasks'] as $t) {
                foreach ($t['periods'] as $per) {
                    if ($maxEnd === null || $per['end'] > $maxEnd) $maxEnd = $per['end'];
                    if ($per['start'] < $minStart) $minStart = $per['start'];
                }
            }
        }
        if ($minStart < $out['startDate']) {
            $this->errors[] = 'Есть мероприятия, начинающиеся раньше даты начала плана (' . $out['startDate'] . ')';
        }
        if ($out['endDate'] === null || $out['endDate'] < $out['startDate']) {
            $end = ($maxEnd !== null && $maxEnd > $out['startDate']) ? $maxEnd : self::addDays($out['startDate'], 27);
            if ($out['scale'] === 'day') {
                // дотягиваем до воскресенья, как в примере
                $end = self::addDays($end, 7 - self::weekday($end));
            } else {
                $weeks = (int)ceil((self::diffDays($out['startDate'], $end) + 1) / 7);
                $end = self::addDays($out['startDate'], $weeks * 7 - 1);
            }
            $out['endDate'] = $end;
        } elseif ($out['scale'] === 'week') {
            $weeks = (int)ceil((self::diffDays($out['startDate'], $out['endDate']) + 1) / 7);
            $out['endDate'] = self::addDays($out['startDate'], $weeks * 7 - 1);
        }
        if (self::diffDays($out['startDate'], $out['endDate']) > 1100) {
            $this->errors[] = 'Слишком длинный период плана (более 3 лет)';
        }
        return $out;
    }

    private function resolveDate($v, $startDate)
    {
        if ($v === null || $v === '') return null;
        if (is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v)) || is_float($v)) {
            return self::addDays($startDate, (int)$v);
        }
        $v = self::str($v);
        if (self::isDate($v)) return $v;
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $v, $m)) {
            $d = $m[3] . '-' . $m[2] . '-' . $m[1];
            if (self::isDate($d)) return $d;
        }
        $this->errors[] = 'Некорректная дата: ' . $v;
        return null;
    }

    // ------------------------------------------------------------ вспомогательное

    private function isNonWorking($ymd)
    {
        return self::weekday($ymd) >= 6 || isset($this->plan['holidays'][$ymd]);
    }

    /** Оценка высоты строки для текста в столбце заданной ширины (Arial Narrow 9). */
    private static function estimateLines($text, $colWidth)
    {
        if ($text === '') return 1;
        $perLine = max(8, (int)floor($colWidth * 1.45));
        $lines = 0;
        foreach (explode("\n", $text) as $para) {
            $len = function_exists('mb_strlen') ? mb_strlen($para, 'UTF-8') : strlen(utf8_decode($para));
            $lines += max(1, (int)ceil($len / $perLine));
        }
        return $lines;
    }

    private static function rowHeightForLines($lines)
    {
        return $lines <= 1 ? 15 : $lines * 12 + 3;
    }

    /** Имя файла для выгрузки. */
    public function fileName()
    {
        $issuer = $this->plan['issuer'] !== '' ? $this->plan['issuer'] : 'план';
        $issuer = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $issuer);
        $issuer = trim(preg_replace('/\s+/u', ' ', $issuer));
        $date = gmdate('d.m.Y', strtotime($this->plan['startDate'] . ' UTC'));
        return 'План-график ' . $issuer . ' ' . $date . '.xlsx';
    }

    /** Транслитерация в латиницу (для ASCII-имени файла). */
    public static function translit($s)
    {
        static $map = array(
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y',
            'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f',
            'х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
            'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'E','Ж'=>'Zh','З'=>'Z','И'=>'I','Й'=>'Y',
            'К'=>'K','Л'=>'L','М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F',
            'Х'=>'H','Ц'=>'C','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Sch','Ъ'=>'','Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'Yu','Я'=>'Ya',
        );
        $s = strtr($s, $map);
        $s = preg_replace('/[^\x20-\x7E]/', '_', $s);
        return preg_replace('/_+/', '_', $s);
    }

    // ------------------------------------------------------------ построение

    /** @return XlsxWriter */
    public function build()
    {
        $plan = $this->plan;
        $isDay = $plan['scale'] === 'day';
        $xw = new XlsxWriter('Дорожная карта');

        $font = array('name' => self::FONT, 'size' => self::FONT_SIZE, 'color' => '000000');
        $fontBold = array_merge($font, array('bold' => true));
        $border = array('color' => self::COLOR_BORDER, 'sides' => 'LRTB');

        // --- стили
        $stHeader = $xw->addStyle(array(
            'font' => array_merge($fontBold, array('color' => 'FFFFFF')),
            'fill' => array('pattern' => 'solid', 'fg' => self::COLOR_HEADER),
            'border' => $border,
            'align' => array('h' => 'center', 'v' => 'center', 'wrap' => true),
        ));
        $stHeaderLeft = $xw->addStyle(array(
            'font' => array_merge($fontBold, array('color' => 'FFFFFF')),
            'fill' => array('pattern' => 'solid', 'fg' => self::COLOR_HEADER),
            'border' => $border,
            'align' => array('h' => 'left', 'v' => 'center', 'wrap' => true, 'indent' => 1),
        ));
        $dateFmt = '[$-419]d\ mmm;@';
        $stHeaderDate = $xw->addStyle(array(
            'font' => array_merge($fontBold, array('color' => 'FFFFFF')),
            'fill' => array('pattern' => 'solid', 'fg' => self::COLOR_HEADER),
            'border' => $border,
            'align' => array('h' => 'center', 'v' => 'center', 'wrap' => true, 'rotation' => $isDay ? 90 : 0),
            'numFmt' => $isDay ? $dateFmt : 'General',
        ));
        $stHeaderWeekend = $xw->addStyle(array(
            'font' => array_merge($fontBold, array('color' => self::COLOR_HEADER_WKND_FONT)),
            'fill' => array('pattern' => 'solid', 'fg' => self::COLOR_HEADER_WKND),
            'border' => $border,
            'align' => array('h' => 'center', 'v' => 'center', 'wrap' => true, 'rotation' => 90),
            'numFmt' => $dateFmt,
        ));
        $stSection = $xw->addStyle(array(
            'font' => $fontBold,
            'fill' => array('pattern' => 'solid', 'fg' => self::COLOR_SECTION),
            'border' => $border,
            'align' => array('h' => 'left', 'v' => 'center', 'wrap' => true, 'indent' => 1),
        ));
        $stSectionCell = $xw->addStyle(array(
            'font' => $fontBold,
            'fill' => array('pattern' => 'solid', 'fg' => self::COLOR_SECTION),
            'border' => $border,
        ));
        $stNum = $xw->addStyle(array(
            'font' => $font, 'border' => $border,
            'align' => array('h' => 'center', 'v' => 'center', 'wrap' => true),
        ));
        $stName = $xw->addStyle(array(
            'font' => $font, 'border' => $border,
            'align' => array('h' => 'left', 'v' => 'center', 'wrap' => true),
        ));
        $stResp = $xw->addStyle(array(
            'font' => $font, 'border' => $border,
            'align' => array('h' => 'center', 'v' => 'center', 'wrap' => true),
        ));
        $stComment = $xw->addStyle(array(
            'font' => $font, 'border' => $border,
            'align' => array('h' => 'left', 'v' => 'center', 'wrap' => true),
        ));
        $stEmpty = $xw->addStyle(array('font' => $font, 'border' => $border));
        $stWeekend = $xw->addStyle(array(
            'font' => $font, 'border' => $border,
            'fill' => array('pattern' => 'solid', 'fg' => self::COLOR_WEEKEND),
        ));
        $stPeriod = array(
            'bar' => $xw->addStyle(array(
                'font' => $font, 'border' => $border,
                'fill' => array('pattern' => 'solid', 'fg' => self::COLOR_BAR),
            )),
            'milestone' => $xw->addStyle(array(
                'font' => $font, 'border' => $border,
                'fill' => array('pattern' => 'solid', 'fg' => self::COLOR_MILESTONE),
            )),
            'tentative' => $xw->addStyle(array(
                'font' => $font, 'border' => $border,
                'fill' => array('pattern' => 'lightUp', 'fg' => 'FFFFFF', 'bg' => self::COLOR_BAR),
            )),
        );

        // --- колонки
        $colNum = 1; $colName = 2; $colResp = 3; $colFirst = 4;
        $totalDays = self::diffDays($plan['startDate'], $plan['endDate']) + 1;
        $nCols = $isDay ? $totalDays : (int)ceil($totalDays / 7);
        $colLast = $colFirst + $nCols - 1;
        $colComment = 0;
        if ($plan['showComments']) {
            $colComment = $colLast + 2; // узкий разделитель + комментарий
        }
        $lastCol = $colComment ? $colComment : $colLast;

        $widthName = 72.57; $widthResp = 34.71; $widthComment = 45;
        $xw->setColWidth($colNum, 5.57);
        $xw->setColWidth($colName, $widthName);
        $xw->setColWidth($colResp, $widthResp);
        for ($c = $colFirst; $c <= $colLast; $c++) {
            $xw->setColWidth($c, $isDay ? 3.71 : 7.4);
        }
        if ($colComment) {
            $xw->setColWidth($colLast + 1, 2.57);
            $xw->setColWidth($colComment, $widthComment);
        }

        // --- заголовок
        $row = 1;
        $xw->setRowHeight($row, 38.25);
        $xw->writeString($row, $colNum, '№', $stHeader);
        $xw->writeString($row, $colName, 'Мероприятие', $stHeaderLeft);
        $xw->writeString($row, $colResp, 'Ответственный', $stHeader);
        $colDates = array(); // col => дата начала периода колонки
        for ($i = 0; $i < $nCols; $i++) {
            $c = $colFirst + $i;
            $date = self::addDays($plan['startDate'], $isDay ? $i : $i * 7);
            $colDates[$c] = $date;
            if ($isDay) {
                $style = $this->isNonWorking($date) ? $stHeaderWeekend : $stHeaderDate;
                if ($i === 0) {
                    $xw->writeNumber($row, $c, XlsxWriter::dateSerial($date), $style);
                } else {
                    $xw->writeFormula($row, $c, XlsxWriter::cellRef($row, $c - 1) . '+1', XlsxWriter::dateSerial($date), $style);
                }
            } else {
                if ($i === 0) {
                    $xw->writeNumber($row, $c, 1, $stHeaderDate);
                } else {
                    $xw->writeFormula($row, $c, XlsxWriter::cellRef($row, $c - 1) . '+1', $i + 1, $stHeaderDate);
                }
            }
        }
        if ($colComment) {
            $xw->writeBlank($row, $colLast + 1, 0);
            $xw->writeString($row, $colComment, 'Комментарий', $stHeader);
        }

        // --- тело
        $globalNo = 0;
        foreach ($plan['sections'] as $sec) {
            $row++;
            $xw->writeString($row, $colNum, $sec['title'], $stSection);
            $xw->writeBlank($row, $colName, $stSectionCell);
            $xw->mergeCells($row, $colNum, $row, $colName);
            for ($c = $colResp; $c <= $colLast; $c++) {
                $xw->writeBlank($row, $c, $stSectionCell);
            }
            if ($colComment) {
                $xw->writeBlank($row, $colComment, $stSectionCell);
            }
            $lines = self::estimateLines($sec['title'], 5.57 + $widthName);
            $xw->setRowHeight($row, max(16.5, self::rowHeightForLines($lines) + 1.5));

            $localNo = 0;
            foreach ($sec['tasks'] as $task) {
                $row++;
                $localNo++; $globalNo++;
                $xw->writeNumber($row, $colNum, $plan['numbering'] === 'global' ? $globalNo : $localNo, $stNum);
                $xw->writeString($row, $colName, $task['name'], $stName);
                $xw->writeString($row, $colResp, $task['responsible'], $stResp);

                // карта заливок: col => style
                $fills = array();
                foreach ($task['periods'] as $per) {
                    foreach ($colDates as $c => $d) {
                        $cellEnd = $isDay ? $d : self::addDays($d, 6);
                        if ($per['start'] <= $cellEnd && $per['end'] >= $d) {
                            // веха имеет приоритет над остальными типами
                            if (!isset($fills[$c]) || $per['type'] === 'milestone') {
                                $fills[$c] = $stPeriod[$per['type']];
                            }
                        }
                    }
                }
                foreach ($colDates as $c => $d) {
                    if ($isDay && $this->isNonWorking($d)) {
                        $xw->writeBlank($row, $c, $stWeekend);
                    } elseif (isset($fills[$c])) {
                        $xw->writeBlank($row, $c, $fills[$c]);
                    } else {
                        $xw->writeBlank($row, $c, $stEmpty);
                    }
                }
                $lines = max(
                    self::estimateLines($task['name'], $widthName),
                    self::estimateLines($task['responsible'], $widthResp)
                );
                if ($colComment) {
                    $xw->writeString($row, $colComment, $task['comment'], $stComment);
                    $lines = max($lines, self::estimateLines($task['comment'], $widthComment));
                }
                $xw->setRowHeight($row, self::rowHeightForLines($lines));
            }
        }

        // --- вид и печать
        $xw->freeze(2, $colFirst);
        $xw->setGridLines(false);
        $xw->setPageSetup(true, 8, 1, 0); // A3, альбомная, по ширине страницы
        $xw->setPrintTitleRows(1, 1);
        return $xw;
    }
}
