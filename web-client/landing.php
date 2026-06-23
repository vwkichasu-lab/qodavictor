<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QODA PU</title>
    <link rel="icon" type="image/png" href="../assets/qoda-logo.png">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, Arial, sans-serif;
            color: white;
            background: #020617;
            overflow-x: hidden;
        }
        body::before { content:""; position:fixed; inset:0; background:url("../assets/qoda-landing.png") center / cover no-repeat fixed; filter:blur(2px); opacity:.58; transform:scale(1.025); }
        body::after { content:""; position:fixed; inset:0; background:linear-gradient(90deg, rgba(2,6,23,.42), rgba(2,6,23,.48) 42%, rgba(2,6,23,.88)); pointer-events:none; }
        main { position:relative; z-index:1; min-height:100vh; display:flex; justify-content:flex-end; align-items:center; padding: clamp(28px, 6vw, 86px); }
        section { width:min(650px, 100%); }
        .brand { display:inline-flex; align-items:center; gap:12px; padding:10px 14px; border-radius:999px; background:rgba(15,23,42,.42); border:1px solid rgba(255,255,255,.24); }
        .q { width:40px; height:40px; display:block; border-radius:13px; overflow:hidden; background:rgba(15,23,42,.52); border:1px solid rgba(255,255,255,.18); }
        .q img, .loader-logo img { width:100%; height:100%; object-fit:cover; display:block; }
        h1 { font-size:clamp(52px, 9vw, 96px); line-height:.92; margin:26px 0 18px; }
        p { font-size:19px; line-height:1.65; color:rgba(255,255,255,.78); max-width:560px; }
        .actions { display:flex; flex-wrap:wrap; gap:14px; margin-top:32px; }
        a { text-decoration:none; color:white; font-weight:900; padding:15px 22px; min-width:150px; text-align:center; border-radius:16px; border:1px solid rgba(255,255,255,.3); background:linear-gradient(135deg,#0ea5e9,#2563eb); }
        a.secondary { background:rgba(15,23,42,.42); backdrop-filter:blur(12px); }
        .qoda-preloader { position:fixed; inset:0; z-index:1000; display:grid; place-items:center; background:radial-gradient(circle at 50% 35%, rgba(14,165,233,.28), transparent 34%), radial-gradient(circle at 30% 75%, rgba(124,58,237,.22), transparent 30%), #020617; transition:opacity .55s ease, visibility .55s ease; }
        .qoda-preloader.is-hidden { opacity:0; visibility:hidden; }
        .loader-card { text-align:center; perspective:900px; }
        .loader-logo { width:116px; height:116px; margin:0 auto 22px; display:grid; place-items:center; border-radius:32px; background:rgba(15,23,42,.52); box-shadow:0 30px 70px rgba(37,99,235,.35), inset -12px -16px 30px rgba(15,23,42,.28), inset 10px 12px 24px rgba(255,255,255,.18); overflow:hidden; border:1px solid rgba(255,255,255,.18); transform-style:preserve-3d; animation:qodaSpin 2.4s ease-in-out infinite; }
        .loader-title { font-size:28px; font-weight:900; letter-spacing:.06em; }
        .loader-sub { margin-top:6px; color:rgba(255,255,255,.72); font-weight:700; }
        .loader-progress { width:min(340px,76vw); height:7px; margin:26px auto 0; border-radius:999px; overflow:hidden; background:rgba(255,255,255,.16); border:1px solid rgba(255,255,255,.18); }
        .loader-progress span { display:block; height:100%; width:0; border-radius:inherit; background:linear-gradient(90deg,#22d3ee,#60a5fa,#a78bfa); animation:qodaProgress 2.15s ease-out forwards; }
        @keyframes qodaSpin { 0%,100% { transform:rotateX(0deg) rotateY(-14deg) translateY(0); } 50% { transform:rotateX(10deg) rotateY(18deg) translateY(-10px); } }
        @keyframes qodaProgress { to { width:100%; } }
    </style>
</head>
<body>
    <div class="qoda-preloader" id="qodaPreloader" aria-label="Loading QODA">
        <div class="loader-card">
            <div class="loader-logo"><img src="../assets/qoda-logo.png" alt="QODA logo"></div>
            <div class="loader-title">QODA PU</div>
            <div class="loader-sub">Secure Coding Examination</div>
            <div class="loader-progress"><span></span></div>
        </div>
    </div>
    <main>
        <section>
            <div class="brand"><span class="q"><img src="../assets/qoda-logo.png" alt="QODA logo"></span><strong>QODA PU</strong></div>
            <h1>QODA EXAM</h1>
            <p>Secure programming examinations, live proctoring, compiler-based assessment, and score publishing for Pentecost University.</p>
            <div class="actions">
                <a href="login.php">Login</a>
                <a class="secondary" href="login.php">Use Assigned Account</a>
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
