<?php
/**
 * Минимальный писатель XLSX (Office Open XML) без внешних зависимостей.
 * Совместим с PHP 5.6 (требуется расширение zip).
 *
 * Поддерживает: строки/числа/формулы, стили (шрифт, заливка, границы,
 * выравнивание, числовой формат), ширины столбцов, высоты строк,
 * объединение ячеек, закрепление областей, параметры печати.
 */
class XlsxWriter
{
    /** @var string */
    private $sheetName = 'Лист1';
    /** @var array  row => col => array(type, value, style[, cached]) */
    private $cells = array();
    /** @var array col => width */
    private $colWidths = array();
    /** @var array row => height */
    private $rowHeights = array();
    /** @var array */
    private $merges = array();
    /** @var array  строки общего пула */
    private $sharedStrings = array();
    private $sharedIndex = array();

    private $fonts = array();
    private $fills = array();
    private $borders = array();
    private $numFmts = array();
    private $xfs = array();
    private $xfIndex = array();

    private $freezeRow = 0;
    private $freezeCol = 0;
    private $showGridLines = true;
    private $landscape = true;
    private $paperSize = 9;      // 9 = A4, 8 = A3
    private $fitToWidth = 1;
    private $fitToHeight = 0;
    private $printTitleRows = null; // например "1:1"
    private $margins = array('left' => 0.25, 'right' => 0.25, 'top' => 0.75, 'bottom' => 0.75, 'header' => 0.3, 'footer' => 0.3);
    private $maxRow = 0;
    private $maxCol = 0;

    public function __construct($sheetName = null)
    {
        if ($sheetName !== null) {
            $this->sheetName = $sheetName;
        }
        // Обязательные базовые записи в стилях
        $this->fonts[]   = '<font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/><family val="2"/></font>';
        $this->fills[]   = '<fill><patternFill patternType="none"/></fill>';
        $this->fills[]   = '<fill><patternFill patternType="gray125"/></fill>';
        $this->borders[] = '<border><left/><right/><top/><bottom/><diagonal/></border>';
        $this->xfs[]     = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
        $this->xfIndex['default'] = 0;
    }

    // ------------------------------------------------------------ утилиты

    /** Буква столбца по номеру (1 => A). */
    public static function colLetter($col)
    {
        $s = '';
        while ($col > 0) {
            $m = ($col - 1) % 26;
            $s = chr(65 + $m) . $s;
            $col = (int)(($col - $m - 1) / 26);
        }
        return $s;
    }

    public static function cellRef($row, $col)
    {
        return self::colLetter($col) . $row;
    }

    /** Порядковый номер даты Excel (1900-система) для даты YYYY-MM-DD. */
    public static function dateSerial($ymd)
    {
        $t = strtotime($ymd . ' UTC');
        if ($t === false) {
            return null;
        }
        return (int)floor($t / 86400) + 25569;
    }

    private static function esc($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // ------------------------------------------------------------ стили

    /**
     * Регистрирует стиль и возвращает его индекс.
     * $spec = array(
     *   'font'   => array('name'=>'Arial Narrow','size'=>9,'bold'=>true,'color'=>'FFFFFF','italic'=>false,'underline'=>false),
     *   'fill'   => array('pattern'=>'solid','fg'=>'0070C0') | array('pattern'=>'lightUp','fg'=>'FFFFFF','bg'=>'BDD7EE'),
     *   'border' => array('color'=>'BFBFBF','sides'=>'LRTB'),
     *   'align'  => array('h'=>'center','v'=>'center','wrap'=>true,'rotation'=>90),
     *   'numFmt' => '[$-419]d\ mmm;@'
     * )
     */
    public function addStyle(array $spec)
    {
        $key = serialize($spec);
        if (isset($this->xfIndex[$key])) {
            return $this->xfIndex[$key];
        }

        $fontId = 0;
        if (!empty($spec['font'])) {
            $f = $spec['font'];
            $xml = '<font>';
            if (!empty($f['bold'])) $xml .= '<b/>';
            if (!empty($f['italic'])) $xml .= '<i/>';
            if (!empty($f['underline'])) $xml .= '<u/>';
            $xml .= '<sz val="' . (isset($f['size']) ? $f['size'] : 11) . '"/>';
            $xml .= '<color rgb="FF' . strtoupper(isset($f['color']) ? $f['color'] : '000000') . '"/>';
            $xml .= '<name val="' . self::esc(isset($f['name']) ? $f['name'] : 'Calibri') . '"/><family val="2"/><charset val="204"/>';
            $xml .= '</font>';
            $fontId = $this->indexOf($this->fonts, $xml);
        }

        $fillId = 0;
        if (!empty($spec['fill'])) {
            $fl = $spec['fill'];
            $pattern = isset($fl['pattern']) ? $fl['pattern'] : 'solid';
            $xml = '<fill><patternFill patternType="' . $pattern . '">';
            if (isset($fl['fg'])) $xml .= '<fgColor rgb="FF' . strtoupper($fl['fg']) . '"/>';
            if (isset($fl['bg'])) {
                $xml .= '<bgColor rgb="FF' . strtoupper($fl['bg']) . '"/>';
            } else {
                $xml .= '<bgColor indexed="64"/>';
            }
            $xml .= '</patternFill></fill>';
            $fillId = $this->indexOf($this->fills, $xml);
        }

        $borderId = 0;
        if (!empty($spec['border'])) {
            $b = $spec['border'];
            $color = isset($b['color']) ? strtoupper($b['color']) : '000000';
            $sides = isset($b['sides']) ? strtoupper($b['sides']) : 'LRTB';
            $style = isset($b['style']) ? $b['style'] : 'thin';
            $xml = '<border>';
            foreach (array('L' => 'left', 'R' => 'right', 'T' => 'top', 'B' => 'bottom') as $k => $tag) {
                if (strpos($sides, $k) !== false) {
                    $xml .= '<' . $tag . ' style="' . $style . '"><color rgb="FF' . $color . '"/></' . $tag . '>';
                } else {
                    $xml .= '<' . $tag . '/>';
                }
            }
            $xml .= '<diagonal/></border>';
            $borderId = $this->indexOf($this->borders, $xml);
        }

        $numFmtId = 0;
        if (!empty($spec['numFmt'])) {
            $code = $spec['numFmt'];
            $builtin = array('General' => 0, '0' => 1, '0.00' => 2, 'dd.mm.yyyy' => 14);
            if (isset($builtin[$code])) {
                $numFmtId = $builtin[$code];
            } else {
                $pos = array_search($code, $this->numFmts, true);
                if ($pos === false) {
                    $this->numFmts[] = $code;
                    $pos = count($this->numFmts) - 1;
                }
                $numFmtId = 164 + $pos;
            }
        }

        $xf = '<xf numFmtId="' . $numFmtId . '" fontId="' . $fontId . '" fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0"';
        if ($numFmtId) $xf .= ' applyNumberFormat="1"';
        if ($fontId) $xf .= ' applyFont="1"';
        if ($fillId) $xf .= ' applyFill="1"';
        if ($borderId) $xf .= ' applyBorder="1"';
        if (!empty($spec['align'])) {
            $a = $spec['align'];
            $xf .= ' applyAlignment="1"><alignment';
            if (!empty($a['h'])) $xf .= ' horizontal="' . $a['h'] . '"';
            if (!empty($a['v'])) $xf .= ' vertical="' . $a['v'] . '"';
            if (!empty($a['rotation'])) $xf .= ' textRotation="' . (int)$a['rotation'] . '"';
            if (!empty($a['wrap'])) $xf .= ' wrapText="1"';
            if (!empty($a['indent'])) $xf .= ' indent="' . (int)$a['indent'] . '"';
            $xf .= '/></xf>';
        } else {
            $xf .= '/>';
        }
        $this->xfs[] = $xf;
        $id = count($this->xfs) - 1;
        $this->xfIndex[$key] = $id;
        return $id;
    }

    private function indexOf(array &$list, $xml)
    {
        $pos = array_search($xml, $list, true);
        if ($pos === false) {
            $list[] = $xml;
            $pos = count($list) - 1;
        }
        return $pos;
    }

    // ------------------------------------------------------------ данные

    private function track($row, $col)
    {
        if ($row > $this->maxRow) $this->maxRow = $row;
        if ($col > $this->maxCol) $this->maxCol = $col;
    }

    public function writeString($row, $col, $value, $style = 0)
    {
        $value = (string)$value;
        if ($value === '') {
            return $this->writeBlank($row, $col, $style);
        }
        if (!isset($this->sharedIndex[$value])) {
            $this->sharedStrings[] = $value;
            $this->sharedIndex[$value] = count($this->sharedStrings) - 1;
        }
        $this->cells[$row][$col] = array('s', $this->sharedIndex[$value], $style);
        $this->track($row, $col);
    }

    public function writeNumber($row, $col, $value, $style = 0)
    {
        $this->cells[$row][$col] = array('n', $value, $style);
        $this->track($row, $col);
    }

    /** Формула без ведущего "=", с кэшированным числовым значением. */
    public function writeFormula($row, $col, $formula, $cached, $style = 0)
    {
        $this->cells[$row][$col] = array('f', $formula, $style, $cached);
        $this->track($row, $col);
    }

    public function writeBlank($row, $col, $style = 0)
    {
        $this->cells[$row][$col] = array('b', null, $style);
        $this->track($row, $col);
    }

    public function setColWidth($col, $width)
    {
        $this->colWidths[$col] = $width;
    }

    public function setRowHeight($row, $height)
    {
        $this->rowHeights[$row] = $height;
    }

    public function mergeCells($row1, $col1, $row2, $col2)
    {
        $this->merges[] = self::cellRef($row1, $col1) . ':' . self::cellRef($row2, $col2);
    }

    /** Закрепить строки выше $row и столбцы левее $col (индексы первой незакреплённой ячейки). */
    public function freeze($row, $col)
    {
        $this->freezeRow = $row - 1;
        $this->freezeCol = $col - 1;
    }

    public function setGridLines($show)
    {
        $this->showGridLines = (bool)$show;
    }

    public function setPageSetup($landscape, $paperSize, $fitToWidth, $fitToHeight)
    {
        $this->landscape = (bool)$landscape;
        $this->paperSize = (int)$paperSize;
        $this->fitToWidth = (int)$fitToWidth;
        $this->fitToHeight = (int)$fitToHeight;
    }

    public function setPrintTitleRows($from, $to)
    {
        $this->printTitleRows = '$' . (int)$from . ':$' . (int)$to;
    }

    // ------------------------------------------------------------ вывод

    /** Возвращает содержимое файла XLSX в виде строки. */
    public function toString()
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->save($tmp);
        $data = file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    public function save($path)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Расширение PHP zip не установлено');
        }
        $zip = new ZipArchive();
        if (file_exists($path)) {
            @unlink($path);
        }
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Не удалось создать файл ' . $path);
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->relsXml());
        $zip->addFromString('docProps/app.xml', $this->appXml());
        $zip->addFromString('docProps/core.xml', $this->coreXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStringsXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml());
        $zip->close();
    }

    private function contentTypesXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function relsXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function appXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Microsoft Excel</Application></Properties>';
    }

    private function coreXml()
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>План-график</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function workbookXml()
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<workbookPr/><bookViews><workbookView xWindow="0" yWindow="0" windowWidth="22260" windowHeight="12645"/></bookViews>'
            . '<sheets><sheet name="' . self::esc($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets>';
        if ($this->printTitleRows !== null) {
            $xml .= '<definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">'
                . self::esc("'" . str_replace("'", "''", $this->sheetName) . "'!" . $this->printTitleRows)
                . '</definedName></definedNames>';
        }
        $xml .= '<calcPr calcId="162913" fullCalcOnLoad="1"/></workbook>';
        return $xml;
    }

    private function workbookRelsXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';
    }

    private function stylesXml()
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if (count($this->numFmts)) {
            $xml .= '<numFmts count="' . count($this->numFmts) . '">';
            foreach ($this->numFmts as $i => $code) {
                $xml .= '<numFmt numFmtId="' . (164 + $i) . '" formatCode="' . self::esc($code) . '"/>';
            }
            $xml .= '</numFmts>';
        }
        $xml .= '<fonts count="' . count($this->fonts) . '">' . implode('', $this->fonts) . '</fonts>';
        $xml .= '<fills count="' . count($this->fills) . '">' . implode('', $this->fills) . '</fills>';
        $xml .= '<borders count="' . count($this->borders) . '">' . implode('', $this->borders) . '</borders>';
        $xml .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        $xml .= '<cellXfs count="' . count($this->xfs) . '">' . implode('', $this->xfs) . '</cellXfs>';
        $xml .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
        $xml .= '<dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>';
        $xml .= '</styleSheet>';
        return $xml;
    }

    private function sharedStringsXml()
    {
        $n = count($this->sharedStrings);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $n . '" uniqueCount="' . $n . '">';
        foreach ($this->sharedStrings as $s) {
            $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
            $attr = (preg_match('/^\s|\s$|\n/', $s)) ? ' xml:space="preserve"' : '';
            $xml .= '<si><t' . $attr . '>' . self::esc($s) . '</t></si>';
        }
        $xml .= '</sst>';
        return $xml;
    }

    private function sheetXml()
    {
        $maxRow = max(1, $this->maxRow);
        $maxCol = max(1, $this->maxCol);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<dimension ref="A1:' . self::cellRef($maxRow, $maxCol) . '"/>'
            . '<sheetViews><sheetView' . ($this->showGridLines ? '' : ' showGridLines="0"') . ' tabSelected="1" zoomScaleNormal="100" workbookViewId="0">';
        if ($this->freezeRow > 0 || $this->freezeCol > 0) {
            $topLeft = self::cellRef($this->freezeRow + 1, $this->freezeCol + 1);
            $pane = ($this->freezeRow > 0 && $this->freezeCol > 0) ? 'bottomRight' : ($this->freezeRow > 0 ? 'bottomLeft' : 'topRight');
            $xml .= '<pane' . ($this->freezeCol > 0 ? ' xSplit="' . $this->freezeCol . '"' : '')
                . ($this->freezeRow > 0 ? ' ySplit="' . $this->freezeRow . '"' : '')
                . ' topLeftCell="' . $topLeft . '" activePane="' . $pane . '" state="frozen"/>';
            $xml .= '<selection pane="' . $pane . '" activeCell="' . $topLeft . '" sqref="' . $topLeft . '"/>';
        }
        $xml .= '</sheetView></sheetViews>';
        $xml .= '<sheetFormatPr defaultRowHeight="15"/>';

        if (count($this->colWidths)) {
            ksort($this->colWidths);
            $xml .= '<cols>';
            foreach ($this->colWidths as $col => $w) {
                $xml .= '<col min="' . $col . '" max="' . $col . '" width="' . $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        ksort($this->cells);
        $rows = $this->cells;
        foreach ($this->rowHeights as $r => $h) {
            if (!isset($rows[$r])) $rows[$r] = array();
        }
        ksort($rows);
        foreach ($rows as $r => $cols) {
            ksort($cols);
            $xml .= '<row r="' . $r . '"';
            if (isset($this->rowHeights[$r])) {
                $xml .= ' ht="' . $this->rowHeights[$r] . '" customHeight="1"';
            }
            $xml .= '>';
            foreach ($cols as $c => $cell) {
                $ref = self::cellRef($r, $c);
                $st = $cell[2] ? ' s="' . $cell[2] . '"' : '';
                switch ($cell[0]) {
                    case 's':
                        $xml .= '<c r="' . $ref . '"' . $st . ' t="s"><v>' . $cell[1] . '</v></c>';
                        break;
                    case 'n':
                        $xml .= '<c r="' . $ref . '"' . $st . '><v>' . $cell[1] . '</v></c>';
                        break;
                    case 'f':
                        $xml .= '<c r="' . $ref . '"' . $st . '><f>' . self::esc($cell[1]) . '</f><v>' . $cell[3] . '</v></c>';
                        break;
                    default:
                        $xml .= '<c r="' . $ref . '"' . $st . '/>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if (count($this->merges)) {
            $xml .= '<mergeCells count="' . count($this->merges) . '">';
            foreach ($this->merges as $m) {
                $xml .= '<mergeCell ref="' . $m . '"/>';
            }
            $xml .= '</mergeCells>';
        }

        $m = $this->margins;
        $xml .= '<pageMargins left="' . $m['left'] . '" right="' . $m['right'] . '" top="' . $m['top'] . '" bottom="' . $m['bottom'] . '" header="' . $m['header'] . '" footer="' . $m['footer'] . '"/>';
        $xml .= '<pageSetup paperSize="' . $this->paperSize . '" orientation="' . ($this->landscape ? 'landscape' : 'portrait') . '"'
            . ' fitToWidth="' . $this->fitToWidth . '" fitToHeight="' . $this->fitToHeight . '" horizontalDpi="1200" verticalDpi="1200"/>';
        $xml .= '</worksheet>';
        return $xml;
    }
}
