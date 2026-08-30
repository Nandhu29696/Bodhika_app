/**
 * assets/phone-input.js — shared "country code + numeric mobile number" widget.
 *
 * Keeps one copy of the per-country digit rules for every page that uses
 * the .phone-input-wrap markup (select + tel input), instead of each page
 * duplicating its own validation script.
 *
 * Rules mirror Lib/Phone.php — keep both in sync if a country is added.
 *
 * Usage:
 *   <div class="phone-input-wrap" id="phoneInputWrap">
 *     <select id="txtCountryCode" class="phone-cc-select">...</select>
 *     <input  id="txtMobileNum"   class="phone-num-input" inputmode="numeric">
 *   </div>
 *   <small id="mobileHint" class="mobile-hint"></small>
 *   <small id="mobileError" class="mobile-error" role="alert" aria-live="polite"></small>
 *   <script src="<?php echo $_root; ?>assets/phone-input.js"></script>
 *   <script>initPhoneField();</script>
 */
var MOBILE_RULES = {
  '+91' : {min:10,max:10,lead:/^[6-9]/,   hint:'10 digits, starts with 6–9 (India)'},
  '+1'  : {min:10,max:10,lead:null,        hint:'10 digits (USA / Canada)'},
  '+44' : {min:10,max:10,lead:null,        hint:'10 digits (UK)'},
  '+61' : {min:9, max:9, lead:null,        hint:'9 digits (Australia)'},
  '+64' : {min:8, max:9, lead:null,        hint:'8–9 digits (New Zealand)'},
  '+971': {min:9, max:9, lead:null,        hint:'9 digits (UAE)'},
  '+966': {min:9, max:9, lead:/^[5]/,      hint:'9 digits, starts with 5 (Saudi Arabia)'},
  '+65' : {min:8, max:8, lead:/^[689]/,    hint:'8 digits, starts with 6/8/9 (Singapore)'},
  '+60' : {min:9, max:10,lead:/^[1]/,      hint:'9–10 digits, starts with 1 (Malaysia)'},
  '+94' : {min:9, max:9, lead:/^[7]/,      hint:'9 digits, starts with 7 (Sri Lanka)'},
  '+92' : {min:10,max:10,lead:/^[3]/,      hint:'10 digits, starts with 3 (Pakistan)'},
  '+880': {min:10,max:10,lead:/^[1]/,      hint:'10 digits, starts with 1 (Bangladesh)'},
  '+977': {min:10,max:10,lead:/^[9]/,      hint:'10 digits, starts with 9 (Nepal)'},
  '+81' : {min:10,max:11,lead:null,        hint:'10–11 digits (Japan)'},
  '+82' : {min:9, max:10,lead:null,        hint:'9–10 digits (South Korea)'},
  '+86' : {min:11,max:11,lead:null,        hint:'11 digits (China)'},
  '+852': {min:8, max:8, lead:null,        hint:'8 digits (Hong Kong)'},
  '+49' : {min:10,max:12,lead:null,        hint:'10–12 digits (Germany)'},
  '+33' : {min:9, max:9, lead:null,        hint:'9 digits (France)'},
  '+39' : {min:9, max:10,lead:null,        hint:'9–10 digits (Italy)'},
  '+34' : {min:9, max:9, lead:null,        hint:'9 digits (Spain)'},
  '+31' : {min:9, max:9, lead:null,        hint:'9 digits (Netherlands)'},
  '+46' : {min:9, max:9, lead:null,        hint:'9 digits (Sweden)'},
  '+47' : {min:8, max:8, lead:null,        hint:'8 digits (Norway)'},
  '+45' : {min:8, max:8, lead:null,        hint:'8 digits (Denmark)'},
  '+41' : {min:9, max:9, lead:null,        hint:'9 digits (Switzerland)'},
  '+7'  : {min:10,max:10,lead:null,        hint:'10 digits (Russia)'},
  '+55' : {min:10,max:11,lead:null,        hint:'10–11 digits (Brazil)'},
  '+52' : {min:10,max:10,lead:null,        hint:'10 digits (Mexico)'},
  '+54' : {min:10,max:10,lead:null,        hint:'10 digits (Argentina)'},
  '+27' : {min:9, max:9, lead:null,        hint:'9 digits (South Africa)'},
  '+234': {min:10,max:10,lead:/^[07-9]/,   hint:'10 digits (Nigeria)'},
  '+254': {min:9, max:9, lead:/^[7]/,      hint:'9 digits, starts with 7 (Kenya)'},
  '+20' : {min:10,max:10,lead:null,        hint:'10 digits (Egypt)'},
  '+212': {min:9, max:9, lead:null,        hint:'9 digits (Morocco)'}
};

/**
 * Wire up one phone-input-wrap widget. All ids are optional overrides —
 * defaults match the markup convention used across the app.
 */
function initPhoneField(opts) {
  opts = opts || {};
  var ccEl   = document.getElementById(opts.ccId    || 'txtCountryCode');
  var numEl  = document.getElementById(opts.numId   || 'txtMobileNum');
  var hintEl = document.getElementById(opts.hintId  || 'mobileHint');
  var errEl  = document.getElementById(opts.errId   || 'mobileError');
  var wrap   = document.getElementById(opts.wrapId  || 'phoneInputWrap');
  if (!ccEl || !numEl || !wrap) return;

  function onCountryChange() {
    var rule = MOBILE_RULES[ccEl.value];
    if (hintEl) hintEl.textContent = rule ? ('Format: ' + rule.hint) : 'Enter 6–15 digit mobile number';
    if (rule) {
      numEl.placeholder = rule.min === rule.max ? (rule.min + ' digits') : (rule.min + '–' + rule.max + ' digits');
      numEl.maxLength = rule.max;
    } else {
      numEl.placeholder = 'Mobile number';
      numEl.maxLength = 15;
    }
    validateMobile();
  }

  function setError(msg) {
    wrap.classList.add('error');
    wrap.classList.remove('ok');
    if (errEl) {
      errEl.style.color = '#e53e3e';
      errEl.textContent = msg;
      errEl.classList.add('visible');
    }
  }

  function validateMobile() {
    var cc     = ccEl.value;
    var digits = numEl.value.replace(/\D/g, '');
    if (numEl.value !== digits) numEl.value = digits; // strip non-digits live

    wrap.classList.remove('ok', 'error');
    if (errEl) { errEl.classList.remove('visible'); errEl.textContent = ''; }

    if (digits.length === 0) return true; // optional field

    var rule = MOBILE_RULES[cc];
    if (!rule) {
      if (digits.length < 6 || digits.length > 15) {
        setError('Enter 6–15 digits for this country.');
        return false;
      }
      wrap.classList.add('ok');
      return true;
    }
    if (digits.length < rule.min || digits.length > rule.max) {
      var expected = rule.min === rule.max ? (rule.min + ' digits') : (rule.min + '–' + rule.max + ' digits');
      setError('Expected ' + expected + ' for ' + cc + ' (entered ' + digits.length + ').');
      return false;
    }
    if (rule.lead && !rule.lead.test(digits)) {
      var m = rule.hint.match(/starts with (.+?) \(/);
      setError('Number for ' + cc + ' should start with ' + (m ? m[1] : 'a different digit') + '.');
      return false;
    }
    wrap.classList.add('ok');
    if (errEl) {
      errEl.style.color = '#38a169';
      errEl.textContent = '✓ Valid';
      errEl.classList.add('visible');
    }
    return true;
  }

  ccEl.addEventListener('change', onCountryChange);
  numEl.addEventListener('input', validateMobile);
  onCountryChange();

  // Expose a validator the enclosing form can call on submit.
  wrap._validatePhone = validateMobile;
}
