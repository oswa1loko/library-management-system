<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';

$msg = '';
$msgType = 'success';
$formData = [
    'fullname' => '',
    'email' => '',
    'role' => 'guest',
    'message' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['fullname'] = trim($_POST['fullname'] ?? '');
    $formData['email'] = trim($_POST['email'] ?? '');
    $formData['role'] = trim($_POST['role'] ?? 'guest');
    $formData['message'] = trim($_POST['message'] ?? '');

    $allowedRoles = ['guest', 'student', 'faculty', 'librarian', 'admin'];
    if (!in_array($formData['role'], $allowedRoles, true)) {
        $formData['role'] = 'guest';
    }

    if ($formData['fullname'] === '') {
        $msg = 'Full name is required.';
        $msgType = 'error';
    } elseif ($formData['email'] === '') {
        $msg = 'Email is required so the admin can send a response.';
        $msgType = 'error';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $msg = 'Enter a valid email address.';
        $msgType = 'error';
    } elseif ($formData['message'] === '') {
        $msg = 'Please enter your complaint or report details.';
        $msgType = 'error';
    } else {
        $emptyMobile = '';
        $stmt = $conn->prepare("
            INSERT INTO complaints (fullname, email, role, mobile_number, message, status)
            VALUES (?, ?, ?, ?, ?, 'new')
        ");
        $stmt->bind_param(
            'sssss',
            $formData['fullname'],
            $formData['email'],
            $formData['role'],
            $emptyMobile,
            $formData['message']
        );

        if ($stmt->execute()) {
            $complaintId = (int) $stmt->insert_id;
            $msg = 'Complaint submitted. The admin can now review it.';
            create_notification(
                $conn,
                'admin',
                'New Complaint Submitted',
                'Complaint #' . $complaintId . ' was submitted by ' . $formData['fullname'] . '.',
                'warning'
            );
            audit_log($conn, 'complaint.create', [
                'complaint_id' => $complaintId,
                'role' => $formData['role'],
            ], null, $formData['role'] !== '' ? $formData['role'] : 'guest');
            $formData = [
                'fullname' => '',
                'email' => '',
                'role' => 'guest',
                'message' => '',
            ];
        } else {
            $msg = 'Unable to submit your complaint right now.';
            $msgType = 'error';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Feedback | Library</title>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css">
<style>
  .feedback-page {
    position: relative;
    isolation: isolate;
  }

  .feedback-page::after {
    content: "";
    position: absolute;
    inset: 28px;
    border-radius: 34px;
    border: 1px solid rgba(143, 211, 255, 0.08);
    background:
      radial-gradient(circle at top right, rgba(143, 211, 255, 0.08), transparent 34%),
      linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0));
    pointer-events: none;
    z-index: -1;
  }

  .feedback-page .feedback-card {
    width: min(100%, 1040px);
    border-radius: 30px;
    border-color: rgba(143, 211, 255, 0.14);
    background:
      linear-gradient(135deg, rgba(9, 24, 40, 0.96), rgba(6, 18, 32, 0.98)),
      rgba(7, 19, 33, 0.96);
    box-shadow:
      0 28px 68px rgba(0, 0, 0, 0.34),
      inset 0 1px 0 rgba(255, 255, 255, 0.04);
  }

  .feedback-page .feedback-layout {
    grid-template-columns: minmax(0, 1.08fr) minmax(320px, 0.92fr);
    gap: 0;
  }

  .feedback-page .feedback-main,
  .feedback-page .feedback-side {
    min-width: 0;
  }

  .feedback-page .feedback-main {
    position: relative;
    background:
      radial-gradient(circle at top left, rgba(143, 211, 255, 0.08), transparent 32%),
      linear-gradient(180deg, rgba(10, 25, 43, 0.96), rgba(6, 18, 31, 0.98));
  }

  .feedback-page .feedback-side {
    position: relative;
    border-left: 1px solid rgba(255, 255, 255, 0.08);
    background:
      radial-gradient(circle at top right, rgba(143, 211, 255, 0.14), transparent 28%),
      linear-gradient(180deg, rgba(13, 31, 52, 0.98), rgba(7, 20, 35, 0.98));
  }

  .feedback-page .feedback-main::after,
  .feedback-page .feedback-side::after {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
  }

  .feedback-page .feedback-main::after {
    background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0));
  }

  .feedback-page .feedback-side::after {
    background: radial-gradient(circle at bottom right, rgba(143, 211, 255, 0.08), transparent 34%);
  }

  .feedback-page .feedback-main > *,
  .feedback-page .feedback-side > * {
    position: relative;
    z-index: 1;
  }

  .feedback-page .feedback-main .card-head,
  .feedback-page .feedback-side .card-head {
    margin-bottom: 18px;
    align-items: flex-start;
  }

  .feedback-page .feedback-main .dashboard-icon,
  .feedback-page .feedback-side .dashboard-icon {
    width: 54px;
    height: 54px;
    border-radius: 18px;
    box-shadow:
      0 14px 28px rgba(0, 0, 0, 0.22),
      inset 0 1px 0 rgba(255,255,255,0.06);
  }

  .feedback-page .heading-tight {
    letter-spacing: -0.03em;
  }

  .feedback-page .feedback-main .heading-tight {
    font-size: clamp(2rem, 2.4vw, 2.4rem);
    line-height: 1.05;
  }

  .feedback-page .feedback-side .heading-tight,
  .feedback-page .feedback-side .heading-card {
    line-height: 1.12;
  }

  .feedback-page .feedback-main .text-measure,
  .feedback-page .feedback-side .muted {
    color: rgba(214, 225, 241, 0.86);
  }

  .feedback-page .feedback-form {
    gap: 20px;
  }

  .feedback-page .feedback-form .grid.form {
    gap: 18px;
  }

  .feedback-page .feedback-tags {
    margin-top: 4px;
    gap: 10px;
  }

  .feedback-page .feedback-tags .chip {
    padding: 8px 12px;
    border-radius: 999px;
    border-color: rgba(143, 211, 255, 0.14);
    background:
      linear-gradient(180deg, rgba(255,255,255,0.08), rgba(255,255,255,0.03)),
      rgba(255,255,255,0.03);
    color: #dbeafe;
    font-weight: 700;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);
  }

  .feedback-page label {
    margin-bottom: 8px;
    color: rgba(214, 225, 241, 0.82);
  }

  .feedback-page input,
  .feedback-page textarea,
  .feedback-page .ui-select {
    border-color: rgba(255, 255, 255, 0.11);
    background:
      linear-gradient(180deg, rgba(255,255,255,0.08), rgba(255,255,255,0.04)),
      rgba(255,255,255,0.02);
    box-shadow:
      inset 0 1px 0 rgba(255,255,255,0.05),
      0 10px 22px rgba(0,0,0,0.08);
  }

  .feedback-page input:hover,
  .feedback-page textarea:hover,
  .feedback-page .ui-select:hover {
    border-color: rgba(143, 211, 255, 0.22);
  }

  .feedback-page textarea {
    min-height: 170px;
    padding-top: 15px;
  }

  .feedback-page .feedback-form .empty-state,
  .feedback-page .feedback-side .empty-state {
    border-radius: 20px;
    border-style: solid;
    border-color: rgba(255,255,255,0.08);
    background:
      linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.025)),
      rgba(255,255,255,0.02);
    box-shadow:
      inset 0 1px 0 rgba(255,255,255,0.03),
      0 12px 24px rgba(0,0,0,0.08);
  }

  .feedback-page .feedback-form .empty-state {
    padding: 18px 18px 18px 22px;
  }

  .feedback-page .feedback-side .empty-state strong {
    font-size: 1.02rem;
  }

  .feedback-page .feedback-actions {
    align-items: stretch;
    gap: 12px;
  }

  .feedback-page .feedback-actions .button,
  .feedback-page .feedback-actions button {
    min-height: 48px;
    min-width: 0;
    padding-inline: 18px;
  }

  .feedback-page .feedback-actions button[type="submit"] {
    box-shadow:
      0 16px 28px rgba(71, 180, 255, 0.24),
      inset 0 1px 0 rgba(255,255,255,0.18);
  }

  .feedback-page .feedback-actions .button.secondary {
    background:
      linear-gradient(180deg, rgba(255,255,255,0.08), rgba(255,255,255,0.03)),
      rgba(8,21,38,0.42);
    border-color: rgba(255,255,255,0.08);
  }

  .feedback-page .feedback-side .stack {
    gap: 14px;
  }

  .feedback-page .feedback-side .empty-state {
    line-height: 1.65;
  }

  .feedback-page .panel-soft-glass {
    margin-top: 18px;
    border-radius: 22px;
    border-color: rgba(143, 211, 255, 0.14);
    background:
      linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.02)),
      rgba(255,255,255,0.03);
    box-shadow:
      inset 0 1px 0 rgba(255,255,255,0.04),
      0 16px 32px rgba(0,0,0,0.12);
  }

  :root[data-theme="light"] .feedback-page::after {
    border-color: rgba(47, 109, 255, 0.1);
    background:
      radial-gradient(circle at top right, rgba(47, 109, 255, 0.08), transparent 36%),
      linear-gradient(180deg, rgba(255,255,255,0.44), rgba(255,255,255,0));
  }

  :root[data-theme="light"] .feedback-page .feedback-card {
    border-color: rgba(47, 109, 255, 0.1);
    background:
      linear-gradient(180deg, rgba(255,255,255,0.995), rgba(244,248,255,0.985)),
      #ffffff;
    box-shadow:
      0 24px 48px rgba(34, 63, 112, 0.12),
      inset 0 1px 0 rgba(255,255,255,0.8);
  }

  :root[data-theme="light"] .feedback-page .feedback-main {
    background:
      radial-gradient(circle at top left, rgba(47, 109, 255, 0.08), transparent 34%),
      linear-gradient(180deg, rgba(255,255,255,0.995), rgba(246,250,255,0.99));
  }

  :root[data-theme="light"] .feedback-page .feedback-side {
    border-left-color: rgba(47, 109, 255, 0.1);
    background:
      radial-gradient(circle at top right, rgba(96, 165, 250, 0.14), transparent 28%),
      linear-gradient(180deg, rgba(239,246,255,0.99), rgba(232,242,255,0.98));
  }

  :root[data-theme="light"] .feedback-page .feedback-main .text-measure,
  :root[data-theme="light"] .feedback-page .feedback-side .muted,
  :root[data-theme="light"] .feedback-page label {
    color: #5b6f8c;
  }

  :root[data-theme="light"] .feedback-page .feedback-tags .chip {
    border-color: rgba(47, 109, 255, 0.12);
    background:
      linear-gradient(180deg, rgba(255,255,255,0.98), rgba(241,246,255,0.94)),
      rgba(255,255,255,0.9);
    color: #31547c;
    box-shadow:
      inset 0 1px 0 rgba(255,255,255,0.96),
      0 8px 18px rgba(34,63,112,0.06);
  }

  :root[data-theme="light"] .feedback-page .feedback-form .empty-state,
  :root[data-theme="light"] .feedback-page .feedback-side .empty-state,
  :root[data-theme="light"] .feedback-page .panel-soft-glass {
    border-color: rgba(47, 109, 255, 0.1);
    background:
      linear-gradient(180deg, rgba(255,255,255,0.98), rgba(241,246,255,0.94)),
      rgba(255,255,255,0.92);
    box-shadow:
      inset 0 1px 0 rgba(255,255,255,0.95),
      0 14px 24px rgba(34,63,112,0.08);
  }

  :root[data-theme="light"] .feedback-page .feedback-actions .button.secondary {
    background:
      linear-gradient(180deg, rgba(255,255,255,0.99), rgba(241,246,255,0.94)),
      rgba(255,255,255,0.94);
    border-color: rgba(47,109,255,0.12);
    color: #244266;
  }

  @media (max-width: 900px) {
    .feedback-page {
      padding: 18px;
    }

    .feedback-page .feedback-layout {
      grid-template-columns: 1fr;
    }

    .feedback-page .feedback-main,
    .feedback-page .feedback-side {
      padding: 24px 20px;
    }

    .feedback-page .feedback-side {
      border-left: 0;
      border-top: 1px solid var(--line);
    }
  }

  @media (max-width: 640px) {
    .feedback-page {
      padding: 12px;
      align-items: start;
    }

    .feedback-page .feedback-card {
      border-radius: 20px;
    }

    .feedback-page .feedback-layout {
      gap: 0;
    }

    .feedback-page .feedback-main,
    .feedback-page .feedback-side {
      padding: 18px 16px;
    }

    .feedback-page::after {
      inset: 12px;
      border-radius: 24px;
    }

    .feedback-page .feedback-main .card-head,
    .feedback-page .feedback-side .card-head {
      align-items: flex-start;
      gap: 12px;
    }

    .feedback-page .feedback-main .card-head > div:last-child,
    .feedback-page .feedback-side .card-head > div:last-child {
      min-width: 0;
    }

    .feedback-page .feedback-main .text-measure,
    .feedback-page .feedback-side .muted {
      font-size: 0.92rem;
      line-height: 1.6;
    }

    .feedback-page .feedback-tags {
      display: grid;
      grid-template-columns: 1fr;
      gap: 8px;
    }

    .feedback-page .feedback-tags .chip {
      width: 100%;
      justify-content: center;
      text-align: center;
      padding: 9px 12px;
      border-radius: 14px;
    }

    .feedback-page .feedback-form {
      gap: 14px;
    }

    .feedback-page .feedback-form .grid.form {
      grid-template-columns: 1fr;
      gap: 14px;
    }

    .feedback-page .feedback-form .empty-state {
      padding: 14px;
      font-size: 0.9rem;
      line-height: 1.6;
    }

    .feedback-page textarea {
      min-height: 180px;
    }

    .feedback-page .feedback-actions {
      display: grid;
      grid-template-columns: 1fr;
      gap: 10px;
    }

    .feedback-page .feedback-actions .button,
    .feedback-page .feedback-actions button {
      width: 100%;
      justify-content: center;
    }

    .feedback-page .feedback-side .stack {
      gap: 12px;
    }

    .feedback-page .feedback-side .panel-soft-glass {
      margin-top: 14px;
    }

    .feedback-page .feedback-side .empty-state {
      padding: 14px;
    }
  }
</style>
</head>
<body data-skip-theme-toggle="true">
<div class="auth-shell feedback-page">
  <div class="card surface-shell-wide feedback-card">
    <div class="split split-stretch feedback-layout">
      <div class="panel-pad-lg feedback-main">
        <div class="card-head">
          <div class="dashboard-icon icon-edit" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow">Feedback</p>
            <h2 class="heading-tight">Complain or Report</h2>
            <p class="muted text-measure">Use this form to report library issues, account concerns, incorrect records, or service-related complaints. Submitted entries are visible to the admin.</p>
          </div>
        </div>

        <div class="inline-actions flow-top-md feedback-tags">
          <span class="chip">Direct to admin</span>
          <span class="chip">Email required</span>
          <span class="chip">Status starts as new</span>
        </div>

        <?php if ($msg !== ''): ?>
          <div class="notice <?php echo $msgType === 'error' ? 'error' : 'success'; ?>"><?php echo h($msg); ?></div>
        <?php endif; ?>

        <form method="post" class="stack flow-top-md feedback-form">
          <div class="grid form">
            <div>
              <label for="fullname">Full Name</label>
              <input id="fullname" name="fullname" value="<?php echo h($formData['fullname']); ?>" required>
            </div>
            <div>
              <label for="email">Email</label>
              <input id="email" name="email" type="email" value="<?php echo h($formData['email']); ?>" required>
            </div>
            <div>
              <label for="role">Role</label>
              <div class="ui-select-shell">
                <select id="role" name="role" class="ui-select">
                  <?php foreach (['guest', 'student', 'faculty', 'librarian', 'admin'] as $roleOption): ?>
                    <option value="<?php echo h($roleOption); ?>" <?php echo $formData['role'] === $roleOption ? 'selected' : ''; ?>>
                      <?php echo h(ucfirst($roleOption)); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <span class="ui-select-caret" aria-hidden="true"></span>
              </div>
            </div>
          </div>
          <div class="empty-state">
            Use an active email address and describe the concern clearly so the admin can respond through email.
          </div>
          <div>
            <label for="message">Complaint / Report Details</label>
            <textarea id="message" name="message" rows="7"><?php echo h($formData['message']); ?></textarea>
          </div>
          <div class="inline-actions feedback-actions">
            <button type="submit">Submit Complaint</button>
            <a class="button secondary" href="/index.php">Back Home</a>
          </div>
        </form>
      </div>

      <div class="panel-pad-lg hero-panel-dark feedback-side">
        <div class="card-head">
          <div class="dashboard-icon icon-feedback" aria-hidden="true"></div>
          <div>
            <h3 class="heading-top heading-tight">What happens after submission</h3>
            <p class="muted">Each complaint is recorded in the admin queue for review, follow-up, and status updates.</p>
          </div>
        </div>
        <div class="stack flow-top-md">
          <div class="empty-state">
            <strong class="label-block-gap">After you submit</strong>
            Your report is saved as a new complaint record and can be reviewed by the admin.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Status updates</strong>
            The complaint may be marked as reviewed or resolved depending on the action taken.
          </div>
        </div>

        <div class="panel flow-top-md panel-soft-glass">
          <div class="card-head">
            <div class="dashboard-icon icon-guide" aria-hidden="true"></div>
            <div>
              <p class="muted eyebrow-compact">Helpful Tips</p>
              <h3 class="heading-card">For faster review</h3>
              <p class="muted">Clear and specific details make it easier to identify the correct account, book, or transaction.</p>
            </div>
          </div>
          <div class="stack">
            <div class="empty-state">Mention the book title, account name, or page involved when possible.</div>
            <div class="empty-state">Submit one concern at a time so tracking and follow-up stay clear.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
