<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_dean_access();
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fuzzy Simulator</title>
<link rel="stylesheet" href="../assets/style.css?v=20261112">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="icon" type="image/png" href="../favicon.png?v=20261016">
</head><body class="dean-module-page dean-simulator-page">
  <h2>Fuzzy Evaluation Simulator</h2>
  <p>Test project and paper quality inputs. Verdict-based rule analysis is shown separately from the official CEIT-T07B score classification.</p>
  <div style="display:flex;gap:16px">
    <div style="width:360px" class="simulator-input-card">
      <h3>Evaluation Inputs</h3>
      <label>Project Quality <output id="projVal">88</output></label>
      <input id="projRange" type="range" min="0" max="100" value="88">
      <label>Paper Quality <output id="papVal">90</output></label>
      <input id="papRange" type="range" min="0" max="100" value="90">
      <div style="margin-top:8px"><button id="runSim" class="btn primary">Simulate</button></div>
    </div>
    <div style="flex:1">
      <h3>Fuzzy Decision Trace</h3>
      <div id="resultPanel" class="glass-card simulator-empty">Run a simulation to view the official average score, verdict, membership values, and applied rule.</div>
    </div>
  </div>
  <script>
    const projRange = document.getElementById('projRange'), papRange = document.getElementById('papRange');
    const projVal = document.getElementById('projVal'), papVal = document.getElementById('papVal');
    projRange.addEventListener('input',()=>projVal.textContent=projRange.value);
    papRange.addEventListener('input',()=>papVal.textContent=papRange.value);
    const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[char]));
    document.getElementById('runSim').addEventListener('click', async ()=>{
      const panel = document.getElementById('resultPanel');
      panel.className = 'glass-card simulator-loading';
      panel.textContent = 'Calculating verdict analysis...';
      try {
        const res = await fetch('../api/simulate_fuzzy.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({project:projRange.value, paper:papRange.value})});
        if (!res.ok) throw new Error('Simulation failed');
        const data = await res.json();
        const degreeRows = Object.entries(data.degrees || {}).flatMap(([variable, values]) => Object.entries(values).map(([label, value]) => `<div class="trace-row"><span>${escapeHtml(variable)} / ${escapeHtml(label)}</span><strong>${Number(value).toFixed(3)}</strong></div>`)).join('');
        const outputRows = Object.entries(data.aggregated || {}).map(([label, value]) => `<div class="trace-row"><span>Overall / ${escapeHtml(label)}</span><strong>${Number(value).toFixed(3)}</strong></div>`).join('') || '<p>No output category was activated.</p>';
        const ruleRows = (data.fires || []).filter(rule => Number(rule.strength) > 0).map(rule => `<li><span>${escapeHtml(rule.text.replace(/^JSON:/, 'Rule'))}</span><strong>${Number(rule.strength).toFixed(3)}</strong></li>`).join('') || '<li>No rule matched these inputs.</li>';
        panel.className = 'glass-card simulator-result';
        panel.innerHTML = `<div class="simulator-score"><span>Official CEIT-T07B Result</span><strong>${Number(data.average_score).toFixed(2)} <small>/ 100 actual average</small></strong><b>${escapeHtml(data.rating)}</b><small>Rounded classification score: ${Number(data.rounded_score)} / 100</small><p>${escapeHtml(data.rating_description)} ${escapeHtml(data.next_step)}</p></div><div class="trace-section"><h4>Verdict Rule Analysis</h4><p>Representative-score analysis (not the official result): <strong>${Number(data.defuzz).toFixed(2)} / 100</strong></p><p>The official score is the unweighted arithmetic mean of the two normalized inputs; the separate rule analysis does not determine the official verdict.</p></div><div class="trace-section"><h4>Verdict Membership Values</h4>${degreeRows}</div><div class="trace-section"><h4>Aggregated Verdict Strengths</h4>${outputRows}</div><div class="trace-section"><h4>Matching Verdict Rule</h4><ul class="trace-rules">${ruleRows}</ul></div>`;
      } catch (error) {
        panel.className = 'glass-card simulator-error';
        panel.textContent = 'Unable to calculate the result. Check the fuzzy configuration and try again.';
      }
    });
  </script>
</body></html>
