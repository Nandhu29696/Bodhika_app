<?php
/**
 * Admin/AppSettings.php — Application Settings
 *
 * Sections:
 *   • Two-Factor Authentication (OTP) — enable/disable email/SMS channels
 *   • Email / SMTP                    — driver, SMTP credentials
 *   • SMS Gateway                     — MSG91 / Twilio / Custom URL
 *   • Certificate                     — signatory details, merit grade-band cutoffs
 *
 * Requires: migration_v30.sql (app_settings table), migration_v37.sql (certificates).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/AppSettings.php';
require_once __DIR__ . '/../Lib/Otp.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }
Auth::validateCsrf();

AppSettings::loadAll();

$success = '';
$errors  = [];
$tab     = in_array($_GET['tab'] ?? '', ['email','sms','payment','certificate','test']) ? $_GET['tab'] : 'otp';

/* ── Handle POST ──────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_otp') {
        $tab = 'otp';
        AppSettings::setMany([
            'otp_email_enabled'  => isset($_POST['otp_email_enabled'])  ? '1' : '0',
            'otp_sms_enabled'    => isset($_POST['otp_sms_enabled'])    ? '1' : '0',
            'otp_expiry_minutes' => (string)max(1, min(60, (int)($_POST['otp_expiry_minutes'] ?? 10))),
            'otp_length'         => (string)in_array((int)($_POST['otp_length'] ?? 6), [4,5,6,7,8]) ? (int)$_POST['otp_length'] : 6,
        ]);
        AppSettings::flushCache(); AppSettings::loadAll();
        $success = 'OTP settings saved.';
    }

    if ($action === 'save_email') {
        $tab = 'email';
        AppSettings::setMany([
            'email_driver'          => in_array($_POST['email_driver'] ?? '', ['mail','smtp']) ? $_POST['email_driver'] : 'mail',
            'email_from_address'    => trim($_POST['email_from_address']    ?? ''),
            'email_from_name'       => trim($_POST['email_from_name']       ?? ''),
            'email_smtp_host'       => trim($_POST['email_smtp_host']       ?? ''),
            'email_smtp_port'       => (string)max(1, (int)($_POST['email_smtp_port'] ?? 587)),
            'email_smtp_encryption' => in_array($_POST['email_smtp_encryption'] ?? '', ['tls','ssl','']) ? $_POST['email_smtp_encryption'] : 'tls',
            'email_smtp_user'       => trim($_POST['email_smtp_user']       ?? ''),
            // Only update pass if a new one was entered
            'email_smtp_pass'       => $_POST['email_smtp_pass'] !== '' ? $_POST['email_smtp_pass'] : AppSettings::get('email_smtp_pass'),
        ]);
        AppSettings::flushCache(); AppSettings::loadAll();
        $success = 'Email settings saved.';
    }

    if ($action === 'save_sms') {
        $tab = 'sms';
        AppSettings::setMany([
            'sms_gateway'        => in_array($_POST['sms_gateway'] ?? '', ['msg91','twilio','custom']) ? $_POST['sms_gateway'] : 'msg91',
            'sms_sender_id'      => trim($_POST['sms_sender_id']      ?? ''),
            'sms_api_key'        => trim($_POST['sms_api_key']        ?? ''),
            'sms_msg91_flow_id'  => trim($_POST['sms_msg91_flow_id']  ?? ''),
            'sms_twilio_sid'     => trim($_POST['sms_twilio_sid']     ?? ''),
            'sms_twilio_from'    => trim($_POST['sms_twilio_from']    ?? ''),
            'sms_custom_url'     => trim($_POST['sms_custom_url']     ?? ''),
            'sms_custom_body'    => trim($_POST['sms_custom_body']    ?? ''),
        ]);
        AppSettings::flushCache(); AppSettings::loadAll();
        $success = 'SMS settings saved.';
    }

    if ($action === 'save_payment') {
        $tab    = 'payment';
        $qrPath = AppSettings::get('payment_qr_image');

        if (!empty($_POST['remove_qr_image']) && $qrPath !== '') {
            @unlink(__DIR__ . '/' . $qrPath);
            $qrPath = '';
        }

        if (!empty($_FILES['qr_image_file']['tmp_name'])) {
            $file = $_FILES['qr_image_file'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $errors[] = 'QR image must be JPG, PNG, GIF, or WEBP.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'QR image must be 2MB or smaller.';
            } else {
                $dir = __DIR__ . '/images/payment/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $name = 'qr_' . uniqid('', true) . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $name)) {
                    if ($qrPath !== '') @unlink(__DIR__ . '/' . $qrPath); // replace old file
                    $qrPath = 'images/payment/' . $name;
                } else {
                    $errors[] = 'Failed to save the uploaded QR image.';
                }
            }
        }

        if (!$errors) {
            AppSettings::setMany([
                'payment_upi_enabled'    => isset($_POST['payment_upi_enabled']) ? '1' : '0',
                'payment_upi_id'         => trim($_POST['payment_upi_id']         ?? ''),
                'payment_upi_payee_name' => trim($_POST['payment_upi_payee_name'] ?? ''),
                'payment_qr_image'       => $qrPath,
            ]);
            AppSettings::flushCache(); AppSettings::loadAll();
            $success = 'Payment settings saved.';
        }
    }

    if ($action === 'save_certificate') {
        $tab = 'certificate';

        $logoPath      = AppSettings::get('cert_logo', '../assets/riyatrix_cert_header.png');
        $signaturePath = AppSettings::get('cert_signature', '');

        if (!empty($_POST['remove_cert_logo']) && $logoPath !== '') {
            $logoAbs = __DIR__ . '/' . $logoPath;
            // Only delete files we own (uploaded into images/certificate/); never
            // unlink the shipped default asset under assets/.
            if (str_starts_with($logoPath, 'images/certificate/')) @unlink($logoAbs);
            $logoPath = '';
        }

        if (!empty($_FILES['cert_logo_file']['tmp_name'])) {
            $file = $_FILES['cert_logo_file'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
                $errors[] = 'Logo image must be JPG, PNG, GIF, WEBP, or SVG.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Logo image must be 2MB or smaller.';
            } else {
                $dir = __DIR__ . '/images/certificate/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $name = 'logo_' . uniqid('', true) . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $name)) {
                    if (str_starts_with($logoPath, 'images/certificate/')) @unlink(__DIR__ . '/' . $logoPath); // replace old upload
                    $logoPath = 'images/certificate/' . $name;
                } else {
                    $errors[] = 'Failed to save the uploaded logo image.';
                }
            }
        }

        /* Digital signature — same upload/remove pattern as the logo above,
           saved alongside it under images/certificate/. Rendered on the
           certificate above the "Director" signature line instead of a
           blank underline. */
        if (!empty($_POST['remove_cert_signature']) && $signaturePath !== '') {
            $sigAbs = __DIR__ . '/' . $signaturePath;
            if (str_starts_with($signaturePath, 'images/certificate/')) @unlink($sigAbs);
            $signaturePath = '';
        }

        if (!empty($_FILES['cert_signature_file']['tmp_name'])) {
            $file = $_FILES['cert_signature_file'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
                $errors[] = 'Signature image must be JPG, PNG, GIF, WEBP, or SVG.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Signature image must be 2MB or smaller.';
            } else {
                $dir = __DIR__ . '/images/certificate/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $name = 'signature_' . uniqid('', true) . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $name)) {
                    if (str_starts_with($signaturePath, 'images/certificate/')) @unlink(__DIR__ . '/' . $signaturePath);
                    $signaturePath = 'images/certificate/' . $name;
                } else {
                    $errors[] = 'Failed to save the uploaded signature image.';
                }
            }
        }

        $thresholds = [
            'distinction' => 90, 'aplus' => 80, 'a' => 70, 'bplus' => 60, 'b' => 50,
        ];
        $thresholdSettings = [];
        foreach ($thresholds as $key => $default) {
            $val = (int)($_POST['cert_grade_' . $key] ?? $default);
            $thresholdSettings['cert_grade_' . $key] = (string)max(0, min(100, $val));
        }

        if (!$errors) {
            $signatoryTitle = trim($_POST['cert_signatory_title'] ?? '');
            AppSettings::setMany(array_merge([
                'cert_institute_name'    => trim($_POST['cert_institute_name']    ?? ''),
                'cert_institute_tagline' => trim($_POST['cert_institute_tagline'] ?? ''),
                'cert_signatory_name'    => trim($_POST['cert_signatory_name']  ?? ''),
                'cert_signatory_title'   => $signatoryTitle !== '' ? $signatoryTitle : 'Director',
                'cert_logo'              => $logoPath,
                'cert_signature'         => $signaturePath,
            ], $thresholdSettings));
            AppSettings::flushCache(); AppSettings::loadAll();
            $success = 'Certificate settings saved.';
        }
    }

    /* ── Test send ─────────────────────────────────────────────────────── */
    if ($action === 'test_email') {
        $tab    = 'test';
        $toAddr = trim($_POST['test_email_to'] ?? '');
        if ($toAddr && filter_var($toAddr, FILTER_VALIDATE_EMAIL)) {
            $ok = Otp::sendEmail($toAddr, 'Test User', '123456');
            $success = $ok
                ? "Test email sent to $toAddr. Check your inbox."
                : 'Test email FAILED — check email settings and server logs.';
        } else {
            $errors[] = 'Please enter a valid email address.';
        }
    }

    if ($action === 'test_sms') {
        $tab    = 'test';
        $toPhone = trim($_POST['test_sms_to'] ?? '');
        if ($toPhone !== '') {
            $ok = Otp::sendSms($toPhone, '123456');
            $success = $ok
                ? "Test SMS sent to $toPhone."
                : 'Test SMS FAILED — check SMS settings and server logs.';
        } else {
            $errors[] = 'Please enter a phone number.';
        }
    }
}

/* ── Current values ─────────────────────────────────────────────────────── */
$s = [
    'otp_email_enabled'    => AppSettings::get('otp_email_enabled', '0'),
    'otp_sms_enabled'      => AppSettings::get('otp_sms_enabled', '0'),
    'otp_expiry_minutes'   => AppSettings::get('otp_expiry_minutes', '10'),
    'otp_length'           => AppSettings::get('otp_length', '6'),
    'email_driver'         => AppSettings::get('email_driver', 'mail'),
    'email_from_address'   => AppSettings::get('email_from_address'),
    'email_from_name'      => AppSettings::get('email_from_name'),
    'email_smtp_host'      => AppSettings::get('email_smtp_host'),
    'email_smtp_port'      => AppSettings::get('email_smtp_port', '587'),
    'email_smtp_encryption'=> AppSettings::get('email_smtp_encryption', 'tls'),
    'email_smtp_user'      => AppSettings::get('email_smtp_user'),
    'sms_gateway'          => AppSettings::get('sms_gateway', 'msg91'),
    'sms_sender_id'        => AppSettings::get('sms_sender_id'),
    'sms_api_key'          => AppSettings::get('sms_api_key'),
    'sms_msg91_flow_id'    => AppSettings::get('sms_msg91_flow_id'),
    'sms_twilio_sid'       => AppSettings::get('sms_twilio_sid'),
    'sms_twilio_from'      => AppSettings::get('sms_twilio_from'),
    'sms_custom_url'       => AppSettings::get('sms_custom_url'),
    'sms_custom_body'      => AppSettings::get('sms_custom_body', '{"phone":"{PHONE}","message":"{MSG}"}'),
    'payment_upi_enabled'    => AppSettings::get('payment_upi_enabled', '0'),
    'payment_upi_id'         => AppSettings::get('payment_upi_id'),
    'payment_upi_payee_name' => AppSettings::get('payment_upi_payee_name'),
    'payment_qr_image'       => AppSettings::get('payment_qr_image'),
    'cert_institute_name'    => AppSettings::get('cert_institute_name',  APP_NAME),
    'cert_institute_tagline' => AppSettings::get('cert_institute_tagline', 'Learn • Practice • Succeed'),
    'cert_signatory_name'    => AppSettings::get('cert_signatory_name'),
    'cert_signatory_title'   => AppSettings::get('cert_signatory_title', 'Director') ?: 'Director',
    'cert_logo'              => AppSettings::get('cert_logo', '../assets/riyatrix_cert_header.png'),
    'cert_signature'         => AppSettings::get('cert_signature', ''),
    'cert_grade_distinction' => AppSettings::get('cert_grade_distinction', '90'),
    'cert_grade_aplus'       => AppSettings::get('cert_grade_aplus',       '80'),
    'cert_grade_a'           => AppSettings::get('cert_grade_a',           '70'),
    'cert_grade_bplus'       => AppSettings::get('cert_grade_bplus',       '60'),
    'cert_grade_b'           => AppSettings::get('cert_grade_b',           '50'),
];

function sel(array $s, string $key, string $val): string {
    return $s[$key] === $val ? ' selected' : '';
}
function chk(array $s, string $key): string {
    return $s[$key] === '1' ? ' checked' : '';
}
function esc(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES);
}

$pageTitle = 'Application Settings';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .settings-tabs { display:flex;gap:0;border-bottom:2px solid var(--clr-border);margin-bottom:1.4rem;flex-wrap:wrap; }
  .settings-tab  { padding:9px 18px;font-size:.88rem;font-weight:600;cursor:pointer;
                   color:var(--tx-muted);border-bottom:3px solid transparent;margin-bottom:-2px;
                   text-decoration:none;transition:.15s; }
  .settings-tab:hover { color:var(--clr-primary); }
  .settings-tab.active { color:var(--clr-primary);border-bottom-color:var(--clr-primary); }

  .toggle-row { display:flex;align-items:center;justify-content:space-between;gap:1rem;
                padding:12px 0;border-bottom:1px solid var(--clr-border); }
  .toggle-row:last-child { border-bottom:none; }
  .toggle-label { font-weight:600; }
  .toggle-hint  { font-size:.8rem;color:var(--tx-muted);margin-top:2px; }

  /* iOS-style toggle */
  .toggle-switch { position:relative;width:48px;height:26px;flex-shrink:0; }
  .toggle-switch input { opacity:0;width:0;height:0; }
  .toggle-slider { position:absolute;inset:0;background:#cbd5e1;border-radius:26px;
                   cursor:pointer;transition:.2s; }
  .toggle-slider::before { content:'';position:absolute;width:20px;height:20px;
                            left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s; }
  .toggle-switch input:checked + .toggle-slider { background:var(--clr-primary); }
  .toggle-switch input:checked + .toggle-slider::before { transform:translateX(22px); }

  .settings-panel { max-width:640px; }
  .gateway-section { display:none; }
  .gateway-section.active { display:block; }

  .test-row { display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap; }
  .status-chip { display:inline-flex;align-items:center;gap:6px;padding:4px 12px;
                 border-radius:20px;font-size:.78rem;font-weight:700; }
  .chip-on  { background:#dcfce7;color:#065f46; }
  .chip-off { background:#f1f5f9;color:#64748b; }
</style>

<div class="card">
  <div class="card-header">
    <h2 style="margin:0;font-size:1.05rem;">⚙️ Application Settings</h2>
  </div>
  <div class="card-body">

    <!-- Tab bar -->
    <div class="settings-tabs">
      <a href="?tab=otp"   class="settings-tab <?= $tab==='otp'   ? 'active' : '' ?>">🔐 Two-Factor Auth</a>
      <a href="?tab=email" class="settings-tab <?= $tab==='email' ? 'active' : '' ?>">✉️ Email / SMTP</a>
      <a href="?tab=sms"   class="settings-tab <?= $tab==='sms'   ? 'active' : '' ?>">📱 SMS Gateway</a>
      <a href="?tab=payment" class="settings-tab <?= $tab==='payment' ? 'active' : '' ?>">💳 Payment / UPI</a>
      <a href="?tab=certificate" class="settings-tab <?= $tab==='certificate' ? 'active' : '' ?>">🎓 Certificate</a>
      <a href="?tab=test"  class="settings-tab <?= $tab==='test'  ? 'active' : '' ?>">🧪 Test Delivery</a>
    </div>

    <?php if ($success): ?>
      <div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger" style="margin-bottom:1rem;">
        <?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?>
      </div>
    <?php endif; ?>

    <!-- ════ OTP Settings ════ -->
    <?php if ($tab === 'otp'): ?>
    <div class="settings-panel">
      <p style="color:var(--tx-muted);font-size:.85rem;margin-bottom:1.2rem;">
        When enabled, users must enter a one-time code after entering their password.
        You can enable either channel or both — both will then send a code simultaneously.
      </p>

      <!-- Status summary -->
      <div style="display:flex;gap:.6rem;margin-bottom:1.2rem;flex-wrap:wrap;">
        <span class="status-chip <?= $s['otp_email_enabled']==='1' ? 'chip-on' : 'chip-off' ?>">
          <?= $s['otp_email_enabled']==='1' ? '✓' : '○' ?> Email OTP <?= $s['otp_email_enabled']==='1' ? 'ON' : 'OFF' ?>
        </span>
        <span class="status-chip <?= $s['otp_sms_enabled']==='1' ? 'chip-on' : 'chip-off' ?>">
          <?= $s['otp_sms_enabled']==='1' ? '✓' : '○' ?> SMS OTP <?= $s['otp_sms_enabled']==='1' ? 'ON' : 'OFF' ?>
        </span>
      </div>

      <form method="post" action="?tab=otp">
        <input type="hidden" name="csrf_token" value="<?= esc(Auth::csrfToken()) ?>">
        <input type="hidden" name="action"     value="save_otp">

        <div class="toggle-row">
          <div>
            <div class="toggle-label">Email OTP</div>
            <div class="toggle-hint">Send a code to the user's email address after password verification.</div>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" name="otp_email_enabled" value="1"<?= chk($s,'otp_email_enabled') ?>>
            <span class="toggle-slider"></span>
          </label>
        </div>

        <div class="toggle-row">
          <div>
            <div class="toggle-label">SMS OTP</div>
            <div class="toggle-hint">Send a code via SMS to the user's registered mobile number.</div>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" name="otp_sms_enabled" value="1"<?= chk($s,'otp_sms_enabled') ?>>
            <span class="toggle-slider"></span>
          </label>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:1.2rem;">
          <div class="form-group">
            <label class="form-label">Code Expiry (minutes)</label>
            <input type="number" class="form-control" name="otp_expiry_minutes"
                   min="1" max="60" value="<?= esc($s['otp_expiry_minutes']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Code Length (digits)</label>
            <select class="form-control" name="otp_length">
              <?php foreach ([4,5,6,7,8] as $len): ?>
                <option value="<?= $len ?>"<?= sel($s,'otp_length',(string)$len) ?>><?= $len ?> digits</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div style="margin-top:1rem;">
          <button type="submit" class="btn btn-primary">Save OTP Settings</button>
        </div>
      </form>

      <?php if ($s['otp_email_enabled']==='1' || $s['otp_sms_enabled']==='1'): ?>
      <div style="margin-top:1.2rem;padding:12px 16px;background:#fef3c7;border-left:4px solid #f59e0b;border-radius:4px;font-size:.84rem;color:#78350f;">
        ⚠️ <strong>OTP is currently active.</strong>
        Make sure email<?= $s['otp_email_enabled']==='1' ? '' : ' (disabled)' ?> and
        SMS<?= $s['otp_sms_enabled']==='1' ? '' : ' (disabled)' ?> delivery are configured and tested
        before enabling for all users. Use the <a href="?tab=test">Test Delivery</a> tab to verify.
      </div>
      <?php endif; ?>
    </div>

    <!-- ════ Email Settings ════ -->
    <?php elseif ($tab === 'email'): ?>
    <div class="settings-panel">
      <form method="post" action="?tab=email">
        <input type="hidden" name="csrf_token" value="<?= esc(Auth::csrfToken()) ?>">
        <input type="hidden" name="action"     value="save_email">

        <div class="form-group">
          <label class="form-label">Email Driver</label>
          <select class="form-control" name="email_driver" onchange="toggleSmtp(this.value)" id="emailDriver">
            <option value="mail"<?= sel($s,'email_driver','mail') ?>>PHP mail() — simple, no SMTP needed</option>
            <option value="smtp"<?= sel($s,'email_driver','smtp') ?>>SMTP — recommended for production</option>
          </select>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group">
            <label class="form-label">From Address</label>
            <input type="email" class="form-control" name="email_from_address"
                   value="<?= esc($s['email_from_address']) ?>" placeholder="noreply@yourdomain.com">
          </div>
          <div class="form-group">
            <label class="form-label">From Name</label>
            <input type="text" class="form-control" name="email_from_name"
                   value="<?= esc($s['email_from_name']) ?>" placeholder="<?= esc(APP_NAME) ?>">
          </div>
        </div>

        <div id="smtpFields" style="<?= $s['email_driver']==='smtp' ? '' : 'display:none;' ?>">
          <hr style="margin:1rem 0;border-color:var(--clr-border);">
          <h4 style="margin:0 0 .8rem;font-size:.92rem;">SMTP Configuration</h4>

          <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;">
            <div class="form-group">
              <label class="form-label">SMTP Host</label>
              <input type="text" class="form-control" name="email_smtp_host"
                     value="<?= esc($s['email_smtp_host']) ?>" placeholder="smtp.gmail.com">
            </div>
            <div class="form-group">
              <label class="form-label">Port</label>
              <input type="number" class="form-control" name="email_smtp_port"
                     value="<?= esc($s['email_smtp_port']) ?>" min="1" max="65535">
            </div>
            <div class="form-group">
              <label class="form-label">Encryption</label>
              <select class="form-control" name="email_smtp_encryption">
                <option value="tls"<?= sel($s,'email_smtp_encryption','tls') ?>>TLS (587)</option>
                <option value="ssl"<?= sel($s,'email_smtp_encryption','ssl') ?>>SSL (465)</option>
                <option value=""<?= sel($s,'email_smtp_encryption','') ?>>None</option>
              </select>
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
              <label class="form-label">SMTP Username</label>
              <input type="text" class="form-control" name="email_smtp_user"
                     value="<?= esc($s['email_smtp_user']) ?>" autocomplete="off">
            </div>
            <div class="form-group">
              <label class="form-label">SMTP Password</label>
              <input type="password" class="form-control" name="email_smtp_pass"
                     placeholder="Leave blank to keep current" autocomplete="new-password">
            </div>
          </div>

          <div style="font-size:.78rem;color:var(--tx-muted);margin-top:-4px;">
            Common hosts: Gmail — smtp.gmail.com:587 TLS (use App Password);
            Outlook — smtp.office365.com:587 TLS;
            SendGrid — smtp.sendgrid.net:587 TLS.
          </div>
        </div>

        <div style="margin-top:1.2rem;">
          <button type="submit" class="btn btn-primary">Save Email Settings</button>
          <a href="?tab=test" class="btn btn-secondary" style="margin-left:.5rem;">🧪 Test Now</a>
        </div>
      </form>
    </div>

    <!-- ════ SMS Settings ════ -->
    <?php elseif ($tab === 'sms'): ?>
    <div class="settings-panel">
      <form method="post" action="?tab=sms">
        <input type="hidden" name="csrf_token" value="<?= esc(Auth::csrfToken()) ?>">
        <input type="hidden" name="action"     value="save_sms">

        <div class="form-group">
          <label class="form-label">SMS Gateway</label>
          <select class="form-control" name="sms_gateway" id="smsGateway" onchange="switchGateway(this.value)">
            <option value="msg91"<?= sel($s,'sms_gateway','msg91') ?>>MSG91 (India)</option>
            <option value="twilio"<?= sel($s,'sms_gateway','twilio') ?>>Twilio</option>
            <option value="custom"<?= sel($s,'sms_gateway','custom') ?>>Custom URL / Webhook</option>
          </select>
        </div>

        <!-- MSG91 -->
        <div id="gw-msg91" class="gateway-section <?= $s['sms_gateway']==='msg91' ? 'active' : '' ?>">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
              <label class="form-label">API Auth Key</label>
              <input type="text" class="form-control" name="sms_api_key"
                     value="<?= esc($s['sms_api_key']) ?>" placeholder="MSG91 authkey">
            </div>
            <div class="form-group">
              <label class="form-label">Sender ID</label>
              <input type="text" class="form-control" name="sms_sender_id"
                     value="<?= esc($s['sms_sender_id']) ?>" placeholder="BODHIK" maxlength="6">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Flow ID (DLT Template ID) <small style="font-weight:400;color:var(--tx-muted);">— optional, for transactional routes</small></label>
            <input type="text" class="form-control" name="sms_msg91_flow_id"
                   value="<?= esc($s['sms_msg91_flow_id']) ?>" placeholder="Leave blank to use plain SMS API">
          </div>
          <div style="font-size:.78rem;color:var(--tx-muted);">
            Get your authkey from <strong>MSG91 → API → Auth Key</strong>.
            DLT registration required for Indian numbers (TRAI regulation).
          </div>
        </div>

        <!-- Twilio -->
        <div id="gw-twilio" class="gateway-section <?= $s['sms_gateway']==='twilio' ? 'active' : '' ?>">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
              <label class="form-label">Account SID</label>
              <input type="text" class="form-control" name="sms_twilio_sid"
                     value="<?= esc($s['sms_twilio_sid']) ?>" placeholder="AC...">
            </div>
            <div class="form-group">
              <label class="form-label">Auth Token <small>(stored in API Key field)</small></label>
              <input type="password" class="form-control" name="sms_api_key"
                     value="<?= esc($s['sms_api_key']) ?>" placeholder="Twilio Auth Token">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">From Number</label>
            <input type="text" class="form-control" name="sms_twilio_from"
                   value="<?= esc($s['sms_twilio_from']) ?>" placeholder="+1XXXXXXXXXX">
          </div>
        </div>

        <!-- Custom -->
        <div id="gw-custom" class="gateway-section <?= $s['sms_gateway']==='custom' ? 'active' : '' ?>">
          <div class="form-group">
            <label class="form-label">POST URL</label>
            <input type="url" class="form-control" name="sms_custom_url"
                   value="<?= esc($s['sms_custom_url']) ?>" placeholder="https://yoursmsgateway.com/send">
          </div>
          <div class="form-group">
            <label class="form-label">Request Body Template (JSON)</label>
            <textarea class="form-control" name="sms_custom_body" rows="4"
                      style="font-family:monospace;font-size:.85rem;"><?= esc($s['sms_custom_body']) ?></textarea>
            <small style="color:var(--tx-muted);">Use <code>{PHONE}</code> and <code>{MSG}</code> as placeholders.</small>
          </div>
          <div class="form-group">
            <label class="form-label">API Key Header (optional)</label>
            <input type="text" class="form-control" name="sms_api_key"
                   value="<?= esc($s['sms_api_key']) ?>" placeholder="Bearer token or API key">
          </div>
        </div>

        <div style="margin-top:1.2rem;">
          <button type="submit" class="btn btn-primary">Save SMS Settings</button>
          <a href="?tab=test" class="btn btn-secondary" style="margin-left:.5rem;">🧪 Test Now</a>
        </div>
      </form>
    </div>

    <!-- ════ Payment / UPI Settings ════ -->
    <?php elseif ($tab === 'payment'): ?>
    <div class="settings-panel">
      <p style="color:var(--tx-muted);font-size:.85rem;margin-bottom:1.2rem;">
        When enabled, students get a "Scan &amp; Pay via UPI" option on the enrollment page
        alongside Razorpay checkout. They scan the QR, pay in their UPI app, then submit the
        Transaction / UTR ID here for you to verify in
        <a href="EnrollmentPayments.php">Enrollment Payments</a> and mark Paid.
      </p>

      <div style="display:flex;gap:.6rem;margin-bottom:1.2rem;flex-wrap:wrap;">
        <span class="status-chip <?= $s['payment_upi_enabled']==='1' ? 'chip-on' : 'chip-off' ?>">
          <?= $s['payment_upi_enabled']==='1' ? '✓' : '○' ?> UPI/QR Payment <?= $s['payment_upi_enabled']==='1' ? 'ON' : 'OFF' ?>
        </span>
      </div>

      <form method="post" action="?tab=payment" id="paymentForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= esc(Auth::csrfToken()) ?>">
        <input type="hidden" name="action"     value="save_payment">

        <div class="toggle-row">
          <div>
            <div class="toggle-label">Enable UPI / QR Payment</div>
            <div class="toggle-hint">Shows the manual-payment option on the student enrollment page.</div>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" name="payment_upi_enabled" value="1"<?= chk($s,'payment_upi_enabled') ?>>
            <span class="toggle-slider"></span>
          </label>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:1.2rem;">
          <div class="form-group">
            <label class="form-label">UPI ID (VPA)</label>
            <input type="text" class="form-control" name="payment_upi_id" id="upiIdField"
                   value="<?= esc($s['payment_upi_id']) ?>" placeholder="institute@upi" oninput="refreshQrPreview()">
          </div>
          <div class="form-group">
            <label class="form-label">Payee Name <small style="font-weight:400;color:var(--tx-muted);">(shown in student's UPI app)</small></label>
            <input type="text" class="form-control" name="payment_upi_payee_name" id="upiPayeeField"
                   value="<?= esc($s['payment_upi_payee_name'] ?: APP_NAME) ?>" placeholder="<?= esc(APP_NAME) ?>" oninput="refreshQrPreview()">
          </div>
        </div>

        <div class="form-group" style="margin-top:.4rem;">
          <label class="form-label">Custom QR Image
            <small style="font-weight:400;color:var(--tx-muted);">(optional — overrides the auto-generated QR below)</small>
          </label>
          <?php if ($s['payment_qr_image']): ?>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
              <img src="<?= esc($s['payment_qr_image']) ?>" alt="Current QR"
                   style="width:90px;height:90px;object-fit:contain;border:1px solid var(--clr-border);border-radius:6px;background:#fff;">
              <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;">
                <input type="checkbox" name="remove_qr_image" value="1"> Remove this image
              </label>
            </div>
          <?php endif; ?>
          <input type="file" class="form-control" name="qr_image_file"
                 accept="image/png,image/jpeg,image/gif,image/webp">
          <div class="toggle-hint">
            Upload your bank/PSP-provided QR (max 2MB) if you'd rather show that exact image instead
            of one generated from the UPI ID. When uploaded, students will need to enter the amount
            themselves in their UPI app — it can't be embedded in a static image. Leave blank to keep
            using the auto-generated QR.
          </div>
        </div>

        <div style="margin-top:1rem;">
          <button type="submit" class="btn btn-primary">Save Payment Settings</button>
        </div>
      </form>

      <div style="margin-top:1.4rem;">
        <h4 style="margin:0 0 .6rem;font-size:.92rem;">Auto-Generated QR Preview</h4>
        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
          <div id="qrPreview" style="padding:10px;background:#fff;border:1px solid var(--clr-border);border-radius:8px;"></div>
          <div style="font-size:.82rem;color:var(--tx-muted);max-width:280px;">
            This is what the QR encodes for a sample &#8377;500 exam fee, used whenever no custom
            QR image is uploaded. The real amount is filled in automatically per-subject on the
            enrollment page.
          </div>
        </div>
      </div>
    </div>

    <!-- ════ Certificate Settings ════ -->
    <?php elseif ($tab === 'certificate'): ?>
    <div class="settings-panel">
      <p style="color:var(--tx-muted);font-size:.85rem;margin-bottom:1.2rem;">
        Controls what appears on issued certificates (<a href="GenerateCertificates.php">Generate Certificates</a>)
        and the percentage cutoffs used to auto-assign a merit grade.
      </p>

      <form method="post" action="?tab=certificate" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= esc(Auth::csrfToken()) ?>">
        <input type="hidden" name="action"     value="save_certificate">

        <h4 style="margin:0 0 .6rem;font-size:.92rem;">Signatory &amp; Institute</h4>

        <div class="form-group">
          <label class="form-label">Certificate Logo
            <small style="font-weight:400;color:var(--tx-muted);">(shown top-left on every certificate — the bundled default already includes the institute name &amp; tagline in the image itself)</small>
          </label>
          <?php if ($s['cert_logo']): ?>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
              <img src="<?= esc($s['cert_logo']) ?>" alt="Current logo"
                   style="width:90px;height:60px;object-fit:contain;border:1px solid var(--clr-border);border-radius:6px;background:#fff;">
              <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;">
                <input type="checkbox" name="remove_cert_logo" value="1"> Remove this image
              </label>
            </div>
          <?php endif; ?>
          <input type="file" class="form-control" name="cert_logo_file"
                 accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
          <div class="toggle-hint">JPG, PNG, GIF, WEBP, or SVG, max 2MB. Leave blank to keep the current logo.</div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group" style="grid-column:1 / -1;">
            <label class="form-label">Institute / Organisation Name</label>
            <input type="text" class="form-control" name="cert_institute_name"
                   value="<?= esc($s['cert_institute_name']) ?>" placeholder="<?= esc(APP_NAME) ?>">
          </div>
          <div class="form-group" style="grid-column:1 / -1;">
            <label class="form-label">Tagline <small style="font-weight:400;color:var(--tx-muted);">(shown under the institute name)</small></label>
            <input type="text" class="form-control" name="cert_institute_tagline"
                   value="<?= esc($s['cert_institute_tagline']) ?>" placeholder="Learn • Practice • Succeed">
          </div>
          <div class="form-group">
            <label class="form-label">Signatory Name</label>
            <input type="text" class="form-control" name="cert_signatory_name"
                   value="<?= esc($s['cert_signatory_name']) ?>" placeholder="e.g. Dr. A. Sharma">
          </div>
          <div class="form-group">
            <label class="form-label">Signatory Title</label>
            <input type="text" class="form-control" name="cert_signatory_title"
                   value="<?= esc($s['cert_signatory_title']) ?>" placeholder="Director / Principal">
          </div>
          <div class="form-group" style="grid-column:1 / -1;">
            <label class="form-label">Digital Signature
              <small style="font-weight:400;color:var(--tx-muted);">(image of a real signature — shown above the signatory's name)</small>
            </label>
            <?php if ($s['cert_signature']): ?>
              <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                <img src="<?= esc($s['cert_signature']) ?>" alt="Current signature"
                     style="width:140px;height:56px;object-fit:contain;border:1px solid var(--clr-border);border-radius:6px;background:#fff;padding:4px;">
                <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;">
                  <input type="checkbox" name="remove_cert_signature" value="1"> Remove this image
                </label>
              </div>
            <?php else: ?>
              <div class="toggle-hint" style="margin-bottom:8px;">
                No signature uploaded yet — certificates currently show a blank line instead.
              </div>
            <?php endif; ?>
            <input type="file" class="form-control" name="cert_signature_file"
                   accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
            <div class="toggle-hint">
              A scan or photo of a signature on a plain/transparent background works best. JPG, PNG, GIF, WEBP, or SVG, max 2MB.
            </div>
          </div>
        </div>

        <hr style="margin:1.2rem 0;border-color:var(--clr-border);">
        <h4 style="margin:0 0 .4rem;font-size:.92rem;">Merit Grade Cutoffs</h4>
        <p class="toggle-hint" style="margin-bottom:.8rem;">
          Minimum score percentage required for each grade band on a Merit certificate.
          A student below the lowest cutoff does not get a grade badge.
        </p>
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;">
          <div class="form-group">
            <label class="form-label">Distinction ≥</label>
            <input type="number" class="form-control" name="cert_grade_distinction" min="0" max="100"
                   value="<?= esc($s['cert_grade_distinction']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">A+ ≥</label>
            <input type="number" class="form-control" name="cert_grade_aplus" min="0" max="100"
                   value="<?= esc($s['cert_grade_aplus']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">A ≥</label>
            <input type="number" class="form-control" name="cert_grade_a" min="0" max="100"
                   value="<?= esc($s['cert_grade_a']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">B+ ≥</label>
            <input type="number" class="form-control" name="cert_grade_bplus" min="0" max="100"
                   value="<?= esc($s['cert_grade_bplus']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">B ≥</label>
            <input type="number" class="form-control" name="cert_grade_b" min="0" max="100"
                   value="<?= esc($s['cert_grade_b']) ?>">
          </div>
        </div>

        <div style="margin-top:1.2rem;">
          <button type="submit" class="btn btn-primary">Save Certificate Settings</button>
          <a href="CertificateTemplates.php" class="btn btn-secondary" style="margin-left:.5rem;">🎨 Manage Templates</a>
          <a href="GenerateCertificates.php" class="btn btn-success" style="margin-left:.5rem;">🎓 Generate Certificates</a>
        </div>
      </form>
    </div>

    <!-- ════ Test Delivery ════ -->
    <?php elseif ($tab === 'test'): ?>
    <div class="settings-panel">
      <p style="font-size:.85rem;color:var(--tx-muted);margin-bottom:1.2rem;">
        Send a test message using your current settings. The code <strong>123456</strong> will be sent.
        Check your inbox/phone to confirm delivery.
      </p>

      <!-- Test Email -->
      <div class="card" style="margin-bottom:1rem;">
        <div class="card-header" style="padding:10px 16px;background:#f0f9ff;">
          <h4 style="margin:0;font-size:.9rem;color:#0369a1;">✉️ Test Email Delivery</h4>
        </div>
        <div class="card-body">
          <form method="post" action="?tab=test">
            <input type="hidden" name="csrf_token" value="<?= esc(Auth::csrfToken()) ?>">
            <input type="hidden" name="action"     value="test_email">
            <div class="test-row">
              <div class="form-group" style="flex:1;margin:0;">
                <label class="form-label">Send test to email</label>
                <input type="email" class="form-control" name="test_email_to"
                       placeholder="you@example.com" required>
              </div>
              <button type="submit" class="btn btn-primary btn-sm">Send Test Email</button>
            </div>
          </form>
          <div style="margin-top:8px;font-size:.78rem;color:var(--tx-muted);">
            Driver: <strong><?= esc($s['email_driver']) ?></strong>
            <?php if ($s['email_driver']==='smtp'): ?>
              &bull; Host: <strong><?= esc($s['email_smtp_host']) ?>:<?= esc($s['email_smtp_port']) ?></strong>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Test SMS -->
      <div class="card">
        <div class="card-header" style="padding:10px 16px;background:#f0fdf4;">
          <h4 style="margin:0;font-size:.9rem;color:#166534;">📱 Test SMS Delivery</h4>
        </div>
        <div class="card-body">
          <form method="post" action="?tab=test">
            <input type="hidden" name="csrf_token" value="<?= esc(Auth::csrfToken()) ?>">
            <input type="hidden" name="action"     value="test_sms">
            <div class="test-row">
              <div class="form-group" style="flex:1;margin:0;">
                <label class="form-label">Send test to phone number (with country code)</label>
                <input type="tel" class="form-control" name="test_sms_to"
                       placeholder="+91XXXXXXXXXX" required>
              </div>
              <button type="submit" class="btn btn-primary btn-sm">Send Test SMS</button>
            </div>
          </form>
          <div style="margin-top:8px;font-size:.78rem;color:var(--tx-muted);">
            Gateway: <strong><?= esc($s['sms_gateway']) ?></strong>
            <?php if ($s['sms_sender_id']): ?>
              &bull; Sender: <strong><?= esc($s['sms_sender_id']) ?></strong>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /card-body -->
</div><!-- /card -->

<script>
function toggleSmtp(val) {
  document.getElementById('smtpFields').style.display = val === 'smtp' ? '' : 'none';
}
function switchGateway(val) {
  document.querySelectorAll('.gateway-section').forEach(function(el) {
    el.classList.remove('active');
  });
  var el = document.getElementById('gw-' + val);
  if (el) el.classList.add('active');
}
/* Init on load */
toggleSmtp(document.getElementById('emailDriver') ? document.getElementById('emailDriver').value : 'mail');
</script>

<?php if ($tab === 'payment'): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
var qrPreviewInstance = null;
function buildUpiUri(amount) {
  var pa = document.getElementById('upiIdField').value.trim();
  var pn = (document.getElementById('upiPayeeField').value.trim() || <?= json_encode(APP_NAME) ?>);
  if (!pa) return '';
  return 'upi://pay?pa=' + encodeURIComponent(pa) +
         '&pn=' + encodeURIComponent(pn) +
         '&am=' + encodeURIComponent(amount) +
         '&cu=INR';
}
function refreshQrPreview() {
  var el  = document.getElementById('qrPreview');
  var uri = buildUpiUri('500.00');
  if (!uri) { el.innerHTML = '<span style="font-size:.8rem;color:#9ca3af;">Enter a UPI ID above</span>'; return; }
  if (!qrPreviewInstance) {
    el.innerHTML = '';
    qrPreviewInstance = new QRCode(el, { text: uri, width: 140, height: 140 });
  } else {
    qrPreviewInstance.clear();
    qrPreviewInstance.makeCode(uri);
  }
}
refreshQrPreview();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
