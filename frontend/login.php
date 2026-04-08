<?php

require __DIR__ . '/app/bootstrap.php';

$loginError = null;
$loginWarning = null;

if (is_post_request()) {
    $userId = trim((string) ($_POST['login_id'] ?? ''));
    $password = (string) ($_POST['login_password'] ?? '');

    if ($userId === '' || $password === '') {
        $loginError = 'Enter both login ID and password.';
    } elseif (!login_config_ready()) {
        $loginWarning = 'Access credentials are not configured yet. Add at least one login ID and password in logindata.json.';
    } elseif (!authenticate_user($userId, $password)) {
        $loginError = 'Invalid login ID or password.';
    } else {
        log_activity_event('login', [
            'details' => 'User logged in successfully.',
            'reference' => authenticated_user_id(),
            'actor' => authenticated_user_id(),
        ]);
        redirect_after_login();
    }
} elseif (!login_config_ready()) {
    $loginWarning = 'Access credentials are not configured yet. Add at least one login ID and password in logindata.json.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?= h(app_config('app_name')) ?></title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="shell shell-login">
        <section class="login-card">
            <div class="login-copy-wrap">
                <p class="eyebrow">Control Access</p>
                <h1><?= h(app_config('app_name')) ?></h1>
                <p class="lead login-copy">Sign in to access the live control desk. Intake runs, daily master actions, and activity logs remain protected until access is verified.</p>
            </div>

            <span class="login-divider" aria-hidden="true"></span>

            <?php if ($loginWarning): ?>
                <div class="alert alert-warning alert-compact-page"><?= h($loginWarning) ?></div>
            <?php endif; ?>

            <?php if ($loginError): ?>
                <div class="alert alert-error alert-compact-page"><?= h($loginError) ?></div>
            <?php endif; ?>

            <form action="login.php" method="post" class="upload-form login-form">
                <label class="field">
                    <span>Login ID</span>
                    <input type="text" name="login_id" autocomplete="username" placeholder="Enter your login ID" required>
                </label>

                <label class="field">
                    <span>Password</span>
                    <input type="password" name="login_password" autocomplete="current-password" placeholder="Enter your password" required>
                </label>

                <div class="actions login-actions">
                    <button type="submit" class="button button-primary button-login">Enter Desk</button>
                </div>
            </form>
        </section>
    </div>
</body>
</html>
