<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';

$msg = '';
$msgType = 'success';
$formData = [
    'fullname' => '',
    'email' => '',
    'role' => 'guest',
    'mobile_number' => '',
    'message' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['fullname'] = trim($_POST['fullname'] ?? '');
    $formData['email'] = trim($_POST['email'] ?? '');
    $formData['role'] = trim($_POST['role'] ?? 'guest');
    $formData['mobile_number'] = trim($_POST['mobile_number'] ?? '');
    $formData['message'] = trim($_POST['message'] ?? '');

    $allowedRoles = ['guest', 'student', 'faculty', 'custodian', 'admin'];
    if (!in_array($formData['role'], $allowedRoles, true)) {
        $formData['role'] = 'guest';
    }

    if ($formData['fullname'] === '') {
        $msg = 'Full name is required.';
        $msgType = 'error';
    } elseif ($formData['mobile_number'] === '') {
        $msg = 'Mobile number is required.';
        $msgType = 'error';
    } elseif (!preg_match('/^(09|\+639)\d{9}$/', str_replace([' ', '-'], '', $formData['mobile_number']))) {
        $msg = 'Enter a valid mobile number like 09XXXXXXXXX or +639XXXXXXXXX.';
        $msgType = 'error';
    } elseif ($formData['message'] === '') {
        $msg = 'Please enter your complaint or report details.';
        $msgType = 'error';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO complaints (fullname, email, role, mobile_number, message, status)
            VALUES (?, ?, ?, ?, ?, 'new')
        ");
        $stmt->bind_param(
            'sssss',
            $formData['fullname'],
            $formData['email'],
            $formData['role'],
            $formData['mobile_number'],
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
                'mobile_number' => '',
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
</head>
<body>
<div class="auth-shell">
  <div class="card surface-shell-wide">
    <div class="split split-stretch">
      <div class="panel-pad-lg">
        <div class="card-head">
          <div class="dashboard-icon icon-edit" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow">Feedback</p>
            <h2 class="heading-tight">Complain or Report</h2>
            <p class="muted text-measure">Use this form to report library issues, account concerns, incorrect records, or service-related complaints. Submitted entries are visible to the admin.</p>
          </div>
        </div>

        <div class="inline-actions flow-top-md">
          <span class="chip">Direct to admin</span>
          <span class="chip">Mobile number required</span>
          <span class="chip">Status starts as new</span>
        </div>

        <?php if ($msg !== ''): ?>
          <div class="notice <?php echo $msgType === 'error' ? 'error' : 'success'; ?>"><?php echo h($msg); ?></div>
        <?php endif; ?>

        <form method="post" class="stack flow-top-md">
          <div class="grid form">
            <div>
              <label for="fullname">Full Name</label>
              <input id="fullname" name="fullname" value="<?php echo h($formData['fullname']); ?>" required>
            </div>
            <div>
              <label for="email">Email</label>
              <input id="email" name="email" type="email" value="<?php echo h($formData['email']); ?>">
            </div>
            <div>
              <label for="role">Role</label>
              <div class="ui-select-shell">
                <select id="role" name="role" class="ui-select">
                  <?php foreach (['guest', 'student', 'faculty', 'custodian', 'admin'] as $roleOption): ?>
                    <option value="<?php echo h($roleOption); ?>" <?php echo $formData['role'] === $roleOption ? 'selected' : ''; ?>>
                      <?php echo h(ucfirst($roleOption)); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <span class="ui-select-caret" aria-hidden="true"></span>
              </div>
            </div>
            <div>
              <label for="mobile_number">Mobile Number</label>
              <input id="mobile_number" name="mobile_number" value="<?php echo h($formData['mobile_number']); ?>" placeholder="09XXXXXXXXX" pattern="^(09|\+639)[0-9]{9}$" required>
            </div>
          </div>
          <div class="empty-state">
            Use a reachable mobile number and describe the problem clearly so the admin can verify the concern faster.
          </div>
          <div>
            <label for="message">Complaint / Report Details</label>
            <textarea id="message" name="message" rows="7"><?php echo h($formData['message']); ?></textarea>
          </div>
          <div class="inline-actions">
            <button type="submit">Submit Complaint</button>
            <a class="button secondary" href="/librarymanage/index.php">Back Home</a>
          </div>
        </form>
      </div>

      <div class="panel-pad-lg hero-panel-dark">
        <div class="card-head">
          <div class="dashboard-icon icon-feedback" aria-hidden="true"></div>
          <div>
            <span class="chip">Admin Review</span>
            <h3 class="heading-top heading-tight">What happens after submission</h3>
            <p class="muted">Each complaint enters the admin queue where it can be reviewed, updated, resolved, and removed only after closure.</p>
          </div>
        </div>
        <div class="stack flow-top-md">
          <div class="empty-state">
            <strong class="label-block-gap">Step 1</strong>
            Your entry is saved in the system as a new complaint record.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Step 2</strong>
            The admin can review, track, and update its status as needed.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Step 3</strong>
            Resolved issues can later be cleaned from the queue, but only after the concern has been closed.
          </div>
        </div>

        <div class="panel flow-top-md panel-soft-glass">
          <div class="card-head">
            <div class="dashboard-icon icon-guide" aria-hidden="true"></div>
            <div>
              <p class="muted eyebrow-compact">Helpful Tips</p>
              <h3 class="heading-card">For faster review</h3>
              <p class="muted">Specific details reduce back-and-forth and make it easier for admin to identify the right account, book, or transaction.</p>
            </div>
          </div>
          <div class="stack">
            <div class="empty-state">Mention the book title, account name, or page involved when possible.</div>
            <div class="empty-state">Use one clear issue per submission so status tracking stays simple.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
