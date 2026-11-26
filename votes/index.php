<?php
/**
 * ============================================================================
 *  VOTE "COUP DE CŒUR DU PUBLIC" - Projets audiovisuels
 *  BUT MMI 2e année x Village by CA Rouen Vallée de Seine
 * ============================================================================
 *  Application autonome en un seul fichier (PHP + MySQL), à déposer sur un
 *  serveur PHP. Elle gère trois pages :
 *
 *    vote.php                      -> la page de notation des 9 projets
 *    vote.php?p=ecran&cle=XXXX     -> l'écran de projection : QR code + compteur
 *    vote.php?p=resultats&cle=XXXX -> le classement en direct + coup de cœur
 *
 *  DÉPLOIEMENT
 *  1. Créer une base MySQL, par ex. :  CREATE DATABASE challenge_vbca;
 *  2. Renseigner la CONFIG ci-dessous (base + clé d'administration)
 *  3. Adapter les 9 noms de commanditaires dans le tableau $PROJETS
 *  4. Envoyer vote.php sur le serveur : les tables se créent à la première
 *     visite. Les participants scannent le QR code projeté pour voter.
 * ============================================================================
 */

/* ============================== CONFIG ============================== */

require_once '../conf.php';

/* Clé d'accès aux pages de projection et de résultats (écran du jury).
   Changez-la : elle protège les résultats avant l'annonce officielle. */
const CLE_ADMIN = 'vbca2027';

/* Les 9 commanditaires / projets — même ordre que la machine à sous.
   Les identifiants 1 à 9 ne changent pas. */
$PROJETS = [
    1 => 'FIDAL Avocats',
    2 => 'Le Village by CA Rouen Vallée de Seine',
    3 => 'Normandie Energies',
    4 => 'Le groupe La Poste',
    5 => 'CANDOR',
    6 => 'HomeDoc',
    7 => 'bulbup',
    8 => 'MALO',
];

/* Logos des partenaires : chemin du dossier (relatif à ce fichier, à adapter
   au déploiement) et nom du PNG pour chaque projet. Un fichier manquant
   n'affiche simplement pas de logo. */
const DOSSIER_LOGOS = '../attribution/logos/';

$LOGOS = [
    1 => 'fidal.png',
    2 => 'village_by_CA.png',
    3 => 'normandie_energies.png',
    4 => 'la_poste.png',
    5 => 'candor.png',
    6 => 'homedoc.png',
    7 => 'bulbup.png',
    8 => 'malo.png',
];

function logoFichier(?int $id): ?string {
    global $LOGOS;
    if ($id === null || empty($LOGOS[$id])) return null;
    $chemin = __DIR__ . '/' . DOSSIER_LOGOS . $LOGOS[$id];
    return is_file($chemin) ? DOSSIER_LOGOS . $LOGOS[$id] : null;
}

function baliseLogo(?int $id, string $classe): string {
    $fichier = logoFichier($id);
    if (!$fichier) return '';
    /* URL absolue pour le navigateur : chemin public = DOSSIER_LOGOS sans le
       préfixe "../" (relatif à ce fichier), résolu depuis la racine du projet
       via urlVote() — indépendant de la balise <base> et de l'URL d'accès. */
    $cheminPublic = urlVote() . preg_replace('#^(?:\.\./)+#', '', $fichier);
    return '<img class="' . htmlspecialchars($classe) . '" src="' . htmlspecialchars($cheminPublic) . '" alt="Logo">';
}

/* ============================ OUTILS ============================ */

/* Identifiant anonyme du votant : un cookie par appareil. */
function idVotant(): string {
    if (empty($_COOKIE['votant'])) {
        $id = bin2hex(random_bytes(16));
        setcookie('votant', $id, ['expires' => time() + 60 * 60 * 24 * 30, 'path' => '/']);
        $_COOKIE['votant'] = $id;
    }
    return $_COOKIE['votant'];
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Connexion MySQL impossible. Vérifiez la configuration (host, base, identifiants).<br>' .
                 'Si la base n\'existe pas encore : CREATE DATABASE ' . DB_NAME . ';');
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS votes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            projet_id TINYINT NOT NULL,
            votant_id CHAR(32) NOT NULL,
            score TINYINT NOT NULL,
            horodatage TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY vote_unique (projet_id, votant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    return $pdo;
}

/* URL publique de la page de vote, pour le QR code projeté.
   On repart de la racine du projet (deux niveaux au-dessus de
   votes/index.php), indépendamment du chemin réécrit utilisé pour
   arriver ici : le QR pointe toujours vers la page de vote. */
function urlVote(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/votes/index.php', 2), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $base . '/';
}

/* QR code : service externe simple. Si le site est sans accès Internet
   pendant la soirée, remplacez par la librairie PHP "phpqrcode". */
function qrUrl(string $data, int $taille = 420): string {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $taille . 'x' . $taille .
           '&margin=12&data=' . urlencode($data);
}

function accueilAdmin(): bool {
    if (!isset($_GET['cle']) || !hash_equals(CLE_ADMIN, (string)$_GET['cle'])) {
        http_response_code(403);
        exit('<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;background:#0f1226;color:#f4f6ff;display:grid;place-items:center;height:100vh;margin:0">'
           . '<p>Accès réservé à l\'écran de projection. Demandez l\'adresse complète à l\'organisation.</p></body>');
    }
    return true;
}

/* ============================ ACTIONS ============================ */

$page = $_GET['p'] ?? 'vote';

/* Le cookie du votant doit être posé AVANT tout affichage HTML :
   setcookie() échoue ("headers already sent") dès qu'un octet a été envoyé. */
idVotant();

/* Enregistrement d'une note (formulaire de la page de vote). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'vote') {
    $pid   = (int)($_POST['projet'] ?? 0);
    $score = (int)($_POST['score'] ?? 0);
    if ($pid >= 1 && $pid <= 9 && $score >= 1 && $score <= 5) {
        $stmt = db()->prepare(
            'INSERT INTO votes (projet_id, votant_id, score) VALUES (:p, :v, :s)
             ON DUPLICATE KEY UPDATE score = VALUES(score)'
        );
        $stmt->execute([':p' => $pid, ':v' => idVotant(), ':s' => $score]);
    }
    header('Location: ?p=vote&merci=' . $pid . '#projet-' . $pid);
    exit;
}

/* ============================ VUE ============================ */
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<base href="<?= urlVote() ?>" />
<title>Pitch Coup de cœur du public - MMI x Village by CA</title>
<style>
  :root {
    --bg: #0f1226;
    --panel: #1a1f3d;
    --panel2: #232950;
    --gold: #ffcb47;
    --pink: #ff5d8f;
    --cyan: #4fd1c5;
    --text: #f4f6ff;
    --muted: #9aa3c7;
    font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    color: var(--text);
    background: radial-gradient(1200px 600px at 50% -10%, #2a3060 0%, var(--bg) 60%);
    min-height: 100vh;
  }
  header { text-align: center; padding: 26px 16px 8px; }
  header h1 { margin: 0; font-size: clamp(1.3rem, 3vw, 2rem); }
  header p { margin: 6px 0 0; color: var(--muted); }
  main { max-width: 760px; margin: 0 auto; padding: 16px 20px 60px; }

  .projet {
    background: var(--panel);
    border: 1px solid #2e3564;
    border-radius: 14px;
    padding: 16px 18px;
    margin-bottom: 12px;
  }
  .projet h2 { margin: 0 0 2px; font-size: 1.05rem; color: var(--gold); }
  .projet .num { color: var(--muted); font-size: .8rem; font-weight: 600; }
  .projet-tete { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
  .logo-projet {
    height: 46px; max-width: 150px;
    object-fit: contain;
    background: #fff;
    border-radius: 9px;
    padding: 4px 8px;
    flex-shrink: 0;
  }
  .etoiles { display: flex; gap: 6px; align-items: center; flex-direction: row-reverse; }
  .etoiles input { display: none; }
  .etoiles label {
    font-size: 1.5rem;
    color: #454d96;
    cursor: pointer;
    transition: color .15s, transform .15s;
  }
  .etoiles label:hover { transform: scale(1.15); }
  .etoiles input:checked + label,
  .etoiles input:checked ~ label { color: var(--gold); }
  .vote-ligne { display: flex; align-items: center; gap: 14px; margin-top: 12px; }
  .vote-ligne button {
    margin-left: auto;
    background: linear-gradient(135deg, var(--pink), #b437ff);
    color: #fff;
    border: none;
    cursor: pointer;
    border-radius: 9px;
    font-weight: 700;
    font-size: .85rem;
    padding: 9px 18px;
    font-family: inherit;
    box-shadow: 0 4px 14px rgba(255, 93, 143, .35);
  }
  .vote-ligne button:hover { filter: brightness(1.1); }
  .merci {
    color: var(--cyan);
    font-size: .85rem;
    font-weight: 700;
    margin-left: 10px;
  }
  .note-actuelle { color: var(--muted); font-size: .8rem; margin-top: 8px; }
  .note-actuelle.brouillon { color: var(--gold); font-weight: 700; }

  /* -------- Écran de projection -------- */
  .ecran {
    max-width: 900px;
    margin: 0 auto;
    padding: 30px 20px 40px;
    text-align: center;
  }
  .ecran h1 { font-size: clamp(1.6rem, 4vw, 2.6rem); margin: 0 0 6px; }
  .ecran .sous { color: var(--muted); font-size: 1.1rem; margin-bottom: 28px; }
  .qr-carte {
    display: inline-block;
    background: #fff;
    border-radius: 20px;
    padding: 18px;
    box-shadow: 0 20px 60px rgba(0,0,0,.5);
  }
  .qr-carte img { display: block; width: min(60vw, 380px); height: auto; }
  .compteur {
    margin-top: 26px;
    font-size: clamp(1.2rem, 3vw, 1.8rem);
    font-weight: 800;
    color: var(--cyan);
  }

  /* -------- Résultats -------- */
  .classement { margin-top: 10px; }
  .rang {
    display: flex;
    align-items: center;
    gap: 14px;
    background: var(--panel);
    border: 1px solid #2e3564;
    border-radius: 12px;
    padding: 13px 16px;
    margin-bottom: 8px;
  }
  .rang .place {
    width: 42px; height: 42px;
    border-radius: 50%;
    display: grid; place-items: center;
    font-weight: 800;
    background: var(--panel2);
    color: var(--muted);
    flex-shrink: 0;
  }
  .rang.top .place { background: var(--gold); color: #14204a; }
  .rang .logo-rang {
    height: 38px; max-width: 110px;
    object-fit: contain;
    background: #fff;
    border-radius: 8px;
    padding: 3px 6px;
    flex-shrink: 0;
  }
  .rang .nom { font-weight: 700; flex: 1; }
  .rang .moy { color: var(--gold); font-weight: 800; font-size: 1.1rem; white-space: nowrap; }
  .rang .nb { color: var(--muted); font-size: .8rem; white-space: nowrap; }
  .rang.top { border-color: var(--gold); box-shadow: 0 8px 26px rgba(255, 190, 60, .15); }
  .coup-coeur {
    margin: 0 0 18px;
    padding: 18px;
    border-radius: 14px;
    background: rgba(255, 203, 71, .12);
    border: 1px solid rgba(255, 203, 71, .5);
    text-align: center;
    font-size: 1.15rem;
  }
  .coup-coeur strong { color: var(--gold); }
  .logo-coup {
    height: 56px; max-width: 170px;
    object-fit: contain;
    background: #fff;
    border-radius: 10px;
    padding: 4px 8px;
    vertical-align: middle;
    margin-right: 10px;
  }
  .auto { color: var(--muted); font-size: .8rem; margin-top: 18px; text-align: center; }
  footer { text-align: center; color: var(--muted); font-size: .8rem; padding-bottom: 30px; }
  footer a { color: var(--muted); }
</style>
</head>
<body>

<?php if ($page === 'vote'):
    $pdo   = db();
    $monVote = [];
    $stmt = $pdo->prepare('SELECT projet_id, score FROM votes WHERE votant_id = :v');
    $stmt->execute([':v' => idVotant()]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $v) { $monVote[(int)$v['projet_id']] = (int)$v['score']; }
    $merci = (int)($_GET['merci'] ?? 0);
?>
<header>
  <h1>🏆 Pitch Coup de cœur du public</h1>
  <p>BUT MMI 2e année x Village by CA Rouen Vallée de Seine<br>
     Notez chaque projet de 1 à 5 étoiles. Vous pouvez modifier vos notes.</p>
</header>
<main>
<?php foreach ($PROJETS as $i => $nom): ?>
  <section class="projet" id="projet-<?= $i ?>">
    <div class="projet-tete">
      <?= baliseLogo($i, 'logo-projet') ?>
      <div>
        <span class="num">Projet <?= $i ?>/8</span>
        <h2><?= htmlspecialchars($nom) ?></h2>
        <?php if ($merci === $i): ?><span class="merci">Note enregistrée ✓</span><?php endif; ?>
      </div>
    </div>
    <form method="post">
      <input type="hidden" name="projet" value="<?= $i ?>">
      <div class="vote-ligne">
        <div class="etoiles">
          <?php for ($s = 1; $s <= 5; $s++): ?>
            <input type="radio" id="p<?= $i ?>s<?= $s ?>" name="score" value="<?= 6-$s ?>"
                   <?= ($monVote[$i] ?? 0) === 6-$s ? 'checked' : '' ?>>
            <label for="p<?= $i ?>s<?= $s ?>" aria-label="<?= 6-$s ?> étoiles">★</label>
          <?php endfor; ?>
        </div>
        <button type="submit">Noter</button>
      </div>
    </form>
    <div class="note-actuelle">
      <?= isset($monVote[$i]) ? 'Votre note actuelle : ' . $monVote[$i] . '/5' : 'Pas encore noté' ?>
    </div>
  </section>
<?php endforeach; ?>
</main>
<footer>Votez au fil des présentations : chaque appareil peut noter chaque projet et modifier ses notes à tout moment.</footer>
<script>
/* Le texte sous les étoiles suit la sélection en direct, avant l'enregistrement. */
(function () {
  document.querySelectorAll('section.projet').forEach(function (carte) {
    var note = carte.querySelector('.note-actuelle');
    carte.querySelectorAll('.etoiles input').forEach(function (inp) {
      inp.addEventListener('change', function () {
        note.textContent = 'Votre note : ' + inp.value + '/5 \u2014 cliquez sur \u00ab Noter \u00bb pour l\u2019enregistrer';
        note.classList.add('brouillon');
      });
    });
  });
})();
</script>

<?php elseif ($page === 'ecran'):
    accueilAdmin();
    $total = (int)db()->query('SELECT COUNT(*) FROM votes')->fetchColumn();
?>
<div class="ecran">
  <h1>🏆 Pitch Coup de cœur du public</h1>
  <p class="sous">Scannez ce QR code avec votre téléphone<br>pour noter les 8 projets pendant les pitches</p>
  <div class="qr-carte"><img src="<?= htmlspecialchars(qrUrl(urlVote())) ?>" alt="QR code vers la page de vote"></div>
  <div class="compteur">
    <?= $total ?> note<?= $total > 1 ? 's' : '' ?> reçue<?= $total > 1 ? 's' : '' ?>
  </div>
  <p class="auto">Écran actualisé automatiquement toutes les 15 secondes.</p>
</div>
<meta http-equiv="refresh" content="15">

<?php elseif ($page === 'resultats'):
    accueilAdmin();
    $rows = db()->query(
        'SELECT projet_id, ROUND(AVG(score), 2) AS moyenne, COUNT(*) AS nb
         FROM votes GROUP BY projet_id ORDER BY moyenne DESC, nb DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $rang = 1;
?>
<div class="ecran">
  <h1>Classement en direct</h1>
  <?php if (count($rows) === 0): ?>
    <p class="sous">Aucune note pour l'instant. Les votes apparaîtront ici en direct.</p>
  <?php else:
      $top = $rows[0]; ?>
    <div class="coup-coeur">
      ❤️ Coup de cœur du public : <?= baliseLogo((int)$top['projet_id'], 'logo-coup') ?>
      <strong><?= htmlspecialchars($PROJETS[(int)$top['projet_id']]) ?></strong><br>
      <span class="sous"><?= number_format((float)$top['moyenne'], 2, ',', ' ') ?>/5
        (<?= $top['nb'] ?> note<?= $top['nb'] > 1 ? 's' : '' ?>)</span>
    </div>
  <?php endif; ?>
  <div class="classement">
  <?php foreach ($rows as $r): $i = (int)$r['projet_id']; ?>
    <div class="rang <?= $rang === 1 ? 'top' : '' ?>">
      <div class="place"><?= $rang ?><?= in_array($rang, [1], true) ? 'er' : 'e' ?></div>
      <?= baliseLogo($i, 'logo-rang') ?>
      <div class="nom"><?= htmlspecialchars($PROJETS[$i]) ?></div>
      <div class="moy"><?= number_format((float)$r['moyenne'], 2, ',', ' ') ?>/5</div>
      <div class="nb"><?= $r['nb'] ?> vote<?= $r['nb'] > 1 ? 's' : '' ?></div>
      <?php $rang++; ?>
    </div>
  <?php endforeach; ?>
  </div>
  <p class="auto">Classement actualisé automatiquement toutes les 10 secondes.</p>
</div>
<meta http-equiv="refresh" content="10">

<?php else:
    http_response_code(404);
    echo '<main><p style="text-align:center;color:var(--muted)">Page inconnue.</p></main>';
endif;
?>
</body>
</html>