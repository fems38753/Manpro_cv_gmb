<?php
// includes/auth_check.php
// Simple, robust auth check for MANPRO/CV GMB
// Do NOT call session_start() here because main app already starts session.

require_once __DIR__ . '/../config/database.php'; // defines current_user(), require_login(), allow(), redirect()

/**
 * Pastikan user sudah login. Fungsi require_login() di config/database.php
 * menaruh redirect ke auth/login.php bila belum.
 */
require_login(); // kalau belum login akan redirect ke /auth/login.php

/**
 * checkPermission($allowed_roles)
 * $allowed_roles = array of role slugs, e.g. ['direktur','staff_keuangan']
 * Jika user tidak punya role => redirect ke dashboard.
 */
function checkPermission(array $allowed_roles) {
    // gunakan helper current_user() yg disediakan config/database.php
    $u = current_user();
    if (!$u) {
        redirect('auth/login.php');
    }
    // role ada di $u['role'] di mayoritas codebase
    $role = $u['role'] ?? null;
    if (!$role || !in_array($role, $allowed_roles, true)) {
        // Akses ditolak — arahkan ke dashboard (atau abort_403 bila ingin)
        redirect('pages/dashboard/index.php');
        exit;
    }
}
