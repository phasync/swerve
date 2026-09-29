<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= \htmlspecialchars($title) ?></title>
<link rel="stylesheet" href="/app.css">
</head>
<body>
<header><nav><a href="/">Home</a> <a href="/orders">Orders</a> <a href="/account">Account</a></nav></header>
<main>
<h1><?= \htmlspecialchars($title) ?></h1>
<table class="orders">
<thead><tr><th>#</th><th>Customer</th><th>Email</th><th>Total</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr class="<?= $row['id'] % 2 ? 'odd' : 'even' ?>"><td><?= $row['id'] ?></td><td><?= \htmlspecialchars($row['name']) ?></td><td><a href="mailto:<?= \htmlspecialchars($row['email']) ?>"><?= \htmlspecialchars($row['email']) ?></a></td><td><?= \number_format($row['total'], 2) ?></td><td class="<?= $row['status'] ?>"><?= \ucfirst($row['status']) ?></td></tr>
<?php endforeach ?>
</tbody>
</table>
</main>
<footer><p>Rendered <?= \count($rows) ?> orders.</p></footer>
</body>
</html>
