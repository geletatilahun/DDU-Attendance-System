<!-- Top Navbar -->
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm mb-4">
    <div class="container-fluid">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarContent">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <span class="navbar-text">
                        <i class="fas fa-user-tie text-primary me-1"></i>
                        <?= htmlspecialchars($teacher['full_name'] ?? 'Teacher') ?>
                        <small class="text-muted ms-2">| <?= htmlspecialchars($teacher['department_name'] ?? 'Department') ?></small>
                    </span>
                </li>
            </ul>
            
            <div class="d-flex align-items-center">
                <!-- Notifications -->
                <div class="dropdown me-3">
                    <a href="#" class="position-relative" data-bs-toggle="dropdown">
                        <i class="fas fa-bell fs-5 text-primary"></i>
                        <?php if ($pending_count > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?= $pending_count > 9 ? '9+' : $pending_count ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <h6 class="dropdown-header">Notifications</h6>
                        <?php if ($pending_count > 0): ?>
                            <a class="dropdown-item" href="mark_attendance.php">
                                <i class="fas fa-clock text-warning me-2"></i>
                                <?= $pending_count ?> pending attendance
                            </a>
                        <?php endif; ?>
                        <a class="dropdown-item" href="#">
                            <i class="fas fa-calendar-check text-info me-2"></i>
                            <?= $today_classes_count ?> classes today
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-center" href="#">
                            View All Notifications
                        </a>
                    </div>
                </div>
                
                <!-- User Profile -->
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-decoration-none" data-bs-toggle="dropdown">
                        <?php if (!empty($teacher['profile_image'])): ?>
                            <img src="../uploads/profiles/<?= htmlspecialchars($teacher['profile_image']) ?>" 
                                 class="rounded-circle" width="40" height="40">
                        <?php else: ?>
                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" 
                                 style="width: 40px; height: 40px;">
                                <i class="fas fa-user-tie"></i>
                            </div>
                        <?php endif; ?>
                        <div class="ms-2 d-none d-md-block">
                            <div class="fw-bold"><?= htmlspecialchars($teacher['full_name']) ?></div>
                            <small class="text-muted">Teacher</small>
                        </div>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <a class="dropdown-item" href="profile.php">
                            <i class="fas fa-user me-2"></i> Profile
                        </a>
                        <a class="dropdown-item" href="settings.php">
                            <i class="fas fa-cog me-2"></i> Settings
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="../logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>