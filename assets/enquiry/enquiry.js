/* ShootPilot public enquiry form: multi-step, validated, single submit. No dependencies. */
(function () {
  'use strict';
  var cfgEl = document.getElementById('pcrm-enquiry-config');
  var root = document.getElementById('pcrm-enquiry');
  if (!cfgEl || !root) return;
  var cfg;
  try { cfg = JSON.parse(cfgEl.textContent); } catch (e) { return; }

  var STEPS = [
    { title: 'Your details', sub: 'How can we reach you?', sections: ['client'] },
    { title: 'Your event', sub: 'Tell us when and where.', sections: ['event'] },
    { title: 'Services & budget', sub: 'Pick everything you are interested in.', sections: ['services', 'budget'] },
    { title: 'A little more', sub: 'Almost done.', sections: ['additional'] }
  ];
  var byKey = {};
  cfg.fields.forEach(function (f) { byKey[f.key] = f; });
  var steps = STEPS.filter(function (s) { return cfg.fields.some(function (f) { return s.sections.indexOf(f.section) >= 0; }); });
  var state = { step: 0, values: { day_type: 'single', services: [] }, sameWa: true, dates: [], sending: false, done: false, errors: {} };

  // UTM and source, kept for this browser session.
  var params = new URLSearchParams(location.search);
  var utm = {};
  ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(function (k) {
    var v = params.get(k);
    try { if (v) sessionStorage.setItem('pcrm_' + k, v); else v = sessionStorage.getItem('pcrm_' + k); } catch (e) {}
    if (v) utm[k] = v.slice(0, 190);
  });

  function h(tag, attrs, kids) {
    var el = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'text') el.textContent = v;
      else if (k === 'class') el.className = v;
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
      else el.setAttribute(k, v === true ? '' : v);
    });
    (kids || []).forEach(function (c) { if (c) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return el;
  }

  var isWedding = function () { return cfg.wedding_types.indexOf(state.values.event_type) >= 0; };
  function visible(f) {
    if (f.key === 'whatsapp' && state.sameWa) return false;
    if (f.key === 'event_end_date' && state.values.day_type !== 'multi') return false;
    if (f.show_for && f.show_for.length && state.values.event_type && f.show_for.indexOf(state.values.event_type) < 0) return false;
    if (f.show_for && f.show_for.length && !state.values.event_type) return false;
    return true;
  }
  function optionsFor(f) {
    if (f.key !== 'services') return f.options;
    return f.options.filter(function (o) { return cfg.wedding_services.indexOf(o[0]) < 0 || isWedding() || !state.values.event_type; });
  }

  /* ---------- Validation (the server checks everything again) ---------- */
  function validPhone(v) {
    var d = String(v || '').replace(/[^\d+]/g, '');
    if (/^\+/.test(d)) { var n = d.slice(1); return n.length >= 8 && n.length <= 15 && (n.slice(0, 2) !== '91' || n.length === 12); }
    if (d.length === 11 && d[0] === '0') d = d.slice(1);
    if (d.length === 12 && d.slice(0, 2) === '91') d = d.slice(2);
    return d.length === 10 && /^[6-9]/.test(d);
  }
  function check(f) {
    var v = state.values[f.key];
    var empty = v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length) || (f.type === 'checkbox' && !v);
    var req = f.required || (f.key === 'event_end_date' && state.values.day_type === 'multi');
    if (empty) return req ? (f.key === 'consent' ? 'Please tick this box so we can contact you.' : 'This field is required.') : '';
    if (f.type === 'phone' && !validPhone(v)) return 'Enter a valid mobile number, like 98765 43210.';
    if (f.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) return 'Enter a valid email address.';
    if (f.type === 'date') {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return 'Enter a valid date.';
      if ((f.key === 'event_date' || f.key === 'event_end_date') && v < cfg.today) return 'The date cannot be in the past.';
      if (f.key === 'event_end_date' && state.values.event_date && v < state.values.event_date) return 'The end date must be on or after the event date.';
    }
    if (f.type === 'number' && (isNaN(Number(v)) || Number(v) < 0)) return 'Enter a valid number.';
    if ((f.type === 'text' || f.type === 'textarea') && String(v).length > (f.type === 'text' ? 190 : 3000)) return 'This is too long.';
    if (f.key === 'full_name' && String(v).trim().length < 2) return 'Please enter your name.';
    return '';
  }
  function validateStep(i) {
    var errs = {};
    fieldsFor(i).forEach(function (f) { if (!visible(f)) return; var e = check(f); if (e) errs[f.key] = e; });
    if (steps[i].sections.indexOf('budget') >= 0 && state.values.budget === 'custom') {
      var amt = Number(String(state.values.budget_custom || '').replace(/[^\d.]/g, ''));
      if (!amt || amt < 1000) errs.budget_custom = 'Enter your budget in rupees (₹1,000 or more).';
    }
    if (steps[i].sections.indexOf('event') >= 0 && state.values.day_type === 'multi') {
      var bad = state.dates.some(function (d) { return d.date && d.date < cfg.today; });
      if (bad) errs.event_dates = 'Each event date must be today or later.';
    }
    return errs;
  }
  function fieldsFor(i) {
    return cfg.fields.filter(function (f) { return steps[i].sections.indexOf(f.section) >= 0; });
  }

  /* ---------- Rendering ---------- */
  function control(f) {
    var id = 'f-' + f.key;
    var v = state.values[f.key];
    var set = function (val) { state.values[f.key] = val; delete state.errors[f.key]; };
    var label = function () { return h('label', { for: id }, [f.label, f.required ? h('span', { class: 'req', text: ' *' }) : null]); };
    var wrap = h('div', { class: 'field' + (f.type === 'textarea' || f.type === 'multiselect' || f.type === 'radio' || f.type === 'checkbox' || f.key === 'address' ? ' full' : '') + (state.errors[f.key] ? ' invalid' : ''), 'data-key': f.key });
    var inputType = { phone: 'tel', email: 'email', date: 'date', number: 'number' }[f.type] || 'text';
    if (f.type === 'textarea') {
      wrap.appendChild(label());
      wrap.appendChild(h('textarea', { id: id, rows: 3, placeholder: f.placeholder, maxlength: 3000, oninput: function (e) { set(e.target.value); } }, [v || '']));
    } else if (f.type === 'select') {
      wrap.appendChild(label());
      var sel = h('select', { id: id, onchange: function (e) { set(e.target.value); if (f.key === 'event_type' || f.key === 'budget') render(); } }, [h('option', { value: '', text: f.placeholder || 'Choose…' })]);
      f.options.forEach(function (o) { var op = h('option', { value: o[0], text: o[1] }); if (String(v) === String(o[0])) op.selected = true; sel.appendChild(op); });
      wrap.appendChild(sel);
    } else if (f.type === 'radio' || f.type === 'multiselect') {
      var multi = f.type === 'multiselect';
      if (f.key === 'services') wrap.className += ' services';
      var fs = h('fieldset', null, [h('legend', null, [f.label, f.required ? h('span', { class: 'req', text: ' *' }) : null])]);
      var opts = h('div', { class: 'opts', role: multi ? 'group' : 'radiogroup' });
      optionsFor(f).forEach(function (o) {
        var on = multi ? (v || []).indexOf(o[0]) >= 0 : String(v) === String(o[0]);
        var inp = h('input', { type: multi ? 'checkbox' : 'radio', name: f.key, value: o[0] });
        inp.checked = on;
        inp.addEventListener('change', function () {
          if (multi) { var cur = (state.values[f.key] || []).slice(); var i = cur.indexOf(o[0]); if (inp.checked && i < 0) cur.push(o[0]); if (!inp.checked && i >= 0) cur.splice(i, 1); set(cur); }
          else set(o[0]);
          render();
        });
        opts.appendChild(h('label', { class: 'opt' + (on ? ' sel' : '') }, [inp, h('span', { text: o[1] })]));
      });
      fs.appendChild(opts);
      wrap.appendChild(fs);
    } else if (f.type === 'checkbox') {
      var cb = h('input', { type: 'checkbox', id: id });
      cb.checked = !!v;
      cb.addEventListener('change', function () { set(cb.checked); render(); });
      wrap.appendChild(h('label', { class: 'opt check' + (v ? ' sel' : ''), for: id }, [cb, h('span', null, [f.label, f.required ? h('span', { class: 'req', text: ' *' }) : null])]));
    } else {
      wrap.appendChild(label());
      var attrs = { id: id, type: inputType, placeholder: f.placeholder, value: v || '', oninput: function (e) { set(e.target.value); }, maxlength: f.type === 'text' ? 190 : null };
      if (f.type === 'phone') { attrs.inputmode = 'tel'; attrs.autocomplete = f.key === 'phone' ? 'tel' : null; }
      if (f.key === 'full_name') attrs.autocomplete = 'name';
      if (f.type === 'email') attrs.autocomplete = 'email';
      if (f.type === 'date') { attrs.min = cfg.today; if (f.key === 'event_end_date' && state.values.event_date) attrs.min = state.values.event_date; attrs.onchange = function (e) { set(e.target.value); if (f.key === 'event_date') render(); }; }
      wrap.appendChild(h('input', attrs));
      if (f.key === 'phone' && byKey.whatsapp) {
        var same = h('input', { type: 'checkbox' });
        same.checked = state.sameWa;
        same.addEventListener('change', function () { state.sameWa = same.checked; if (same.checked) delete state.values.whatsapp; render(); });
        wrap.appendChild(h('label', { class: 'inline' }, [same, 'This number is on WhatsApp']));
      }
    }
    if (f.help) wrap.appendChild(h('p', { class: 'help', text: f.help }));
    if (state.errors[f.key]) wrap.appendChild(h('p', { class: 'err', role: 'alert', text: state.errors[f.key] }));
    return wrap;
  }

  function datesBlock() {
    if (!state.dates.length) state.dates = [{ date: state.values.event_date || '', label: '' }];
    var box = h('div', { class: 'field full' + (state.errors.event_dates ? ' invalid' : '') }, [h('label', { text: 'Specific event dates (optional)' }), h('p', { class: 'help', text: 'Add each function, like Haldi, Mehndi, Wedding or Reception.' })]);
    var list = h('div', { class: 'dates' });
    state.dates.forEach(function (d, i) {
      var di = h('input', { type: 'date', value: d.date, min: cfg.today, 'aria-label': 'Date ' + (i + 1) });
      di.addEventListener('change', function () { d.date = di.value; delete state.errors.event_dates; });
      var li = h('input', { type: 'text', value: d.label, placeholder: 'Function (e.g. Haldi)', maxlength: 60, 'aria-label': 'Function name ' + (i + 1) });
      li.addEventListener('input', function () { d.label = li.value; });
      var rm = h('button', { type: 'button', class: 'x', 'aria-label': 'Remove date', text: '×', onclick: function () { state.dates.splice(i, 1); render(); } });
      list.appendChild(h('div', { class: 'daterow' }, [di, li, state.dates.length > 1 ? rm : h('span')]));
    });
    box.appendChild(list);
    if (state.dates.length < 15) box.appendChild(h('button', { type: 'button', class: 'link', text: '+ Add another date', onclick: function () { state.dates.push({ date: '', label: '' }); render(); } }));
    if (state.errors.event_dates) box.appendChild(h('p', { class: 'err', text: state.errors.event_dates }));
    return box;
  }

  function stepper() {
    var bar = h('div', { class: 'steps', 'aria-label': 'Progress' });
    steps.forEach(function (s, i) {
      bar.appendChild(h('div', { class: 's' + (i < state.step ? ' done' : '') + (i === state.step ? ' on' : '') }, [h('div', { class: 'bar' }, [h('i')]), h('div', { class: 'lbl', text: (i + 1) + '. ' + s.title })]));
    });
    return bar;
  }

  var captchaReady = false;
  function captcha(box) {
    if (!cfg.captcha) return;
    var holder = h('div', { class: 'cf-turnstile', 'data-sitekey': cfg.captcha, style: 'margin-top:14px' });
    box.appendChild(holder);
    if (!captchaReady) {
      captchaReady = true;
      var s = document.createElement('script');
      s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
      s.async = true;
      document.head.appendChild(s);
    } else if (window.turnstile) {
      try { window.turnstile.render(holder); } catch (e) {}
    }
  }

  function render(focusFirstError) {
    if (state.done) return;
    root.innerHTML = '';
    if (state.alert) root.appendChild(h('div', { class: 'alert', role: 'alert', text: state.alert }));
    if (steps.length > 1) root.appendChild(stepper());
    var s = steps[state.step];
    root.appendChild(h('p', { class: 'step-title', text: s.title }));
    root.appendChild(h('p', { class: 'step-sub', text: s.sub }));
    var grid = h('div', { class: 'grid' });
    fieldsFor(state.step).forEach(function (f) {
      if (!visible(f)) return;
      grid.appendChild(control(f));
      if (f.key === 'budget' && state.values.budget === 'custom') {
        var cw = h('div', { class: 'field' + (state.errors.budget_custom ? ' invalid' : '') }, [h('label', { for: 'f-budget_custom' }, ['Your budget (₹)', h('span', { class: 'req', text: ' *' })])]);
        cw.appendChild(h('input', { id: 'f-budget_custom', inputmode: 'numeric', placeholder: 'e.g. 2,50,000', value: state.values.budget_custom || '', oninput: function (e) { state.values.budget_custom = e.target.value; delete state.errors.budget_custom; } }));
        if (state.errors.budget_custom) cw.appendChild(h('p', { class: 'err', text: state.errors.budget_custom }));
        grid.appendChild(cw);
      }
      if (f.key === 'event_end_date' && state.values.day_type === 'multi') grid.appendChild(datesBlock());
    });
    root.appendChild(grid);
    var last = state.step === steps.length - 1;
    if (last) captcha(root);
    var back = state.step > 0 ? h('button', { type: 'button', class: 'btn', text: 'Back', onclick: function () { state.step--; state.alert = ''; render(); scrollTop(); } }) : h('span');
    var next = h('button', { type: 'button', class: 'btn primary', onclick: last ? send : goNext }, state.sending ? [h('span', { class: 'spin' }), 'Sending…'] : [last ? 'Send enquiry' : 'Continue']);
    if (state.sending) next.disabled = true;
    root.appendChild(h('div', { class: 'nav' }, [back, next]));
    if (last) root.appendChild(h('p', { class: 'secure', text: '🔒 Your details are shared only with ' + cfg.studio + '.' }));
    if (focusFirstError) {
      var bad = root.querySelector('.invalid input, .invalid select, .invalid textarea');
      if (bad) { bad.focus({ preventScroll: true }); bad.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    }
  }
  function scrollTop() { var t = root.getBoundingClientRect().top + window.scrollY - 16; if (window.scrollY > t) window.scrollTo({ top: t, behavior: 'smooth' }); }

  function goNext() {
    var errs = validateStep(state.step);
    state.errors = errs;
    state.alert = '';
    if (Object.keys(errs).length) { render(true); return; }
    state.step++;
    render();
    scrollTop();
  }

  function payload() {
    var out = { _token: cfg.token, _page: location.href.split('#')[0].slice(0, 500), _ref: (document.referrer || '').slice(0, 500), website_url: (document.querySelector('input[name=website_url]') || {}).value || '' };
    cfg.fields.forEach(function (f) { if (visible(f) && state.values[f.key] !== undefined) out[f.key] = state.values[f.key]; });
    if (state.values.budget === 'custom') out.budget_custom = state.values.budget_custom;
    if (state.values.day_type === 'multi') out.event_dates = state.dates.filter(function (d) { return d.date; });
    Object.keys(utm).forEach(function (k) { out[k] = utm[k]; });
    var ts = document.querySelector('[name="cf-turnstile-response"]');
    if (ts) out['cf-turnstile-response'] = ts.value;
    return out;
  }

  function send() {
    if (state.sending) return;
    for (var i = 0; i < steps.length; i++) {
      var errs = validateStep(i);
      if (Object.keys(errs).length) { state.errors = errs; state.step = i; render(true); return; }
    }
    state.sending = true;
    state.alert = '';
    render();
    fetch(cfg.action + (cfg.preview ? '?preview=1' : ''), { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, credentials: 'same-origin', body: JSON.stringify(payload()) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Something went wrong. Please try again.' }; }); })
      .then(function (res) {
        state.sending = false;
        if (res && res.ok) { thanks(res); return; }
        state.alert = (res && res.message) || 'Something went wrong. Please try again.';
        var errs = (res && res.errors) || {};
        if (Object.keys(errs).length) {
          state.errors = errs;
          for (var i = 0; i < steps.length; i++) {
            var keys = fieldsFor(i).map(function (f) { return f.key; }).concat(i === 2 ? ['budget_custom'] : []).concat(i === 1 ? ['event_dates'] : []);
            if (keys.some(function (k) { return errs[k]; })) { state.step = i; break; }
          }
        }
        render(true);
        scrollTop();
      })
      .catch(function () { state.sending = false; state.alert = 'We could not reach the server. Check your internet connection and try again.'; render(); });
  }

  function thanks(res) {
    state.done = true;
    var hero = document.querySelector('.hero');
    if (hero) hero.style.display = 'none';
    root.className = 'card center thanks';
    root.innerHTML = '';
    root.appendChild(h('div', { class: 'tick', 'aria-hidden': 'true', html: null }, [svgTick()]));
    root.appendChild(h('h1', { text: cfg.success_title }));
    root.appendChild(h('p', { class: 'lead', text: 'Thank you, ' + res.name + '.' }));
    root.appendChild(h('p', { class: 'muted', text: cfg.success_message }));
    root.appendChild(h('p', { class: 'ref' }, ['Enquiry reference ', h('b', { text: res.reference }), ' · ' + res.submitted_at]));
    if (res.whatsapp_link) root.appendChild(h('a', { class: 'btn wa', href: res.whatsapp_link, target: '_blank', rel: 'noopener', text: 'Chat with us on WhatsApp' }));
    root.setAttribute('tabindex', '-1');
    root.focus();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  function svgTick() {
    var ns = 'http://www.w3.org/2000/svg';
    var s = document.createElementNS(ns, 'svg');
    s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('width', '30'); s.setAttribute('height', '30');
    s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '2.5'); s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round');
    var p = document.createElementNS(ns, 'path'); p.setAttribute('d', 'M20 6 9 17l-5-5'); s.appendChild(p);
    return s;
  }

  render();
})();
