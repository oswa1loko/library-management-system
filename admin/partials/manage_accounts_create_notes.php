<div class="panel manage-users-create">
  <div class="card-head">
    <div class="dashboard-icon icon-add" aria-hidden="true"></div>
    <div>
      <p class="muted eyebrow-compact">Account Provisioning</p>
      <h3 class="heading-card">Create and Invite Users</h3>
      <p class="muted">New accounts are created without a shared default password. The system sends a secure setup link so each user can activate their own account.</p>
    </div>
  </div>
  <div class="inline-actions manage-users-create-chips">
    <span class="chip">Roles available: <?php echo count($rolesAllowed); ?></span>
    <span class="chip">Password setup via secure link</span>
    <span class="chip"><?php echo can_send_library_email() ? 'Email transport ready' : 'Email transport not configured'; ?></span>
  </div>

  <div
    class="manage-users-provisioning js-manage-users-provisioning"
    data-active-tab="<?php echo h($activeProvisioningTab ?? 'single'); ?>"
  >
    <div class="manage-users-tabs" role="tablist" aria-label="Account provisioning mode">
      <button
        type="button"
        class="manage-users-tab is-active"
        data-tab-trigger="single"
        role="tab"
        aria-selected="true"
        aria-controls="manage-users-tab-single"
        id="manage-users-tab-button-single"
      >
        Single Invite
      </button>
      <button
        type="button"
        class="manage-users-tab"
        data-tab-trigger="bulk"
        role="tab"
        aria-selected="false"
        aria-controls="manage-users-tab-bulk"
        id="manage-users-tab-button-bulk"
      >
        Bulk Import
      </button>
    </div>

    <div
      class="manage-users-tab-panel is-active"
      data-tab-panel="single"
      role="tabpanel"
      id="manage-users-tab-single"
      aria-labelledby="manage-users-tab-button-single"
    >
      <div class="manage-users-create-grid manage-users-create-grid-single">
        <form method="post" class="grid form chips-row manage-users-create-form js-manage-users-single-create">
          <div class="manage-users-create-field manage-users-create-field-wide">
            <label>Full name</label>
            <input name="fullname" value="<?php echo h($createData['fullname']); ?>" required>
          </div>
          <div class="manage-users-create-field manage-users-create-field-wide">
            <label>Email</label>
            <input type="email" name="email" value="<?php echo h($createData['email']); ?>" required>
          </div>
          <div class="manage-users-create-field">
            <label>Username</label>
            <input name="username" value="<?php echo h($createData['username']); ?>" required>
          </div>
          <div class="manage-users-create-field">
            <label>Role</label>
            <div class="ui-select-shell">
              <select name="role" class="ui-select" required>
                <?php foreach ($rolesAllowed as $roleOption): ?>
                  <option value="<?php echo h($roleOption); ?>" <?php echo $createData['role'] === $roleOption ? 'selected' : ''; ?>><?php echo h(role_label($roleOption)); ?></option>
                <?php endforeach; ?>
              </select>
              <span class="ui-select-caret" aria-hidden="true"></span>
            </div>
          </div>
          <div class="manage-users-create-field">
            <label>Program</label>
            <div class="ui-select-shell">
              <select name="course" class="ui-select">
                <option value="">Select program</option>
                <?php foreach ($courseOptions as $courseValue => $courseLabel): ?>
                  <option value="<?php echo h($courseValue); ?>" <?php echo ($createData['course'] ?? '') === $courseValue ? 'selected' : ''; ?>><?php echo h($courseLabel); ?></option>
                <?php endforeach; ?>
              </select>
              <span class="ui-select-caret" aria-hidden="true"></span>
            </div>
            <p class="muted manage-users-create-help">Required for student accounts.</p>
          </div>
          <div class="manage-users-create-field manage-users-create-field-wide">
            <label>Provisioning flow</label>
            <div class="notice info manage-users-inline-note">After saving, the system will create the account, queue a welcome email, and ask the user to set their own password.</div>
          </div>
          <div class="align-end manage-users-create-actions">
            <button type="submit" name="create" value="1">Create and Send Invite</button>
          </div>
        </form>
      </div>
    </div>

    <div
      class="manage-users-tab-panel"
      data-tab-panel="bulk"
      role="tabpanel"
      id="manage-users-tab-bulk"
      aria-labelledby="manage-users-tab-button-bulk"
      hidden
    >
      <form method="post" class="stack manage-users-bulk-form">
        <div class="card-head manage-users-bulk-head">
          <div>
            <p class="muted eyebrow-compact">Bulk Provisioning</p>
            <h4 class="heading-top-sm">Bulk Student/Faculty Import</h4>
            <p class="muted">Paste one user per line using: <code>fullname,email,username,role,course</code>. The <code>course</code> column can be blank for faculty, librarian, and admin rows.</p>
          </div>
        </div>
        <label for="bulk_rows">Bulk rows</label>
        <textarea id="bulk_rows" name="bulk_rows" rows="8" placeholder="fullname,email,username,role,course&#10;Juan Dela Cruz,juan@example.com,juan.student,student,BSIT&#10;Maria Santos,maria@example.com,maria.faculty,faculty,"><?php echo h($bulkData); ?></textarea>
        <div class="inline-actions manage-users-bulk-actions">
          <button type="submit" name="bulk_create" value="1">Create Bulk Accounts</button>
        </div>
      </form>
    </div>
  </div>
</div>
