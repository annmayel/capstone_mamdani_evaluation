<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/auth.php';
require_dean_access();

$rules = fuzzy_rule_definitions();
$verdicts = official_verdict_definitions();
$ruleCount = count($rules);
$inputVariables = [];
$consequentLabels = [];

foreach ($rules as $rule) {
    foreach ($rule['antecedents'] as $antecedent) {
        $inputVariables[$antecedent['variable']] = true;
    }
    $consequentLabels[$rule['consequent']['label']] = true;
}

$variableCount = count($inputVariables);
$outputCategoryCount = count($verdicts);
$initialVisibleCount = min(10, $ruleCount);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Predefined Fuzzy Rules</title>
  <link rel="stylesheet" href="../assets/style.css?v=20261112">
  <link rel="icon" type="image/png" href="../favicon.png?v=20261016">
  <style>
    .dean-module-page.fuzzy-rules-module-page {
      padding: 0 !important;
    }
    .fuzzy-rules-page {
      --rules-ink: #10232a;
      --rules-muted: #607078;
      --rules-line: #e4e8e5;
      --rules-paper: #fff;
      --rules-wash: #f8f7f4;
      width: calc(100% - 36px);
      max-width: 1100px;
      margin: 24px auto;
      box-sizing: border-box;
      padding: 30px clamp(18px, 3vw, 42px) 42px;
      color: var(--rules-ink);
      border: 1px solid var(--rules-line);
      border-radius: 10px;
      background: var(--rules-paper);
      box-shadow: 0 8px 22px rgba(16, 35, 42, .045);
    }
    .rules-heading {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      gap: 20px;
      margin-bottom: 20px;
    }
    .rules-heading .eyebrow {
      margin: 0 0 6px;
      color: #a87950;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 1.3px;
      text-transform: uppercase;
    }
    .rules-heading h1 {
      margin: 0;
      font-size: 19px;
      font-weight: 700;
      letter-spacing: -.7px;
      line-height: 1.2;
    }
    .rules-heading p {
      max-width: 620px;
      margin: 8px 0 0;
      color: var(--rules-muted);
      font-size: 10px;
      line-height: 1.55;
    }
    .rules-readonly {
      flex: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 9px 12px;
      border: 1px solid #e4d5c7;
      border-radius: 7px;
      background: #fbf7f2;
      color: #78583f;
      font-size: 9px;
      font-weight: 600;
      white-space: nowrap;
    }
    .rules-readonly::before {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #b58863;
      content: "";
    }
    .rules-summary {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      margin: 20px 0 18px;
    }
    .rules-summary article {
      min-width: 0;
      padding: 15px 16px;
      border: 1px solid var(--rules-line);
      border-radius: 9px;
      background: var(--rules-paper);
      box-shadow: 0 4px 12px rgba(16, 35, 42, .035);
    }
    .rules-summary span {
      display: block;
      color: var(--rules-muted);
      font-size: 9px;
      font-weight: 500;
      letter-spacing: .25px;
    }
    .rules-summary strong {
      display: block;
      margin-top: 6px;
      color: var(--rules-ink);
      font-size: 16px;
      font-weight: 700;
      line-height: 1.15;
    }
    .rules-summary .source-value {
      font-size: 11px;
      letter-spacing: -.2px;
    }
    .rules-summary small {
      display: block;
      margin-top: 4px;
      color: var(--rules-muted);
      font-size: 9px;
    }
    .rules-panel {
      margin-top: 18px;
    }
    .rules-panel-heading {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 17px 0 13px;
    }
    .rules-panel-heading h2 {
      margin: 0;
      font-size: 12px;
      font-weight: 700;
    }
    .rules-panel-heading p {
      margin: 4px 0 0;
      color: var(--rules-muted);
      font-size: 9px;
      line-height: 1.5;
    }
    .rules-count {
      flex: none;
      color: var(--rules-muted);
      font-size: 9px;
      text-align: right;
    }
    .rules-toolbar {
      display: flex;
      align-items: flex-end;
      gap: 12px;
      padding: 2px 0 15px;
    }
    .rules-field {
      display: grid;
      gap: 5px;
    }
    .rules-field label {
      color: #56666d;
      font-size: 9px;
      font-weight: 600;
    }
    .rules-field input,
    .rules-field select {
      width: 100%;
      min-height: 38px;
      box-sizing: border-box;
      padding: 8px 10px;
      border: 1px solid #d9dfdc;
      border-radius: 6px;
      background: #fff;
      color: var(--rules-ink);
      font: inherit;
      font-size: 10px;
    }
    .rules-field input:focus,
    .rules-field select:focus,
    .rules-page-button:focus-visible {
      outline: 3px solid rgba(181, 136, 99, .25);
      outline-offset: 1px;
      border-color: #b58863;
    }
    .rules-search-field {
      flex: 1 1 360px;
      max-width: 520px;
    }
    .rules-filter-field {
      flex: 0 1 260px;
    }
    .rules-table-wrap {
      overflow-x: auto;
      border-top: 1px solid var(--rules-line);
      border-bottom: 1px solid var(--rules-line);
    }
    .rules-table {
      width: 100%;
      min-width: 900px;
      border-collapse: collapse;
      table-layout: fixed;
      font-size: 10px;
    }
    .rules-table th {
      padding: 10px 12px;
      background: var(--rules-wash);
      color: #526168;
      font-size: 9px;
      font-weight: 700;
      letter-spacing: .7px;
      text-align: left;
      text-transform: uppercase;
    }
    .rules-table td {
      padding: 10px 12px;
      border-top: 1px solid #edf0ee;
      vertical-align: top;
      overflow-wrap: anywhere;
    }
    .rules-table tr[hidden] {
      display: none !important;
    }
    .rules-table tbody tr:hover {
      background: #fcfaf7;
    }
    .rules-table .rule-column { width: 8%; }
    .rules-table .conditions-column { width: 50%; }
    .rules-table .consequent-column { width: 27%; }
    .rules-table .source-column { width: 15%; }
    .rule-id {
      display: inline-block;
      padding: 4px 6px;
      border-radius: 4px;
      background: #f4eee8;
      color: #8b6241;
      font-size: 9px;
      font-weight: 700;
    }
    .rule-conditions {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: 6px;
    }
    .rule-condition {
      min-width: 0;
      color: #43545b;
      line-height: 1.55;
    }
    .rule-condition-label {
      display: block;
      color: #637279;
      font-size: 9px;
      font-weight: 600;
      letter-spacing: .15px;
    }
    .rule-condition strong {
      display: block;
      color: #1d333b;
      font-weight: 650;
    }
    .rule-and {
      color: #a87950;
      font-size: 9px;
      font-weight: 700;
      letter-spacing: .6px;
      line-height: 1;
    }
    .rule-consequent {
      color: #1d333b;
      font-weight: 650;
      line-height: 1.55;
    }
    .rule-consequent-label {
      display: block;
      margin-bottom: 3px;
      color: #637279;
      font-size: 9px;
      font-weight: 600;
    }
    .verdict-badge {
      display: inline-flex;
      align-items: center;
      max-width: 100%;
      padding: 5px 8px;
      border: 1px solid;
      border-radius: 5px;
      font-size: 9px;
      font-weight: 700;
      line-height: 1.4;
      overflow-wrap: anywhere;
    }
    .verdict-badge--passed {
      border-color: #a6cbb2;
      background: #e8f4ed;
      color: #245c3c;
    }
    .verdict-badge--redefense {
      border-color: #d9ab54;
      background: #fff3db;
      color: #784d08;
    }
    .verdict-badge--failed {
      border-color: #dfaaa6;
      background: #fdebea;
      color: #8b302d;
    }
    .rule-source {
      color: #526168;
      font-size: 9px;
      line-height: 1.55;
    }
    .rules-empty {
      padding: 34px 18px !important;
      color: var(--rules-muted);
      text-align: center;
    }
    .rules-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      padding: 12px 0;
    }
    .rules-range {
      color: var(--rules-muted);
      font-size: 9px;
      font-variant-numeric: tabular-nums;
    }
    .rules-pagination {
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .rules-page-button {
      min-width: 30px;
      height: 30px;
      padding: 0 8px;
      border: 1px solid transparent;
      border-radius: 5px;
      background: transparent;
      color: #45565d;
      cursor: pointer;
      font: inherit;
      font-size: 9px;
    }
    .rules-page-button:hover:not(:disabled) {
      border-color: #e2d5c9;
      background: #fbf7f2;
    }
    .rules-page-button[aria-current="page"] {
      border-color: #b58863;
      background: #b58863;
      color: #fff;
      font-weight: 700;
    }
    .rules-page-button:disabled {
      color: #aab2b4;
      cursor: not-allowed;
    }
    .verdict-section {
      margin-top: 14px;
      padding-top: 15px;
      border-top: 1px solid var(--rules-line);
    }
    .verdict-heading {
      padding: 0 0 9px;
    }
    .verdict-heading h2 {
      margin: 0;
      color: var(--rules-ink);
      font-size: 12px;
      font-weight: 700;
    }
    .verdict-heading p {
      margin: 4px 0 0;
      color: var(--rules-muted);
      font-size: 9px;
      line-height: 1.5;
    }
    .verdict-table-wrap {
      overflow-x: auto;
      padding: 0 0 12px;
    }
    .fuzzy-rules-page .verdict-table {
      width: 100%;
      min-width: 0;
      border-collapse: collapse;
      table-layout: fixed;
      font-size: 10px;
    }
    .verdict-table th:nth-child(1),
    .verdict-table td:nth-child(1) { width: 14%; }
    .verdict-table th:nth-child(2),
    .verdict-table td:nth-child(2) { width: 18%; }
    .verdict-table th:nth-child(3),
    .verdict-table td:nth-child(3) { width: 31%; }
    .verdict-table th:nth-child(4),
    .verdict-table td:nth-child(4) { width: 37%; }
    .verdict-table th,
    .verdict-table td {
      padding: 9px 10px;
      border-top: 1px solid #edf0ee;
      text-align: left;
      vertical-align: top;
      line-height: 1.5;
    }
    .verdict-table th {
      color: #526168;
      font-size: 9px;
      letter-spacing: .5px;
      text-transform: uppercase;
    }
    .verdict-table td:first-child {
      color: #78583f;
      font-variant-numeric: tabular-nums;
      font-weight: 700;
      white-space: nowrap;
    }
    .verdict-table td:nth-child(2) {
      color: #263a41;
      font-weight: 650;
    }
    .rules-noscript {
      margin: 10px 19px;
      color: #78583f;
      font-size: 10px;
    }
    @media (max-width: 820px) {
      .rules-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .rules-heading { align-items: flex-start; }
    }
    @media (max-width: 720px) {
      .rules-table {
        min-width: 0;
        table-layout: auto;
      }
      .rules-table thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        clip-path: inset(50%);
      }
      .rules-table tbody {
        display: grid;
        gap: 9px;
        padding: 10px;
      }
      .rules-table tbody tr[data-rule-row] {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(110px, auto);
        gap: 8px 12px;
        padding: 12px;
        border: 1px solid var(--rules-line);
        border-radius: 7px;
        background: #fff;
      }
      .rules-table tbody tr[data-rule-row]:hover {
        background: #fcfaf7;
      }
      .rules-table tbody tr[data-rule-row] td {
        padding: 0;
        border: 0;
      }
      .rules-table tbody tr[data-rule-row] td:nth-child(1) {
        grid-column: 1;
        grid-row: 1;
      }
      .rules-table tbody tr[data-rule-row] td:nth-child(2) {
        grid-column: 1 / -1;
        grid-row: 2;
      }
      .rules-table tbody tr[data-rule-row] td:nth-child(3) {
        grid-column: 2;
        grid-row: 1;
        text-align: right;
      }
      .rules-table tbody tr[data-rule-row] td:nth-child(4) {
        grid-column: 1 / -1;
        grid-row: 3;
        padding-top: 7px;
        border-top: 1px solid #edf0ee;
      }
      .rule-conditions {
        grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
        align-items: start;
        gap: 8px;
      }
      .rule-condition-label,
      .rule-consequent-label {
        font-size: 10px;
      }
      .rules-table tbody tr#rules-empty {
        display: block;
      }
      .rules-table tbody tr#rules-empty .rules-empty {
        display: block;
      }
      .verdict-table {
        min-width: 0;
        border-collapse: separate;
        border-spacing: 0 8px;
      }
      .verdict-table thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        clip-path: inset(50%);
      }
      .verdict-table tbody tr {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1.6fr);
        border: 1px solid var(--rules-line);
        border-radius: 7px;
        background: #fff;
      }
      .verdict-table tbody td {
        padding: 8px 10px;
        border: 0;
      }
      .verdict-table tbody td:nth-child(1),
      .verdict-table tbody td:nth-child(2) {
        border-bottom: 1px solid #edf0ee;
      }
      .verdict-table tbody td:nth-child(2) {
        text-align: right;
      }
      .verdict-table tbody td:nth-child(3),
      .verdict-table tbody td:nth-child(4) {
        grid-column: 1 / -1;
      }
      .verdict-table tbody td:nth-child(4) {
        padding-top: 0;
      }
      .verdict-table tbody td::before {
        display: block;
        margin-bottom: 2px;
        color: #637279;
        content: attr(data-label);
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .4px;
        text-transform: uppercase;
      }
    }
    @media (max-width: 560px) {
      .fuzzy-rules-page {
        width: calc(100% - 26px);
        margin: 13px auto;
        padding: 22px 13px 30px;
      }
      .rules-heading { flex-direction: column; gap: 12px; }
      .rules-heading p { font-size: 11px; }
      .rules-readonly { align-self: flex-start; }
      .rules-summary { gap: 8px; margin: 15px 0; }
      .rules-summary article { padding: 12px; }
      .rules-summary strong { font-size: 20px; }
      .rules-summary .source-value { font-size: 14px; }
      .rules-panel-heading { align-items: flex-start; padding: 14px 0 11px; }
      .rules-panel-heading h2 { font-size: 14px; }
      .rules-toolbar { flex-direction: column; align-items: stretch; padding: 2px 0 13px; }
      .rules-search-field,
      .rules-filter-field { flex: auto; max-width: none; }
      .rules-footer { align-items: flex-start; flex-direction: column; padding: 11px 0; }
      .rules-pagination { align-self: flex-end; }
      .rules-table tbody { padding: 8px; }
      .rules-table tbody tr[data-rule-row] { padding: 10px; }
      .rule-conditions { grid-template-columns: minmax(0, 1fr); gap: 5px; }
      .rule-and { display: none; }
      .verdict-heading { padding: 0 0 8px; }
      .verdict-table-wrap { padding: 0 0 10px; }
      .verdict-table tbody tr { grid-template-columns: minmax(0, .8fr) minmax(0, 1.2fr); }
    }
    @media (prefers-reduced-motion: no-preference) {
      .rules-panel { animation: rules-panel-in .25s ease-out both; }
      @keyframes rules-panel-in {
        from { opacity: .75; transform: translateY(4px); }
        to { opacity: 1; transform: translateY(0); }
      }
    }
  </style>
</head>
<body class="dean-module-page fuzzy-rules-module-page">
  <main class="fuzzy-rules-page">
    <header class="rules-heading">
      <div>
        <p class="eyebrow">Decision reference · CEIT-T07B</p>
        <h1>Predefined Fuzzy Rules</h1>
        <p>Inspect the source-generated rule set for Project Quality and Paper Quality. Membership is one-hot by rounded score; the official score and verdict are calculated separately.</p>
      </div>
      <span class="rules-readonly">Read-only · source-defined</span>
    </header>

    <section class="rules-summary" aria-label="Rule set summary">
      <article>
        <span>Generated rules</span>
        <strong><?=esc($ruleCount)?></strong>
        <small>From the current rule definitions</small>
      </article>
      <article>
        <span>Input variables</span>
        <strong><?=esc($variableCount)?></strong>
        <small>Distinct antecedent variables</small>
      </article>
      <article>
        <span>Output categories</span>
        <strong><?=esc($outputCategoryCount)?></strong>
        <small>Official verdict definitions</small>
      </article>
      <article>
        <span>Rule source</span>
        <strong class="source-value">PHP definitions</strong>
        <small>Generated by the fuzzy engine</small>
      </article>
    </section>

    <section class="verdict-section" aria-labelledby="verdict-title">
      <div class="verdict-heading">
        <h2 id="verdict-title">Official CEIT-T07B score bands</h2>
        <p>Ranges apply to the final score rounded to a whole number.</p>
      </div>
      <div class="verdict-table-wrap">
        <table class="verdict-table">
          <thead>
            <tr><th scope="col">Rounded score</th><th scope="col">Official verdict</th><th scope="col">Description</th><th scope="col">Recommended next step</th></tr>
          </thead>
          <tbody>
            <?php foreach ($verdicts as $verdict): ?>
              <tr>
                <td data-label="Rounded score"><?=esc($verdict['minimum'] . '–' . $verdict['maximum'])?></td>
                <td data-label="Official verdict"><?=esc($verdict['label'])?></td>
                <td data-label="Description"><?=esc($verdict['description'])?></td>
                <td data-label="Recommended next step"><?=esc($verdict['next_step'])?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="rules-panel" aria-labelledby="rules-title">
      <div class="rules-panel-heading">
        <div>
          <h2 id="rules-title">Rule table</h2>
          <p>Conditions and consequents are taken directly from the active fuzzy rule definitions.</p>
        </div>
        <div class="rules-count" id="rules-count" aria-live="polite"><?=esc($ruleCount)?> matching <?= $ruleCount === 1 ? 'rule' : 'rules' ?></div>
      </div>

      <div class="rules-toolbar">
        <div class="rules-field rules-search-field">
          <label for="rule-search">Search rules</label>
          <input id="rule-search" type="search" placeholder="Search ID, conditions, or consequent…" autocomplete="off">
        </div>
        <div class="rules-field rules-filter-field">
          <label for="verdict-filter">Filter by consequent</label>
          <select id="verdict-filter">
            <option value="">All consequents</option>
            <?php foreach (array_keys($consequentLabels) as $label): ?>
              <option value="<?=esc($label)?>"><?=esc($label)?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="rules-table-wrap">
        <table class="rules-table">
          <thead>
            <tr>
              <th class="rule-column" scope="col">Rule</th>
              <th class="conditions-column" scope="col">Conditions (AND)</th>
              <th class="consequent-column" scope="col">Consequent</th>
              <th class="source-column" scope="col">Configuration</th>
            </tr>
          </thead>
          <tbody id="rules-body">
            <?php foreach ($rules as $rule):
              $first = $rule['antecedents'][0];
              $second = $rule['antecedents'][1];
              $consequentLabel = $rule['consequent']['label'];
              $consequentTone = $consequentLabel === 'REDEFENSE'
                  ? 'redefense'
                  : ($consequentLabel === 'FAILED'
                      ? 'failed'
                      : (strpos($consequentLabel, 'PASSED/APPROVED') === 0 ? 'passed' : ''));
              $searchText = implode(' ', [
                  $rule['id'],
                  $first['variable'],
                  $first['label'],
                  $second['variable'],
                  $second['label'],
                  $rule['consequent']['variable'],
                  $rule['consequent']['label'],
              ]);
            ?>
              <tr data-rule-row data-search="<?=esc($searchText)?>" data-consequent="<?=esc($rule['consequent']['label'])?>">
                <td><span class="rule-id"><?=esc($rule['id'])?></span></td>
                <td>
                  <div class="rule-conditions">
                    <div class="rule-condition">
                      <span class="rule-condition-label"><?=esc($first['variable'])?> IS</span>
                      <strong><?=esc($first['label'])?></strong>
                    </div>
                    <span class="rule-and" aria-label="and">AND</span>
                    <div class="rule-condition">
                      <span class="rule-condition-label"><?=esc($second['variable'])?> IS</span>
                      <strong><?=esc($second['label'])?></strong>
                    </div>
                  </div>
                </td>
                <td class="rule-consequent">
                  <span class="rule-consequent-label"><?=esc($rule['consequent']['variable'])?> IS</span>
                  <span class="verdict-badge<?= $consequentTone !== '' ? ' verdict-badge--' . esc($consequentTone) : '' ?>"><?=esc($consequentLabel)?></span>
                </td>
                <td class="rule-source">Generated from source definitions</td>
              </tr>
            <?php endforeach; ?>
            <tr id="rules-empty" <?= $ruleCount > 0 ? 'hidden' : '' ?>>
              <td class="rules-empty" colspan="4"><span id="rules-empty-message"><?= $ruleCount > 0 ? 'No rules match your search or selected consequent.' : 'No rules are currently defined in the source.' ?></span></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="rules-footer">
        <div class="rules-range" id="rules-range" aria-live="polite">
          <?= $ruleCount > 0 ? 'Showing 1–' . esc($initialVisibleCount) . ' of ' . esc($ruleCount) : 'Showing 0–0 of 0' ?>
        </div>
        <nav class="rules-pagination" id="rules-pagination" aria-label="Rules table pages"></nav>
      </div>
      <noscript><p class="rules-noscript">JavaScript is required for rule search, filtering, and pagination. All source-defined rules remain visible above.</p></noscript>
    </section>

  </main>

  <script>
    (() => {
      const pageSize = 10;
      const rows = Array.from(document.querySelectorAll('[data-rule-row]'));
      const searchInput = document.getElementById('rule-search');
      const verdictFilter = document.getElementById('verdict-filter');
      const pagination = document.getElementById('rules-pagination');
      const range = document.getElementById('rules-range');
      const count = document.getElementById('rules-count');
      const emptyRow = document.getElementById('rules-empty');
      const emptyMessage = document.getElementById('rules-empty-message');
      let currentPage = 1;

      function makePageButton(label, accessibleLabel, disabled, onClick, current) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'rules-page-button';
        button.textContent = label;
        button.setAttribute('aria-label', accessibleLabel);
        button.disabled = disabled;
        if (current) button.setAttribute('aria-current', 'page');
        button.addEventListener('click', onClick);
        return button;
      }

      function render() {
        const query = searchInput.value.trim().toLocaleLowerCase();
        const selectedVerdict = verdictFilter.value;
        const matchingRows = rows.filter((row) => {
          const matchesSearch = row.dataset.search.toLocaleLowerCase().includes(query);
          const matchesVerdict = !selectedVerdict || row.dataset.consequent === selectedVerdict;
          return matchesSearch && matchesVerdict;
        });
        const pageCount = Math.max(1, Math.ceil(matchingRows.length / pageSize));
        currentPage = Math.min(currentPage, pageCount);
        const firstIndex = (currentPage - 1) * pageSize;
        const visibleRows = new Set(matchingRows.slice(firstIndex, firstIndex + pageSize));

        rows.forEach((row) => {
          row.hidden = !visibleRows.has(row);
        });

        const total = matchingRows.length;
        const firstRecord = total ? firstIndex + 1 : 0;
        const lastRecord = total ? Math.min(firstIndex + pageSize, total) : 0;
        range.textContent = `Showing ${firstRecord}–${lastRecord} of ${total}`;
        count.textContent = `${total} matching ${total === 1 ? 'rule' : 'rules'}`;
        emptyRow.hidden = total !== 0;
        if (total === 0) {
          emptyMessage.textContent = rows.length === 0
            ? 'No rules are currently defined in the source.'
            : 'No rules match your search or selected consequent.';
        }

        pagination.replaceChildren();
        if (total <= pageSize) return;

        pagination.appendChild(makePageButton(
          'Previous',
          'Go to previous page',
          currentPage === 1,
          () => { currentPage -= 1; render(); },
          false
        ));
        for (let page = 1; page <= pageCount; page += 1) {
          pagination.appendChild(makePageButton(
            String(page),
            `Go to page ${page}`,
            false,
            () => { currentPage = page; render(); },
            page === currentPage
          ));
        }
        pagination.appendChild(makePageButton(
          'Next',
          'Go to next page',
          currentPage === pageCount,
          () => { currentPage += 1; render(); },
          false
        ));
      }

      searchInput.addEventListener('input', () => {
        currentPage = 1;
        render();
      });
      verdictFilter.addEventListener('change', () => {
        currentPage = 1;
        render();
      });
      render();
    })();
  </script>
</body>
</html>
