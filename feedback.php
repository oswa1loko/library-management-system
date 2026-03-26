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
  .feedback-page .feedback-card {
    width: min(100%, 1040px);
  }

  .feedback-page .feedback-layout {
    grid-template-columns: minmax(0, 1.08fr) minmax(320px, 0.92fr);
    gap: 0;
  }

  .feedback-page .feedback-main,
  .feedback-page .feedback-side {
    min-width: 0;
  }

  .feedback-page .feedback-form {
    gap: 18px;
  }

  .feedback-page .feedback-form .grid.form {
    gap: 16px;
  }

  .feedback-page .feedback-actions {
    align-items: stretch;
  }

  .feedback-page .feedback-actions .button,
  .feedback-page .feedback-actions button {
    min-height: 46px;
  }

  .feedback-page .feedback-side .empty-state {
    line-height: 1.65;
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
<body>
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
            <span class="chip">Admin Review</span>
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
