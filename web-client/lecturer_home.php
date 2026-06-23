<?php
require_once __DIR__ . '/config.php';

if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'LECTURER') {
    header('Location: login.php');
    exit;
}

$name = $_SESSION['fullName'] ?? $_SESSION['user_id_value'] ?? 'Lecturer';
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Home | QODA PU</title>
    <link rel="icon" type="image/png" href="../assets/qoda-logo.png">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, Arial, sans-serif;
            color: #e5f4ff;
            background: radial-gradient(circle at 20% 20%, #1d4ed8, #07111f 56%, #020617);
        }
        main {
            min-height: 100vh;
            display: grid;
            grid-template-columns: minmax(300px, 560px) minmax(320px, 620px);
            gap: 60px;
            align-items: center;
            justify-content: center;
            padding: clamp(24px, 6vw, 80px);
        }
        h1 { margin: 0 0 18px; font-size: clamp(40px, 7vw, 76px); line-height: .98; }
        p { margin: 0; color: #b9c7db; font-size: 18px; line-height: 1.7; }
        .actions { display:flex; gap:14px; flex-wrap:wrap; margin-top:32px; }
        a {
            padding: 15px 22px;
            border-radius: 16px;
            text-decoration: none;
            color: white;
            font-weight: 900;
            background: linear-gradient(135deg, #0ea5e9, #7c3aed);
            border: 1px solid rgba(255,255,255,.22);
        }
        .laptop {
            width: min(620px, 100%);
            aspect-ratio: 1.35;
            position: relative;
            perspective: 900px;
        }
        .screen {
            position: absolute;
            inset: 0 4% 13%;
            border-radius: 24px;
            border: 1px solid rgba(148,163,184,.42);
            background: linear-gradient(145deg, rgba(15,23,42,.98), rgba(30,41,59,.92));
            box-shadow: 0 38px 90px rgba(0,0,0,.38);
            padding: 28px;
            overflow: hidden;
            transform: rotateX(4deg);
        }
        .base {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 16%;
            border-radius: 0 0 28px 28px;
            background: linear-gradient(180deg, #cbd5e1, #64748b);
            transform: rotateX(58deg);
            transform-origin: top;
        }
        .line {
            height: 13px;
            margin-bottom: 14px;
            border-radius: 999px;
            background: linear-gradient(90deg, #22d3ee, #7c3aed);
            animation: codeLine 2.2s ease-in-out infinite;
            opacity: .9;
        }
        .line:nth-child(2n) { width: 70%; animation-delay: .25s; }
        .line:nth-child(3n) { width: 45%; animation-delay: .5s; }
        .line:nth-child(4n) { width: 86%; animation-delay: .75s; }
        .cursor {
            width: 12px;
            height: 22px;
            background: #22d3ee;
            animation: blink .8s step-end infinite;
        }
        @keyframes codeLine { 50% { transform: translateX(16px); filter: brightness(1.25); } }
        @keyframes blink { 50% { opacity: 0; } }
        @media (max-width: 900px) { main { grid-template-columns:1fr; text-align:center; } .actions { justify-content:center; } }
    </style>
</head>
<body>
    <main>
        <section>
            <h1><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($name) ?>.</h1>
            <p>Welcome back to QODA PU. Prepare exams, review submissions, monitor live sessions, and publish results from your lecturer workspace.</p>
            <div class="actions">
                <a href="lecturer_dashboard.php#dashboard">Open Dashboard</a>
                <a href="lecturer_dashboard.php#exams">Create or Manage Exams</a>
            </div>
        </section>
        <section class="laptop" aria-label="Animated laptop showing code">
            <div class="screen">
                <div class="line" style="width:54%"></div>
                <div class="line" style="width:82%"></div>
                <div class="line" style="width:62%"></div>
                <div class="line" style="width:74%"></div>
                <div class="line" style="width:48%"></div>
                <div class="line" style="width:88%"></div>
                <div class="line" style="width:60%"></div>
                <div class="cursor"></div>
            </div>
            <div class="base"></div>
        </section>
    </main>
</body>
</html>
