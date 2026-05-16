<?php
$errorMessages = [
    'empty_fields'        => 'Please fill in all required fields.',
    'invalid_phone'       => 'Please enter a valid Philippine mobile number in +63 format.',
    'not_authorized'      => 'Your details do not match the admin-authorized records. Please contact support if this is unexpected.',
    'request_exists'      => 'An activation request for these details already exists.',
    'already_activated'   => 'These details have already been activated. Please sign in with your Unique ID.',
    'invalid_email'       => 'Please enter a valid email address.',
    'invalid_role'        => 'Please select whether you are registering as a student or faculty member.',
    'fullname_taken'      => 'An account with that full name already exists.',
    'username_taken'      => 'That username is already taken. Please choose another.',
    'server'              => 'A server error occurred. Please try again later.',
];
$errorCode = trim($_GET['error'] ?? '');
$errorMsg = $errorMessages[$errorCode] ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - Helios University</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        (function(){
            var t = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --paper: #ffffff;
            --surface: rgba(255,255,255,0.94);
            --surface-soft: #eaf2ff;
            --line: rgba(223,231,243,0.82);
            --ink: #183153;
            --muted: #617089;
            --muted-soft: #8fa0b8;
            --brand: #3f70b8;
            --brand-deep: #284d84;
            --danger: #dc2626;
            --danger-bg: #fee2e2;
            --shadow: 0 24px 60px rgba(24,49,83,0.18);
            --font: 'Poppins', system-ui, sans-serif;
            --ease: cubic-bezier(0.16,1,0.3,1);
        }
        [data-theme="dark"] {
            --paper: #07111f;
            --surface: rgba(16,26,40,0.94);
            --surface-soft: #13243d;
            --line: rgba(42,57,80,0.9);
            --ink: #eef4ff;
            --muted: #aebbd0;
            --muted-soft: #71829b;
            --brand: #5f91dd;
            --brand-deep: #dbe9ff;
            --danger: #f87171;
            --danger-bg: rgba(248,113,113,0.12);
            --shadow: 0 24px 60px rgba(0,0,0,0.42);
        }
        html { min-height: 100%; -webkit-font-smoothing: antialiased; }
        body {
            min-height: 100vh;
            font-family: var(--font);
            color: var(--ink);
            background:
                linear-gradient(90deg, rgba(34,72,122,0.94), rgba(63,112,184,0.72)),
                linear-gradient(135deg, #f8fbff 0 20%, #c8d8ee 20% 38%, #ffffff 38% 55%, #a7bad5 55% 100%);
            transition: background 520ms cubic-bezier(0.4,0,0.2,1), color 520ms cubic-bezier(0.4,0,0.2,1);
            overflow-x: hidden;
        }
        [data-theme="dark"] body {
            background:
                linear-gradient(90deg, rgba(3,12,25,0.96), rgba(16,37,66,0.9)),
                linear-gradient(135deg, #07111f 0 22%, #10233d 22% 44%, #0b182a 44% 66%, #18365e 66% 100%);
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(90deg, transparent 0 11%, rgba(255,255,255,0.14) 11% 11.35%, transparent 11.35% 100%),
                radial-gradient(circle at 74% 34%, rgba(47,157,147,0.22) 0 8%, transparent 8.3%),
                radial-gradient(circle at 81% 31%, rgba(227,165,53,0.18) 0 5%, transparent 5.3%),
                repeating-linear-gradient(90deg, transparent 0 70px, rgba(255,255,255,0.055) 70px 71px);
            opacity: 1;
        }
        [data-theme="dark"] body::before { opacity: 0.72; }
        a { color: inherit; text-decoration: none; }
        .nav {
            position: fixed;
            inset: 0 0 auto;
            z-index: 10;
            height: 70px;
            width: min(1180px, calc(100% - 48px));
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            color: #fff;
            animation: fadeDown 700ms 80ms var(--ease) both;
        }
        .brand-logo-img { display: block; width: 120px; height: 54px; object-fit: contain; object-position: left center; }
        .brand-logo-dark { display: none; }
        [data-theme="dark"] .brand-logo-light { display: none; }
        [data-theme="dark"] .brand-logo-dark { display: block; }
        .theme-toggle {
            width: 38px;
            height: 38px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.44);
            background: rgba(255,255,255,0.15);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform 260ms var(--ease), background 260ms ease, border-color 260ms ease;
        }
        .theme-toggle:hover { transform: translateY(-2px) rotate(12deg); background: rgba(255,255,255,0.24); border-color: rgba(255,255,255,0.72); }
        .theme-toggle:active { transform: scale(0.94); }
        .theme-toggle svg { width: 17px; height: 17px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .icon-sun { display: none; }
        [data-theme="dark"] .icon-sun { display: block; }
        [data-theme="dark"] .icon-moon { display: none; }
        .page {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            width: min(1180px, calc(100% - 48px));
            margin: 0 auto;
            padding: 104px 0 56px;
            display: grid;
            grid-template-columns: minmax(260px, 430px) minmax(480px, 600px);
            align-items: center;
            gap: clamp(34px, 6vw, 78px);
        }
        .brand-showcase {
            min-height: 520px;
            border-radius: 8px;
            background: rgba(255,255,255,0.86);
            box-shadow: var(--shadow);
            opacity: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(34px, 6vw, 72px);
            animation: imageRise 900ms 220ms var(--ease) forwards;
        }
        [data-theme="dark"] .brand-showcase { background: rgba(16,26,40,0.88); }
        .brand-showcase .brand-logo-img {
            width: min(100%, 360px);
            height: auto;
            object-fit: contain;
            object-position: center;
        }
        .brand-showcase .brand-logo-dark { display: none; }
        [data-theme="dark"] .brand-showcase .brand-logo-light { display: none; }
        [data-theme="dark"] .brand-showcase .brand-logo-dark { display: block; }
        .card {
            width: 100%;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            opacity: 0;
            animation: fadeUp 760ms 180ms var(--ease) forwards;
            overflow: hidden;
        }
        .card-head { padding: 34px 40px 26px; border-bottom: 1px solid var(--line); }
        .card-head-title { font-size: 28px; font-weight: 800; color: var(--ink); margin-bottom: 6px; }
        .card-head-sub { font-size: 13px; color: var(--muted); line-height: 1.6; }
        .card-body { padding: 30px 40px 34px; }
        .section-sep {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 22px 0 16px;
            color: var(--muted-soft);
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .section-sep:first-child { margin-top: 0; }
        .section-sep::before, .section-sep::after { content: ''; height: 1px; flex: 1; background: var(--line); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .field { position: relative; margin-bottom: 16px; }
        .field label {
            display: block;
            margin-bottom: 6px;
            color: var(--muted-soft);
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .field input, .phone-row {
            width: 100%;
            min-height: 46px;
            color: var(--ink);
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: 10px;
            outline: none;
            transition: transform 260ms var(--ease), border-color 260ms ease, box-shadow 260ms ease, background 520ms cubic-bezier(0.4,0,0.2,1);
        }
        .field input {
            padding: 11px 14px;
            font-family: var(--font);
            font-size: 13.5px;
        }
        .field input:focus, .phone-row:focus-within {
            transform: translateY(-1px);
            border-color: var(--brand-deep);
            box-shadow: 0 0 0 3px rgba(63,112,184,0.16);
            background: var(--surface);
        }
        .field input.has-pw-toggle { padding-right: 42px; }
        .role-options {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }
        .role-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .role-option span {
            min-height: 48px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--surface-soft);
            color: var(--muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            transition: transform 260ms var(--ease), border-color 260ms ease, background 260ms ease, color 260ms ease;
        }
        .role-option input:checked + span {
            transform: translateY(-1px);
            border-color: var(--brand);
            background: var(--surface);
            color: var(--brand);
            box-shadow: 0 0 0 3px rgba(63,112,184,0.14);
        }
        .phone-row { display: flex; overflow: hidden; }
        .phone-prefix-wrap { position: relative; flex: 0 0 auto; display: flex; }
        .phone-prefix-select {
            min-width: 82px;
            border: 0;
            border-right: 1px solid var(--line);
            background: transparent;
            color: var(--ink);
            padding: 11px 28px 11px 12px;
            font: 600 14px var(--font);
            appearance: none;
            outline: none;
        }
        .phone-prefix-caret {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted-soft);
            pointer-events: none;
        }
        .phone-prefix-caret svg { width: 11px; height: 11px; stroke: currentColor; fill: none; stroke-width: 2.5; }
        .phone-number-input {
            flex: 1;
            border: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
            min-width: 0;
        }
        .pw-toggle {
            position: absolute;
            right: 12px;
            bottom: 11px;
            border: 0;
            background: none;
            color: var(--muted-soft);
            cursor: pointer;
            display: flex;
        }
        .pw-toggle svg { width: 15px; height: 15px; stroke: currentColor; fill: none; stroke-width: 2; }
        .alert {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 11px 14px;
            margin-bottom: 20px;
            border: 1px solid rgba(220,38,38,0.2);
            border-radius: 10px;
            background: var(--danger-bg);
            color: var(--danger);
            font-size: 12.5px;
            font-weight: 600;
        }
        .alert svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2.5; flex-shrink: 0; }
        .btn-submit {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            width: 100%;
            min-height: 50px;
            margin-top: 8px;
            border: 0;
            border-radius: 999px;
            background: var(--brand);
            color: #fff;
            font: 700 13.5px var(--font);
            cursor: pointer;
            box-shadow: 0 14px 28px rgba(24,49,83,0.18);
            transition: transform 260ms var(--ease), box-shadow 260ms ease, background 260ms ease;
        }
        .btn-submit::before {
            content: '';
            position: absolute;
            inset: -40% -20%;
            z-index: -1;
            background: linear-gradient(120deg, transparent 20%, rgba(255,255,255,0.4) 50%, transparent 80%);
            transform: translateX(-120%);
            transition: transform 560ms var(--ease);
        }
        .btn-submit:hover { transform: translateY(-4px) scale(1.01); box-shadow: 0 18px 36px rgba(24,49,83,0.24); }
        .btn-submit:hover::before { transform: translateX(120%); }
        .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .signin-row { margin-top: 22px; text-align: center; color: var(--muted); font-size: 12.5px; }
        .signin-row a { color: var(--brand); font-weight: 700; }
        .signin-row a:hover { text-decoration: underline; }
        .tip {
            position: absolute;
            left: 0;
            top: calc(100% + 8px);
            z-index: 20;
            min-width: 220px;
            padding: 13px 16px;
            border-radius: 10px;
            background: #1a1f2e;
            color: rgba(255,255,255,0.72);
            box-shadow: 0 8px 28px rgba(0,0,0,0.28);
            opacity: 0;
            pointer-events: none;
            transform: translateY(4px);
            transition: opacity 180ms ease, transform 180ms ease;
        }
        .tip.visible { opacity: 1; pointer-events: auto; transform: translateY(0); }
        .tip-title { color: rgba(255,255,255,0.92); font-size: 12.5px; font-weight: 700; margin-bottom: 8px; }
        .check-item { display: flex; gap: 8px; font-size: 12px; line-height: 1.5; padding: 2px 0; }
        .check-dot { width: 5px; height: 5px; border-radius: 50%; background: rgba(255,255,255,0.35); margin-top: 7px; flex-shrink: 0; }
        .check-item.pass { color: rgba(255,255,255,0.9); }
        .check-item.pass .check-dot { background: #4ade80; }
        .check-item.fail .check-dot { background: #f87171; }
        html.is-theme-switching body::after { opacity: 1; transform: scale(1); }
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            z-index: 999;
            pointer-events: none;
            background: radial-gradient(circle at top right, rgba(255,255,255,0.16), transparent 34%);
            opacity: 0;
            transform: scale(1.04);
            transition: opacity 420ms cubic-bezier(0.4,0,0.2,1), transform 420ms cubic-bezier(0.4,0,0.2,1);
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(18px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeDown {
            from { opacity: 0; transform: translateY(-14px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes imageRise {
            from { opacity: 0; transform: translateY(32px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @media (max-width: 980px) {
            .page { grid-template-columns: 1fr; width: min(100% - 34px, 620px); padding-top: 92px; }
            .brand-showcase { min-height: 220px; }
        }
        @media (max-width: 580px) {
            .nav { width: min(100% - 34px, 620px); }
            .card-head { padding: 28px 24px 24px; }
            .card-body { padding: 24px 24px 28px; }
            .grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<nav class="nav">
    <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
        <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        <svg class="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
    </button>
</nav>

<main class="page">
    <a class="brand-showcase" href="index.php" aria-label="Helios University home">
        <img class="brand-logo-img brand-logo-light" src="img/Light Icon.png" alt="Helios University">
        <img class="brand-logo-img brand-logo-dark" src="img/Dark Icon.png" alt="Helios University">
    </a>
    <section class="card">
        <div class="card-head">
            <div class="card-head-title">Account Activation Request</div>
            <div class="card-head-sub">Enter your authorized details. If they match the admin records, your request will be sent for approval.</div>
        </div>
        <div class="card-body">
            <div id="errorContainer">
                <?php if ($errorMsg): ?>
                    <div class="alert"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span><?= htmlspecialchars($errorMsg) ?></span></div>
                <?php endif; ?>
            </div>

            <form action="process_register.php" method="POST" id="regForm" novalidate>
                <div class="section-sep">Personal</div>
                <div class="grid-2">
                    <div class="field">
                        <label for="firstname">First Name</label>
                        <input type="text" id="firstname" name="firstname" required value="<?= htmlspecialchars($_GET['firstname'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="lastname">Last Name</label>
                        <input type="text" id="lastname" name="lastname" required value="<?= htmlspecialchars($_GET['lastname'] ?? '') ?>">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label for="phonenumber">Phone</label>
                        <div class="phone-row">
                            <div class="phone-prefix-wrap">
                                <select id="countryCode" name="country_code" class="phone-prefix-select" aria-label="Country code">
                                    <option value="+63" selected>+63</option>
                                </select>
                                <span class="phone-prefix-caret"><svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></span>
                            </div>
                            <input type="tel" id="phonenumber" name="phonenumber" class="phone-number-input" placeholder="9XXXXXXXXX" required maxlength="10" inputmode="numeric" pattern="9[0-9]{9}" value="<?= htmlspecialchars($_GET['phonenumber'] ?? '') ?>">
                            <input type="hidden" id="fullphone" name="fullphone">
                        </div>
                    </div>
                    <div class="field">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" required autocomplete="email" value="<?= htmlspecialchars($_GET['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="section-sep">Account Type</div>
                <div class="role-options">
                    <?php $selectedRole = $_GET['role'] ?? 'student'; ?>
                    <label class="role-option">
                        <input type="radio" name="role" value="student" <?= $selectedRole === 'student' ? 'checked' : '' ?>>
                        <span>Student</span>
                    </label>
                    <label class="role-option">
                        <input type="radio" name="role" value="faculty" <?= $selectedRole === 'faculty' ? 'checked' : '' ?>>
                        <span>Faculty</span>
                    </label>
                </div>

                <button type="submit" class="btn-submit" id="regBtn">Send Activation Request</button>
            </form>
            <div class="signin-row">Already have an account? <a href="login.php">Sign in</a></div>
        </div>
    </section>
</main>

<script>
(function(){
    var toggle = document.getElementById('themeToggle');
    toggle.addEventListener('click', function(){
        var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.classList.add('is-theme-switching');
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
        window.setTimeout(function(){ document.documentElement.classList.remove('is-theme-switching'); }, 460);
    });

    var form = document.getElementById('regForm');
    var errorBox = document.getElementById('errorContainer');
    var phoneInput = document.getElementById('phonenumber');
    var countryCode = document.getElementById('countryCode');
    var fullPhoneHidden = document.getElementById('fullphone');
    var firstNameInput = document.getElementById('firstname');
    var lastNameInput = document.getElementById('lastname');
    var emailInput = document.getElementById('email');

    function showError(msg) {
        errorBox.innerHTML = '<div class="alert"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span>' + msg + '</span></div>';
    }
    function updateFullPhone() {
        var digits = phoneInput.value.replace(/\D/g, '').slice(0, 10);
        phoneInput.value = digits;
        countryCode.value = '+63';
        fullPhoneHidden.value = '+63' + digits;
    }
    phoneInput.addEventListener('input', updateFullPhone);
    countryCode.addEventListener('change', updateFullPhone);

    form.addEventListener('submit', function(e){
        errorBox.innerHTML = '';
        updateFullPhone();
        if (!firstNameInput.value.trim() || !lastNameInput.value.trim() || !phoneInput.value.trim() || !emailInput.value.trim() || !document.querySelector('input[name="role"]:checked')) {
            e.preventDefault(); showError('Please fill in all fields.'); return;
        }
        if (!/^9\d{9}$/.test(phoneInput.value.replace(/\D/g, ''))) {
            e.preventDefault(); showError('Please enter a valid Philippine mobile number: +63 9XXXXXXXXX.'); return;
        }
        var btn = document.getElementById('regBtn');
        btn.disabled = true;
        btn.textContent = 'Submitting request...';
    });
    updateFullPhone();
})();
</script>
</body>
</html>
