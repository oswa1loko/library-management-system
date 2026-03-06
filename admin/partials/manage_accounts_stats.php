<div class="stat-grid manage-users-stats">
  <div class="stat-card">
    <span class="code-pill">Directory</span>
    <strong><?php echo (int) ($stats['total_users'] ?? 0); ?></strong>
    <span class="muted">Total users</span>
  </div>
  <div class="stat-card">
    <span class="code-pill">Students</span>
    <strong><?php echo (int) ($stats['students'] ?? 0); ?></strong>
    <span class="muted">Student accounts</span>
  </div>
  <div class="stat-card">
    <span class="code-pill">Faculty</span>
    <strong><?php echo (int) ($stats['faculty'] ?? 0); ?></strong>
    <span class="muted">Faculty accounts</span>
  </div>
  <div class="stat-card">
    <span class="code-pill">Custodians</span>
    <strong><?php echo (int) ($stats['custodians'] ?? 0); ?></strong>
    <span class="muted">Custodian accounts</span>
  </div>
</div>

