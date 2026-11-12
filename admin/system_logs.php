<?php
session_start();

// Include database connection
require_once('../includes/db_connect.php');

// Get the database connection
$pdo = db_connect(); // Assuming your db_connect.php returns the connection

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

// Create logs table if not exists
$sql = "
    CREATE TABLE IF NOT EXISTS system_logs (
        log_id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT,
        username VARCHAR(50),
        action VARCHAR(100),
        details TEXT,
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
";

try {
    $pdo->exec($sql);
} catch (PDOException $e) {
    die("Error creating logs table: " . $e->getMessage());
}

// Handle log clearing
if (isset($_POST['clear_logs']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->exec("TRUNCATE TABLE system_logs");
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Logs cleared successfully'];
        header('Location: system_logs.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Error clearing logs: ' . $e->getMessage()];
        header('Location: system_logs.php');
        exit;
    }
}

// Handle export to CSV
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    exportLogsToCSV($pdo);
    exit;
}

// Pagination
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page); // Ensure page is at least 1
$offset = ($page - 1) * $limit;

// Initialize filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$action_filter = isset($_GET['action_filter']) ? trim($_GET['action_filter']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Build query
$query = "SELECT l.*, u.full_name, u.role 
          FROM system_logs l 
          LEFT JOIN users u ON l.user_id = u.user_id 
          WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (l.username LIKE ? OR l.details LIKE ? OR u.full_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($action_filter)) {
    $query .= " AND l.action = ?";
    $params[] = $action_filter;
}

if (!empty($date_from)) {
    $query .= " AND DATE(l.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND DATE(l.created_at) <= ?";
    $params[] = $date_to;
}

// Get total count
$count_query = "SELECT COUNT(*) as total FROM (" . str_replace("l.*, u.full_name, u.role", "l.log_id", $query) . ") as count_table";
try {
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($params);
    $total_logs = $stmt->fetchColumn();
} catch (PDOException $e) {
    $total_logs = 0;
}

// Add ordering and pagination
$query .= " ORDER BY l.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

// Get logs
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    $logs = [];
    $_SESSION['message'] = ['type' => 'error', 'text' => 'Error loading logs: ' . $e->getMessage()];
}

// Get unique actions for filter dropdown
try {
    $actions_stmt = $pdo->query("SELECT DISTINCT action FROM system_logs ORDER BY action");
    $actions = $actions_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $actions = [];
}

// Function to export logs to CSV
function exportLogsToCSV($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT 
                l.log_id,
                l.username,
                u.full_name,
                u.role,
                l.action,
                l.details,
                l.ip_address,
                l.user_agent,
                l.created_at
            FROM system_logs l
            LEFT JOIN users u ON l.user_id = u.user_id
            ORDER BY l.created_at DESC
        ");
        
        $logs = $stmt->fetchAll();
        
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="system_logs_' . date('Y-m-d_H-i-s') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add CSV headers
        fputcsv($output, ['ID', 'Username', 'Full Name', 'Role', 'Action', 'Details', 'IP Address', 'User Agent', 'Timestamp']);
        
        // Add data rows
        foreach ($logs as $log) {
            fputcsv($output, [
                $log['log_id'] ?? '',
                $log['username'] ?? '',
                $log['full_name'] ?? 'N/A',
                $log['role'] ?? 'N/A',
                $log['action'] ?? '',
                $log['details'] ?? '',
                $log['ip_address'] ?? '',
                $log['user_agent'] ?? '',
                $log['created_at'] ?? ''
            ]);
        }
        
        fclose($output);
        exit;
    } catch (PDOException $e) {
        die("Error exporting logs: " . $e->getMessage());
    }
}

// Display any messages
$message = isset($_SESSION['message']) ? $_SESSION['message'] : null;
unset($_SESSION['message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --dark-color: #1a1a2e;
            --light-color: #f8f9fa;
        }
        
        body {
            background-color: #f5f7fb;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 12px 12px 0 0 !important;
            padding: 1.2rem 1.5rem;
        }
        
        .filter-section {
            background-color: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .action-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        
        .action-login { background-color: #d1e7dd; color: #0f5132; }
        .action-logout { background-color: #f8d7da; color: #842029; }
        .action-create { background-color: #cff4fc; color: #055160; }
        .action-update { background-color: #fff3cd; color: #664d03; }
        .action-delete { background-color: #f8d7da; color: #842029; }
        .action-view { background-color: #d1e7dd; color: #0f5132; }
        .action-other { background-color: #e2e3e5; color: #41464b; }
        
        .table th {
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }
        
        .pagination .page-link {
            color: var(--primary-color);
            border: none;
            margin: 0 2px;
            border-radius: 6px;
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .stats-card .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stats-card .stat-label {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .details-cell {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .details-cell:hover {
            overflow: visible;
            white-space: normal;
            position: relative;
            z-index: 10;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            padding: 10px;
            border-radius: 5px;
        }
        
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- Toast Container for Messages -->
        <div class="toast-container">
            <?php if ($message): ?>
                <div class="toast show align-items-center text-white bg-<?php echo $message['type'] === 'error' ? 'danger' : 'success'; ?> border-0" role="alert">
                    <div class="d-flex">
                        <div class="toast-body">
                            <?php echo htmlspecialchars($message['text']); ?>
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1"><i class="bi bi-journal-text me-2"></i>System Logs</h2>
                <p class="text-muted mb-0">Track user activities and system events</p>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-outline-secondary me-2">
                    <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
                </a>
                <a href="?export=csv" class="btn btn-success">
                    <i class="bi bi-download me-1"></i> Export CSV
                </a>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stat-number"><?php echo number_format($total_logs); ?></div>
                    <div class="stat-label">Total Logs</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <?php
                    try {
                        $today_logs = $pdo->query("SELECT COUNT(*) FROM system_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
                    } catch (PDOException $e) {
                        $today_logs = 0;
                    }
                    ?>
                    <div class="stat-number"><?php echo number_format($today_logs); ?></div>
                    <div class="stat-label">Today's Logs</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <?php
                    try {
                        $unique_users = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM system_logs WHERE user_id IS NOT NULL")->fetchColumn();
                    } catch (PDOException $e) {
                        $unique_users = 0;
                    }
                    ?>
                    <div class="stat-number"><?php echo number_format($unique_users); ?></div>
                    <div class="stat-label">Unique Users</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <?php
                    try {
                        $last_week = $pdo->query("SELECT COUNT(*) FROM system_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
                    } catch (PDOException $e) {
                        $last_week = 0;
                    }
                    ?>
                    <div class="stat-number"><?php echo number_format($last_week); ?></div>
                    <div class="stat-label">Last 7 Days</div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Search username, name, or details...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Action Type</label>
                    <select class="form-select" name="action_filter">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $action): ?>
                            <option value="<?php echo htmlspecialchars($action); ?>" 
                                <?php echo $action_filter == $action ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($action); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="d-flex gap-2 w-100">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-funnel me-1"></i> Filter
                        </button>
                        <a href="system_logs.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle me-1"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Logs Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Activity Logs</h5>
                <form method="POST" onsubmit="return confirm('Are you sure you want to clear ALL logs? This action cannot be undone.');">
                    <button type="submit" name="clear_logs" class="btn btn-danger btn-sm">
                        <i class="bi bi-trash me-1"></i> Clear All Logs
                    </button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>IP Address</th>
                                <th>User Agent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        <i class="bi bi-inbox display-6 d-block mb-2"></i>
                                        No logs found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?php echo $log['log_id']; ?></td>
                                        <td>
                                            <?php 
                                            if ($log['created_at']) {
                                                $date = new DateTime($log['created_at']);
                                                echo $date->format('M d, Y H:i:s');
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($log['user_id']): ?>
                                                <strong><?php echo htmlspecialchars($log['username'] ?? 'Unknown'); ?></strong>
                                                <?php if ($log['full_name']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($log['full_name']); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">System</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($log['role']): ?>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($log['role']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $action_class = 'action-other';
                                            if ($log['action']) {
                                                if (stripos($log['action'], 'login') !== false) $action_class = 'action-login';
                                                elseif (stripos($log['action'], 'logout') !== false) $action_class = 'action-logout';
                                                elseif (stripos($log['action'], 'create') !== false) $action_class = 'action-create';
                                                elseif (stripos($log['action'], 'update') !== false) $action_class = 'action-update';
                                                elseif (stripos($log['action'], 'delete') !== false) $action_class = 'action-delete';
                                                elseif (stripos($log['action'], 'view') !== false) $action_class = 'action-view';
                                            }
                                            ?>
                                            <span class="action-badge <?php echo $action_class; ?>">
                                                <?php echo htmlspecialchars($log['action'] ?? 'Unknown'); ?>
                                            </span>
                                        </td>
                                        <td class="details-cell">
                                            <?php echo htmlspecialchars($log['details'] ?? ''); ?>
                                        </td>
                                        <td>
                                            <code><?php echo htmlspecialchars($log['ip_address'] ?? ''); ?></code>
                                        </td>
                                        <td class="small">
                                            <?php 
                                            $user_agent = htmlspecialchars($log['user_agent'] ?? '');
                                            echo strlen($user_agent) > 30 ? substr($user_agent, 0, 30) . '...' : $user_agent;
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_logs > $limit): ?>
                <div class="card-footer">
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center mb-0">
                            <?php
                            $total_pages = ceil($total_logs / $limit);
                            $visible_pages = 5;
                            $start_page = max(1, $page - floor($visible_pages / 2));
                            $end_page = min($total_pages, $start_page + $visible_pages - 1);
                            $start_page = max(1, $end_page - $visible_pages + 1);
                            
                            if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&action_filter=<?php echo urlencode($action_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&action_filter=<?php echo urlencode($action_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&action_filter=<?php echo urlencode($action_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Info Alert -->
        <div class="alert alert-info mt-4">
            <div class="d-flex">
                <div class="me-3">
                    <i class="bi bi-info-circle display-6"></i>
                </div>
                <div>
                    <h5 class="alert-heading">About System Logs</h5>
                    <p class="mb-2">This page displays all user activities and system events. Logs are automatically recorded when:</p>
                    <ul class="mb-0">
                        <li>Users log in or out</li>
                        <li>Users create, update, or delete records</li>
                        <li>Important system actions occur</li>
                        <li>Any action is performed in the admin panel</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const toasts = document.querySelectorAll('.toast');
            toasts.forEach(toast => {
                const bsToast = new bootstrap.Toast(toast);
                setTimeout(() => {
                    bsToast.hide();
                }, 5000);
                
                toast.addEventListener('hidden.bs.toast', function () {
                    toast.remove();
                });
            });
            
            // Make details cells expandable
            const detailsCells = document.querySelectorAll('.details-cell');
            detailsCells.forEach(cell => {
                cell.addEventListener('mouseenter', function() {
                    this.classList.add('expanded');
                });
                cell.addEventListener('mouseleave', function() {
                    this.classList.remove('expanded');
                });
            });
        });
        
        // Confirm before clearing logs
        document.querySelector('form[onsubmit]')?.addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to clear ALL logs? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>