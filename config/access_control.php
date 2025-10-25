<?php
// config/access_control.php
// RBAC + page permission helpers for MANPRO
// Author: revised for CV_GMB
// Usage: require_once __DIR__.'/access_control.php'; then call checkPermission('stok') or require_permission('purchases.create')

if (session_status() === PHP_SESSION_NONE) session_start();

// if config/database.php exists in same folder, include it (it defines DB helpers & RBAC helpers)
if (file_exists(__DIR__ . '/database.php')) {
    require_once __DIR__ . '/database.php';
}

/**
 * Static fallback role -> page mapping (compatibility with old code)
 * Keys = session role name (legacy), Values = list of allowed page keys
 */
$role_permissions = [
    'direktur' => ['dashboard', 'transaksi', 'stok', 'penjualan', 'keuangan', 'pembelian', 'user', 'laporan'],
    'staff_keuangan' => ['dashboard', 'transaksi', 'penjualan', 'keuangan', 'laporan'],
    'staff_operasional' => ['dashboard', 'stok', 'pembelian']
];

/**
 * Map page keys to permission codes (for permission-based check if DB present)
 * Keep these codes consistent with your permissions table (permissions.code)
 */
$page_to_perm = [
    'dashboard'   => 'dashboard.view',
    'transaksi'   => 'transactions.view',
    'stok'        => 'stock.view',
    'penjualan'   => 'sales.view',
    'keuangan'    => 'transactions.view',
    'pembelian'   => 'purchases.view',
    'user'        => 'users.manage',
    'laporan'     => 'reports.view'
];

/* ---------- Helpers ---------- */

/** Redirect ke halaman login (menggunakan BASE_URL bila tersedia) */
function redirect_login(): void {
    $login = '/auth/login.php';
    if (defined('BASE_URL')) {
        $login = rtrim(BASE_URL, '/') . $login;
    }
    header("Location: {$login}");
    exit;
}

/** Tampilkan halaman 403 sederhana dan exit */
function abort_403(string $message = 'Akses ditolak'): void {
    http_response_code(403);
    echo '<div style="margin:2rem;font:14px/1.5 system-ui"><b>403 - Akses ditolak</b><p>' . htmlspecialchars($message) . '</p></div>';
    exit;
}

/** Ambil user session (wrapper) */
function ac_current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

/** Ambil role name dari session/user; mendukung legacy 'role' kolom */
function ac_get_session_role_name(): ?string {
    $u = ac_current_user();
    if (!$u) return null;
    if (!empty($u['role_name'])) return (string)$u['role_name'];
    if (!empty($u['role'])) return (string)$u['role']; // legacy
    if (!empty($u['role_id']) && function_exists('get_user_role_name')) {
        // jika config/database.php ter-include, gunakan helper dari sana
        $name = get_user_role_name($u);
        if ($name) return $name;
    }
    return null;
}

/** Cek apakah session user memiliki salah satu role (case-insensitive) */
function ac_user_has_role(array $roles): bool {
    $name = ac_get_session_role_name();
    if (!$name) return false;
    foreach ($roles as $r) {
        if (strcasecmp($r, $name) === 0) return true;
    }
    return false;
}

/** Fallback: cek role -> page mapping dari static array */
function ac_static_role_allows(string $role, string $page): bool {
    global $role_permissions;
    $role = strtolower($role);
    if (!isset($role_permissions[$role])) return false;
    return in_array($page, $role_permissions[$role], true);
}

/** Ambil permissions user (code strings) via DB helper jika tersedia; fallback to [] */
function ac_get_user_permissions_from_db(int $user_id): array {
    if (function_exists('get_user_permissions')) {
        return get_user_permissions($user_id);
    }
    return [];
}

/** Cek apakah user memiliki permission code (memakai DB jika tersedia) */
function ac_user_has_permission(int $user_id, string $perm_code): bool {
    // if DB helper available, use it
    if (function_exists('user_has_permission')) {
        try { return user_has_permission($user_id, $perm_code); } catch (Exception $e) { /* ignore */ }
    }
    // else check list from ac_get_user_permissions_from_db (if available)
    $perms = ac_get_user_permissions_from_db($user_id);
    if (in_array($perm_code, $perms, true)) return true;
    return false;
}

/* ---------- Core API ---------- */

/**
 * checkPermission($page)
 * - $page = one of: 'dashboard','transaksi','stok','penjualan','keuangan','pembelian','user','laporan'
 * Behavior:
 *  - if not logged in -> redirect to login
 *  - if role is 'direktur' -> allow
 *  - if DB-based permissions exist -> check mapped permission
 *  - else fallback to static mapping $role_permissions
 *  - if not allowed -> abort 403
 */
function checkPermission(string $page): void {
    global $page_to_perm, $role_permissions;

    // Ensure login
    if (empty($_SESSION['user'])) {
        redirect_login();
    }
    $u = ac_current_user();
    // Direktur always allow
    $roleName = ac_get_session_role_name();
    if ($roleName && strcasecmp($roleName, 'direktur') === 0) {
        return; // allowed
    }

    // If DB permission mapping exists (permission table & role_permissions), try permission check
    $permCode = $page_to_perm[$page] ?? null;
    if ($permCode && isset($u['user_id']) && function_exists('get_user_permissions')) {
        // If user has permission via DB, allow
        if (ac_user_has_permission((int)$u['user_id'], $permCode)) return;
    }

    // Fallback to static mapping using session role or legacy session role text
    $sessionRole = ac_get_session_role_name() ?? ($_SESSION['role'] ?? null);
    if ($sessionRole && ac_static_role_allows($sessionRole, $page)) {
        return; // allowed
    }

    // Not allowed
    abort_403("Anda tidak memiliki akses ke halaman $page.");
}

/**
 * require_permission($perm_code)
 * - Direct permission check by code (e.g. 'purchases.create')
 * - Redirects to login if not logged in; aborts 403 if no permission.
 */
function require_permission(string $perm_code): void {
    if (empty($_SESSION['user'])) redirect_login();
    $u = ac_current_user();
    if (empty($u['user_id'])) abort_403('User id not found in session');

    // Direktur bypass
    $roleName = ac_get_session_role_name();
    if ($roleName && strcasecmp($roleName, 'direktur') === 0) return;

    // If DB-level permission check available, use it
    if (function_exists('user_has_permission')) {
        if (user_has_permission((int)$u['user_id'], $perm_code)) return;
    } else {
        // fallback: map some common perm_codes to static pages + use checkPermission
        $fallback_map = [
            'dashboard.view' => 'dashboard',
            'transactions.view' => 'transaksi',
            'stock.view' => 'stok',
            'purchases.create' => 'pembelian',
            'purchases.view' => 'pembelian',
            'sales.create' => 'penjualan',
            'sales.view' => 'penjualan',
            'users.manage' => 'user',
            'reports.view' => 'laporan'
        ];
        if (isset($fallback_map[$perm_code])) {
            checkPermission($fallback_map[$perm_code]);
            return;
        }
    }

    // if reached here -> not allowed
    abort_403('Aksi tidak diizinkan (permission: ' . htmlspecialchars($perm_code) . ').');
}

/* ---------- Warehouse access helper for operasional ---------- */
/**
 * require_warehouse_access($warehouse_id)
 * - Untuk user operasional, pastikan mereka punya hak ke gudang ini
 * - Untuk direktur/keuangan, default allowed
 */
function require_warehouse_access(int $warehouse_id): void {
    if (empty($_SESSION['user'])) redirect_login();
    $u = ac_current_user();
    // if no role info => deny
    $roleName = ac_get_session_role_name();
    if (!$roleName) abort_403('Role tidak ditemukan');

    // direktur & keuangan bypass
    if (in_array(strtolower($roleName), ['direktur','staff_keuangan'], true)) return;

    // operasional check: use DB helper if exists, else allow (or deny?) — we'll deny if no mapping
    if (function_exists('user_can_access_warehouse')) {
        $ok = user_can_access_warehouse((int)$u['user_id'], $warehouse_id);
        if ($ok) return;
    } else {
        // fallback: check user_warehouses table directly if present
        global $pdo;
        try {
            $st = $pdo->prepare("SELECT 1 FROM user_warehouses WHERE user_id = ? AND warehouse_id = ? LIMIT 1");
            $st->execute([(int)$u['user_id'], $warehouse_id]);
            if ($st->fetchColumn()) return;
        } catch (Exception $e) {
            // on error, deny
        }
    }
    abort_403('Anda tidak diizinkan mengakses gudang ini.');
}

/* ---------- Utility display helpers ---------- */

/** Dapatkan display name untuk role (Indonesian) */
function getRoleName(string $role): string {
    $role_names = [
        'direktur' => 'Direktur',
        'staff_keuangan' => 'Staff Keuangan',
        'staff_operasional' => 'Staff Operasional'
    ];
    return $role_names[$role] ?? $role;
}

/* End of access_control.php */
