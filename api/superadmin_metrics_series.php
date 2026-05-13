<?php
// M/2026/05/13/20 — Endpoint timeseries metrics M98 (complement de superadmin_metrics.php M93 KPIs live).
// Lit metrics_daily (cron quotidien) pour graphique investor-ready 30j/90j/1y.
require_once __DIR__ . '/superadmin_lib.php';
superadmin_or_403();
header('Content-Type: application/json');

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$action = $_GET['action'] ?? 'series';

if ($action === 'latest') {
    $r = $meta->query("SELECT kpi_key, kpi_value FROM metrics_daily WHERE metric_date = (SELECT MAX(metric_date) FROM metrics_daily)")->fetchAll(PDO::FETCH_KEY_PAIR);
    echo json_encode(['ok' => true, 'kpis' => $r, 'date' => $meta->query("SELECT MAX(metric_date) FROM metrics_daily")->fetchColumn()]);
    exit;
}

$kpi = (string)($_GET['kpi'] ?? 'mau');
if (!preg_match('/^[a-z0-9_]+$/', $kpi)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid_kpi']); exit; }
$range = (string)($_GET['range'] ?? '30d');
$daysMap = ['7d' => 7, '30d' => 30, '90d' => 90, '1y' => 365, 'all' => 3650];
$days = $daysMap[$range] ?? 30;
$st = $meta->prepare("SELECT metric_date AS date, kpi_value AS value FROM metrics_daily WHERE kpi_key = ? AND metric_date > DATE_SUB(CURDATE(), INTERVAL ? DAY) ORDER BY metric_date ASC");
$st->bindValue(1, $kpi);
$st->bindValue(2, $days, PDO::PARAM_INT);
$st->execute();
echo json_encode(['ok' => true, 'kpi' => $kpi, 'range' => $range, 'series' => $st->fetchAll()]);
