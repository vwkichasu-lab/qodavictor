<?php
require_once __DIR__ . '/auth.php';
$u = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= isset($page_title) ? e($page_title) . ' · QODA' : 'QODA' ?></title>
<link rel="icon" type="image/png" href="<?= base_url('assets/qoda-logo.png') ?>">
<link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>">
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= base_url() ?>">QODA</a>
  <nav>
    <?php if ($u && $u['role'] === 'lecturer'): ?>
      <a href="<?= base_url('lecturer/dashboard.php') ?>">Dashboard</a>
      <a href="<?= base_url('lecturer/create_exam.php') ?>">Create Exam</a>
      <a href="<?= base_url('lecturer/view_exams.php') ?>">My Exams</a>
    <?php elseif ($u && $u['role'] === 'student'): ?>
      <a href="<?= base_url('student/dashboard.php') ?>">Dashboard</a>
      <a href="<?= base_url('student/exams.php') ?>">Available Exams</a>
      <a href="<?= base_url('student/results.php') ?>">My Results</a>
    <?php endif; ?>
    <?php if ($u): ?>
      <span class="user">Hi, <?= e($u['name']) ?></span>
      <a class="btn-ghost" href="<?= base_url('auth/logout.php') ?>">Logout</a>
    <?php else: ?>
      <a href="<?= base_url('auth/login.php') ?>">Login</a>
      <a href="<?= base_url('auth/register.php') ?>">Register</a>
    <?php endif; ?>
  </nav>
</header>
<main class="container">
