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
  <form method="post" class="grid form chips-row manage-users-create-form">
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
    <div class="manage-users-create-field">
      <label>Password</label>
      <input type="password" name="password" required>
    </div>
    <div class="align-end manage-users-create-actions">
      <button type="submit" name="create" value="1">Create User</button>
    </div>
  </form>
</div>
