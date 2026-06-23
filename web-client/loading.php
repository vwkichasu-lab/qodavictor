<?php
$next = $_GET['next'] ?? 'login.php';
$allowed = ['login.php', 'landing.php', 'student_home.php', 'lecturer_home.php'];
if (!in_array($next, $allowed, true)) {
    $next = 'login.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loading QODA PU</title>
    <link rel="icon" type="image/png" href="../assets/qoda-logo.png">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: Inter, Arial, sans-serif;
            color: white;
            background: radial-gradient(circle at 30% 20%, #2563eb, #07111f 60%, #020617);
            overflow: hidden;
        }
        .loader { text-align: center; padding: 32px; }
        .logo {
            width: 96px;
            height: 96px;
            margin: 0 auto 24px;
            display: grid;
            place-items: center;
            border-radius: 30px;
            background: rgba(15,23,42,.52);
            box-shadow: 0 28px 70px rgba(0,0,0,.34);
            overflow: hidden;
            border: 1px solid rgba(255,255,255,.18);
            animation: logoFloat 2.5s ease-in-out infinite;
        }
        .logo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .word { display: flex; justify-content: center; gap: 5px; font-size: clamp(42px, 9vw, 78px); font-weight: 900; }
        .word span { opacity: .32; animation: letterRise 1.25s ease-in-out infinite; animation-delay: calc(var(--i) * .12s); }
        .bar { width: min(320px, 76vw); height: 5px; margin: 28px auto 0; border-radius: 999px; overflow: hidden; background: rgba(255,255,255,.18); }
        .bar::before { content: ""; display: block; height: 100%; width: 0; background: white; animation: progress 5s linear forwards; }
        @keyframes logoFloat { 50% { transform: translateY(-10px) rotate(-2deg); } }
        @keyframes letterRise { 45%, 65% { opacity: 1; transform: translateY(-14px); } }
        @keyframes progress { to { width: 100%; } }
    </style>
</head>
<body>
    <main class="loader" aria-label="Loading QODA PU">
        <div class="logo"><img src="../assets/qoda-logo.png" alt="QODA logo"></div>
        <div class="word">
            <span style="--i:0">Q</span><span style="--i:1">o</span><span style="--i:2">d</span><span style="--i:3">a</span>
            <span style="--i:4">&nbsp;</span><span style="--i:5">P</span><span style="--i:6">U</span>
        </div>
        <div class="bar"></div>
    </main>
    <script>
        setTimeout(() => {
            window.location.href = <?= json_encode($next) ?>;
        }, 5000);
    </script>
</body>
</html>
