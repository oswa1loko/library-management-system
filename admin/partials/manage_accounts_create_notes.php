<div class="split manage-users-layout">
  <div class="panel manage-users-create">
    <div class="card-head">
      <div class="dashboard-icon icon-add" aria-hidden="true"></div>
      <div>
        <p class="muted eyebrow-compact">Create Account</p>
        <h3 class="heading-card">Create User</h3>
        <p class="muted">Add a new library account with role-based access and a secure initial password.</p>
      </div>
    </div>
    <div class="inline-actions manage-users-create-chips">
      <span class="chip">Roles available: <?php echo count($rolesAllowed); ?></span>
      <span class="chip">Password minimum: 6 characters</span>
    </div>
    <form method="post" class="grid form chips-row">
      <div><label>Full name</label><input name="fullname" value="<?php echo h($createData['fullname']); ?>" required></div>
      <div><label>Email</label><input type="email" name="email" value="<?php echo h($createData['email']); ?>" required></div>
      <div><label>Username</label><input name="username" value="<?php echo h($createData['username']); ?>" required></div>
      <div>
        <label>Role</label>
        <div class="ui-select-shell">
          <select name="role" class="ui-select" required>
            <?php foreach ($rolesAllowed as $roleOption): ?>
              <option value="<?php echo h($roleOption); ?>" <?php echo $createData['role'] === $roleOption ? 'selected' : ''; ?>><?php echo h(ucfirst($roleOption)); ?></option>
            <?php endforeach; ?>
          </select>
          <span class="ui-select-caret" aria-hidden="true"></span>
        </div>
      </div>
      <div><label>Password</label><input type="password" name="password" required></div>
      <div class="align-end"><button type="submit" name="create" value="1">Create User</button></div>
    </form>
  </div>

  <div class="panel manage-users-notes">
    <div class="card-head">
      <div class="dashboard-icon icon-notes" aria-hidden="true"></div>
      <div>
        <p class="muted eyebrow-compact">Quick Notes</p>
        <h3 class="heading-card">Account management reminders</h3>
        <p class="muted">Use the directory below to search accounts fast, then open the separate edit screen when profile details need changes.</p>
      </div>
    </div>
    <div class="stack">
      <div class="empty-state">Passwords are stored as hashes and are never shown back in plain text.</div>
      <div class="empty-state">The active admin account cannot be deleted from this page.</div>
      <div class="empty-state">Use the dedicated edit screen to change roles, credentials, or profile details without crowding the directory view.</div>
    </div>
  </div>
</div>

