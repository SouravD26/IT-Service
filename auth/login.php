<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Attendance System</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            min-height: 100vh;
            display: flex;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #fff;
        }

        /* -- Top-left logo -- */
        .site-logo {
            position: fixed;
            top: 22px; left: 28px;
            display: flex; align-items: center; gap: 10px;
            z-index: 20; text-decoration: none;
        }
        .site-logo .logo-icon {
            width: 34px; height: 34px;
            background: linear-gradient(135deg, #7c3aed, #5b21b6);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 12px rgba(124,58,237,0.4);
        }
        .site-logo .logo-icon i { color: #fff; font-size: 15px; }
        .site-logo .logo-text { font-size: 14px; font-weight: 700; color: #1f2937; }
        .site-logo .logo-text span { color: #7c3aed; }
        .site-logo img { height: 36px; width: auto; }

        /* -- LEFT PANEL -- */
        .panel-left {
            flex: 0 0 58%;
            background: #fff;
            display: flex;
            align-items: center;
            padding: 80px 80px 60px 100px;
            position: relative;
            z-index: 1;
        }

        .form-wrap { width: 100%; max-width: 370px; }

        .form-heading {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 7px;
            line-height: 1.25;
        }
        .form-subtitle {
            font-size: 12.5px;
            color: #9ca3af;
            margin-bottom: 30px;
        }

        /* -- Inputs -- */
        .input-group {
            display: flex; align-items: center; gap: 10px;
            border: 1.5px solid #e5e7eb;
            border-radius: 30px;
            padding: 11px 18px;
            margin-bottom: 13px;
            transition: border-color 0.2s;
            background: #fff;
        }
        .input-group:focus-within { border-color: #7c3aed; }
        .input-group .icon { color: #9ca3af; font-size: 13px; flex-shrink: 0; }
        .input-group input {
            flex: 1; border: none; outline: none;
            font-size: 13.5px; color: #374151;
            font-family: inherit; background: transparent;
        }
        .input-group input::placeholder { color: #9ca3af; }
        .eye-btn {
            background: none; border: none; color: #9ca3af;
            cursor: pointer; font-size: 13px; padding: 0; line-height: 1;
            transition: color 0.2s;
        }
        .eye-btn:hover { color: #7c3aed; }

        /* -- Remember + Forgot -- */
        .row-mid {
            display: flex; align-items: center;
            justify-content: space-between;
            margin-bottom: 24px; padding: 0 4px;
        }
        .remember {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; color: #6b7280;
            cursor: pointer; user-select: none;
        }
        .remember input[type="checkbox"] {
            width: 13px; height: 13px;
            accent-color: #7c3aed; cursor: pointer;
        }
        .forgot a {
            font-size: 12px; color: #6b7280;
            text-decoration: none; transition: color 0.2s;
        }
        .forgot a:hover { color: #7c3aed; }

        /* -- Button row -- */
        .btn-row {
            display: flex; align-items: center;
            gap: 22px; margin-bottom: 24px;
        }
        .btn-login {
            padding: 12px 40px;
            background: #6b21a8;
            color: #fff;
            border: none;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            letter-spacing: 0.5px;
            transition: all 0.25s;
            box-shadow: 0 4px 16px rgba(107,33,168,0.35);
        }
        .btn-login:hover {
            background: #7c3aed;
            box-shadow: 0 8px 22px rgba(107,33,168,0.45);
            transform: translateY(-1px);
        }
        .btn-signup {
            font-size: 13px; color: #6b7280;
            text-decoration: none; transition: color 0.2s;
        }
        .btn-signup:hover { color: #7c3aed; }

        /* -- Social icons -- */
        .social-row { display: flex; gap: 10px; align-items: center; }
        .social-btn {
            width: 36px; height: 36px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 14px; text-decoration: none;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .social-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .social-btn.fb { background: #1877f2; }
        .social-btn.tw { background: #1da1f2; }
        .social-btn.gp { background: #ea4335; }

        /* -- RIGHT PANEL -- */
        .panel-right {
            flex: 1;
            background: #5b21b6;
            position: relative;
            overflow: hidden;
            min-height: 100vh;
        }

        /* White wave on left edge of right panel */
        .panel-right::before {
            content: '';
            position: absolute;
            top: 0; left: -1px;
            width: 90px; height: 100%;
            background: #fff;
            clip-path: ellipse(90px 52% at 0% 50%);
            z-index: 2;
        }

        /* Second soft wave layer */
        .panel-right::after {
            content: '';
            position: absolute;
            top: 0; left: -1px;
            width: 60px; height: 100%;
            background: rgba(255,255,255,0.15);
            clip-path: ellipse(60px 48% at 0% 50%);
            z-index: 1;
        }

        /* Decorative arch illustration */
        .deco-art {
            position: absolute;
            top: 50%; left: 58%;
            transform: translate(-50%, -50%);
            z-index: 3;
            height: 480px;
            width: auto;
            max-width: 80%;
        }

        /* -- Footer -- */
        .site-footer {
            position: fixed;
            bottom: 13px; left: 0; right: 0;
            text-align: center;
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: 2.5px;
            /* text-transform: uppercase; */
            color: #9ca3af;
            z-index: 20;
        }
        .site-footer span { color: #7c3aed; }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .panel-left { flex: none; padding: 90px 32px 40px; }
            .panel-right { flex: none; min-height: 220px; }
            .panel-right::before {
                top: -1px; left: 0;
                width: 100%; height: 70px;
                clip-path: ellipse(55% 70px at 50% 0%);
            }
            .panel-right::after { display: none; }
            .deco-art { height: 200px; }
        }
    </style>
</head>
<body>

<!-- Top-left Logo -->
<a class="site-logo" href="#">
    <img src="../assets/images/logo.png" alt="Logo">
</a>

<!-- LEFT panel -->
<div class="panel-left">
    <div class="form-wrap">
        <h1 class="form-heading">Welcome to login system</h1>
        <p class="form-subtitle">Sign in by entering the information below</p>

        <form method="POST" action="login_process.php">
            <div class="input-group">
                <span class="icon"><i class="fas fa-user"></i></span>
                <input type="tel" id="phone" name="phone"
                    placeholder="Mobile Number"
                    required inputmode="tel" pattern="[0-9]{10,}"
                    title="Phone number must contain only digits (minimum 10 digits)"
                    onkeypress="return /[0-9]/.test(String.fromCharCode(event.which))"
                    onpaste="event.preventDefault()">
            </div>

            <div class="input-group">
                <span class="icon"><i class="fas fa-lock"></i></span>
                <input type="password" id="password" name="password"
                    placeholder="Password" required>
                <button type="button" class="eye-btn" id="togglePassword" onclick="togglePwd()">
                    <i class="fas fa-eye"></i>
                </button>
            </div>

            <div class="row-mid">
                <!-- <label class="remember">
                    <input type="checkbox" name="remember"> Remember me
                </label> -->
                <div class="forgot">
                    <a href="../admin/reset_password.php">Forgot Password?</a>
                </div>
            </div>

            <div class="btn-row">
                <button type="submit" class="btn-login">Login</button>
                <!-- <a href="#" class="btn-signup">Sign up</a> -->
            </div>
        </form>

        <!-- <div class="social-row">
            <a href="#" class="social-btn fb"><i class="fab fa-facebook-f"></i></a>
            <a href="#" class="social-btn tw"><i class="fab fa-twitter"></i></a>
            <a href="#" class="social-btn gp"><i class="fab fa-google-plus-g"></i></a>
        </div> -->
    </div>
</div>

<!-- RIGHT panel -->
<div class="panel-right">
    <!-- Decorative illustration -->
    <img class="deco-art" src="../assets/images/p.png" alt="Illustration">
</div>

<!-- Footer -->
<div class="site-footer">Made by <span>BlueCoree</span></div>

<script>
function togglePwd() {
    const p = document.getElementById('password');
    const b = document.getElementById('togglePassword');
    if (p.type === 'password') {
        p.type = 'text';
        b.innerHTML = '<i class="fas fa-eye-slash"></i>';
    } else {
        p.type = 'password';
        b.innerHTML = '<i class="fas fa-eye"></i>';
    }
}
</script>

<script>
<?php
if (isset($_GET['error'])) {
    $error = $_GET['error'];
    $messages = [
        'invalid_phone'    => 'Invalid phone number',
        'invalid_password' => 'Invalid phone number or password',
        'user_not_found'   => 'User not found',
        'database_error'   => 'Database error occurred',
        'invalid_request'  => 'Invalid request'
    ];
    $msg = $messages[$error] ?? 'Login failed';
    $msg_escaped = addslashes($msg);
    echo "Swal.fire({
        icon: 'error',
        title: 'Login Failed',
        text: '$msg_escaped',
        confirmButtonColor: '#6b21a8',
        confirmButtonText: 'Try Again'
    });";
}
?>
</script>

</body>
</html>