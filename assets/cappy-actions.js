/**
 * cappy-actions.js — Cappy's in-page "hands" for REDCap.
 *
 * Because Cappy is a REDCap External Module, this runs SAME-ORIGIN inside REDCap's
 * own page DOM — no browser extension, no iframe, no cross-origin bridge. It can
 * scan, highlight, and fill the very page the user is on.
 *
 * Two targeting strategies (the efficient hybrid):
 *   - FIELDS  -> targeted deterministically by REDCap field_name (the data dictionary
 *                already gives the agent the names). No fuzzy DOM guessing.
 *   - CHROME  -> links / buttons / tabs / text via a light scan with stable controlIds
 *                (ASTRA-style), so the agent can also say "click Save" or "open this tab".
 *
 * Transport (no React rebuild): we wrap window.chatbot_jsmo_module.callAI to
 *   (1) inject a compact page scan + action guidance into the outgoing context, and
 *   (2) parse ```cappy-action {json}``` blocks out of the assistant reply, execute
 *       them, and strip them before the React UI renders the message.
 */
(function () {
  'use strict';
  if (window.CappyActions) return;

  var GOLD = '#E4B31C';
  var PAGE_CTX_LABEL = 'Cappy Page Context';
  var ACTION_GUIDANCE = [
    'The user is viewing a LIVE REDCap page. You can act on it with these tools:',
    '- page.highlight — draw a gold ring around a field or element to show the user where to',
    '  click or look. Pass EITHER field (a REDCap variable name) OR control_id (from the',
    '  "Page elements" list below). Call this whenever the user asks you to highlight, show,',
    '  point to, or find something on the page.',
    '- page.fill — propose a value for a data-entry field (the user confirms before it writes).',
    '- page.clearHighlights — remove any rings.',
    'Data-entry fields are targetable by their REDCap variable name; buttons/links/tabs use the',
    'control_id from the list below.'
  ].join('\n');

  // ---------------------------------------------------------------------------
  // Small utils
  // ---------------------------------------------------------------------------
  function cssEscape(s) {
    if (window.CSS && CSS.escape) return CSS.escape(s);
    return String(s).replace(/["\\\][.#:>~+*^$|(){}=]/g, '\\$&');
  }
  function trim(v, max) { return String(v == null ? '' : v).replace(/\s+/g, ' ').trim().slice(0, max || 160); }
  function isVisible(el) {
    if (!el || !el.getBoundingClientRect) return false;
    var r = el.getBoundingClientRect();
    if (r.width <= 1 && r.height <= 1) return false;
    var s = window.getComputedStyle(el);
    return s.visibility !== 'hidden' && s.display !== 'none' && Number(s.opacity) !== 0;
  }

  // ---------------------------------------------------------------------------
  // REDCap field targeting (deterministic)
  // ---------------------------------------------------------------------------
  function fieldRow(fieldName) {
    if (!fieldName) return null;
    var row = document.getElementById(fieldName + '-tr');
    if (row) return row;
    var input = fieldInput(fieldName);
    return input ? input.closest('tr') : null;
  }
  function fieldInput(fieldName) {
    if (!fieldName) return null;
    var esc = cssEscape(fieldName);
    // Text/textarea/select/radio/checkbox all expose name="field" (radios share it).
    return document.querySelector('[name="' + esc + '"]')
      || document.querySelector('#' + esc)
      || null;
  }
  function fieldTarget(fieldName) {
    return fieldRow(fieldName) || fieldInput(fieldName);
  }

  // ---------------------------------------------------------------------------
  // Generic page scan (chrome the dictionary doesn't cover)
  // ---------------------------------------------------------------------------
  var CHROME_SELECTOR = 'a[href], button, input[type=button], input[type=submit], [role=button], [role=tab], .nav-tabs a, #formSaveTip button, .btn';
  var elementIds = new WeakMap();
  var registry = new Map();
  var counter = 0;

  function idFor(el) {
    var id = elementIds.get(el);
    if (!id) { id = 'el_' + (counter++); elementIds.set(el, id); }
    registry.set(id, el);
    return id;
  }
  function labelFor(el) {
    return trim(
      el.getAttribute('aria-label') ||
      el.getAttribute('title') ||
      el.value ||
      el.innerText ||
      el.textContent ||
      el.getAttribute('name') || '', 120);
  }
  function scan() {
    var seen = new Set();
    var out = [];
    var nodes = document.querySelectorAll(CHROME_SELECTOR);
    for (var i = 0; i < nodes.length && out.length < 150; i++) {
      var el = nodes[i];
      // Skip Cappy's own UI.
      if (el.closest && el.closest('#chatbot_ui_container')) continue;
      if (seen.has(el) || !isVisible(el)) continue;
      seen.add(el);
      var label = labelFor(el);
      if (!label) continue;
      out.push({
        controlId: idFor(el),
        tag: el.tagName.toLowerCase(),
        role: el.getAttribute('role') || (el.tagName === 'A' ? 'link' : 'button'),
        label: label,
        href: el.getAttribute('href') || ''
      });
    }
    return { url: location.href, title: document.title, elements: out };
  }
  function resolveControl(controlId) {
    var el = registry.get(controlId);
    if (el && el.isConnected) return el;
    if (el) registry.delete(controlId);
    return null;
  }

  // ---------------------------------------------------------------------------
  // Overlay highlight (rAF-coalesced, same approach as the ASTRA extension)
  // ---------------------------------------------------------------------------
  var OVERLAY_ID = '__cappy_overlay_layer';
  var overlays = [];
  function layer() {
    var l = document.getElementById(OVERLAY_ID);
    if (!l) {
      l = document.createElement('div');
      l.id = OVERLAY_ID;
      // Ring sits ABOVE REDCap page content/modals but BELOW Cappy's chat widget
      // (#chatbot_ui_container, z-index 1000000). Append to document.body — the SAME
      // stacking context as the chat container — so z-index ordering is deterministic
      // regardless of whether <body> forms its own stacking context. The container is
      // transparent + pointer-events:none, so the ring shows through it everywhere
      // except under the opaque chat box (which correctly paints over the ring).
      l.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:999999';
      document.body.appendChild(l);
    }
    return l;
  }
  function clearHighlights() {
    overlays.forEach(function (o) { o.el.remove(); });
    overlays = [];
  }
  function drawHighlight(target, label) {
    if (!target) return false;
    var r = target.getBoundingClientRect();
    var ring = document.createElement('div');
    ring.style.cssText = [
      'position:fixed',
      'left:' + (r.left - 4) + 'px',
      'top:' + (r.top - 4) + 'px',
      'width:' + (r.width + 8) + 'px',
      'height:' + (r.height + 8) + 'px',
      'border:3px solid ' + GOLD,
      'border-radius:8px',
      'box-shadow:0 0 0 3px rgba(228,179,28,0.35),0 0 14px rgba(228,179,28,0.5)',
      'pointer-events:none',
      'transition:all 120ms ease-out'
    ].join(';');
    if (label) {
      var tag = document.createElement('div');
      tag.textContent = label;
      tag.style.cssText = 'position:absolute;top:-22px;left:0;background:' + GOLD + ';color:#1a1a1a;font:600 11px/1.4 system-ui,sans-serif;padding:1px 6px;border-radius:5px;white-space:nowrap';
      ring.appendChild(tag);
    }
    layer().appendChild(ring);
    overlays.push({ el: ring, target: target });
    if (target.scrollIntoView) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return true;
  }
  var repositionScheduled = false;
  function scheduleReposition() {
    if (repositionScheduled || overlays.length === 0) return;
    repositionScheduled = true;
    requestAnimationFrame(function () {
      repositionScheduled = false;
      overlays.forEach(function (o) {
        var r = o.target.getBoundingClientRect();
        o.el.style.left = (r.left - 4) + 'px';
        o.el.style.top = (r.top - 4) + 'px';
        o.el.style.width = (r.width + 8) + 'px';
        o.el.style.height = (r.height + 8) + 'px';
      });
    });
  }
  window.addEventListener('scroll', scheduleReposition, true);
  window.addEventListener('resize', scheduleReposition, true);

  // ---------------------------------------------------------------------------
  // Fill (REDCap-aware) — text/textarea/select; triggers REDCap's own handlers
  // ---------------------------------------------------------------------------
  function fillField(fieldName, value) {
    var el = fieldInput(fieldName);
    if (!el) return { ok: false, reason: 'field_not_found' };
    var tag = el.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA') {
      var proto = tag === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
      var setter = Object.getOwnPropertyDescriptor(proto, 'value');
      if (setter && setter.set) setter.set.call(el, value); else el.value = value;
    } else if (tag === 'SELECT') {
      el.value = value;
    } else {
      el.value = value;
    }
    ['input', 'change', 'blur'].forEach(function (t) {
      el.dispatchEvent(new Event(t, { bubbles: true }));
    });
    // Nudge REDCap's branching/calc engine if present.
    try { if (typeof window.doBranching === 'function') window.doBranching(fieldName); } catch (e) { void e; }
    try { if (typeof window.calculate === 'function') window.calculate(); } catch (e) { void e; }
    return { ok: true };
  }

  // ---------------------------------------------------------------------------
  // Apply one action
  // ---------------------------------------------------------------------------
  function apply(action) {
    if (!action || !action.action) return { ok: false, reason: 'no_action' };
    switch (action.action) {
      case 'highlight': {
        clearHighlights();
        var target = null, label = action.label || '';
        if (action.field) { target = fieldTarget(action.field); label = label || action.field; }
        else if (action.controlId) { target = resolveControl(action.controlId); }
        else if (action.selector) { target = document.querySelector(action.selector); }
        var ok = drawHighlight(target, label);
        return { ok: ok, reason: ok ? undefined : 'target_not_found' };
      }
      case 'fill': {
        var el = fieldInput(action.field);
        if (!el) return { ok: false, reason: 'field_not_found' };
        drawHighlight(fieldTarget(action.field), action.label || action.field);
        if (!action.confirmed) {
          var ok2 = window.confirm('Cappy wants to set "' + (action.label || action.field) + '" to:\n\n' + action.value + '\n\nApply to the page?');
          if (!ok2) return { ok: false, reason: 'user_declined' };
        }
        return fillField(action.field, String(action.value == null ? '' : action.value));
      }
      case 'clear_highlights':
        clearHighlights();
        return { ok: true };
      default:
        return { ok: false, reason: 'unsupported:' + action.action };
    }
  }

  window.CappyActions = {
    scan: scan, apply: apply, fillField: fillField,
    clearHighlights: clearHighlights, resolveControl: resolveControl
  };
  console.log('[Cappy] actions script loaded');

  // ---------------------------------------------------------------------------
  // Transport: wrap the JSMO callAI bridge (no React rebuild needed)
  // ---------------------------------------------------------------------------
  function scanToText(s) {
    return (s.elements || []).map(function (e) {
      return '- control_id=' + e.controlId + ' | ' + e.role + ' | ' + e.label + (e.href ? ' | href=' + e.href : '');
    }).join('\n');
  }
  function withPageContext(payload) {
    if (!payload || !Array.isArray(payload.messages)) return payload;
    var s = scan();
    var content = PAGE_CTX_LABEL + '\n' + ACTION_GUIDANCE +
      '\n\nCurrent page: ' + s.title + ' (' + s.url + ')' +
      '\nPage elements you can act on:\n' + scanToText(s);
    var msgs = payload.messages.filter(function (m) {
      return !(m.role === 'system' && typeof m.content === 'string' && m.content.indexOf(PAGE_CTX_LABEL) === 0);
    });
    msgs.unshift({ role: 'system', content: content });
    var next = {};
    for (var k in payload) if (Object.prototype.hasOwnProperty.call(payload, k)) next[k] = payload[k];
    next.messages = msgs;
    return next;
  }
  // Map a real tool call (page.*) from tools_used into a client action for the renderer.
  function toClientAction(name, args) {
    args = args || {};
    if (name === 'page.highlight') {
      return { action: 'highlight', field: args.field, controlId: args.control_id || args.controlId, label: args.label };
    }
    if (name === 'page.fill') {
      return { action: 'fill', field: args.field, value: args.value, label: args.label };
    }
    if (name === 'page.clearHighlights') {
      return { action: 'clear_highlights' };
    }
    return null;
  }
  function installBridge() {
    var mod = window.chatbot_jsmo_module;
    if (!mod || typeof mod.callAI !== 'function' || mod.__cappyWrapped) return !!(mod && mod.__cappyWrapped);
    var orig = mod.callAI.bind(mod);
    mod.__cappyWrapped = true;
    mod.callAI = function (payload, onSuccess, onError) {
      try {
        payload = withPageContext(payload);
        var n = (payload.messages || []).length;
        console.log('[Cappy] callAI intercepted — injected page context (' + n + ' msgs)');
      } catch (e) { void e; /* best effort */ }
      return orig(payload, function (res) {
        try {
          // Execute page.* tool calls (real agent tools) from the response metadata.
          var tu = (res && res.tools_used) || [];
          var pageCalls = tu.filter(function (t) {
            return t && typeof t.name === 'string' && t.name.indexOf('page.') === 0;
          });
          console.log('[Cappy] tools_used: ' + tu.length + ', page action(s): ' + pageCalls.length);
          pageCalls.forEach(function (t) {
            var a = toClientAction(t.name, t.arguments);
            if (a) {
              var result = apply(a);
              console.log('[Cappy] applied', a, '->', result);
            }
          });
        } catch (e) { void e; /* never break the chat on action handling */ }
        if (onSuccess) onSuccess(res);
      }, onError);
    };
    console.log('[Cappy] bridge installed — wrapping chatbot_jsmo_module.callAI');
    return true;
  }
  if (!installBridge()) {
    var iv = setInterval(function () { if (installBridge()) clearInterval(iv); }, 300);
    setTimeout(function () {
      clearInterval(iv);
      if (!(window.chatbot_jsmo_module && window.chatbot_jsmo_module.__cappyWrapped)) {
        console.warn('[Cappy] bridge NOT installed — chatbot_jsmo_module.callAI never became available');
      }
    }, 15000);
  }
})();
