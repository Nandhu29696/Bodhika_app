/**
 * assets/password-toggle.js — shared show/hide toggle for every
 * <input type="password"> on the page (login, register, forgot/change
 * password, forced reset, admin add-user/add-student forms, etc.).
 *
 * Fully automatic, zero markup required: just load this script anywhere
 * after the password field(s) exist in the DOM (e.g. right before
 * </body>, or once via includes/header.php for pages that share that
 * layout — every page under that layout gets it for free). It finds
 * every input[type=password], wraps it, and adds a 👁/🙈 button that
 * flips the field between password and text. Injects its own tiny
 * stylesheet so it looks right even on legacy pages that don't load
 * assets/style.css. Safe to include on a page more than once, and safe
 * to call again (window.initPasswordToggles()) after dynamically adding
 * more password fields later — already-wired fields are skipped.
 */
(function () {
  var STYLE_ID = 'pwd-toggle-style';

  function injectStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent =
      '.pwd-toggle-wrap{position:relative;}' +
      '.pwd-toggle-wrap input{padding-right:38px !important;box-sizing:border-box;}' +
      '.pwd-toggle-btn{position:absolute;right:6px;top:50%;transform:translateY(-50%);' +
        'background:none;border:none;cursor:pointer;color:var(--clr-text-muted,#6b7280);' +
        'font-size:1.05rem;line-height:1;padding:4px 6px;}' +
      '.pwd-toggle-btn:hover{color:var(--clr-primary,#0369a1);}' +
      '.pwd-toggle-btn:focus{outline:2px solid var(--clr-primary,#0369a1);outline-offset:1px;border-radius:4px;}' +
      '.pwd-toggle-wrap input:disabled ~ .pwd-toggle-btn{opacity:.4;cursor:not-allowed;}';
    document.head.appendChild(style);
  }

  function wire(input) {
    if (!input || input.dataset.pwdToggle === 'done') return;
    input.dataset.pwdToggle = 'done';

    var wrap = document.createElement('div');
    wrap.className = 'pwd-toggle-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pwd-toggle-btn';
    btn.setAttribute('aria-label', 'Show password');
    btn.textContent = '👁'; // 👁

    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? '🙈' : '👁'; // 🙈 : 👁
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });

    wrap.appendChild(btn);
  }

  function init() {
    injectStyle();
    var fields = document.querySelectorAll('input[type="password"]');
    for (var i = 0; i < fields.length; i++) wire(fields[i]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.initPasswordToggles = init;
})();
