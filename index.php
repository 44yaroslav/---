<?php
/**
 * Конструктор план-графиков размещения облигаций с выгрузкой в XLSX.
 * PHP >= 5.6, расширение zip. Данные редактируются в браузере,
 * выгрузка формируется на сервере (export.php).
 */
header('Content-Type: text/html; charset=utf-8');
$version = @filemtime(__DIR__ . '/assets/app.js');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Конструктор план-графиков</title>
<link rel="stylesheet" href="assets/style.css?v=<?php echo (int)$version; ?>">
</head>
<body>
<header class="topbar">
  <div class="brand">
    <span class="brand-mark"></span>
    <div>
      <div class="brand-title">Конструктор план-графиков</div>
      <div class="brand-sub">Дорожная карта размещения облигаций · выгрузка в XLSX</div>
    </div>
  </div>
  <div class="toolbar">
    <label class="tb-group">
      <span>Пресет</span>
      <select id="presetSelect"></select>
      <button type="button" id="btnPreset" class="btn">Загрузить</button>
    </label>
    <label class="tb-group">
      <span>Сохранённые</span>
      <select id="savedSelect"></select>
      <button type="button" id="btnOpen" class="btn">Открыть</button>
      <button type="button" id="btnDelete" class="btn btn-ghost" title="Удалить выбранный план">✕</button>
    </label>
    <div class="tb-group">
      <button type="button" id="btnSave" class="btn">Сохранить</button>
      <button type="button" id="btnSaveAs" class="btn btn-ghost">Сохранить как новый</button>
    </div>
    <div class="tb-group">
      <button type="button" id="btnImport" class="btn btn-ghost">Импорт JSON</button>
      <button type="button" id="btnExportJson" class="btn btn-ghost">Экспорт JSON</button>
      <input type="file" id="fileImport" accept=".json,application/json" hidden>
    </div>
    <button type="button" id="btnXlsx" class="btn btn-primary">Скачать XLSX</button>
  </div>
</header>

<main class="layout">
  <section class="panel settings">
    <h2>Параметры плана</h2>
    <div class="grid">
      <label>Название плана<input type="text" id="fName" placeholder="План-график размещения"></label>
      <label>Эмитент<input type="text" id="fIssuer" placeholder="ГК Сервис-Телеком"></label>
      <label>Дата начала<input type="date" id="fStart"></label>
      <label>Дата окончания <small>(пусто — авто)</small><input type="date" id="fEnd"></label>
      <label>Шкала
        <select id="fScale">
          <option value="day">Дни (даты в шапке, выходные подсвечены)</option>
          <option value="week">Недели (номера недель, как в шаблоне)</option>
        </select>
      </label>
      <label>Нумерация мероприятий
        <select id="fNumbering">
          <option value="section">В каждом разделе с 1</option>
          <option value="global">Сквозная</option>
        </select>
      </label>
      <label class="check"><input type="checkbox" id="fComments"> Столбец «Комментарий»</label>
      <label class="check"><input type="checkbox" id="fShiftWithStart" checked> Сдвигать мероприятия при изменении даты начала</label>
    </div>
    <details class="holidays">
      <summary>Нерабочие дни (кроме субботы и воскресенья)</summary>
      <div class="holidays-body">
        <textarea id="fHolidays" rows="4" placeholder="Даты по одной в строке: 01.01.2027 или 2027-01-01"></textarea>
        <div class="row-actions">
          <button type="button" id="btnHolidaysRf" class="btn btn-ghost">Добавить праздники РФ за период плана</button>
          <span class="hint">Переносы выходных дней добавляйте вручную.</span>
        </div>
      </div>
    </details>
    <div class="tools">
      <label>Сдвинуть все даты на <input type="number" id="fShiftDays" value="7" step="1" class="short"> дн.</label>
      <button type="button" id="btnShiftMinus" class="btn btn-ghost">← Раньше</button>
      <button type="button" id="btnShiftPlus" class="btn btn-ghost">Позже →</button>
      <label class="check"><input type="checkbox" id="fSkipWeekends" checked> при сдвиге пропускать выходные</label>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head">
      <h2>Разделы и мероприятия</h2>
      <div class="legend">
        <span><i class="sw sw-bar"></i>Мероприятие</span>
        <span><i class="sw sw-milestone"></i>Ключевое событие</span>
        <span><i class="sw sw-tentative"></i>Ориентировочно</span>
        <span><i class="sw sw-weekend"></i>Выходной</span>
      </div>
    </div>
    <div id="sections"></div>
    <div class="panel-foot">
      <button type="button" id="btnAddSection" class="btn">+ Добавить раздел</button>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head">
      <h2>Предпросмотр</h2>
      <label class="check"><input type="checkbox" id="fPreview" checked> показывать</label>
    </div>
    <div id="preview" class="preview"></div>
  </section>
</main>

<div id="toast" class="toast" hidden></div>

<script>window.APP_CONFIG = { api: 'api.php', export: 'export.php' };</script>
<script src="assets/app.js?v=<?php echo (int)$version; ?>"></script>
</body>
</html>
