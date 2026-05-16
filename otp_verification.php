<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP</title>
    <link rel="stylesheet" href="style.css">
    <script>
        (function(){
            var t=localStorage.getItem('theme')||(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');
            document.documentElement.setAttribute('data-theme',t);
        })();
    </script>
</head>
<body>
<?php
$errors = [
    'empty'          => 'Please enter the OTP sent to your email.',
    'invalid_format' => 'The OTP must be exactly 6 digits.',
    'wrong_otp'      => 'Incorrect OTP. Please check your email and try again.',
    'expired'        => 'Your OTP has expired. Please sign in again to receive a new one.',
];

$error     = $errors[$_GET['error'] ?? ''] ?? null;
$isExpired = ($_GET['error'] ?? '') === 'expired';
?>
    <div class="auth-wrapper">
        <div class="auth-card">

            <div class="auth-brand-row">
                <a href="index.php" class="auth-logo" aria-label="Helios University home">
                    <img class="auth-logo-img auth-logo-light" src="img/Light Icon.png" alt="Helios University">
                    <img class="auth-logo-img auth-logo-dark" src="img/Dark Icon.png" alt="Helios University">
                </a>
                <button class="theme-toggle" aria-label="Toggle theme">
                    <svg class="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                    <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                </button>
            </div>

            <h2>Verify your identity</h2>
            <p class="auth-subtitle">Enter the 6-digit code sent to your chosen method of delivery</p>

            <?php if ($error): ?>
            <div class="alert <?= $isExpired ? 'alert-warning' : 'alert-error' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex-shrink:0;margin-top:2px">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span><?= $error ?></span>
            </div>
            <?php if ($isExpired): ?>
            <a href="login.php" class="btn btn-secondary" style="width:100%;margin-bottom:16px;justify-content:center">Back to Sign In</a>
            <?php endif; ?>
            <?php else: ?>
            <div class="otp-hint">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <p>The code expires in <strong>5 minutes</strong>. Check your inbox or spam folder.</p>
            </div>
            <?php endif; ?>

            <?php if (!$isExpired): ?>
            <form action="otp_verification_process.php" method="POST" id="otpForm">
                <input type="hidden" name="otp" id="otpHidden">

                <div class="otp-slots" id="otpSlots">
                    <input class="otp-slot" type="text" inputmode="numeric" maxlength="1" placeholder="_" aria-label="Digit 1">
                    <input class="otp-slot" type="text" inputmode="numeric" maxlength="1" placeholder="_" aria-label="Digit 2">
                    <input class="otp-slot" type="text" inputmode="numeric" maxlength="1" placeholder="_" aria-label="Digit 3">
                    <input class="otp-slot" type="text" inputmode="numeric" maxlength="1" placeholder="_" aria-label="Digit 4">
                    <input class="otp-slot" type="text" inputmode="numeric" maxlength="1" placeholder="_" aria-label="Digit 5">
                    <input class="otp-slot" type="text" inputmode="numeric" maxlength="1" placeholder="_" aria-label="Digit 6">
                </div>

                <p class="otp-status" id="otpStatus"></p>

                <button type="submit" class="btn btn-primary" id="otpSubmit" disabled>Verify &amp; Continue</button>
            </form>
            <?php endif; ?>

            <p class="auth-footer"><a href="login.php">Back to sign in</a></p>
        </div>
    </div>

    <script src="theme.js"></script>
    <script>
    (function () {
        var slots   = Array.from(document.querySelectorAll('.otp-slot'));
        var hidden  = document.getElementById('otpHidden');
        var status  = document.getElementById('otpStatus');
        var submit  = document.getElementById('otpSubmit');
        var form    = document.getElementById('otpForm');

        if (!slots.length) return;

        /* ── helpers ── */
        function value() { return slots.map(function(s){ return s.value; }).join(''); }

        function setSlotState(cls) {
            slots.forEach(function(s) {
                s.classList.remove('otp-correct', 'otp-wrong');
                if (cls) s.classList.add(cls);
            });
        }

        function showStatus(msg, type) {
            status.textContent = msg;
            status.className   = 'otp-status visible ' + type;
        }

        function clearStatus() {
            status.className   = 'otp-status';
            status.textContent = '';
        }

        function verify() {
            var otp = value();
            if (otp.length < 6) { clearStatus(); setSlotState(''); submit.disabled = true; return; }

            hidden.value    = otp;
            submit.disabled = false;

            /* Live visual feedback — correct means all 6 numeric digits filled */
            if (/^\d{6}$/.test(otp)) {
                setSlotState('otp-correct');
                showStatus('Code complete', 'correct');
            } else {
                setSlotState('otp-wrong');
                showStatus('Digits only, please', 'wrong');
                submit.disabled = true;
            }
        }

        /* ── input handling ── */
        slots.forEach(function(slot, i) {
            slot.addEventListener('input', function() {
                /* keep only last digit typed */
                slot.value = slot.value.replace(/\D/g, '').slice(-1);
                if (slot.value && i < slots.length - 1) slots[i + 1].focus();
                verify();
            });

            slot.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && !slot.value && i > 0) {
                    slots[i - 1].value = '';
                    slots[i - 1].focus();
                    verify();
                }
            });

            slot.addEventListener('paste', function(e) {
                e.preventDefault();
                var pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
                pasted.split('').forEach(function(ch, j) {
                    if (slots[j]) slots[j].value = ch;
                });
                slots[Math.min(pasted.length, slots.length - 1)].focus();
                verify();
            });
        });

        slots[0].focus();

        form.addEventListener('submit', function() {
            hidden.value = value();
        });
    })();
    </script>
</body>
</html>
