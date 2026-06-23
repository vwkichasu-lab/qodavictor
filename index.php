<?php
function qoda_url(string $path): string
{
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($base === '' || $base === '.') {
        $base = '';
    }
    return $base . '/' . ltrim($path, '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QODA PU | Online Programming Examination</title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(qoda_url('assets/qoda-logo.png'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, Arial, sans-serif;
            color: #fff;
            background: #020617;
            overflow-x: hidden;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: url("<?= htmlspecialchars(qoda_url('assets/qoda-landing.png'), ENT_QUOTES, 'UTF-8') ?>") center / cover no-repeat fixed;
            filter: blur(2px);
            opacity: .58;
            transform: scale(1.025);
            pointer-events: none;
        }
        body::after {
            content: "";
            position: fixed;
            inset: 0;
            background: linear-gradient(90deg, rgba(2,6,23,.42), rgba(2,6,23,.48) 42%, rgba(2,6,23,.88));
            pointer-events: none;
        }
        .page {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 34px clamp(22px, 5vw, 70px);
        }
        .nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            font-weight: 900;
            letter-spacing: .02em;
        }
        .logo {
            width: 58px;
            height: 58px;
            display: grid;
            place-items: center;
            border-radius: 18px;
            background: rgba(15,23,42,.52);
            box-shadow: 0 18px 40px rgba(14,165,233,.35);
            overflow: hidden;
            border: 1px solid rgba(255,255,255,.18);
        }
        .logo img,
        .loader-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .hero {
            flex: 1;
            width: min(680px, 100%);
            margin-left: auto;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
            padding: 70px 0;
        }
        .eyebrow {
            width: max-content;
            max-width: 100%;
            padding: 10px 14px;
            border: 1px solid rgba(255,255,255,.24);
            border-radius: 999px;
            background: rgba(15,23,42,.34);
            backdrop-filter: blur(12px);
            color: #bfdbfe;
            font-weight: 800;
        }
        h1 {
            margin: 18px 0 16px;
            font-size: clamp(48px, 8vw, 92px);
            line-height: .92;
            letter-spacing: 0;
        }
        p {
            max-width: 560px;
            margin: 0;
            color: rgba(255,255,255,.8);
            font-size: 19px;
            line-height: 1.65;
        }
        .actions {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            margin-top: 34px;
        }
        .btn {
            min-width: 150px;
            padding: 15px 22px;
            border-radius: 16px;
            color: #fff;
            text-decoration: none;
            font-weight: 900;
            text-align: center;
            border: 1px solid rgba(255,255,255,.3);
            background: linear-gradient(135deg, #0ea5e9, #2563eb);
            box-shadow: 0 18px 36px rgba(37,99,235,.32);
        }
        .btn.secondary {
            background: rgba(15,23,42,.36);
            backdrop-filter: blur(12px);
        }
        @media (max-width: 680px) {
            body { background-position: 58% center; }
            .nav { align-items:flex-start; }
            .hero { justify-content:flex-end; padding-bottom: 44px; }
            .actions .btn { flex:1 1 100%; }
        }
        .qoda-preloader {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: grid;
            place-items: center;
            background:
                radial-gradient(circle at 50% 35%, rgba(14,165,233,.28), transparent 34%),
                radial-gradient(circle at 30% 75%, rgba(124,58,237,.22), transparent 30%),
                #020617;
            transition: opacity .55s ease, visibility .55s ease;
        }
        .qoda-preloader.is-hidden {
            opacity: 0;
            visibility: hidden;
        }
        .loader-card {
            text-align: center;
            perspective: 900px;
        }
        .loader-logo {
            width: 116px;
            height: 116px;
            margin: 0 auto 22px;
            display: grid;
            place-items: center;
            border-radius: 32px;
            background: rgba(15,23,42,.52);
            box-shadow: 0 30px 70px rgba(37,99,235,.35), inset -12px -16px 30px rgba(15,23,42,.28), inset 10px 12px 24px rgba(255,255,255,.18);
            overflow: hidden;
            border: 1px solid rgba(255,255,255,.18);
            transform-style: preserve-3d;
            animation: qodaSpin 2.4s ease-in-out infinite;
        }
        .loader-title {
            font-size: 28px;
            font-weight: 900;
            letter-spacing: .06em;
        }
        .loader-sub {
            margin-top: 6px;
            color: rgba(255,255,255,.72);
            font-weight: 700;
        }
        .loader-progress {
            width: min(340px, 76vw);
            height: 7px;
            margin: 26px auto 0;
            border-radius: 999px;
            overflow: hidden;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.18);
        }
        .loader-progress span {
            display: block;
            height: 100%;
            width: 0;
            border-radius: inherit;
            background: linear-gradient(90deg, #22d3ee, #60a5fa, #a78bfa);
            animation: qodaProgress 2.15s ease-out forwards;
        }
        @keyframes qodaSpin {
            0%, 100% { transform: rotateX(0deg) rotateY(-14deg) translateY(0); }
            50% { transform: rotateX(10deg) rotateY(18deg) translateY(-10px); }
        }
        @keyframes qodaProgress { to { width: 100%; } }
    </style>
</head>
<body>
    <div class="qoda-preloader" id="qodaPreloader" aria-label="Loading QODA">
        <div class="loader-card">
            <div class="loader-logo"><img src="<?= htmlspecialchars(qoda_url('assets/qoda-logo.png'), ENT_QUOTES, 'UTF-8') ?>" alt="QODA logo"></div>
            <div class="loader-title">QODA PU</div>
            <div class="loader-sub">Secure Coding Examination</div>
            <div class="loader-progress"><span></span></div>
        </div>
    </div>
    <main class="page">
        <nav class="nav">
            <div class="brand">
                <div class="logo"><img src="<?= htmlspecialchars(qoda_url('assets/qoda-logo.png'), ENT_QUOTES, 'UTF-8') ?>" alt="QODA logo"></div>
                <div>
                    <div>QODA PU</div>
                    <small style="color:rgba(255,255,255,.66);font-weight:700;">Secure Coding Examination</small>
                </div>
            </div>
        </nav>
        <section class="hero">
            <div class="eyebrow">Pentecost University programming exams</div>
            <h1>QODA EXAM</h1>
            <p>Write, supervise, grade, and publish programming assessments from one secure examination workspace.</p>
            <div class="actions">
                <a class="btn" href="<?= htmlspecialchars(qoda_url('web-client/login.php'), ENT_QUOTES, 'UTF-8') ?>">Login</a>
                <a class="btn secondary" href="<?= htmlspecialchars(qoda_url('web-client/login.php'), ENT_QUOTES, 'UTF-8') ?>">Use Assigned Account</a>
            </div>
        </section>
    </main>
    <script>
        window.addEventListener('load', () => {
            setTimeout(() => {
                document.getElementById('qodaPreloader')?.classList.add('is-hidden');
            }, 2100);
        });
    </script>
</body>
</html>
