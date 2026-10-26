// Progressive enhancement only. Every page works without this file.
(function () {
  'use strict';

  var meta = function (name) {
    var m = document.querySelector('meta[name="' + name + '"]');
    return m ? m.getAttribute('content') : '';
  };
  var BASE = meta('app-base');
  var CSRF = meta('csrf-token');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // --- 7.1: vendor picker is only enabled when the activity involves data sharing -------------
  function initSharingToggle() {
    var radios = document.querySelectorAll('[data-sharing-toggle]');
    var picker = document.querySelector('[data-vendor-picker]');
    var flag = document.querySelector('[data-js-flag]');
    if (!radios.length || !picker) return;
    if (flag) flag.value = '1';
    var select = picker.querySelector('select');
    var hint = picker.querySelector('.hint');
    function apply() {
      var yes = Array.prototype.some.call(radios, function (r) { return r.checked && r.value === '1'; });
      if (!select.hasAttribute('data-locked')) select.disabled = !yes || radios[0].disabled;
      picker.hidden = !yes;
      if (!yes) select.value = '';
      if (hint) hint.setAttribute('aria-hidden', yes ? 'false' : 'true');
    }
    if (select.disabled) select.setAttribute('data-locked', '1');
    Array.prototype.forEach.call(radios, function (r) { r.addEventListener('change', apply); });
    apply();
  }

  // --- live inherent-score preview -------------------------------------------------------------
  function formToObject(form) {
    var out = {};
    new FormData(form).forEach(function (value, key) {
      if (key === '_csrf' || key === '_js') return;
      if (key.slice(-2) === '[]') {
        key = key.slice(0, -2);
        (out[key] = out[key] || []).push(value);
      } else {
        out[key] = value; // later fields (the visible radios) win over hidden carry-over values
      }
    });
    return out;
  }

  function badge(rating, label) {
    return '<span class="badge badge-' + esc(rating) + '">' + esc(label) + '</span>';
  }

  function initPreview() {
    var form = document.querySelector('[data-preview-form]');
    var box = document.querySelector('[data-preview-box]');
    if (!form || !box || !window.fetch) return;
    var body = box.querySelector('[data-preview-body]');
    box.hidden = false;
    var timer = null;
    var seq = 0;

    function run() {
      var data = formToObject(form);
      // the hidden carry-over value must not override a visible radio choice for the same field
      form.querySelectorAll('input[type=radio]:checked').forEach(function (r) { data[r.name] = r.value; });
      if (!form.querySelector('input[name="data_type[]"]:not([data-preview-only])') && !data.data_type) data.data_type = [];
      data.entity_type = form.getAttribute('data-entity-type');
      data.risk_entity_id = form.getAttribute('data-entity-id');
      var mine = ++seq;
      fetch(BASE + '/api/v1/score-preview', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'Accept': 'application/json' },
        body: JSON.stringify(data)
      }).then(function (res) {
        return res.json().then(function (j) { return { status: res.status, json: j }; });
      }).then(function (r) {
        if (mine !== seq) return;
        if (r.status !== 200) {
          var fields = (r.json && r.json.error && r.json.error.fields) || {};
          var n = Object.keys(fields).length;
          body.innerHTML = '<p class="muted small">' + (n ? 'Answer ' + n + ' more question' + (n === 1 ? '' : 's') + ' to see the score.' : 'Answer every question to see the score.') + '</p>';
          return;
        }
        var x = r.json.inherent;
        var top = x.breakdown.slice().sort(function (a, b) { return b.weighted_value - a.weighted_value; }).slice(0, 3);
        var html = '<div class="score-number">' + Number(x.inherent_score).toFixed(2) + ' <small>/ 5</small></div>' +
          '<p>' + badge(x.inherent_rating, x.inherent_rating_label) + '</p>' +
          '<p class="small"><strong>Biggest contributors</strong></p><ul class="small">' +
          top.map(function (c) { return '<li>' + esc(c.factor_label) + ' (score ' + c.score + ')</li>'; }).join('') + '</ul>';
        if (x.excluded_factors.length) {
          html += '<p class="small muted">' + x.excluded_factors.map(function (f) { return esc(f.factor_label) + ' not included.'; }).join(' ') + '</p>';
        }
        body.innerHTML = html;
      }).catch(function () {
        body.innerHTML = '<p class="muted small">Preview unavailable. Saving will still calculate the score.</p>';
      });
    }

    form.addEventListener('change', function () {
      clearTimeout(timer);
      timer = setTimeout(run, 200);
    });
    run();
  }

  // --- approval form: the end date only matters for risk acceptance ------------------------------
  function initDecisionForm() {
    var form = document.querySelector('[data-decision-form]');
    if (!form) return;
    var until = form.querySelector('[data-accept-until]');
    if (!until) return;
    var input = until.querySelector('input');
    function apply() {
      var accept = form.querySelector('input[name=decision][value=risk_accepted]:checked');
      until.hidden = !accept;
      input.required = !!accept;
    }
    form.addEventListener('change', apply);
    apply();
  }

  // --- admin: running total of factor weights ------------------------------------------------------
  function initWeightTotal() {
    var inputs = document.querySelectorAll('[data-weight]');
    var out = document.querySelector('[data-weight-total]');
    if (!inputs.length || !out) return;
    function sum() {
      var t = 0;
      inputs.forEach(function (i) { t += parseFloat(i.value) || 0; });
      t = Math.round(t * 100) / 100;
      out.textContent = t;
      out.parentElement.classList.toggle('field-error', Math.abs(t - 100) > 0.0001);
    }
    inputs.forEach(function (i) { i.addEventListener('input', sum); });
    sum();
  }

  document.addEventListener('DOMContentLoaded', function () {
    initSharingToggle();
    initPreview();
    initDecisionForm();
    initWeightTotal();
  });
})();
