<div class="panel">
  <div class="toolbar manage-users-toolbar">
    <div class="grow">
      <div class="card-head manage-users-head">
        <div class="dashboard-icon icon-accounts" aria-hidden="true"></div>
        <div>
          <p class="muted eyebrow-compact">Account Directory</p>
          <h3 class="heading-card">All Users</h3>
          <p class="muted">Search by full name, email, or username and filter by role.</p>
        </div>
      </div>
    </div>
    <form method="get" class="toolbar grow manage-users-filters">
      <div class="grow">
        <label for="search">Search</label>
        <input id="search" name="search" value="<?php echo h($search); ?>" placeholder="Search name, email, or username">
      </div>
      <div>
        <label for="role_filter">Role</label>
        <div class="ui-select-shell">
          <select id="role_filter" name="role" class="ui-select">
            <option value="">All roles</option>
            <?php foreach ($rolesAllowed as $roleOption): ?>
              <option value="<?php echo h($roleOption); ?>" <?php echo $roleFilter === $roleOption ? 'selected' : ''; ?>><?php echo h(ucfirst($roleOption)); ?></option>
            <?php endforeach; ?>
          </select>
          <span class="ui-select-caret" aria-hidden="true"></span>
        </div>
      </div>
      <div class="inline-actions">
        <button type="submit">Apply</button>
        <a class="button secondary" href="manage_accounts.php">Reset</a>
      </div>
    </form>
  </div>
  <div class="inline-actions chips-row manage-users-summary">
    <span class="chip">Showing role: <?php echo h($roleFilter !== '' ? ucfirst($roleFilter) : 'All'); ?></span>
    <span class="chip">Search term: <?php echo h($search !== '' ? $search : 'None'); ?></span>
    <span class="chip">Records loaded: <?php echo (int) $users->num_rows; ?></span>
  </div>
  <div class="inline-actions chips-row manage-users-printbar">
    <div class="manage-users-print-control">
      <label for="printAction" class="manage-users-print-label">Print options</label>
      <div class="manage-users-print-shell">
        <select id="printAction" class="manage-users-print-select">
          <option value="">Select print option</option>
          <option value="all">Print All Users</option>
          <option value="student">Print Student</option>
          <option value="faculty">Print Faculty</option>
          <option value="custodian">Print Custodian</option>
          <option value="admin">Print Admin</option>
          <option value="selected">Print Selected</option>
        </select>
        <span class="manage-users-print-caret" aria-hidden="true"></span>
      </div>
    </div>
    <button type="button" class="button secondary" id="runPrintAction">Print</button>
  </div>
  <div class="table-wrap table-wrap-top">
    <table>
      <thead>
        <tr>
          <th><input type="checkbox" id="selectAllUsers" aria-label="Select all users"></th>
          <th>ID</th>
          <th>Full Name</th>
          <th>Email</th>
          <th>Username</th>
          <th>Role</th>
          <th>Created</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($users->num_rows === 0): ?>
          <tr><td colspan="8" class="muted">No users matched your filters.</td></tr>
        <?php endif; ?>
        <?php while ($user = $users->fetch_assoc()): ?>
          <tr>
            <td>
              <input
                type="checkbox"
                class="user-print-check"
                value="<?php echo (int) $user['id']; ?>"
                aria-label="Select <?php echo h($user['fullname']); ?> for printing"
              >
            </td>
            <td><?php echo (int) $user['id']; ?></td>
            <td>
              <strong class="label-block"><?php echo h($user['fullname']); ?></strong>
              <span class="muted">User ID #<?php echo (int) $user['id']; ?></span>
            </td>
            <td><?php echo h($user['email']); ?></td>
            <td><?php echo h($user['username']); ?></td>
            <td><span class="badge"><?php echo h($user['role']); ?></span></td>
            <td><?php echo h($user['created_at']); ?></td>
            <td>
              <div class="inline-actions manage-users-actions">
                <a class="button secondary" href="manage_accounts.php?print=1&user_id=<?php echo (int) $user['id']; ?>">Print</a>
                <a class="button secondary" href="edit_user.php?id=<?php echo (int) $user['id']; ?>">Edit</a>
                <form method="post" class="inline-form js-confirm-delete-user">
                  <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                  <button type="submit" class="danger" name="delete" value="1">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
