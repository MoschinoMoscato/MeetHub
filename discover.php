<?php
require_once 'config.php';
requireLogin();
$user = currentUser();
$db = getDB();

// ===== GESTIONE RICHIESTE AJAX =====
// Gestione richiesta JSON per dettagli utente (modal)
if (isset($_GET['get_user_details']) && $_GET['get_user_details'] == 1 && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $db = getDB();
        $targetId = new MongoDB\BSON\ObjectId($_GET['id']);
        $targetUser = $db->users->findOne(['_id' => $targetId]);
        
        if (!$targetUser) {
            echo json_encode(['success' => false, 'error' => 'Utente non trovato']);
            exit;
        }
        
        // Estrai interessi in modo sicuro
        $interests = [];
        if (!empty($targetUser->interests)) {
            if (is_array($targetUser->interests)) {
                $interests = $targetUser->interests;
            } elseif ($targetUser->interests instanceof Traversable) {
                $interests = iterator_to_array($targetUser->interests, false);
            }
        }
        
        $traits = [];
        if (!empty($targetUser->traits)) {
            if (is_array($targetUser->traits)) {
                $traits = $targetUser->traits;
            } elseif ($targetUser->traits instanceof Traversable) {
                $traits = iterator_to_array($targetUser->traits, false);
            }
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'id' => (string)$targetUser->_id,
                'name' => $targetUser->name,
                'age' => calcAge($targetUser->birthdate ?? null),
                'city' => $targetUser->city ?? '',
                'job' => $targetUser->job ?? '',
                'height' => $targetUser->height ?? null,
                'bio' => $targetUser->bio ?? '',
                'profile_image' => $targetUser->profile_image ?? null,
                'interests' => $interests,
                'traits' => $traits
            ]
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Gestione richiesta AJAX per like/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $targetUserId = $_POST['target_user_id'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!$targetUserId || !in_array($action, ['like', 'reject'], true)) {
        echo json_encode(['success' => false, 'error' => 'Azione non valida.']);
        exit;
    }
    
    try {
        $db = getDB();
        $fromId = new MongoDB\BSON\ObjectId($_SESSION['user_id']);
        $toId = new MongoDB\BSON\ObjectId($targetUserId);

        if ((string)$fromId === (string)$toId) {
            echo json_encode(['success' => false, 'error' => 'Non puoi valutare il tuo profilo.']);
            exit;
        }
        
        // Controlla se esiste già un'interazione
        $existingInteraction = $db->interactions->findOne([
            'from_user_id' => $fromId,
            'to_user_id' => $toId
        ]);
        
        if ($existingInteraction) {
            // Aggiorna l'interazione esistente
            $db->interactions->updateOne(
                ['_id' => $existingInteraction->_id],
                ['$set' => [
                    'action' => $action,
                    'updated_at' => new MongoDB\BSON\UTCDateTime(),
                ]]
            );
        } else {
            // Crea nuova interazione
            $db->interactions->insertOne([
                'from_user_id' => $fromId,
                'to_user_id' => $toId,
                'action' => $action,
                'created_at' => new MongoDB\BSON\UTCDateTime(),
                'updated_at' => new MongoDB\BSON\UTCDateTime(),
            ]);
        }
        
        $match = false;
        
        if ($action === 'like') {
            // Verifica like reciproco
            $reciprocalLike = $db->interactions->findOne([
                'from_user_id' => $toId,
                'to_user_id' => $fromId,
                'action' => 'like',
            ]);
            
            if ($reciprocalLike) {
                // Verifica se il match esiste già
                $existingMatch = $db->matches->findOne([
                    'users' => ['$all' => [$fromId, $toId]]
                ]);
                
                if (!$existingMatch) {
                    $db->matches->insertOne([
                        'users' => [$fromId, $toId],
                        'created_at' => new MongoDB\BSON\UTCDateTime(),
                    ]);
                }
                $match = true;
            }
        }
        
        echo json_encode([
            'success' => true,
            'action' => $action,
            'match' => $match,
            'message' => $match ? 'Match!' : ($action === 'like' ? 'Like inviato' : 'Profilo rifiutato')
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Errore: ' . $e->getMessage()]);
        exit;
    }
}
// ===== FINE GESTIONE RICHIESTE AJAX =====

$success = '';
$error = '';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetUserId = $_POST['target_user_id'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!$targetUserId || !in_array($action, ['like', 'reject'], true)) {
        $error = 'Azione non valida.';
    } else {
        try {
            $fromId = new MongoDB\BSON\ObjectId($_SESSION['user_id']);
            $toId = new MongoDB\BSON\ObjectId($targetUserId);

            if ((string)$fromId === (string)$toId) {
                $error = 'Non puoi valutare il tuo profilo.';
            } else {
                $db->interactions->updateOne(
                    ['from_user_id' => $fromId, 'to_user_id' => $toId],
                    ['$set' => [
                        'action' => $action,
                        'updated_at' => new MongoDB\BSON\UTCDateTime(),
                    ], '$setOnInsert' => [
                        'created_at' => new MongoDB\BSON\UTCDateTime(),
                    ]],
                    ['upsert' => true]
                );

                if ($action === 'like') {
                    $reciprocalLike = $db->interactions->findOne([
                        'from_user_id' => $toId,
                        'to_user_id' => $fromId,
                        'action' => 'like',
                    ]);

                    if ($reciprocalLike) {
                        $db->matches->updateOne(
                            ['users' => ['$all' => [$fromId, $toId]]],
                            ['$setOnInsert' => [
                                'users' => [$fromId, $toId],
                                'created_at' => new MongoDB\BSON\UTCDateTime(),
                            ]],
                            ['upsert' => true]
                        );
                        $success = 'Match! Anche questa persona ha messo like al tuo profilo.';
                    } else {
                        $success = 'Richiesta inviata con successo.';
                    }
                } else {
                    $success = 'Profilo rifiutato.';
                }
            }
        } catch (Exception $e) {
            $error = 'Impossibile salvare la scelta.';
        }
    }
}

$currentUserId = new MongoDB\BSON\ObjectId($_SESSION['user_id']);
$alreadySeenIds = [];
$seenInteractions = $db->interactions->find(['from_user_id' => $currentUserId]);
foreach ($seenInteractions as $interaction) {
    $alreadySeenIds[] = $interaction->to_user_id;
}

$preferences = $user->preferences ?? [];
$preferredGenders = [];
if (isset($preferences->gender)) {
    if (is_array($preferences->gender)) {
        $preferredGenders = $preferences->gender;
    } elseif ($preferences->gender instanceof Traversable) {
        $preferredGenders = iterator_to_array($preferences->gender, false);
    }
}
$prefMinAge = isset($preferences->min_age) ? (int)$preferences->min_age : 18;
$prefMaxAge = isset($preferences->max_age) ? (int)$preferences->max_age : 99;
$prefMaxDist = isset($preferences->max_dist) ? (int)$preferences->max_dist : 0;
$interestOptions = ['🎵 Musica','🎮 Gaming','🍕 Cucina','✈️ Viaggi','📚 Lettura','🎨 Arte','🏋️ Sport','🌿 Natura','🐶 Animali','🎬 Cinema','🍷 Vino','🧘 Yoga','💃 Danza','🎭 Teatro','🎸 Concerti','🏄 Surf'];
$selectedInterestFilters = $_GET['interests'] ?? [];
if (!is_array($selectedInterestFilters)) {
    $selectedInterestFilters = [];
}
$selectedInterestFilters = array_values(array_filter(array_map('trim', $selectedInterestFilters), function ($interest) use ($interestOptions) {
    return in_array($interest, $interestOptions, true);
}));

$today = new DateTime();
$maxBirthdateObj = (clone $today)->modify('-' . $prefMinAge . ' years');
$minBirthdateObj = (clone $today)->modify('-' . $prefMaxAge . ' years');
$maxBirthdate = $maxBirthdateObj->format('Y-m-d');
$minBirthdate = $minBirthdateObj->format('Y-m-d');

$query = [
    '_id' => ['$ne' => $currentUserId],
    'profile_complete' => true,
    'birthdate' => ['$gte' => $minBirthdate, '$lte' => $maxBirthdate],
];

if (!empty($alreadySeenIds)) {
    $query['_id']['$nin'] = $alreadySeenIds;
}

if (!empty($preferredGenders)) {
    $query['gender'] = ['$in' => $preferredGenders];
}

if (!empty($selectedInterestFilters)) {
    $query['interests'] = ['$in' => $selectedInterestFilters];
}

$profilesCursor = $db->users->find($query, ['limit' => 12]);
$profiles = iterator_to_array($profilesCursor, false);
$showingIncompleteProfiles = false;

if (count($profiles) === 0) {
    $fallbackQuery = [
        '_id' => ['$ne' => $currentUserId],
        'birthdate' => ['$gte' => $minBirthdate, '$lte' => $maxBirthdate],
    ];

    if (!empty($alreadySeenIds)) {
        $fallbackQuery['_id']['$nin'] = $alreadySeenIds;
    }

    if (!empty($preferredGenders)) {
        $fallbackQuery['gender'] = ['$in' => $preferredGenders];
    }

    if (!empty($selectedInterestFilters)) {
        $fallbackQuery['interests'] = ['$in' => $selectedInterestFilters];
    }

    $fallbackCursor = $db->users->find($fallbackQuery, ['limit' => 12]);
    $profiles = iterator_to_array($fallbackCursor, false);
    $showingIncompleteProfiles = count($profiles) > 0;
}

if (
    $prefMaxDist > 0 &&
    isset($user->lat, $user->lng) &&
    is_numeric($user->lat) &&
    is_numeric($user->lng)
) {
    $userLat = (float)$user->lat;
    $userLng = (float)$user->lng;
    $profiles = array_values(array_filter($profiles, function ($profile) use ($userLat, $userLng, $prefMaxDist) {
        if (!isset($profile->lat, $profile->lng) || !is_numeric($profile->lat) || !is_numeric($profile->lng)) {
            return false;
        }
        $distance = haversineDistance($userLat, $userLng, (float)$profile->lat, (float)$profile->lng);
        return $distance <= $prefMaxDist;
    }));
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MeetHub – Discover</title>
<link rel="stylesheet" href="style.css">
<style>
/* Stili per il modal dei dettagli profilo */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.85);
    backdrop-filter: blur(8px);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateX(-50%) translateY(-50px);
    }
    to {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }
}

.profile-detail {
    text-align: center;
}

.profile-detail-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    margin: 0 auto 1rem;
    overflow: hidden;
    background: linear-gradient(135deg, var(--coral), var(--gold));
}

.profile-detail-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-detail-avatar div {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    font-size: 3rem;
}

.profile-detail-name {
    font-family: 'Playfair Display', serif;
    font-size: 1.8rem;
    margin-bottom: 0.25rem;
}

.profile-detail-meta {
    color: var(--text-muted);
    margin-bottom: 1rem;
    font-size: 0.9rem;
}

.profile-detail-bio {
    background: var(--card2);
    padding: 1rem;
    border-radius: 16px;
    margin: 1rem 0;
    text-align: left;
}

.profile-detail-section {
    margin: 1rem 0;
    text-align: left;
}

.profile-detail-section h4 {
    color: var(--coral);
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.tags-container {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.tag {
    background: rgba(255,75,110,0.15);
    padding: 0.3rem 0.8rem;
    border-radius: 50px;
    font-size: 0.8rem;
    color: var(--coral-light);
}

.match-notification {
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: linear-gradient(135deg, var(--coral), var(--gold));
    color: white;
    padding: 15px 30px;
    border-radius: 50px;
    font-weight: bold;
    z-index: 1001;
    animation: slideDown 0.3s ease;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    cursor: pointer;
}

.match-notification a {
    color: white;
    margin-left: 10px;
    text-decoration: underline;
}

.profile-card-image {
    cursor: pointer;
}
</style>
</head>
<body>
<div class="page-wrapper">
  <div class="discover-layout" style="grid-template-columns:1fr; max-width:1100px">
    <div class="card">
      <div class="flex justify-between items-center" style="flex-wrap:wrap; gap:1rem">
        <div>
          <h2>Scopri persone</h2>
          <p class="text-muted mt-1">Ciao <?= htmlspecialchars($user->name ?? 'utente') ?>, scegli i profili con ❤️ o rifiuta con ❌. Clicca sulla foto per vedere i dettagli.</p>
        </div>
        <div class="flex gap-1" style="flex-wrap:wrap">
          <a class="btn btn-primary" href="chat.php">Apri Chat Match</a>
          <a class="btn btn-ghost" href="onboarding.php">Modifica profilo</a>
          <a class="btn btn-outline" href="discover.php?logout=1">Esci</a>
        </div>
      </div>

      <?php if ($error): ?>
      <div class="alert alert-danger mt-2"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
      <div class="alert alert-success mt-2"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <?php if ($showingIncompleteProfiles): ?>
      <div class="alert mt-2" style="background:rgba(245,166,35,0.12); border:1px solid rgba(245,166,35,0.35); color:#ffd57a;">
        Ti sto mostrando anche profili non ancora completi, ma sempre in linea con le tue preferenze.
      </div>
      <?php endif; ?>

      <form method="GET" class="mt-2">
        <input type="hidden" name="filter" value="1">
        <label>Filtra per interessi</label>
        <div class="chips-grid">
          <?php foreach ($interestOptions as $interest): ?>
          <label class="chip <?= in_array($interest, $selectedInterestFilters, true) ? 'selected' : '' ?>" style="display:inline-flex; align-items:center; gap:0.5rem;">
            <input type="checkbox" name="interests[]" value="<?= htmlspecialchars($interest) ?>" <?= in_array($interest, $selectedInterestFilters, true) ? 'checked' : '' ?> style="width:auto;">
            <span><?= htmlspecialchars($interest) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <div class="flex gap-1 mt-2">
          <button type="submit" class="btn btn-primary btn-sm">Applica filtri</button>
          <a href="discover.php" class="btn btn-ghost btn-sm">Reset</a>
        </div>
      </form>
    </div>

    <div class="profiles-grid">
      <?php foreach ($profiles as $profile): ?>
      <div class="profile-card">
        <div class="profile-card-image" onclick="showProfileDetails('<?= (string)$profile->_id ?>')">
          <?php if (!empty($profile->profile_image)): ?>
          <img src="<?= htmlspecialchars($profile->profile_image) ?>" alt="Foto profilo di <?= htmlspecialchars($profile->name ?? 'Utente') ?>">
          <?php else: ?>
          <div class="avatar-emoji">👤</div>
          <?php endif; ?>
          <div class="profile-gradient-overlay"></div>
          <div class="profile-card-info">
            <div class="profile-name">
              <?= htmlspecialchars($profile->name ?? 'Utente') ?><?= calcAge($profile->birthdate ?? null) > 0 ? ', ' . calcAge($profile->birthdate ?? null) : '' ?>
            </div>
            <div class="profile-meta">
              <?= htmlspecialchars($profile->city ?? 'Citta non indicata') ?><?= !empty($profile->job) ? ' • ' . htmlspecialchars($profile->job) : '' ?>
            </div>
            <?php
              // Estrai interessi in modo sicuro per la visualizzazione
              $interests = [];
              if (!empty($profile->interests)) {
                  if (is_array($profile->interests)) {
                      $interests = $profile->interests;
                  } elseif ($profile->interests instanceof Traversable) {
                      $interests = iterator_to_array($profile->interests, false);
                  }
              }
            ?>
            <?php if (!empty($interests)): ?>
            <div class="profile-tags">
              <?php foreach (array_slice($interests, 0, 3) as $interest): ?>
              <span class="tag"><?= htmlspecialchars($interest) ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card-actions">
          <form method="POST" class="action-form" data-user-id="<?= (string)$profile->_id ?>" data-action="reject">
            <input type="hidden" name="target_user_id" value="<?= (string)$profile->_id ?>">
            <input type="hidden" name="action" value="reject">
            <button type="submit" class="action-btn btn-pass" title="Rifiuta">❌</button>
          </form>

          <button type="button" class="action-btn" style="background: var(--card2); border: 1px solid var(--border);" 
                  onclick="showProfileDetails('<?= (string)$profile->_id ?>')" 
                  title="Vedi dettagli profilo">
            👁️
          </button>

          <form method="POST" class="action-form" data-user-id="<?= (string)$profile->_id ?>" data-action="like">
            <input type="hidden" name="target_user_id" value="<?= (string)$profile->_id ?>">
            <input type="hidden" name="action" value="like">
            <button type="submit" class="action-btn btn-like" title="Richiedi match">❤️</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if (count($profiles) === 0): ?>
    <div class="card empty-state">
      <div class="emoji">🫶</div>
      <h3>Nessun nuovo profilo disponibile</h3>
      <p class="text-muted mt-1">Hai gia valutato tutti i profili disponibili. Torna piu tardi.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal per vedere dettagli profilo prima di interagire -->
<div id="profile-modal" class="modal-overlay" style="display: none;">
    <div class="modal-card" style="max-width: 500px; max-height: 80vh; overflow-y: auto;">
        <div style="text-align: right;">
            <button onclick="closeModal()" style="background: none; border: none; color: var(--text-muted); font-size: 1.5rem; cursor: pointer; padding: 0.5rem;">&times;</button>
        </div>
        <div id="modal-content">
            <div style="text-align:center; padding:2rem;">⏳ Caricamento profilo...</div>
        </div>
        <div class="flex gap-1" style="margin-top: 1.5rem; justify-content: center;">
            <button id="modal-reject-btn" class="btn btn-pass" style="font-size: 1.2rem; padding: 0.75rem 1.5rem;">❌ Rifiuta</button>
            <button id="modal-like-btn" class="btn btn-like" style="font-size: 1.2rem; padding: 0.75rem 1.5rem;">❤️ Like</button>
        </div>
    </div>
</div>

<script>
// Variabile per tenere traccia dell'utente corrente nel modal
let currentModalUserId = null;

// Mostra notifica di match
function showMatchNotification(userId) {
    const notification = document.createElement('div');
    notification.className = 'match-notification';
    notification.innerHTML = '🎉 È un MATCH! 🎉 <a href="chat.php">Vai alla chat →</a>';
    notification.onclick = () => {
        window.location.href = 'chat.php';
    };
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.opacity = '0';
        setTimeout(() => notification.remove(), 300);
    }, 5000);
}

// Esegue azione (like/reject) via AJAX 
async function performAction(userId, action, closeModalAfter = true) {
    try {
        const formData = new FormData();
        formData.append('target_user_id', userId);
        formData.append('action', action);
        
        const response = await fetch('discover.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (result.match) {
                showMatchNotification(userId);
            }
            if (closeModalAfter) {
                closeModal();
            }
            location.reload();
        } else {
            alert(result.error || 'Errore durante l\'operazione');
        }
    } catch (error) {
        console.error('Errore:', error);
        alert('Errore di connessione');
    }
}

// Apre il modal con i dettagli del profilo
async function showProfileDetails(userId) {
    const modal = document.getElementById('profile-modal');
    const modalContent = document.getElementById('modal-content');
    currentModalUserId = userId;
    
    modalContent.innerHTML = '<div style="text-align:center; padding:2rem;">⏳ Caricamento profilo...</div>';
    modal.style.display = 'flex';
    
    try {
        const response = await fetch(`discover.php?get_user_details=1&id=${userId}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const result = await response.json();
        
        if (result.success && result.data) {
            const user = result.data;
            
            modalContent.innerHTML = `
                <div class="profile-detail">
                    <div class="profile-detail-avatar">
                        ${user.profile_image ? 
                            `<img src="${escapeHtml(user.profile_image)}" alt="${escapeHtml(user.name)}">` : 
                            '<div>👤</div>'
                        }
                    </div>
                    <div class="profile-detail-name">${escapeHtml(user.name)}${user.age ? `, ${user.age}` : ''}</div>
                    <div class="profile-detail-meta">
                        ${user.city ? `📍 ${escapeHtml(user.city)}` : ''}
                        ${user.job ? ` • 💼 ${escapeHtml(user.job)}` : ''}
                        ${user.height ? ` • 📏 ${user.height} cm` : ''}
                    </div>
                    
                    ${user.bio ? `
                    <div class="profile-detail-bio">
                        <strong>📝 Chi sono</strong><br>
                        ${escapeHtml(user.bio).replace(/\n/g, '<br>')}
                    </div>
                    ` : ''}
                    
                    ${user.interests && user.interests.length > 0 ? `
                    <div class="profile-detail-section">
                        <h4>🎯 Interessi</h4>
                        <div class="tags-container">
                            ${user.interests.map(i => `<span class="tag">${escapeHtml(i)}</span>`).join('')}
                        </div>
                    </div>
                    ` : ''}
                    
                    ${user.traits && user.traits.length > 0 ? `
                    <div class="profile-detail-section">
                        <h4>✨ Qualità</h4>
                        <div class="tags-container">
                            ${user.traits.map(t => `<span class="tag">${escapeHtml(t)}</span>`).join('')}
                        </div>
                    </div>
                    ` : ''}
                </div>
            `;
            
            const likeBtn = document.getElementById('modal-like-btn');
            const rejectBtn = document.getElementById('modal-reject-btn');
            
            const newLikeBtn = likeBtn.cloneNode(true);
            const newRejectBtn = rejectBtn.cloneNode(true);
            likeBtn.parentNode.replaceChild(newLikeBtn, likeBtn);
            rejectBtn.parentNode.replaceChild(newRejectBtn, rejectBtn);
            
            newLikeBtn.onclick = () => performAction(userId, 'like', true);
            newRejectBtn.onclick = () => performAction(userId, 'reject', true);
            
        } else {
            modalContent.innerHTML = '<div style="text-align:center; padding:2rem; color:var(--danger);">❌ ' + (result.error || 'Errore nel caricamento del profilo') + '</div>';
        }
    } catch (error) {
        console.error('Errore:', error);
        modalContent.innerHTML = `
            <div style="text-align:center; padding:2rem; color:var(--danger);">
                ❌ Errore di connessione<br>
                <small style="font-size:0.8rem;">Ricarica la pagina e riprova</small>
            </div>
        `;
    }
}

function closeModal() {
    document.getElementById('profile-modal').style.display = 'none';
    currentModalUserId = null;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.querySelectorAll('.action-form').forEach(form => {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const userId = form.dataset.userId;
        const action = form.dataset.action;
        await performAction(userId, action, false);
    });
});

document.getElementById('profile-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('profile-modal').style.display === 'flex') {
        closeModal();
    }
});
</script>

</body>
</html>