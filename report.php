<?php
// /prestation/report.php
declare(strict_types=1);

require_once __DIR__ . '/env_loader.php';

// Charger les variables d'environnement
loadEnv(__DIR__ . '/.env');

// Fonction d'échappement sécurisée
function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Connexion PDO
date_default_timezone_set('Africa/Kinshasa');

try {
    $dsn = "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=" . ($_ENV['DB_NAME'] ?? '') . ";charset=utf8mb4";
    $pdo = new PDO(
        $dsn,
        $_ENV['DB_USER'] ?? 'root',
        $_ENV['DB_PASS'] ?? '',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données : " . h($e->getMessage()));
}

// Récupération des filtres
$filterMonth   = $_GET['month'] ?? ''; // Format attendu : YYYY-MM (ex: 2026-10)
$filterDate    = $_GET['date'] ?? ($filterMonth ? '' : date('Y-m-d'));
$filterService = $_GET['service'] ?? '';
$filterAgentId = isset($_GET['agent_id']) && $_GET['agent_id'] !== '' ? (int)$_GET['agent_id'] : 0;

// 1. Construction de la requête pour le tableau détaillé des pointages
$sqlDetails = "
    SELECT 
        p.*,
        a.nom,
        a.postnom,
        a.prenom,
        a.telephone,
        a.service
    FROM presence_agent p
    JOIN agent a ON p.agent_id = a.id
    WHERE 1=1
";

$params = [];

if (!empty($filterMonth)) {
    $sqlDetails .= " AND p.date_presence LIKE :month";
    $params[':month'] = $filterMonth . '%';
} elseif (!empty($filterDate)) {
    $sqlDetails .= " AND p.date_presence = :date_presence";
    $params[':date_presence'] = $filterDate;
}

if (!empty($filterService)) {
    $sqlDetails .= " AND a.service = :service";
    $params[':service'] = $filterService;
}

if ($filterAgentId > 0) {
    $sqlDetails .= " AND p.agent_id = :agent_id";
    $params[':agent_id'] = $filterAgentId;
}

$sqlDetails .= " ORDER BY p.date_presence DESC, a.nom, a.postnom, a.prenom";

$stmt = $pdo->prepare($sqlDetails);
$stmt->execute($params);
$reports = $stmt->fetchAll();

// 2. Construction de la requête pour les STATISTIQUES RÉCAPITULATIVES PAR AGENT
// MIN(heure_arrivee) et MIN(heure_depart) permettent de récupérer les plus tôt (ex: 00h01 avant 12h21)
$sqlStats = "
    SELECT 
        a.id,
        a.nom,
        a.postnom,
        a.prenom,
        a.service,
        COUNT(p.id) AS jours_prestes,
        SUM(CASE WHEN p.statut = 'present' THEN 1 ELSE 0 END) as total_presents,
        SUM(CASE WHEN p.statut = 'retard' THEN 1 ELSE 0 END) as total_retards,
        SUM(CASE WHEN p.statut = 'absent' THEN 1 ELSE 0 END) as total_absents,
        MIN(NULLIF(p.heure_arrivee, '')) as meilleure_arrivee,
        MIN(NULLIF(p.heure_depart, '')) as meilleur_depart
    FROM agent a
    LEFT JOIN presence_agent p ON p.agent_id = a.id
";

$statsParams = [];
$whereClauses = ["a.status = 'actif'"];

if (!empty($filterMonth)) {
    $whereClauses[] = "p.date_presence LIKE :m_stats";
    $statsParams[':m_stats'] = $filterMonth . '%';
} elseif (!empty($filterDate)) {
    $whereClauses[] = "p.date_presence = :d_stats";
    $statsParams[':d_stats'] = $filterDate;
}

if (!empty($filterService)) {
    $whereClauses[] = "a.service = :s_stats";
    $statsParams[':s_stats'] = $filterService;
}

if ($filterAgentId > 0) {
    $whereClauses[] = "a.id = :a_stats";
    $statsParams[':a_stats'] = $filterAgentId;
}

if (count($whereClauses) > 0) {
    $sqlStats .= " WHERE " . implode(" AND ", $whereClauses);
}

$sqlStats .= " GROUP BY a.id, a.nom, a.postnom, a.prenom, a.service ORDER BY a.nom, a.postnom, a.prenom";

$stmtStats = $pdo->prepare($sqlStats);
$stmtStats->execute($statsParams);
$statsAgents = $stmtStats->fetchAll();

// ---------------------------------------------------------------------
// TRAITEMENT EXPORT EXCEL
// ---------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $fileSuffix = $filterMonth ? "Mois_" . $filterMonth : $filterDate;
    $filename = "Rapport_Presence_" . $fileSuffix . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "\xEF\xBB\xBF"; // BOM UTF-8
    echo "<h3>Rapport Synthetique des Statistiques</h3>";
    echo "<table border='1'>";
    echo "<tr style='background-color:#198754; color:#ffffff; font-weight:bold;'>";
    echo "<th>Agent</th><th>Service</th><th>Jours Prestes</th><th>Presents</th><th>Retards</th><th>Absents</th><th>Meilleure Arrivée</th><th>Meilleur Départ</th>";
    echo "</tr>";
    foreach ($statsAgents as $st) {
        $fName = trim($st['nom'] . ' ' . $st['postnom'] . ' ' . $st['prenom']);
        echo "<tr>";
        echo "<td>" . h($fName) . "</td>";
        echo "<td>" . h((string)$st['service']) . "</td>";
        echo "<td>" . (int)$st['jours_prestes'] . "</td>";
        echo "<td>" . (int)$st['total_presents'] . "</td>";
        echo "<td>" . (int)$st['total_retards'] . "</td>";
        echo "<td>" . (int)$st['total_absents'] . "</td>";
        echo "<td>" . ($st['meilleure_arrivee'] ? h($st['meilleure_arrivee']) : '—') . "</td>";
        echo "<td>" . ($st['meilleur_depart'] ? h($st['meilleur_depart']) : '—') . "</td>";
        echo "</tr>";
    }
    echo "</table><br><br>";

    echo "<h3>Details des Pointages</h3>";
    echo "<table border='1'>";
    echo "<tr style='background-color:#0d6efd; color:#ffffff; font-weight:bold;'>";
    echo "<th>#</th><th>Date</th><th>Agent</th><th>Service</th><th>Heure Arrivee</th><th>Heure Depart</th><th>Statut</th><th>Motif</th>";
    echo "</tr>";

    $i = 1;
    foreach ($reports as $row) {
        $fullName = trim($row['nom'] . ' ' . $row['postnom'] . ' ' . $row['prenom']);
        echo "<tr>";
        echo "<td>" . $i++ . "</td>";
        echo "<td>" . h($row['date_presence']) . "</td>";
        echo "<td>" . h($fullName) . "</td>";
        echo "<td>" . h((string)$row['service']) . "</td>";
        echo "<td>" . ($row['heure_arrivee'] ? h($row['heure_arrivee']) : '—') . "</td>";
        echo "<td>" . ($row['heure_depart'] ? h($row['heure_depart']) : '—') . "</td>";
        echo "<td>" . h(ucfirst($row['statut'])) . "</td>";
        echo "<td>" . ($row['motif'] ? h($row['motif']) : '—') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit;
}

// Listes pour les filtres
$servicesStmt = $pdo->query("SELECT DISTINCT service FROM agent WHERE service IS NOT NULL AND service != '' ORDER BY service");
$servicesList = $servicesStmt->fetchAll(PDO::FETCH_COLUMN);

$allAgentsStmt = $pdo->query("SELECT id, nom, postnom, prenom, service FROM agent WHERE status = 'actif' ORDER BY nom, postnom, prenom");
$allAgentsList = $allAgentsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>CS ELMA SOMBE - Rapport de présence</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <!-- Google Fonts Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f6f9;
            color: #334155;
        }
        .card-report {
            border: none;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        }
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background-color: #fff;
            }
            .card-report {
                box-shadow: none !important;
            }
        }
    </style>
</head>
<body>

    <!-- Top Navbar -->
    <nav class="navbar navbar-dark bg-dark shadow-sm py-3 no-print">
        <div class="container-fluid px-4">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <i class="bi bi-arrow-left-circle text-primary fs-4"></i>
                <span>Retour au Pointage</span>
            </a>
            <div class="text-white fw-semibold">CS ELMA SOMBE - Rapport des Présences</div>
        </div>
    </nav>

    <div class="container-fluid px-4 py-4">

        <!-- En-tête et Actions -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h1 class="h3 fw-bold mb-1">Rapport des Présences & Statistiques</h1>
                <p class="text-muted mb-0 small">Synthèse des jours prestés, présences, retards et absences.</p>
            </div>

            <!-- Boutons d'impression et d'export Excel -->
            <div class="d-flex align-items-center gap-2 no-print">
                <?php 
                    $exportUrl = "report.php?export=excel&month=" . urlencode($filterMonth) 
                               . "&date=" . urlencode($filterDate) 
                               . "&service=" . urlencode($filterService) 
                               . "&agent_id=" . $filterAgentId;
                ?>
                <a href="<?= $exportUrl ?>" class="btn btn-success shadow-sm d-flex align-items-center gap-2">
                    <i class="bi bi-file-earmark-excel-fill"></i>
                    <span>Exporter Excel</span>
                </a>
                <button onclick="window.print()" class="btn btn-dark shadow-sm d-flex align-items-center gap-2">
                    <i class="bi bi-printer"></i>
                    <span>Imprimer</span>
                </button>
            </div>
        </div>

        <!-- Formulaire de Filtres -->
        <div class="card card-report bg-white p-3 mb-4 no-print">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-12 col-sm-6 col-lg-2">
                    <label for="month" class="form-label small fw-semibold">Filtrer par mois</label>
                    <input type="month" id="month" name="month" class="form-control" value="<?= h($filterMonth) ?>">
                </div>
                <div class="col-12 col-sm-6 col-lg-2">
                    <label for="date" class="form-label small fw-semibold">Ou Date précise</label>
                    <input type="date" id="date" name="date" class="form-control" value="<?= h($filterDate) ?>">
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label for="service" class="form-label small fw-semibold">Service</label>
                    <select id="service" name="service" class="form-select">
                        <option value="">Tous les services</option>
                        <?php foreach ($servicesList as $srv): ?>
                            <option value="<?= h($srv) ?>" <?= $filterService === $srv ? 'selected' : '' ?>>
                                <?= h($srv) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label for="agent_id" class="form-label small fw-semibold">Agent</label>
                    <select id="agent_id" name="agent_id" class="form-select">
                        <option value="">Tous les agents</option>
                        <?php foreach ($allAgentsList as $ag): ?>
                            <?php $agName = trim($ag['nom'] . ' ' . $ag['postnom'] . ' ' . $ag['prenom']); ?>
                            <option value="<?= (int)$ag['id'] ?>" <?= $filterAgentId === (int)$ag['id'] ? 'selected' : '' ?>>
                                <?= h($agName) ?> (<?= h((string)$ag['service']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-lg-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="bi bi-filter me-1"></i> Filtrer
                    </button>
                    <a href="report.php" class="btn btn-outline-secondary" title="Réinitialiser">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- TABLEAU SYNTHÉTIQUE DES STATISTIQUES PAR AGENT -->
        <div class="card card-report bg-white mb-4">
            <div class="card-header bg-white py-3 px-4 d-flex justify-content-between align-items-center">
                <h2 class="h5 fw-bold mb-0 text-dark"><i class="bi bi-bar-chart-fill text-primary me-2"></i>Synthèse des statistiques par agent</h2>
                <span class="badge bg-primary-subtle text-primary border px-3 py-2"><?= count($statsAgents) ?> agent(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Agent</th>
                            <th>Service</th>
                            <th class="text-center">Jours Prestés</th>
                            <th class="text-center">Présents</th>
                            <th class="text-center">Retards</th>
                            <th class="text-center">Absents</th>
                            <th class="text-center">Meilleure Arrivée</th>
                            <th class="text-center">Meilleur Départ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($statsAgents)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">Aucune statistique disponible pour cette période.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($statsAgents as $st): ?>
                                <?php $fullNameSt = trim($st['nom'] . ' ' . $st['postnom'] . ' ' . $st['prenom']); ?>
                                <tr>
                                    <td class="ps-4 fw-semibold text-dark"><?= h($fullNameSt) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= h((string)$st['service']) ?></span></td>
                                    <td class="text-center fw-bold"><?= (int)$st['jours_prestes'] ?></td>
                                    <td class="text-center text-success fw-semibold"><?= (int)$st['total_presents'] ?></td>
                                    <td class="text-center text-warning fw-semibold"><?= (int)$st['total_retards'] ?></td>
                                    <td class="text-center text-danger fw-semibold"><?= (int)$st['total_absents'] ?></td>
                                    <td class="text-center font-monospace text-success"><?= $st['meilleure_arrivee'] ? h($st['meilleure_arrivee']) : '—' ?></td>
                                    <td class="text-center font-monospace text-primary"><?= $st['meilleur_depart'] ? h($st['meilleur_depart']) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TABLEAU DÉTAILLÉ DES POINTAGES -->
        <div class="card card-report bg-white">
            <div class="card-header bg-white py-3 px-4 d-flex justify-content-between align-items-center">
                <h2 class="h5 fw-bold mb-0 text-dark"><i class="bi bi-list-task text-secondary me-2"></i>Détail chronologique des pointages</h2>
                <span class="badge bg-secondary-subtle text-dark border px-3 py-2"><?= count($reports) ?> enregistrement(s)</span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Date</th>
                            <th>Agent</th>
                            <th>Service</th>
                            <th class="text-center">Heure Arrivée</th>
                            <th class="text-center">Heure Départ</th>
                            <th class="text-center">Statut</th>
                            <th>Motif / Remarque</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($reports)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                    Aucun détail de pointage trouvé pour ces critères.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reports as $row): ?>
                                <?php 
                                    $fullName = trim($row['nom'] . ' ' . $row['postnom'] . ' ' . $row['prenom']);
                                    $badgeClass = match($row['statut']) {
                                        'present' => 'bg-success-subtle text-success border border-success-subtle',
                                        'retard'  => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                                        'absent'  => 'bg-danger-subtle text-danger border border-danger-subtle',
                                        'conge'   => 'bg-info-subtle text-info-emphasis border border-info-subtle',
                                        'malade'  => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                                        default   => 'bg-light text-dark'
                                    };
                                ?>
                                <tr>
                                    <td class="ps-4 font-monospace small text-muted"><?= h(date('d/m/Y', strtotime($row['date_presence']))) ?></td>
                                    <td class="fw-semibold text-dark"><?= h($fullName) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= h((string)$row['service']) ?></span></td>
                                    <td class="text-center font-monospace"><?= $row['heure_arrivee'] ? h($row['heure_arrivee']) : '—' ?></td>
                                    <td class="text-center font-monospace"><?= $row['heure_depart'] ? h($row['heure_depart']) : '—' ?></td>
                                    <td class="text-center">
                                        <span class="badge px-3 py-1 fw-medium <?= $badgeClass ?>">
                                            <?= h(ucfirst($row['statut'])) ?>
                                        </span>
                                    </td>
                                    <td class="text-muted small"><?= $row['motif'] ? h($row['motif']) : '<span class="text-black-50">—</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>