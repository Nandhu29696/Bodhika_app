/* ── Exam Instructions banner: collapsible, collapsed by default ─────────────
   Was previously always expanded, eating a large chunk of vertical space
   above the question navigator on every load. Same collapse pattern as the
   case-study panel below — header toggles the body, "Got it x" still fully
   dismisses the whole banner for the rest of this attempt. */
function toggleExamInstructions() {
  var body = document.getElementById('eiBody');
  var hint = document.getElementById('eiToggleHint');
  var sub  = document.getElementById('eiSub');
  if (!body) return;
  var showing = body.style.display !== 'none';
  body.style.display = showing ? 'none' : 'block';
  if (hint) hint.innerHTML = showing ? 'Show &#9662;' : 'Hide &#9652;';
  if (sub) sub.textContent = showing
    ? 'Tap to view duration, question count, and important notes before you begin.'
    : 'Please read the following details carefully before you begin.';
}

/* Arriving via the header's "Exam Instructions" link (#examInstructionsBanner)
   should actually show the instructions, not just scroll to a collapsed
   header — auto-expand when that's how this page was reached. */
if (window.location.hash === '#examInstructionsBanner') {
  toggleExamInstructions();
  document.getElementById('examInstructionsBanner').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/* ── Case study panel: collapsible + tabbed sections ──────────────────────── */
function toggleCsPanel(panelId) {
  var body = document.getElementById(panelId);
  var hint = document.getElementById(panelId + '_hint');
  if (!body) return;
  var showing = body.style.display !== 'none';
  body.style.display = showing ? 'none' : 'block';
  if (hint) hint.innerHTML = showing ? 'Show background info &#9662;' : 'Hide background info &#9652;';
}
function switchCsTab(panelId, idx) {
  var body = document.getElementById(panelId);
  if (!body) return;
  body.querySelectorAll('.cs-tab-btn').forEach(function(btn, i) {
    btn.classList.toggle('active', i === idx);
  });
  body.querySelectorAll('.cs-tab-content').forEach(function(tab) {
    tab.classList.toggle('active', tab.id === panelId + '_tab' + idx);
  });
}

/* ── Answer tracking ─────────────────────────────────────────────────────── */
var totalQ     = EXAM_CONFIG.totalQuestions;
var answered   = 0;
var answeredSet = {};

function markAnswered(idx, isAnswered) {
  var wasAnswered = !!answeredSet[idx];
  answeredSet[idx] = isAnswered;
  if (isAnswered && !wasAnswered)  answered++;
  if (!isAnswered && wasAnswered)  answered--;
  // Class-based (not getElementById) so the bottom nav bar's duplicate
  // Answered/progress display — added alongside its Prev/Next/Skip buttons
  // so students get the same feedback without scrolling back up — updates
  // in lockstep with the original one at the top for free.
  document.querySelectorAll('.js-answered-count').forEach(function (el) { el.textContent = answered; });
  document.querySelectorAll('.js-progress-fill').forEach(function (el) { el.style.width = (answered / totalQ * 100) + '%'; });
  var card = document.getElementById('qrow'+idx);
  var num  = document.getElementById('qnum'+idx);
  if (card) card.classList.toggle('answered', isAnswered);
  if (num)  num.classList.toggle('answered',  isAnswered);
}

/* ── MCQ: radio via styled divs ─────────────────────────────────────────── */
document.querySelectorAll('.mcq-opt').forEach(function(opt) {
  opt.addEventListener('click', function() {
    var radio = this.querySelector('input[type=radio]');
    if (!radio) return;
    var name = radio.name;
    document.querySelectorAll('input[name="'+name+'"]').forEach(function(r) {
      r.closest('.mcq-opt').classList.remove('selected');
      r.closest('.mcq-opt').querySelector('.opt-badge').style.background='#312e81';
    });
    radio.checked = true;
    this.classList.add('selected');
    this.querySelector('.opt-badge').style.background='#059669';
    // extract index from name "rdoAnswerN"
    var idx = parseInt(name.replace('rdoAnswer',''));
    markAnswered(idx, true);
  });
});

/* ── MULTI: checkbox toggle ─────────────────────────────────────────────── */
function toggleMultiOpt(qIdx, optNum, labelEl) {
  var chk    = document.getElementById('chk_'+qIdx+'_'+optNum);
  var hidFld = document.getElementById('multiVal_'+qIdx);
  var maxSel = hidFld ? parseInt(hidFld.getAttribute('data-maxsel') || 0) : 0;
  var isChecked = chk.checked;   // state BEFORE toggle

  if (!isChecked && maxSel >= 2) {
    // User wants to check — enforce max
    var alreadyCount = document.querySelectorAll('input[name="chkAnswer'+qIdx+'[]"]:checked').length;
    if (alreadyCount >= maxSel) {
      // At limit — flash the label as feedback, block
      labelEl.style.transition = 'box-shadow .1s';
      labelEl.style.boxShadow  = '0 0 0 3px #f87171';
      setTimeout(function(){ labelEl.style.boxShadow = ''; }, 300);
      return;
    }
  }

  chk.checked = !isChecked;
  labelEl.classList.toggle('selected', chk.checked);
  labelEl.querySelector('.opt-badge').style.background = chk.checked ? '#059669' : '#312e81';

  var vals = [];
  document.querySelectorAll('input[name="chkAnswer'+qIdx+'[]"]:checked').forEach(function(c){
    vals.push(c.value);
  });
  if (hidFld) hidFld.value = vals.join(',');

  // Update "N selected" counter badge
  var selBadge = document.getElementById('multiSel_'+qIdx);
  if (selBadge && maxSel >= 2) {
    selBadge.textContent = '(' + vals.length + ' selected)';
    selBadge.style.color = (vals.length === maxSel) ? '#059669' : '#7c3aed';
  }

  markAnswered(qIdx, vals.length > 0);
}

/* ── MATCH: drag & drop matching (+ click-to-place fallback) ──────────────
   Each statement slot stores the matched option number on the drop target's
   data-filled-opt attribute (0 = unfilled). The hidden #matchVal_{qIdx} field
   is rebuilt from those attributes after every change and is what
   collectAnswers()/submit.php actually reads — a comma list of option
   numbers ordered by statement position, e.g. "2,1,3".               ─── */
var matchLetters    = ['A', 'B', 'C', 'D'];
var _matchSelected  = {};   // qIdx -> currently click-selected option number (or null)

function matchChipLabel(qIdx, optNum) {
  var chip = document.getElementById('matchChip_' + qIdx + '_' + optNum);
  return chip ? chip.getAttribute('data-text') : '';
}

function matchSyncHidden(qIdx) {
  var hid = document.getElementById('matchVal_' + qIdx);
  if (!hid) return;
  var n = parseInt(hid.getAttribute('data-pairs') || '0');
  var vals = [], anyFilled = false;
  for (var s = 1; s <= n; s++) {
    var drop = document.getElementById('matchDrop_' + qIdx + '_' + s);
    var opt  = drop ? (drop.getAttribute('data-filled-opt') || '0') : '0';
    if (opt !== '0') anyFilled = true;
    vals.push(opt);
  }
  /* Empty string (not "0,0,0") when nothing is matched yet, so autosave's
     truthiness check in collectAnswers() correctly treats it as unanswered. */
  hid.value = anyFilled ? vals.join(',') : '';
  markAnswered(qIdx, anyFilled);
}

function matchSetChipUsed(qIdx, optNum, used) {
  var chip = document.getElementById('matchChip_' + qIdx + '_' + optNum);
  if (!chip) return;
  chip.classList.toggle('match-chip-used', used);
  chip.setAttribute('draggable', used ? 'false' : 'true');
}

function matchFindSlotForOpt(qIdx, optNum) {
  var hid = document.getElementById('matchVal_' + qIdx);
  var n = hid ? parseInt(hid.getAttribute('data-pairs') || '0') : 0;
  for (var s = 1; s <= n; s++) {
    var drop = document.getElementById('matchDrop_' + qIdx + '_' + s);
    if (drop && drop.getAttribute('data-filled-opt') === String(optNum)) return s;
  }
  return null;
}

function matchClearSlot(qIdx, stmtIdx) {
  var drop = document.getElementById('matchDrop_' + qIdx + '_' + stmtIdx);
  if (!drop) return;
  var prevOpt = drop.getAttribute('data-filled-opt');
  drop.setAttribute('data-filled-opt', '0');
  drop.classList.remove('match-drop-filled');
  drop.innerHTML = '<span class="match-drop-placeholder">Answer</span>';
  if (prevOpt && prevOpt !== '0') matchSetChipUsed(qIdx, prevOpt, false);
}

function matchPlaceOption(qIdx, optNum, stmtIdx) {
  /* If this option is already placed in another slot, vacate that slot first */
  var prevSlot = matchFindSlotForOpt(qIdx, optNum);
  if (prevSlot !== null && prevSlot !== stmtIdx) matchClearSlot(qIdx, prevSlot);

  var drop = document.getElementById('matchDrop_' + qIdx + '_' + stmtIdx);
  if (!drop) return;

  /* If the target slot already holds a different option, return it to the pool */
  var existing = drop.getAttribute('data-filled-opt');
  if (existing && existing !== '0' && existing !== String(optNum)) {
    matchSetChipUsed(qIdx, existing, false);
  }

  drop.setAttribute('data-filled-opt', String(optNum));
  drop.classList.add('match-drop-filled');
  var letter = matchLetters[optNum - 1] || optNum;
  drop.innerHTML =
    '<span class="opt-badge" style="width:22px;height:22px;min-width:22px;font-size:.7rem;">' +
    letter + '</span><span>' + matchChipLabel(qIdx, optNum) + '</span>';
  matchSetChipUsed(qIdx, optNum, true);

  /* Clear click-to-select state for this question */
  document.querySelectorAll('.match-chip.match-chip-selected[id^="matchChip_' + qIdx + '_"]')
    .forEach(function (c) { c.classList.remove('match-chip-selected'); });
  _matchSelected[qIdx] = null;

  matchSyncHidden(qIdx);
}

function matchDragStart(ev, qIdx, optNum) {
  if (ev.target.classList.contains('match-chip-used')) { ev.preventDefault(); return; }
  ev.dataTransfer.setData('text/plain', qIdx + ':' + optNum);
  ev.dataTransfer.effectAllowed = 'move';
}
function matchDragOver(ev) {
  ev.preventDefault();
  ev.currentTarget.classList.add('match-drop-over');
}
function matchDragLeave(ev) {
  ev.currentTarget.classList.remove('match-drop-over');
}
function matchDrop(ev, qIdx, stmtIdx) {
  ev.preventDefault();
  ev.currentTarget.classList.remove('match-drop-over');
  var data = ev.dataTransfer.getData('text/plain');
  var parts = data.split(':');
  if (parts.length !== 2) return;
  var dQIdx = parseInt(parts[0]), optNum = parseInt(parts[1]);
  if (dQIdx !== qIdx) return;   // ignore drops dragged from a different question
  matchPlaceOption(qIdx, optNum, stmtIdx);
}

/* Click-to-select / click-to-place fallback for touch & accessibility */
function matchChipClick(qIdx, optNum) {
  var chip = document.getElementById('matchChip_' + qIdx + '_' + optNum);
  if (!chip || chip.classList.contains('match-chip-used')) return;
  var already = _matchSelected[qIdx] === optNum;
  document.querySelectorAll('.match-chip.match-chip-selected[id^="matchChip_' + qIdx + '_"]')
    .forEach(function (c) { c.classList.remove('match-chip-selected'); });
  _matchSelected[qIdx] = already ? null : optNum;
  if (!already) chip.classList.add('match-chip-selected');
}
function matchDropClick(qIdx, stmtIdx) {
  var drop = document.getElementById('matchDrop_' + qIdx + '_' + stmtIdx);
  if (!drop) return;
  var filled = drop.getAttribute('data-filled-opt');
  if (filled && filled !== '0') {
    /* Already filled — clicking it removes the answer and frees the chip */
    matchClearSlot(qIdx, stmtIdx);
    matchSyncHidden(qIdx);
    return;
  }
  var sel = _matchSelected[qIdx];
  if (sel) matchPlaceOption(qIdx, sel, stmtIdx);
}

/* ── YESNO ───────────────────────────────────────────────────────────────── */
function setYNAnswer(qIdx, stmtIdx, val) {
  var yLbl = document.getElementById('ynYes_'+qIdx+'_'+stmtIdx);
  var nLbl = document.getElementById('yNo_'+qIdx+'_'+stmtIdx);
  if (yLbl) yLbl.classList.toggle('yn-checked-y', val==='Y');
  if (nLbl) nLbl.classList.toggle('yn-checked-n', val==='N');
  if (val==='Y' && yLbl) yLbl.classList.remove('yn-checked-n');
  if (val==='N' && nLbl) nLbl.classList.remove('yn-checked-y');
  var inp = document.getElementById('ynInput_'+qIdx+'_'+stmtIdx+'_'+val);
  if (inp) inp.checked = true;
  // mark question answered when at least one statement answered
  markAnswered(qIdx, true);
}

/* ── Countdown timer ─────────────────────────────────────────────────────── */
var timeLeft = EXAM_CONFIG.timeLeftSeconds;
function tick() {
  var m = Math.floor(timeLeft/60), s = timeLeft % 60;
  document.getElementById('countdown').textContent =
    (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  if (timeLeft <= 0) { document.getElementById('frmWriteExam').submit(); return; }
  if (timeLeft <= 300) document.getElementById('countdown').style.color = '#c53030';
  timeLeft--;
  setTimeout(tick, 1000);
}
tick();

/* ── Validate before submit ──────────────────────────────────────────────── */
function validateExam(e) {
  var unanswered = totalQ - answered;
  if (unanswered > 0) {
    return confirm('You have ' + unanswered + ' unanswered question(s). Submit anyway?');
  }
  return true;
}

/* ── Restore visual selection state from draft on page load ────────────── */
(function restoreDraftVisuals() {
  /* MCQ: mark any pre-checked radio's parent label as .selected */
  document.querySelectorAll('input[type=radio][name^=rdoAnswer]').forEach(function(r) {
    if (r.checked) {
      var lbl = r.closest('.mcq-opt');
      if (lbl) {
        lbl.classList.add('selected');
        var badge = lbl.querySelector('.opt-badge');
        if (badge) badge.style.background = '#059669';
        /* Count it as answered */
        var name = r.name;          /* rdoAnswerN */
        var idx  = parseInt(name.replace('rdoAnswer',''));
        if (!isNaN(idx)) markAnswered(idx, true);
      }
    }
  });

  /* MULTI: mark pre-checked checkboxes' parent labels as .selected */
  document.querySelectorAll('input[type=checkbox][name^=chkAnswer]').forEach(function(cb) {
    if (cb.checked) {
      var lbl = cb.closest('.mcq-opt');
      if (lbl) {
        lbl.classList.add('selected');
        var badge = lbl.querySelector('.opt-badge');
        if (badge) badge.style.background = '#059669';
      }
    }
  });

  /* MULTI: re-sync hidden multiVal field and answered count from checked boxes */
  document.querySelectorAll('input[type=hidden][id^=multiVal_]').forEach(function(hid) {
    var qIdx = parseInt(hid.id.replace('multiVal_', ''));
    if (!isNaN(qIdx) && hid.value !== '') {
      markAnswered(qIdx, true);
      /* Sync toggleMultiOpt internal state by marking checked boxes */
      document.querySelectorAll('input[type=checkbox][name=chkAnswer'+qIdx+'\[\]]:checked').forEach(function(cb) {
        /* already checked in HTML — visual state handled above */
      });
    }
  });

  /* YESNO: restore visual highlight from pre-checked radios */
  document.querySelectorAll('input[type=radio][value=Y]:checked, input[type=radio][value=N]:checked').forEach(function(r) {
    if (!r.name.startsWith('rdoAnswer')) return;
    var parts = r.name.replace('rdoAnswer','').split('_');
    if (parts.length >= 2) {
      var qIdx  = parseInt(parts[0]);
      var sIdx  = parseInt(parts[1]);
      setYNAnswer(qIdx, sIdx, r.value);
    }
  });

  /* MATCH: server already rendered any pre-filled drop targets (and greyed
     out their matching chips) from the saved draft — just feed the progress
     counter so the header's "Answered" tally reflects it on load. */
  document.querySelectorAll('.match-drop[data-filled-opt]').forEach(function(drop) {
    var opt = drop.getAttribute('data-filled-opt');
    if (!opt || opt === '0') return;
    var m = drop.id.match(/^matchDrop_(\d+)_(\d+)$/);
    if (m) markAnswered(parseInt(m[1]), true);
  });
})();

/* ── Auto-save (60 s interval + 3 s after answer change) ────────────────── */
var EXAM_ID      = EXAM_CONFIG.examId;
var _saveTimer   = null;
var _saveIndicator = document.getElementById('autoSaveStatus');

function collectAnswers() {
  var answers = {};
  var form    = document.getElementById('frmWriteExam');
  if (!form) return answers;
  var inputs  = form.elements;
  for (var i = 0; i < inputs.length; i++) {
    var el = inputs[i];
    if (!el.name) continue;
    var skip = ['csrf_token','InfoId','starttime','number_of_ques',
                'SubjectId','MarksOutOf','GradeId','PassingMarks'];
    if (skip.indexOf(el.name) !== -1) continue;

    if (el.type === 'radio' && el.checked && el.name.startsWith('rdoAnswer')) {
      /* MCQ/DROPDOWN — name=rdoAnswerN, value=optNum (1-4) */
      var parts = el.name.match(/rdoAnswer(\d+)$/);
      if (parts) answers[_questionIdForIdx(parseInt(parts[1]))] = el.value;
      continue;
    }
    if (el.type === 'hidden' && el.id && el.id.startsWith('multiVal_') && el.value) {
      /* MULTI — hidden field holds "1,3" (comma-separated option numbers) */
      var qIdx = parseInt(el.id.replace('multiVal_',''));
      answers[_questionIdForIdx(qIdx)] = el.value;
      continue;
    }
    if (el.type === 'hidden' && el.id && el.id.startsWith('matchVal_') && el.value) {
      /* MATCH — hidden field holds "2,1,3" (option number per statement position) */
      var qIdxMatch = parseInt(el.id.replace('matchVal_',''));
      answers[_questionIdForIdx(qIdxMatch)] = el.value;
      continue;
    }
    if (el.type === 'hidden' && el.name.startsWith('rdoAnswer') && el.value &&
        el.name !== el.id) {
      /* YESNO accumulated hidden */
      var parts2 = el.name.match(/rdoAnswer(\d+)$/);
      if (parts2) answers[_questionIdForIdx(parseInt(parts2[1]))] = el.value;
    }
  }
  return answers;
}

/* Map question index → QuestionId from hidden QuestionId{n} fields */
function _questionIdForIdx(idx) {
  var hid = document.querySelector('input[name=QuestionId'+idx+']');
  return hid ? hid.value : idx;
}

function doAutoSave() {
  var answers = collectAnswers();
  if (_saveIndicator) { _saveIndicator.textContent = 'Saving…'; _saveIndicator.style.color = '#93c5fd'; }
  fetch('autosave.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ examId: EXAM_ID, action: 'save', answers: answers })
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    // A successful autosave proves the student is actively taking the
    // exam even if they haven't moved the mouse — count it as activity
    // for the session-expiry countdown banner (see includes/header.php).
    if (d.ok && typeof window.__resetSessionCountdown === 'function') {
      window.__resetSessionCountdown();
    }
    if (_saveIndicator) {
      _saveIndicator.textContent = d.ok
        ? ('Draft saved ✓ ' + new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}))
        : 'Save failed';
      _saveIndicator.style.color = d.ok ? '#86efac' : '#fca5a5';
    }
  })
  .catch(function() {
    if (_saveIndicator) { _saveIndicator.textContent = 'Offline — not saved'; _saveIndicator.style.color = '#fca5a5'; }
  });
}

/* ═══════════════════════════════════════════════════════════════════════════
   LOCKDOWN / PROCTORING  (only active when proctor_lock = 1 on this exam)
   ═══════════════════════════════════════════════════════════════════════════
   Behaviour:
     • On exam load: request fullscreen automatically.
     • Any focus loss (alt-tab, another app) or tab-switch triggers a violation.
     • Violation 1 & 2: show a warning overlay — student clicks Resume to continue.
       Exam timer keeps running during the overlay.
     • Violation 3: overlay says the exam is being submitted, form auto-submits.
     • Every violation is logged server-side via proctor-violation.php.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  var PROCTOR_ACTIVE = EXAM_CONFIG.proctorActive;
  if (!PROCTOR_ACTIVE) return;

  var MAX_VIOLATIONS  = 3;
  var violationCount  = 0;
  var overlayVisible  = false;
  var _ignoreBlur     = false;   // set true briefly after programmatic fullscreen changes

  /* ── Inject overlay HTML ──────────────────────────────────────────────── */
  var overlay = document.createElement('div');
  overlay.id  = 'proctorOverlay';
  overlay.innerHTML = [
    '<div id="proctorBox">',
    '  <div id="proctorIcon">&#9888;</div>',
    '  <h2 id="proctorTitle">Focus Lost</h2>',
    '  <p  id="proctorMsg"></p>',
    '  <div id="proctorStrike"></div>',
    '  <button id="proctorResume" onclick="proctorResume()">Resume Exam</button>',
    '</div>'
  ].join('');
  overlay.style.cssText = [
    'display:none',
    'position:fixed',
    'inset:0',
    'background:rgba(15,10,40,0.92)',
    'z-index:99999',
    'align-items:center',
    'justify-content:center',
    'font-family:system-ui,sans-serif'
  ].join(';');
  document.body.appendChild(overlay);

  /* Inline styles for the inner box */
  var style = document.createElement('style');
  style.textContent = [
    '#proctorBox{background:#1e1b4b;border:2px solid #7c3aed;border-radius:16px;',
    'padding:40px 48px;max-width:480px;text-align:center;color:#e0e7ff;}',
    '#proctorIcon{font-size:3.5rem;margin-bottom:8px;}',
    '#proctorTitle{font-size:1.6rem;font-weight:700;color:#f5d0fe;margin:0 0 12px;}',
    '#proctorMsg{font-size:1rem;color:#c4b5fd;line-height:1.6;margin:0 0 16px;}',
    '#proctorStrike{font-size:.85rem;color:#a78bfa;margin-bottom:24px;letter-spacing:.5px;}',
    '#proctorResume{background:#7c3aed;color:#fff;border:none;border-radius:8px;',
    'padding:12px 32px;font-size:1rem;font-weight:600;cursor:pointer;}',
    '#proctorResume:hover{background:#6d28d9;}'
  ].join('');
  document.head.appendChild(style);

  /* ── Request fullscreen on load ───────────────────────────────────────── */
  function enterFullscreen() {
    _ignoreBlur = true;
    var el = document.documentElement;
    var fn = el.requestFullscreen || el.webkitRequestFullscreen || el.mozRequestFullScreen || el.msRequestFullscreen;
    if (fn) {
      fn.call(el).catch(function() {}).finally(function() {
        setTimeout(function() { _ignoreBlur = false; }, 800);
      });
    } else {
      setTimeout(function() { _ignoreBlur = false; }, 800);
    }
  }

  /* Delay slightly so page is interactive before we request fullscreen */
  setTimeout(enterFullscreen, 600);

  /* ── Show warning / submit overlay ───────────────────────────────────── */
  function showOverlay(type) {
    if (overlayVisible) return;    // don't stack overlays
    overlayVisible = true;

    var strikes = '';
    for (var i = 0; i < MAX_VIOLATIONS; i++) {
      strikes += '<span style="font-size:1.4rem;margin:0 3px;color:' +
                 (i < violationCount ? '#f87171' : '#4b5563') + ';">&#9632;</span>';
    }
    document.getElementById('proctorStrike').innerHTML = 'Violations: ' + strikes;

    if (type === 'autosubmit') {
      document.getElementById('proctorIcon').textContent  = '🚫';
      document.getElementById('proctorTitle').textContent = 'Exam Terminated';
      document.getElementById('proctorMsg').textContent   =
        'You switched away from the exam 3 times. Your answers are being submitted now.';
      document.getElementById('proctorResume').style.display = 'none';
    } else {
      document.getElementById('proctorIcon').textContent  = '⚠️';
      document.getElementById('proctorTitle').textContent = 'You Left the Exam Window';
      document.getElementById('proctorMsg').innerHTML     =
        'Switching to other applications or tabs is not allowed during a lockdown exam.<br>' +
        '<strong style="color:#fbbf24;">Warning ' + violationCount + ' of ' + MAX_VIOLATIONS + '</strong> — ' +
        'one more violation after this will auto-submit your exam.';
      document.getElementById('proctorResume').style.display = '';
    }

    overlay.style.display = 'flex';
  }

  /* ── Resume button ────────────────────────────────────────────────────── */
  window.proctorResume = function() {
    overlay.style.display = 'none';
    overlayVisible = false;
    enterFullscreen();
  };

  /* ── Log violation to server ──────────────────────────────────────────── */
  function logViolation(vtype) {
    var hdnViolations = document.getElementById('hdnViolations');
    if (hdnViolations) hdnViolations.value = violationCount;

    var csrfInput = document.querySelector('input[name=csrf_token]');
    fetch('proctor-violation.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        examId:       EXAM_ID,
        type:         vtype,
        violationNum: violationCount,
        csrf_token:   csrfInput ? csrfInput.value : ''
      })
    }).catch(function() {});    // fire-and-forget; never block the UI
  }

  /* ── Core violation handler ───────────────────────────────────────────── */
  function handleViolation(vtype) {
    if (overlayVisible) return;
    violationCount++;
    logViolation(vtype);

    if (violationCount >= MAX_VIOLATIONS) {
      showOverlay('autosubmit');
      /* Give the student 2 seconds to read the message, then submit */
      setTimeout(function() {
        document.getElementById('frmWriteExam').submit();
      }, 2000);
    } else {
      showOverlay('warning');
    }
  }

  /* ── Event listeners ──────────────────────────────────────────────────── */

  /* Window blur: fires when the browser window loses focus entirely
     (alt-tab to another app, clicking another window, etc.)             */
  window.addEventListener('blur', function() {
    if (_ignoreBlur) return;
    handleViolation('focus_lost');
  });

  /* Page Visibility API: fires when switching browser tabs             */
  document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
      handleViolation('tab_switch');
    }
  });

  /* Fullscreen exit: fires when the user presses Escape or otherwise
     leaves fullscreen without using the Resume button                   */
  document.addEventListener('fullscreenchange', function() {
    if (!document.fullscreenElement && !overlayVisible) {
      handleViolation('fullscreen_exit');
    }
  });
  document.addEventListener('webkitfullscreenchange', function() {
    if (!document.webkitFullscreenElement && !overlayVisible) {
      handleViolation('fullscreen_exit');
    }
  });

  /* Block common keyboard shortcuts that bypass the browser
     (these are best-effort; the OS can intercept before the browser)   */
  document.addEventListener('keydown', function(e) {
    /* Alt+Tab, Alt+F4, Win key (Meta), Cmd+Tab (Mac) */
    if ((e.altKey && (e.key === 'Tab' || e.key === 'F4')) ||
         e.key === 'Meta' ||
        (e.metaKey && e.key === 'Tab')) {
      e.preventDefault();
    }
    /* F11 fullscreen toggle — re-enter fullscreen instead */
    if (e.key === 'F11') {
      e.preventDefault();
      enterFullscreen();
    }
  });

  /* Block right-click context menu during lockdown */
  document.addEventListener('contextmenu', function(e) { e.preventDefault(); });

})();
/* ── End proctoring ─────────────────── */

/* ── Autosave timer setup ───────────────────────────────────────────────
   Intervals driven by exam_settings (migration_v18).
   AUTOSAVE_INTERVAL_MS = 0 means periodic timer is disabled for this exam.
───────────────────────────────────────────────────────────────────────────── */
var AUTOSAVE_INTERVAL_MS = EXAM_CONFIG.autosaveIntervalMs;
var AUTOSAVE_DEBOUNCE_MS = EXAM_CONFIG.autosaveDebounceMs;

if (AUTOSAVE_INTERVAL_MS > 0) {
  _saveTimer = setInterval(doAutoSave, AUTOSAVE_INTERVAL_MS);
}

document.getElementById('frmWriteExam').addEventListener('change', function() {
  clearTimeout(window._chgDebounce);
  window._chgDebounce = setTimeout(doAutoSave, AUTOSAVE_DEBOUNCE_MS);
});

/* Clear draft on submit */
document.getElementById('frmWriteExam').addEventListener('submit', function() {
  try {
    navigator.sendBeacon('autosave.php', JSON.stringify({ examId: EXAM_ID, action: 'clear' }));
  } catch(ex) {}
});

/* ═══════════════════════════════════════════════════════════════════════════
   BEHAVIOUR TRACKING  (always active — logs to exam_events for admin review)
   Complements proctor mode: proctor enforces rules, this just records counts.
   All calls are fire-and-forget and never interrupt the student experience.
   ═══════════════════════════════════════════════════════════════════════════ */
(function behaviorTracker() {
  var LOG_URL = 'log-event.php';

  function logEvent(type) {
    try {
      var payload = JSON.stringify({ examId: EXAM_ID, eventType: type });
      /* sendBeacon is available in all modern browsers and works on page unload */
      if (navigator.sendBeacon) {
        navigator.sendBeacon(LOG_URL, payload);
      } else {
        fetch(LOG_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: payload,
          keepalive: true
        }).catch(function(){});
      }
    } catch(e) {}
  }

  /* Tab switch — Page Visibility API */
  document.addEventListener('visibilitychange', function() {
    if (document.hidden) logEvent('tab_switch');
  });

  /* Copy / Cut on the exam page */
  document.addEventListener('copy', function() { logEvent('copy'); });
  document.addEventListener('cut',  function() { logEvent('copy'); }); // treat cut same as copy

  /* Paste on the exam page */
  document.addEventListener('paste', function() { logEvent('paste'); });

  /* Browser refresh / navigate away */
  window.addEventListener('beforeunload', function() { logEvent('browser_refresh'); });

})();
/* ── End behaviour tracking ─────────────────────────────── */

/* ═══════════════════════════════════════════════════════════════════════════
   LAYOUT TOGGLE — Stacked (default, unchanged) vs. Side-by-side (comparison)
   Purely visual: swaps a CSS class on #examBody. Remembered via localStorage
   so flipping back and forth to compare survives a page reload/timer resume.
   ═══════════════════════════════════════════════════════════════════════════ */
function setExamLayout(mode) {
  var body = document.getElementById('examBody');
  if (!body) return;
  body.classList.toggle('layout-split', mode === 'split');
  var stackedBtn = document.getElementById('layoutStackedBtn');
  var splitBtn   = document.getElementById('layoutSplitBtn');
  if (stackedBtn) stackedBtn.classList.toggle('active', mode !== 'split');
  if (splitBtn)   splitBtn.classList.toggle('active',   mode === 'split');
  try { localStorage.setItem('examLayoutMode_' + EXAM_ID, mode); } catch (e) {}
}
(function () {
  var saved = null;
  try { saved = localStorage.getItem('examLayoutMode_' + EXAM_ID); } catch (e) {}
  if (saved === 'split') setExamLayout('split');
})();

/* ═══════════════════════════════════════════════════════════════════════════
   ONE-QUESTION-AT-A-TIME NAVIGATOR
   Purely a display layer on top of everything above: it only toggles which
   .q-card (plus its owning section header / case-study panel, via the
   data-section/data-casestudy attributes rendered on each card) has the
   .q-hidden class. All form fields stay in the DOM and submit exactly as
   before — collectAnswers()/autosave/validateExam/proctoring are untouched.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  var qOrder = [];
  document.querySelectorAll('.q-card').forEach(function (card) {
    var m = card.id.match(/^qrow(\d+)$/);
    if (m) qOrder.push(parseInt(m[1], 10));
  });
  if (!qOrder.length) return; // no questions — nothing to navigate

  var curPos     = 0;
  var skippedSet = {};

  /* Every question's markup — including <img data-src="…"> for any question/
     answer image — is rendered into the page up front by write.php; only
     visibility is toggled here. Swapping data-src → src on demand (rather
     than a real src= from the server) means the browser only ever fetches
     the images for a question the student actually reaches, instead of
     eagerly downloading every image in the exam on page load regardless of
     whether display is none. Idempotent — data-src is removed after the
     swap, so calling this twice on the same card is a harmless no-op. */
  function resolveLazyImages(scopeEl) {
    if (!scopeEl) return;
    scopeEl.querySelectorAll('img[data-src]').forEach(function (img) {
      img.src = img.getAttribute('data-src');
      img.removeAttribute('data-src');
    });
  }

  function qShow(pos) {
    if (pos < 0) pos = 0;
    if (pos > qOrder.length - 1) pos = qOrder.length - 1;
    curPos = pos;
    var curIdx = qOrder[curPos];

    document.querySelectorAll('.q-card, .exam-section-hdr, .case-study-panel')
      .forEach(function (el) { el.classList.add('q-hidden'); });

    var card = document.getElementById('qrow' + curIdx);
    if (card) {
      card.classList.remove('q-hidden');
      resolveLazyImages(card);
      var sec = card.getAttribute('data-section');
      var cs  = card.getAttribute('data-casestudy');
      if (sec) {
        var secEl = document.getElementById('secHdr_' + sec);
        if (secEl) secEl.classList.remove('q-hidden');
      }
      if (cs && cs !== '0') {
        var csEl = document.getElementById('csWrap_' + cs);
        if (csEl) csEl.classList.remove('q-hidden');
      }
      card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // Prefetch the next question's image(s) in the background so clicking
    // Next feels instant instead of popping in an image after the click —
    // still only ever the one neighbor, not the whole exam.
    var nextIdx = qOrder[curPos + 1];
    if (nextIdx) {
      var nextCard = document.getElementById('qrow' + nextIdx);
      if (nextCard) resolveLazyImages(nextCard);
    }

    updateNavUI();
  }

  function updateNavUI() {
    var curIdx = qOrder[curPos];
    document.querySelectorAll('.qnav-count').forEach(function (el) {
      el.textContent = 'Question ' + (curPos + 1) + ' of ' + qOrder.length;
    });
    document.querySelectorAll('.qnav-prev').forEach(function (b) { b.disabled = curPos === 0; });
    document.querySelectorAll('.qnav-next').forEach(function (b) { b.disabled = curPos === qOrder.length - 1; });
    document.querySelectorAll('.qnav-skip').forEach(function (b) {
      b.style.visibility = curPos === qOrder.length - 1 ? 'hidden' : 'visible';
    });
    document.querySelectorAll('.qpalette-btn').forEach(function (btn) {
      var idx = parseInt(btn.getAttribute('data-idx'), 10);
      btn.classList.toggle('qp-current',  idx === curIdx);
      btn.classList.toggle('qp-answered', !!answeredSet[idx]);
      btn.classList.toggle('qp-skipped',  !!skippedSet[idx] && !answeredSet[idx]);
    });
  }

  window.qGoto = function (idx) {
    var pos = qOrder.indexOf(idx);
    if (pos !== -1) qShow(pos);
  };
  window.qPrev = function () { qShow(curPos - 1); };
  window.qNext = function () { qShow(curPos + 1); };
  window.qSkip = function () {
    var curIdx = qOrder[curPos];
    if (!answeredSet[curIdx]) skippedSet[curIdx] = true;
    qShow(curPos + 1);
  };

  /* Wrap markAnswered (declared earlier in this file) so that actually
     answering a question — even one previously marked Skipped — clears its
     skipped flag and refreshes the palette color immediately. */
  var _origMarkAnswered = markAnswered;
  markAnswered = function (idx, isAnswered) {
    _origMarkAnswered(idx, isAnswered);
    if (isAnswered && skippedSet[idx]) delete skippedSet[idx];
    updateNavUI();
  };

  qShow(0);
})();

/* ═══════════════════════════════════════════════════════════════════════════
   SUBJECT TABS — only rendered by write.php when the drawn pool spans more
   than one subject (a configured multi-subject Exam Pattern, or an exam
   built via exam/question-bank-builder.php). Purely a filter layer on top
   of the palette above: "All" shows every number, picking a subject hides
   every .qpalette-btn whose data-section doesn't match and jumps straight
   to that subject's first question via the qGoto() exposed above. Nothing
   here touches answers/autosave/scoring — nudges the same navigator.
   ═══════════════════════════════════════════════════════════════════════════ */
function qFilterSubject(sectionId, btnEl) {
  document.querySelectorAll('.qsubject-tab').forEach(function (b) { b.classList.remove('active'); });
  if (btnEl) btnEl.classList.add('active');

  var palette = document.getElementById('qPalette');
  if (palette) {
    palette.querySelectorAll('.qpalette-btn').forEach(function (btn) {
      var sec = btn.getAttribute('data-section') || '';
      var match = (sectionId === 'all' || sec === String(sectionId));
      btn.classList.toggle('qp-filtered-out', !match);
    });
  }

  if (sectionId !== 'all' && btnEl) {
    var startIdx = parseInt(btnEl.getAttribute('data-start'), 10);
    if (!isNaN(startIdx) && typeof window.qGoto === 'function') window.qGoto(startIdx);
  }
}
window.qFilterSubject = qFilterSubject;
