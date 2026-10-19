<?php
// /prestation/index.php
declare(strict_types=1);

require_once __DIR__ . '/env_loader.php';

// Charger les variables d'environnement
loadEnv(__DIR__ . '/.env');

/**
 * Génère un matricule au format : ID-INITIALES-ANNEE (ex: 14-NMC-26)
 */
function generateMatricule(int $id, string$nom, string $postnom, string$prenom, ?string $dateEmbauche): string {$initials = strtoupper(
        mb_substr(trim($nom), 0, 1) . 
        mb_substr(trim($postnom), 0, 1) . 
        mb_substr(trim($prenom), 0, 1) );$yearPart = '00';
    if (!empty($dateEmbauche)) {
        $yearPart = date('y', strtotime($dateEmbauche));
    }
    
    return "{$id}-{$initials}-{$yearPart}";
}

// ---------------------------------------------------------------------
// FONCTIONS UTILES & SÉCURITÉ
// ---------------------------------------------------------------------
function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Détermine le statut en fonction du service, de l'heure d'arrivée et du jour.
 */
function compute_statut_arrivee(string $service, string $heureArrivee, string $datePresence): string {
    $serviceClean = trim($service);
    $isSaturday   = (date('N', strtotime($datePresence)) === '6');

    // Les SEC & HUM et PREFET prennent la configuration de PRIM & MAT les samedis
    if ($isSaturday && ($serviceClean === 'SEC & HUM' || $serviceClean === 'PREFET')) {
        $serviceClean = 'PRIM & MAT';
    }

    // Définition des heures limites strictes avant retard
    $limits = [
        'DIR & ADM'  => '07:19:59', // Retard à partir de 07:20:00
        'PRIM & MAT' => '07:29:59', // Retard à partir de 07:30:00
        'SEC & HUM'  => '12:29:59', // Retard à partir de 12:30:00
        'PREFET'     => '10:00:59', // Retard à partir de 10:01:00 (en semaine)
        'SENTINELLE' => '17:59:59', // Retard à partir de 18:00:00
    ];

    $limitStr = $limits[$serviceClean] ?? '23:59:59';
    return (strtotime($heureArrivee) > strtotime($limitStr)) ? 'retard' : 'present';
}

// ---------------------------------------------------------------------
// CONNEXION PDO DIRECTEMENT DEPUIS $_ENV
// ---------------------------------------------------------------------
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

// ---------------------------------------------------------------------
// JOUR COURANT ET HEURE
// ---------------------------------------------------------------------
$today      = date('Y-m-d');
$nowTime    = date('H:i:s');
$serverHr   = date('H:i:s');
$isSaturday = (date('N', strtotime($today)) === '6');

// ---------------------------------------------------------------------
// TRAITEMENT DU FORMULAIRE (ARRIVÉE / DÉPART)
// ---------------------------------------------------------------------
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $agentId = isset($_POST['agent_id']) ? (int)$_POST['agent_id'] : 0;
    $action  = $_POST['action'] ?? '';

    if ($agentId > 0 && in_array($action, ['arrive', 'depart'], true)) {
        try {
            $stmt = $pdo->prepare("SELECT id, nom, postnom, prenom, service FROM agent WHERE id = :id AND status = 'actif' LIMIT 1");
            $stmt->execute([':id' => $agentId]);
            $agent = $stmt->fetch();

            if (!$agent) {
                $error = "Agent introuvable ou inactif.";
            } else {
                $stmt = $pdo->prepare("
                    SELECT id, agent_id, date_presence, heure_arrivee, heure_depart, statut
                    FROM presence_agent
                    WHERE agent_id = :aid AND date_presence = :d
                    LIMIT 1
                ");
                $stmt->execute([':aid' => $agentId, ':d' => $today]);
                $presence = $stmt->fetch();

                if ($action === 'arrive') {
                    if ($presence && !empty($presence['heure_arrivee'])) {$error = "L'arrivée de cet agent a déjà été enregistrée pour la journée.";
                    } else {
                        $statut = compute_statut_arrivee((string)$agent['service'], $nowTime,$today);

                        if ($presence) {
                            $stmt =$pdo->prepare("
                                UPDATE presence_agent
                                SET heure_arrivee = :ha, statut = :st
                                WHERE id = :id
                            ");
                            $stmt->execute([
                                ':ha' => $nowTime,
                                ':st' => $statut,
                                ':id' => $presence['id'],
                            ]);
                        } else {
                            $stmt =$pdo->prepare("
                                INSERT INTO presence_agent (agent_id, date_presence, heure_arrivee, statut)
                                VALUES (:aid, :dj, :ha, :st)
                            ");
                            $stmt->execute([
                                ':aid' => $agentId,
                                ':dj'  => $today,
                                ':ha'  => $nowTime,
                                ':st'  => $statut,
                            ]);
                        }

                        $fullName = trim($agent['nom'] . ' ' .$agent['postnom'] . ' ' . $agent['prenom']);$message  = "Arrivée enregistrée pour <strong>{$fullName}</strong> à {$nowTime} (statut : <strong>{$statut}</strong>).";
                    }
                } elseif ($action === 'depart') {
                    if (!$presence || empty($presence['heure_arrivee'])) {$error = "Impossible d'enregistrer le départ : l'arrivée n'a pas encore été enregistrée.";
                    } elseif (!empty($presence['heure_depart'])) {$error = "Le départ de cet agent a déjà été enregistré pour la journée.";
                    } else {
                        $stmt =$pdo->prepare("
                            UPDATE presence_agent
                            SET heure_depart = :hd
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':hd' => $nowTime,
                            ':id' => $presence['id'],
                        ]);

                        $fullName = trim($agent['nom'] . ' ' .$agent['postnom'] . ' ' . $agent['prenom']);$message  = "Départ enregistré pour <strong>{$fullName}</strong> à {$nowTime}.";
                    }
                }
            }
        } catch (Throwable $e) {$error = "Erreur lors de l'enregistrement : " . $e->getMessage();
        }
    } else {
        $error = "Requête invalide.";
    }
}

// ---------------------------------------------------------------------
// RÉCUPÉRATION DE LA LISTE DES AGENTS ACTIFS + PRÉSENCE DU JOUR
// ---------------------------------------------------------------------
$stmt =$pdo->prepare("
    SELECT 
        a.id,
        a.matricule,
        a.nom,
        a.postnom,
        a.prenom,
        a.telephone,
        a.service,
        a.dateEmbauche,
        p.id AS presence_id,
        p.heure_arrivee,
        p.heure_depart,
        p.statut
    FROM agent a
    LEFT JOIN presence_agent p 
        ON p.agent_id = a.id 
       AND p.date_presence = :dj
    WHERE a.status = 'actif'
    ORDER BY a.nom, a.postnom, a.prenom
");
$stmt->execute([':dj' =>$today]);
$agents =$stmt->fetchAll();

// Statistiques rapides
$totalAgents   = count($agents);$countPresents = 0;
$countRetards  = 0;
$countAbsents  = 0;

foreach ($agents as$ag) {
    if (!$ag['presence_id']) {$countAbsents++;
    } else {
        if ($ag['statut'] === 'retard') {$countRetards++;
        } else {
            $countPresents++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>CS ELMA SOMBE - Présence du personnel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <!-- Google Fonts Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CDN Bootstrap 5.3 & Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f6f9;
            color: #334155;
        }

        .navbar-brand {
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .kpi-card {
            border: none;
            border-radius: 12px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.06) !important;
        }

        .kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
        }

        .card-table {
            border: none;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
            overflow: hidden;
        }

        .table thead th {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 600;
            color: #64748b;
            background-color: #f8fafc;
            padding-top: 12px;
            padding-bottom: 12px;
        }

        .table tbody td {
            padding-top: 12px;
            padding-bottom: 12px;
            font-size: 0.9rem;
        }

        .avatar-initial {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: #e2e8f0;
            color: #475569;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .badge-status {
            padding: 6px 12px;
            font-weight: 500;
            font-size: 0.78rem;
            border-radius: 20px;
        }

        .rules-popover {
            font-size: 0.82rem;
            background-color: #ffffff;
            border-left: 4px solid #0d6efd;
            border-radius: 8px;
        }

        .search-box .form-control {
            border-radius: 8px 0 0 8px;
            border-right: none;
        }

        .search-box .input-group-text {
            border-radius: 0 8px 8px 0;
            background-color: #fff;
        }
    </style>
</head>

<body>
    <!-- Top Header Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm py-3">
        <div class="container-fluid px-4">
            <a class="navbar-brand d-flex align-items-center gap-2" href="#">
                <i class="bi bi-clock-history text-primary fs-4"></i>
                <span>CS ELMA SOMBE</span>
            </a>
            <div class="d-flex align-items-center gap-3 text-light ms-auto">
                <a class="btn btn-primary me-2" href="report.php"> 
                    <span>Report présence</span>
                </a>
                <div class="text-end d-none d-sm-block">
                    <div class="small text-secondary"><i class="bi bi-calendar3 me-1"></i>Date du jour</div>
                    <div class="fw-semibold text-white"><?= h(date('d/m/Y', strtotime($today))) ?></div>
                </div>
                <div class="vr bg-secondary opacity-50 d-none d-sm-block my-1" style="height: 30px;"></div>
                <div class="text-end">
                    <div class="small text-secondary"><i class="bi bi-clock me-1"></i>Heure système</div>
                    <div class="fw-bold text-warning font-monospace fs-6" id="digital-clock"><?= h($serverHr) ?></div>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4 py-4">

        <!-- Banner & Header Actions -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h1 class="h3 fw-bold mb-1">Pointage du personnel</h1>
                <p class="text-muted mb-0 small">Enregistrement et suivi quotidien des arrivées et départs.</p>
            </div>
            
            <!-- Information sur les règles de retard -->
            <div class="rules-popover p-3 shadow-sm">
                <div class="fw-semibold text-dark mb-1"><i class="bi bi-info-circle me-1 text-primary"></i> Horaires de présence & Retards :</div>
                <div class="d-flex flex-wrap gap-3 small text-muted">
                    <span><strong class="text-dark">DIR & ADM :</strong> Retard dès 07h20</span>
                    <span><strong class="text-dark">PRIM & MAT :</strong> Retard dès 07h30</span>
                    <span><strong class="text-dark">SEC & HUM :</strong> Retard dès 12h30 <?= $isSaturday ? '<span class="badge bg-warning text-dark ms-1">Samedi (07h30)</span>' : '' ?></span>
                    <span><strong class="text-dark">PREFET :</strong> Retard dès 10h01 <?= $isSaturday ? '<span class="badge bg-warning text-dark ms-1">Samedi (07h30)</span>' : '' ?></span>
                    <span><strong class="text-dark">SENTINELLE :</strong> Retard dès 18h00</span>
                </div>
            </div>
        </div>

        <!-- Messages Flash -->
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($message !== ''): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- KPI Cards Summary -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card kpi-card shadow-sm p-3 bg-white">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-medium">Total Agents Actifs</div>
                            <div class="fs-3 fw-bold text-dark mt-1"><?= $totalAgents ?></div>
                        </div>
                        <div class="kpi-icon bg-primary-subtle text-primary">
                            <i class="bi bi-people-fill"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card kpi-card shadow-sm p-3 bg-white">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-medium">Présents à l'heure</div>
                            <div class="fs-3 fw-bold text-success mt-1"><?= $countPresents ?></div>
                        </div>
                        <div class="kpi-icon bg-success-subtle text-success">
                            <i class="bi bi-person-check-fill"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card kpi-card shadow-sm p-3 bg-white">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-medium">Retards</div>
                            <div class="fs-3 fw-bold text-warning mt-1"><?= $countRetards ?></div>
                        </div>
                        <div class="kpi-icon bg-warning-subtle text-warning">
                            <i class="bi bi-person-exclamation"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card kpi-card shadow-sm p-3 bg-white">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-medium">Non pointés</div>
                            <div class="fs-3 fw-bold text-danger mt-1"><?= $countAbsents ?></div>
                        </div>
                        <div class="kpi-icon bg-danger-subtle text-danger">
                            <i class="bi bi-person-x-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Data Table Card -->
        <div class="card card-table bg-white">
            <div class="card-header bg-white py-3 px-4 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <h2 class="h5 fw-bold mb-0 text-dark">Liste des agents actifs (<?= $totalAgents ?>)</h2>
                
                <!-- Search Input -->
                <div class="input-group search-box style-input" style="max-width: 320px;">
                    <span class="input-group-text border-end-0 bg-light text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" id="searchInput" class="form-control bg-light border-start-0" placeholder="Rechercher un agent...">
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="agentsTable">
                    <thead>
                        <tr>
                            <th>Matricule</th>
                            <th>Agent</th>
                            <th>Téléphone</th>
                            <th>Service</th>
                            <th class="text-center">Heure Arrivée</th>
                            <th class="text-center">Heure Départ</th>
                            <th class="text-center">Statut</th>
                            <th class="text-end pe-4">Actions de Pointage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$agents): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                    Aucun agent actif enregistré dans le système.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($agents as $a):$fullName  = trim(($a['nom'] ?? '') . ' ' . ($a['postnom'] ?? '') . ' ' . ($a['prenom'] ?? ''));$initials  = strtoupper(mb_substr($a['nom'] ?? 'A', 0, 1) . mb_substr($a['prenom'] ?? '', 0, 1));
                                $arrivee   =$a['heure_arrivee'] ?: '';
                                $depart    =$a['heure_depart'] ?: '';
                                $statutRaw =$a['statut'] ?: '';

                                if (!$a['presence_id']) {$statutAff = 'non pointé';
                                    $badgeClass = 'bg-danger-subtle text-danger border border-danger-subtle';
                                } else {
                                    $statutAff =$statutRaw ?: 'présent';
                                    switch ($statutAff) {
                                        case 'present': 
                                            $badgeClass = 'bg-success-subtle text-success border border-success-subtle';$statutAff = 'présent';
                                            break;
                                        case 'retard':  
                                            $badgeClass = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'; 
                                            break;
                                        default:        
                                            $badgeClass = 'bg-secondary-subtle text-secondary border border-secondary-subtle'; 
                                            break;
                                    }
                                }

                                $canArrive = empty($arrivee);$canDepart = !empty($arrivee) && empty($depart);

                                $matriculeAff = !empty($a['matricule']) 
                                    ? $a['matricule'] 
                                    : generateMatricule((int)$a['id'], (string)$a['nom'], (string)$a['postnom'], (string)$a['prenom'],$a['dateEmbauche'] ?? null);
                            ?>
                                <tr class="agent-row">
                                    <td>
                                        <span class="badge bg-secondary-subtle text-dark border font-monospace"><?= h($matriculeAff) ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="avatar-initial"><?= h($initials) ?></div>
                                            <div>
                                                <div class="fw-semibold text-dark name-search"><?= h($fullName) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-muted">
                                        <?php if ($a['telephone']): ?>
                                            <i class="bi bi-telephone me-1 small opacity-50"></i><?= h((string)$a['telephone']) ?>
                                        <?php else: ?>
                                            <span class="text-black-50">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border px-2 py-1 fw-normal"><?= h((string)$a['service']) ?></span>
                                    </td>
                                    <td class="text-center font-monospace">
                                        <?= $arrivee ? '<span class="fw-medium text-dark"><i class="bi bi-box-arrow-in-right text-success me-1"></i>' . h($arrivee) . '</span>' : '<span class="text-muted opacity-50">—</span>' ?>
                                    </td>
                                    <td class="text-center font-monospace">
                                        <?= $depart ? '<span class="fw-medium text-dark"><i class="bi bi-box-arrow-right text-danger me-1"></i>' . h($depart) . '</span>' : '<span class="text-muted opacity-50">—</span>' ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-status <?= $badgeClass ?>">
                                            <?= h(ucfirst($statutAff)) ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="d-inline-flex gap-2">
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="agent_id" value="<?= (int)$a['id'] ?>">
                                                <input type="hidden" name="action" value="arrive">
                                                <button type="submit" class="btn btn-sm btn-success px-3 fw-medium" <?= $canArrive ? '' : 'disabled' ?>>
                                                    <i class="bi bi-check2-circle me-1"></i> Arrivée
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="agent_id" value="<?= (int)$a['id'] ?>">
                                                <input type="hidden" name="action" value="depart">
                                                <button type="submit" class="btn btn-sm btn-outline-danger px-3 fw-medium" <?= $canDepart ? '' : 'disabled' ?>>
                                                    <i class="bi bi-door-open me-1"></i> Départ
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- JS Bootstrap 5 -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Custom Script JS -->
    <script>
        // Horloge numérique dynamique en temps réel
        function updateClock() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            document.getElementById('digital-clock').textContent = `${hours}:${minutes}:${seconds}`;
        }
        setInterval(updateClock, 1000);

        // Recherche instantanée dans la table
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const value = this.value.toLowerCase();
            const rows = document.querySelectorAll('#agentsTable .agent-row');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(value) ? '' : 'none';
            });
        });
    </script>
</body>

</html>