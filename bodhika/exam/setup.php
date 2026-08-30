<?php
/**
 * exam/setup.php — Admin setup hub (replaces the Setup dropdown).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

if (!Auth::isAdmin()) { header('Location: search.php'); exit; }

$pageTitle = 'Setup';
include __DIR__ . '/../includes/header.php';
?>

<style>
.setup-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 20px;
  margin-top: 8px;
}
.setup-card {
  background: #fff;
  border: 1.5px solid var(--clr-border);
  border-radius: var(--radius-lg);
  padding: 28px 24px;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 10px;
  text-decoration: none;
  transition: box-shadow .18s, transform .18s, border-color .18s;
  box-shadow: var(--shadow-sm);
}
.setup-card:hover {
  box-shadow: var(--shadow);
  transform: translateY(-3px);
  border-color: var(--clr-primary-accent);
  text-decoration: none;
}
.setup-card .sc-icon {
  font-size: 2rem;
  line-height: 1;
}
.setup-card .sc-title {
  font-size: 1rem;
  font-weight: 700;
  color: var(--clr-primary);
}
.setup-card .sc-desc {
  font-size: .82rem;
  color: var(--clr-text-muted);
  line-height: 1.45;
}
</style>

<div class="card">
  <div class="card-header">&#9881; Setup</div>
  <div class="card-body">
    <div class="setup-grid">

      <a href="subjects.php" class="setup-card">
        <span class="sc-icon">&#128218;</span>
        <span class="sc-title">Subjects</span>
        <span class="sc-desc">Add, edit, or remove exam subjects and set fees.</span>
      </a>

      <a href="grades.php" class="setup-card">
        <span class="sc-icon">&#127891;</span>
        <span class="sc-title">Grades</span>
        <span class="sc-desc">Manage grade levels used to categorise exams.</span>
      </a>

      <a href="../Admin/ManageCoupons.php" class="setup-card">
        <span class="sc-icon">&#127381;</span>
        <span class="sc-title">Coupons</span>
        <span class="sc-desc">Create and manage discount coupons for enrollments.</span>
      </a>

      <a href="../Admin/EnrollmentPayments.php" class="setup-card">
        <span class="sc-icon">&#128200;</span>
        <span class="sc-title">Enrollments</span>
        <span class="sc-desc">View and manage student enrollment payments.</span>
      </a>

      <a href="../Admin/ExamResults.php" class="setup-card">
        <span class="sc-icon">&#128202;</span>
        <span class="sc-title">Results</span>
        <span class="sc-desc">Browse all student exam results and scores.</span>
      </a>

      <a href="../Admin/ChangeUserRole.php" class="setup-card">
        <span class="sc-icon">&#128101;</span>
        <span class="sc-title">Users</span>
        <span class="sc-desc">View users and manage their roles and access.</span>
      </a>

    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
