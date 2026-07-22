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
    '  LABEL FORMAT — two flavors:',
    '    * Main-content buttons: "DESCRIPTIVE TEXT [verb]" — e.g.',
    '        "Twilio SMS and Voice Call services for surveys and alerts [Enable]"',
    '      The unique identifying text leads; the verb is bracketed at the end.',
    '    * Sidebar / nav links: "[Section Name] Link Text" — e.g.',
    '        "[External Modules] Manage"',
    '        "[External Modules] View Logs"',
    '        "[Help & FAQ] Video Tutorials"',
    '      Brackets mean "this is in the sidebar, not a feature button". When the',
    '      user wants to navigate somewhere (e.g. "open External Modules", "go to',
    '      User Rights", "show me help videos"), pick the bracketed nav link.',
    '  WHICH PARAMETER TO PASS:',
    '    * Use control_id (from the Page elements list) for ANY button, link,',
    '      or tab — including module toggle buttons like the Twilio Enable.',
    '      This is the most reliable parameter and resolves deterministically.',
    '    * Use field (REDCap variable name) ONLY for actual REDCap data-entry',
    '      FIELDS (text inputs, textareas, dropdowns, checkboxes, radios) on a',
    '      data-entry form. Do NOT use field for module names like "Twilio",',
    '      "MyCap", "SendGrid" — those are not REDCap fields, and passing them',
    '      as `field` will fail to resolve and produce no highlight.',
    '    * label is OPTIONAL and only shown above the ring as a tooltip.',
    '  CRITICAL — match the LABEL, not the role. Many REDCap setup pages list a long',
    '  stack of identical-looking "Enable" / "Disable" toggles (one per optional',
    '  module). Several rows end with the SAME tail text (e.g. "...for surveys and',
    '  alerts"), so do NOT anchor on the tail or the verb — anchor on the UNIQUE',
    '  identifying keyword the user said (e.g. "Twilio", "MyCap"). When the user',
    '  asks to enable / turn on / configure a specific module, pick the main-content',
    '  button whose label uniquely contains that module name AND pass its control_id.',
    '  E.g. "enable Twilio" → page.highlight(control_id="el_4", label="Twilio SMS ...").',
    '  Do NOT pass field="Twilio" — Twilio is not a REDCap field, only the button',
    '  is, and the renderer needs control_id to find the button. If no label in',
    '  the list clearly matches the user\'s intent, ask for clarification instead',
    '  of guessing — highlighting the wrong control wastes the user\'s time and',
    '  erodes trust.',
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
  // True only for actual form controls. We reject anything else so the loose
  // `#id` fallback below can't land the highlight on a non-field element that
  // happens to share the field's id (e.g. a wrapper div / section anchor).
  function isFormControl(el) {
    if (!el) return false;
    var tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
  }
  // Match the field's actual <input>/<select>/<textarea> in the DOM.
  // REDCap renders:
  //   text/textarea/select/radio/file  -> name="field" (radios share it)
  //   checkbox                         -> name="field[1]" / "field[2]" / ...
  //                                       (plus name="field___chknull" for the null box)
  // We try each pattern so checkboxes resolve to the right row instead of
  // falling through to a wrong-id match.
  function fieldInput(fieldName) {
    if (!fieldName) return null;
    var esc = cssEscape(fieldName);
    var el =
      document.querySelector('[name="' + esc + '"]')
      || document.querySelector('[name="' + esc + '[1]"]')         // first checkbox option
      || document.querySelector('[name="' + esc + '___chknull"]') // checkbox null box
      || document.querySelector('#' + esc);                        // last-resort: any element with the id
    return isFormControl(el) ? el : null;
  }
  // Confirm a row actually belongs to the field. A tr with id="field-tr" is
  // almost always the data-entry row, but we verify it contains a control
  // matching the field — otherwise a stale or unrelated "-tr" element (a
  // section header, a repeating-instance header, a preview hidden in the DOM)
  // could draw the ring on the wrong field.
  function rowOwnsField(row, input) {
    return !!(row && input) && row.contains(input);
  }
  function fieldRow(fieldName) {
    if (!fieldName) return null;
    var input = fieldInput(fieldName);
    if (!input) return null;
    var row = document.getElementById(fieldName + '-tr');
    if (rowOwnsField(row, input)) return row;
    // No verified -tr row — fall back to the input's nearest <tr>.
    return input.closest('tr');
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
    var direct = trim(
      el.getAttribute('aria-label') ||
      el.getAttribute('title') ||
      el.value ||
      el.textContent ||
      el.getAttribute('name') || '', 120);

    // DOM-AWARE label: if the element sits in a sidebar / nav region, prefix
    // its label with the section heading so the agent sees e.g.
    //   "[External Modules] Manage"
    //   "[Help & FAQ] Video Tutorials"
    // This lets the agent distinguish nav links (brackets = sidebar/nav) from
    // main-content controls (no brackets). When the user asks to enable a
    // specific module, the agent can see at a glance that "Manage" is in
    // a nav region labeled "External Modules" — different from a main-content
    // button next to "Twilio SMS ...".
    if (isInNavigation(el)) {
      var section = findNavSection(el);
      return trim('[' + section + '] ' + direct, 200);
    }

    // Many REDCap setup pages stack rows of identically-labeled buttons
    // (e.g. a long list of "Enable" toggles, one per optional module) where
    // the descriptive text sits in the SAME row next to the button. Without
    // context the agent can't tell them apart and highlights the wrong row
    // (e.g. picks "Use the MyCap participant-facing mobile app?" when the
    // user asked about Twilio). For generic button labels, append the
    // descriptive text from the row's container so the agent sees e.g.:
    //   "Enable — Twilio SMS and Voice Call services for surveys and alerts"
    //
    // REDCap uses BOTH <tr><td>...</td><td>label</td></tr> (data entry forms,
    // some setup tables) AND plain <div> containers with the button + label +
    // help link + video link as siblings (Project Setup's "Enable optional
    // modules" section). Try <tr> first (works for td-based rows), then
    // fall back to the closest <div> (works for div-based rows).
    var GENERIC = /^(enable|disable|save|cancel|delete|edit|add|remove|submit|ok|yes|no|new|update|back|next|continue|close|view|export|download|upload|import|approve|reject|reset|clear|search|configure|manage|setup)$/i;
    if (!GENERIC.test(direct)) return direct;

    // Case A: <tr>-based rows (data entry forms). Collect sibling <td> text.
    var row = el.closest('tr');
    if (row) {
      var buttonCell = el.closest('td');
      var cells = row.querySelectorAll('td');
      var parts = [];
      for (var i = 0; i < cells.length; i++) {
        if (buttonCell && cells[i] === buttonCell) continue;
        var t = trim(cells[i].textContent || '', 120);
        if (t) parts.push(t);
      }
      var rowText = parts.join(' ').trim();
      if (rowText && rowText.toLowerCase() !== direct.toLowerCase()) {
        return trim(direct + ' — ' + rowText, 200);
      }
    }

    // Case B: <div>-based rows (REDCap Project Setup's optional modules).
    // Take the immediate parent <div>'s textContent and strip the button's
    // own text plus common noise (the "?" help link, "VIDEO: …" training
    // links, "Learn more about X" links) so only the descriptive text remains.
    var divContainer = el.closest('div');
    if (divContainer) {
      var divText = trim(divContainer.textContent || '', 200);
      var escDirect = direct.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      divText = divText.replace(new RegExp('^\\s*' + escDirect + '\\s*[—\\-:]?\\s*', 'i'), '');
      // Strip common REDCap decorations.
      divText = divText.replace(/\bLearn more about [^.\s]+/gi, '');
      divText = divText.replace(/\bVIDEO:\s*[^.\n]+/gi, '');
      divText = divText.replace(/\s*\?\s*$/, '');
      divText = trim(divText, 200);
      if (divText && divText.toLowerCase() !== direct.toLowerCase() && divText.length > 2) {
        return trim(direct + ' — ' + divText, 200);
      }
    }

    return direct;
  }
  function scan() {
    var seen = new Set();
    var main = [];
    var nav = [];
    var nodes = document.querySelectorAll(CHROME_SELECTOR);
    // Separate caps. Without this, the top-of-DOM nav/sidebar fills the
    // entire 150-slot cap and the actual main-content controls (e.g. the
    // Twilio Enable button) get pushed out of the agent's context entirely.
    var MAIN_CAP = 50;
    var NAV_CAP = 20;
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      if (el.closest && el.closest('#chatbot_ui_container')) continue;
      if (seen.has(el) || !isVisible(el)) continue;
      seen.add(el);
      var label = labelFor(el);
      if (!label) continue;
      var rec = {
        controlId: idFor(el),
        tag: el.tagName.toLowerCase(),
        role: el.getAttribute('role') || (el.tagName === 'A' ? 'link' : 'button'),
        label: label,
        href: el.getAttribute('href') || ''
      };
      // Bucket by nav status. isInNavigation was computed inside labelFor
      // (we can't recover it cleanly without re-checking); the bracket-prefix
      // convention is the reliable signal here.
      if (/^\[/.test(label)) {
        if (nav.length < NAV_CAP) nav.push(rec);
      } else {
        if (main.length < MAIN_CAP) main.push(rec);
      }
    }
    // Order matters: main-content controls FIRST so the agent's attention
    // lands on the actual feature buttons before the sidebar nav block.
    // (DOM order would put top-nav + sidebar before main, biasing the model
    // toward picking e.g. el_4 = "My Projects" when asked about Twilio.)
    var out = main.concat(nav);
    var navCount = nav.length;
    console.log('[Cappy] scan: ' + nodes.length + ' candidates, ' + main.length +
      ' main kept (cap ' + MAIN_CAP + '), ' + navCount +
      ' nav kept (cap ' + NAV_CAP + '); registry size=' + registry.size);
    if (out.length > 0) {
      var sample = out.slice(0, Math.min(8, out.length)).map(function (e) {
        return e.controlId + '="' + e.label + '"';
      }).join(', ');
      console.log('[Cappy] scan sample[0..' + Math.min(8, out.length) + ']: ' + sample);
    }
    return { url: location.href, title: document.title, elements: out, mainCount: main.length, navCount: navCount };
  }
  // Returns true if the element sits inside a sidebar / navigation region.
  // Used to label those elements with section context (e.g.
  // "[External Modules] Manage") so the agent can tell them apart from
  // identically-worded main-content controls. We deliberately do NOT skip
  // these elements — the user sometimes wants to navigate via the sidebar
  // (e.g. "go to external modules"), and the bracketed label gives the
  // model enough context to pick correctly.
  function isInNavigation(el) {
    if (!el || !el.closest) return false;
    var n = el;
    while (n && n !== document.body) {
      if (n.tagName === 'NAV' || n.tagName === 'ASIDE') return true;
      var role = n.getAttribute && n.getAttribute('role');
      if (role === 'navigation' || role === 'complementary') return true;
      var id = (n.id || '').toLowerCase();
      if (/(^|[_-])(menu|sidebar|nav|side[_-]?nav|leftmenu|left[_-]?menu|west)([_-]|$)/.test(id) && id !== 'chatbot_ui_container') return true;
      var cls = (typeof n.className === 'string' ? n.className : '').toLowerCase();
      if (/\b(menu|sidebar|sidenav|side-nav|navigation|nav[_-]?bar|leftmenu|left-menu)\b/.test(cls)) return true;
      n = n.parentElement;
    }
    return false;
  }
  // Find the section heading that labels the nav container the element lives
// in. Uses preceding-sibling semantics: at each level of the walk we look
// for the closest heading SIBLING that comes BEFORE the current node in
// document order. This is the standard "section label" pattern (e.g. an
// <h4> directly before a <ul> inside a sidebar menu-section).
//
// We deliberately do NOT use querySelector on the current node — that finds
// DESCENDANTS, which at higher levels (body / page-wrapper) would pick up
// unrelated headings from the main content and mislabel every nav link with
// whatever h2 happens to be on the page (e.g. "Enable optional modules and
// customizations" leaking onto "My Projects").
  function findNavSection(el) {
    if (!el) return 'sidebar';
    var n = el;
    while (n && n.parentElement && n !== document.body) {
      // Look at preceding siblings of `n` within its parent — start from
      // the closest one and walk back. The first heading wins.
      var prev = n.previousElementSibling;
      while (prev) {
        if (isSectionHeading(prev)) {
          var text = trim(prev.textContent || '', 60);
          if (text) return text;
        }
        prev = prev.previousElementSibling;
      }
      n = n.parentElement;
    }
    return 'sidebar';
  }
  function isSectionHeading(el) {
    if (!el) return false;
    if (/^H[2-6]$/.test(el.tagName)) return true;
    if (el.tagName === 'LEGEND') return true;
    var cls = (typeof el.className === 'string' ? el.className : '').toLowerCase();
    if (/\b(section-title|section_title|nav-title|menu-title)\b/.test(cls)) return true;
    return false;
  }
  function resolveControl(controlId) {
    var el = registry.get(controlId);
    if (el && el.isConnected) return el;
    if (el) registry.delete(controlId);
    return null;
  }
  // Scan the registry for an element whose labelFor() output contains the
  // given label fragment (case-insensitive). Used as a fallback when an
  // exact controlId lookup fails so we still produce a highlight instead of
  // silently dropping it.
  function findByLabel(labelFragment) {
    if (!labelFragment) return null;
    var needle = String(labelFragment).toLowerCase();
    var best = null;
    registry.forEach(function (el, id) {
      if (best || !el || !el.isConnected) return;
      var l = labelFor(el);
      if (l && l.toLowerCase().indexOf(needle) !== -1) best = el;
    });
    return best;
  }

  // Deterministic text-based matching: when the agent passes `text="Twilio SMS"`
  // (the preferred new parameter), we don't trust it to also pass the right
  // control_id. Instead, we tokenize the query, score every element in the
  // registry by token overlap with its label, and return the highest scorer
  // above a minimum confidence threshold. Stop words are ignored so "the
  // Twilio button" still ranks Twilio on top.
  var MATCH_STOP_WORDS = {
    a:1,an:1,the:1,and:1,or:1,of:1,to:1,for:1,on:1,in:1,at:1,with:1,
    by:1,is:1,it:1,this:1,that:1,as:1,be:1,please:1,can:1,you:1,me:1,
    i:1,my:1,we:1,our:1,do:1,does:1,just:1,go:1,click:1,tap:1,find:1,
    show:1,where:1,highlight:1,enable:1,disable:1,button:1,link:1,
    field:1,option:1,checkbox:1,tab:1,page:1,section:1
  };
  function findByText(queryText) {
    if (!queryText) return null;
    var query = String(queryText).toLowerCase();
    var rawTokens = query.split(/[^a-z0-9]+/).filter(function (t) {
      return t.length >= 2 && !MATCH_STOP_WORDS[t];
    });
    if (rawTokens.length === 0) return null;

    var best = null;
    var bestScore = 0;
    var bestLabel = '';
    var candidates = [];
    registry.forEach(function (el, id) {
      if (!el || !el.isConnected) return;
      var l = labelFor(el) || '';
      var lower = l.toLowerCase();
      var score = 0;
      var matchedTokens = 0;
      for (var i = 0; i < rawTokens.length; i++) {
        var tok = rawTokens[i];
        if (lower.indexOf(tok) !== -1) {
          score += 10;
          matchedTokens++;
          // Bonus: token starts the label — strong signal it's the right element.
          if (lower.indexOf(tok) === 0) score += 5;
          // Bonus: word-boundary match (not just a substring of a bigger word).
          var re = new RegExp('(^|[^a-z0-9])' + tok.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '($|[^a-z0-9])');
          if (re.test(lower)) score += 3;
        }
      }
      // Bonus: every query token matched (full coverage > partial).
      if (matchedTokens === rawTokens.length) score += 8;
      // Penalize bracket-prefixed nav labels (avoid accidental sidebar hits).
      if (/^\[/.test(l)) score -= 5;
      if (score > bestScore) {
        bestScore = score;
        best = el;
        bestLabel = l;
      }
      if (score > 0) candidates.push({ label: l, score: score });
    });
    // Minimum confidence: must score above a floor AND have matched at least
    // one meaningful token. Prevents highlighting a random element for a
    // completely-unrelated query.
    var minScore = rawTokens.length >= 2 ? 12 : 8;
    if (bestScore < minScore) {
      console.log('[Cappy] findByText: no confident match for "' + queryText + '" (best="' + bestLabel + '" score=' + bestScore + ' min=' + minScore + ')');
      console.log('[Cappy] findByText candidates:', candidates);
      return null;
    }
    console.log('[Cappy] findByText: "' + queryText + '" -> "' + bestLabel + '" (score=' + bestScore + ')');
    return best;
  }

  // ---------------------------------------------------------------------------
  // Overlay highlight (rAF-coalesced, same approach as the ASTRA extension)
  // ---------------------------------------------------------------------------
  var OVERLAY_ID = '__cappy_overlay_layer';
  var HIGHLIGHT_LIFESPAN_MS = 12000;     // auto-fade if the user does nothing
  var HIGHLIGHT_FADE_MS = 240;          // CSS opacity transition length
  var overlays = [];
  var fadeTimers = {};
  var overlayCounter = 0;
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
    overlays.slice().forEach(function (o) {
      if (fadeTimers[o.id]) { clearTimeout(fadeTimers[o.id]); delete fadeTimers[o.id]; }
      if (o.el && o.el.parentNode) o.el.remove();
    });
    overlays = [];
  }
  function fadeOverlay(overlay) {
    if (!overlay || !overlay.el || !overlay.el.parentNode) return;
    if (fadeTimers[overlay.id]) { clearTimeout(fadeTimers[overlay.id]); delete fadeTimers[overlay.id]; }
    overlay.el.style.opacity = '0';
    setTimeout(function () {
      if (overlay.el && overlay.el.parentNode) overlay.el.remove();
      var idx = overlays.indexOf(overlay);
      if (idx !== -1) overlays.splice(idx, 1);
    }, HIGHLIGHT_FADE_MS + 20);
  }
  function fadeAllHighlights() {
    overlays.slice().forEach(fadeOverlay);
  }
  function scheduleHighlightFade(overlay) {
    if (fadeTimers[overlay.id]) clearTimeout(fadeTimers[overlay.id]);
    fadeTimers[overlay.id] = setTimeout(function () { fadeOverlay(overlay); }, HIGHLIGHT_LIFESPAN_MS);
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
      'opacity:1',
      'transition:opacity ' + HIGHLIGHT_FADE_MS + 'ms ease-out, left 120ms ease-out, top 120ms ease-out, width 120ms ease-out, height 120ms ease-out'
    ].join(';');
    if (label) {
      var tag = document.createElement('div');
      tag.textContent = label;
      tag.style.cssText = 'position:absolute;top:-22px;left:0;background:' + GOLD + ';color:#1a1a1a;font:600 11px/1.4 system-ui,sans-serif;padding:1px 6px;border-radius:5px;white-space:nowrap';
      ring.appendChild(tag);
    }
    layer().appendChild(ring);
    var overlay = { id: ++overlayCounter, el: ring, target: target };
    overlays.push(overlay);
    scheduleHighlightFade(overlay);
    // Diagnostic: log exactly which element got the ring, with its computed
    // label and bounding rect. Open devtools to see why a highlight landed
    // where it did (or why a target came back null).
    var actualLabel = labelFor(target);
    var inspect = target.tagName + (target.id ? '#' + target.id : '') +
      (target.className && typeof target.className === 'string' ? '.' + target.className.split(/\s+/).join('.') : '');
    console.log('[Cappy] drew highlight #' + overlay.id +
      ' on ' + inspect +
      ' rect=' + Math.round(r.left) + ',' + Math.round(r.top) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height) +
      ' computedLabel="' + actualLabel + '"' +
      ' tooltip="' + (label || '') + '"');
    if (target.scrollIntoView) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return true;
  }
  // Activity listeners: fade on any page-level interaction so the ring doesn't
  // linger once the user has moved on. We deliberately do NOT fade on scroll
  // alone — the user may scroll a bit to read context around the highlighted
  // element — but we DO fade on click and on new chat activity.
  document.addEventListener('click', function (e) {
    // Don't fade for clicks inside the chat widget (user may be typing).
    if (e.target && e.target.closest && e.target.closest('#chatbot_ui_container')) return;
    fadeAllHighlights();
  }, true);
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
        // Preferred path: `text` parameter. Agent gives us a phrase like
        // "Twilio SMS" or "MyCap" and we deterministic-token-match against
        // every live element. No more "agent picked el_4 when it meant el_16".
        if (action.text) {
          target = findByText(action.text);
          label = label || action.text;
        }
        if (!target && action.field) {
          target = fieldTarget(action.field);
          label = label || action.field;
        } else if (!target && action.controlId) {
          target = resolveControl(action.controlId);
        } else if (!target && action.selector) {
          target = document.querySelector(action.selector);
        }
        // Last-resort fallback: substring match on label.
        if (!target && label) {
          target = findByLabel(label);
          if (target) console.log('[Cappy] highlight: recovered by substring label "' + label + '"');
        }
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
    clearHighlights: clearHighlights, resolveControl: resolveControl,
    debug: debug
  };
  console.log('[Cappy] actions script loaded');

  // ---------------------------------------------------------------------------
  // Transport: wrap the JSMO callAI bridge (no React rebuild needed)
  // ---------------------------------------------------------------------------
function scanToText(s) {
    var lines = [];
    // The scan already sorted main-content first, then sidebar/nav. Insert
    // visible section headers so the agent sees the break and doesn't stop
    // scanning after the first sidebar block.
    var mainCount = (typeof s.mainCount === 'number') ? s.mainCount : 0;
    var all = s.elements || [];
    for (var i = 0; i < all.length; i++) {
      var e = all[i];
      if (i === 0 && mainCount > 0) {
        lines.push('--- MAIN CONTENT (preferred targets; read these first) ---');
      } else if (i === mainCount && mainCount > 0 && i < all.length) {
        lines.push('--- SIDEBAR / NAVIGATION (navigation links, not feature buttons; read only if user asks to navigate) ---');
      }
      var label = e.label;
      // Enriched main-content labels look like "Enable — Use Twilio SMS ...".
      // Reorder so the unique, identifying text leads the label:
      //   "Use Twilio SMS and Voice Call services for surveys and alerts [Enable]"
      // Nav/sidebar labels (already bracketed, e.g. "[External Modules] Manage")
      // pass through unchanged — the bracket prefix is the signal that this is
      // a nav element.
      if (!/^\[/.test(label)) {
        var m = label.match(/^([A-Za-z]+)\s+—\s+(.*)$/);
        if (m) label = m[2] + ' [' + m[1] + ']';
      }
      lines.push('- control_id=' + e.controlId + ' | ' + e.role + ' | ' + label + (e.href ? ' | href=' + e.href : ''));
    }
    return lines.join('\n');
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
  // Arguments may arrive as either a JSON object or a JSON string depending on
  // the agent gateway — parse defensively so neither shape silently drops args.
  function toClientAction(name, args) {
    if (typeof args === 'string') {
      try { args = JSON.parse(args); } catch (e) { args = {}; }
    }
    if (!args || typeof args !== 'object') args = {};
    if (name === 'page.highlight') {
      return {
        action: 'highlight',
        text: args.text,
        field: args.field,
        controlId: args.control_id || args.controlId,
        label: args.label,
        selector: args.selector
      };
    }
    if (name === 'page.fill') {
      return { action: 'fill', field: args.field, value: args.value, label: args.label };
    }
    if (name === 'page.clearHighlights') {
      return { action: 'clear_highlights' };
    }
    return null;
  }
  // Console debug helper: dumps the current page scan and every registered
  // controlId with its computed label. Useful when "highlight landed on the
  // wrong thing" — paste the output back and we'll see exactly why.
  function debug() {
    var s = scan();
    console.groupCollapsed('[Cappy] debug: ' + s.elements.length + ' elements, registry=' + registry.size);
    console.log('url:', s.url, 'title:', s.title);
    console.table(s.elements.map(function (e) {
      var el = registry.get(e.controlId);
      var inspect = el ? (el.tagName + (el.id ? '#' + el.id : '')) : '(disconnected)';
      return { controlId: e.controlId, role: e.role, label: e.label, tag: inspect };
    }));
    console.groupEnd();
    return s;
  }

  function installBridge() {
    var mod = window.chatbot_jsmo_module;
    if (!mod || typeof mod.callAI !== 'function' || mod.__cappyWrapped) return !!(mod && mod.__cappyWrapped);
    var orig = mod.callAI.bind(mod);
    mod.__cappyWrapped = true;
    mod.callAI = function (payload, onSuccess, onError) {
      try {
        payload = withPageContext(payload);
        // User is engaging with the chat — any prior ring is now stale.
        // New highlights (if the response calls page.highlight) will be drawn
        // fresh below.
        fadeAllHighlights();
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
          if (pageCalls.length === 0 && tu.length > 0) {
            console.log('[Cappy] (non-page tools were used; agent did not call page.highlight / page.fill / page.clearHighlights)');
          }
          pageCalls.forEach(function (t) {
            var a = toClientAction(t.name, t.arguments);
            console.log('[Cappy] tool call:', t.name, 'args=', JSON.stringify(t.arguments), '-> action=', JSON.stringify(a));
            if (a) {
              var result = apply(a);
              console.log('[Cappy] applied', JSON.stringify(a), '->', JSON.stringify(result));
            }
          });
        } catch (e) {
          console.error('[Cappy] page-action handling failed:', e);
          void e; /* never break the chat on action handling */
        }
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
