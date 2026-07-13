<?php
// Full logout: clear session data, expire the browser cookie, then destroy the
// server-side session and issue a fresh ID (session_destroy() alone leaves the
// old PHPSESSID cookie/value intact in the browser — it only removes server data).
// Explicit logout revokes this browser's trusted-device credential. Closing the
// browser or an ordinary idle timeout intentionally leaves quick login intact.
remembered_login_forget();
session_unset();
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
session_regenerate_id(true);
header('Location: ' . url(''));
exit;
