<?php
require_once 'config.php';
requireLogin();
$user = currentUser();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();
    $id = new MongoDB\BSON\ObjectId($_SESSION['user_id']);

    // RACCOLTA DATI DAL FORM
    $bio       = trim($_POST['bio'] ?? '');
    $city      = trim($_POST['city'] ?? '');
    $height    = (int)($_POST['height'] ?? 0);
    $job       = trim($_POST['job'] ?? '');
    $profileImage = $user->profile_image ?? '';
    
    // INTERESSI - prendi direttamente dal POST
    $interests = $_POST['interests'] ?? [];
    if (!is_array($interests)) {
        $interests = [];
    }
    
    // TRAITS - prendi direttamente dal POST
    $traits = $_POST['traits'] ?? [];
    if (!is_array($traits)) {
        $traits = [];
    }
    
    // PREFERENZE GENERE - prendi direttamente dal POST
    $pref_gender = $_POST['pref_gender'] ?? [];
    if (!is_array($pref_gender)) {
        $pref_gender = [];
    }
    
    $pref_min_age    = (int)($_POST['pref_min_age'] ?? 18);
    $pref_max_age    = (int)($_POST['pref_max_age'] ?? 50);
    $pref_max_dist   = (int)($_POST['pref_max_dist'] ?? 50);

    // DEBUG: log per vedere cosa arriva dal form
    error_log("INTERESTS ricevuti: " . print_r($interests, true));
    error_log("TRAITS ricevuti: " . print_r($traits, true));
    error_log("PREF_GENDER ricevuti: " . print_r($pref_gender, true));

    // Upload immagine
    if (isset($_FILES['profile_image']) && ($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $uploadError = $_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadError !== UPLOAD_ERR_OK) {
            $error = "Errore durante il caricamento dell'immagine profilo.";
        } else {
            $tmpPath = $_FILES['profile_image']['tmp_name'] ?? '';
            $maxSize = 5 * 1024 * 1024;
            $fileSize = (int)($_FILES['profile_image']['size'] ?? 0);

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($tmpPath);
            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif',
            ];

            if ($fileSize <= 0 || $fileSize > $maxSize) {
                $error = "L'immagine deve essere inferiore a 5MB.";
            } elseif (!isset($allowedTypes[$mimeType])) {
                $error = 'Formato immagine non valido. Usa JPG, PNG, WEBP o GIF.';
            } else {
                $uploadDir = __DIR__ . '/uploads/profiles';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $fileName = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
                $targetPath = $uploadDir . '/' . $fileName;
                if (!move_uploaded_file($tmpPath, $targetPath)) {
                    $error = "Impossibile salvare l'immagine caricata.";
                } else {
                    $profileImage = 'uploads/profiles/' . $fileName;
                }
            }
        }
    }

    if (!$error) {
        // AGGIORNAMENTO DATABASE
        $updateResult = $db->users->updateOne(['_id' => $id], ['$set' => [
            'bio'           => $bio,
            'city'          => $city,
            'height'        => $height,
            'job'           => $job,
            'profile_image' => $profileImage,
            'interests'     => $interests,
            'traits'        => $traits,
            'preferences'   => [
                'gender'    => $pref_gender,
                'min_age'   => $pref_min_age,
                'max_age'   => $pref_max_age,
                'max_dist'  => $pref_max_dist,
            ],
            'profile_complete' => true,
            'updated_at'    => new MongoDB\BSON\UTCDateTime(),
        ]]);
        
        // Verifica se l'update ha funzionato
        if ($updateResult->getModifiedCount() > 0 || $updateResult->getUpsertedCount() > 0) {
            header('Location: discover.php');
            exit;
        } else {
            $error = "Nessuna modifica salvata. Riprova.";
        }
    }
}

$interestOptions = ['🎵 Musica','🎮 Gaming','🍕 Cucina','✈️ Viaggi','📚 Lettura','🎨 Arte','🏋️ Sport','🌿 Natura','🐶 Animali','🎬 Cinema','🍷 Vino','🧘 Yoga','💃 Danza','🎭 Teatro','🎸 Concerti','🏄 Surf'];
$traitOptions    = ['😄 Sorridente','🤓 Intellettuale','🦋 Avventuroso','💖 Romantico','😂 Divertente','🎯 Ambizioso','😌 Tranquillo','🔥 Passionale','🌸 Sensibile','🦁 Determinato','🌟 Ottimista','🎭 Creativo'];

// Recupera valori esistenti per pre-compilare i chip
$existingInterests = [];
$existingTraits = [];
$existingPrefGender = [];

if (!empty($user->interests)) {
    if (is_array($user->interests)) {
        $existingInterests = $user->interests;
    } elseif ($user->interests instanceof Traversable) {
        $existingInterests = iterator_to_array($user->interests, false);
    }
}

if (!empty($user->traits)) {
    if (is_array($user->traits)) {
        $existingTraits = $user->traits;
    } elseif ($user->traits instanceof Traversable) {
        $existingTraits = iterator_to_array($user->traits, false);
    }
}

$preferences = $user->preferences ?? [];
if (!empty($preferences->gender)) {
    if (is_array($preferences->gender)) {
        $existingPrefGender = $preferences->gender;
    } elseif ($preferences->gender instanceof Traversable) {
        $existingPrefGender = iterator_to_array($preferences->gender, false);
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MeetHub – Completa il tuo profilo</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="page-wrapper">
<div class="onboarding-container">
<div class="onboarding-card">

  <div style="text-align:center; margin-bottom:2rem">
    <div style="font-family:'Playfair Display',serif; font-size:1.8rem; font-weight:900; background:linear-gradient(135deg,var(--coral),var(--gold)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text">MeetHub</div>
    <h2 style="margin-top:0.5rem">Crea il tuo profilo ✨</h2>
    <p class="text-muted" style="font-size:0.9rem">Raccontaci di te per trovare persone compatibili</p>
  </div>

  <div class="step-progress" id="stepProgress">
    <div class="step-dot active"></div>
    <div class="step-dot"></div>
    <div class="step-dot"></div>
    <div class="step-dot"></div>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger mb-2"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" id="onboardingForm" enctype="multipart/form-data">

    <!-- Step 0: About you -->
    <div class="step" id="step0">
      <h3 class="mb-2">👤 Chi sei?</h3>
      <div class="form-group">
        <label>Immagine profilo</label>
        <input type="file" name="profile_image" accept=".jpg,.jpeg,.png,.webp,.gif,image/*">
        <small class="text-muted">Formato: JPG, PNG, WEBP o GIF (max 5MB)</small>
        <?php if (!empty($user->profile_image)): ?>
        <div class="onboarding-profile-preview">
          <img src="<?= htmlspecialchars($user->profile_image) ?>" alt="Immagine profilo attuale">
        </div>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label>Città</label>
        <input type="text" name="city" placeholder="Es. Milano, Roma..." value="<?= htmlspecialchars($user->city ?? '') ?>">
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem">
        <div class="form-group">
          <label>Altezza (cm)</label>
          <input type="number" name="height" placeholder="170" min="140" max="220" value="<?= htmlspecialchars($user->height ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Professione</label>
          <input type="text" name="job" placeholder="Es. Designer" value="<?= htmlspecialchars($user->job ?? '') ?>">
        </div>
      </div>
      <div class="form-group">
        <label>Descrizione (bio)</label>
        <textarea name="bio" placeholder="Raccontati in poche parole... cosa ti piace? Cosa cerchi?"><?= htmlspecialchars($user->bio ?? '') ?></textarea>
      </div>
      <button type="button" class="btn btn-primary btn-full mt-2" onclick="goStep(1)">Avanti →</button>
    </div>

    <!-- Step 1: Interests -->
    <div class="step hidden" id="step1">
      <h3 class="mb-1">🎯 I tuoi interessi</h3>
      <p class="text-muted mb-2" style="font-size:0.88rem">Seleziona almeno 3 passioni che ti rappresentano</p>
      <div class="chips-grid" id="interests-grid">
        <?php foreach ($interestOptions as $i): ?>
        <button type="button" class="chip <?= in_array($i, $existingInterests) ? 'selected' : '' ?>" data-value="<?= htmlspecialchars($i) ?>" onclick="toggleInterest(this)"><?= $i ?></button>
        <?php endforeach; ?>
      </div>
      <div id="interests-container"></div>
      <div style="display:flex; gap:1rem; margin-top:1.5rem">
        <button type="button" class="btn btn-ghost" onclick="goStep(0)">← Indietro</button>
        <button type="button" class="btn btn-primary" style="flex:1" onclick="goStep(2)">Avanti →</button>
      </div>
    </div>

    <!-- Step 2: Traits -->
    <div class="step hidden" id="step2">
      <h3 class="mb-1">✨ Come ti descriveresti?</h3>
      <p class="text-muted mb-2" style="font-size:0.88rem">Scegli le caratteristiche che ti descrivono meglio</p>
      <div class="chips-grid" id="traits-grid">
        <?php foreach ($traitOptions as $t): ?>
        <button type="button" class="chip <?= in_array($t, $existingTraits) ? 'selected' : '' ?>" data-value="<?= htmlspecialchars($t) ?>" onclick="toggleTrait(this)"><?= $t ?></button>
        <?php endforeach; ?>
      </div>
      <div id="traits-container"></div>
      <div style="display:flex; gap:1rem; margin-top:1.5rem">
        <button type="button" class="btn btn-ghost" onclick="goStep(1)">← Indietro</button>
        <button type="button" class="btn btn-primary" style="flex:1" onclick="goStep(3)">Avanti →</button>
      </div>
    </div>

    <!-- Step 3: Preferences -->
    <div class="step hidden" id="step3">
      <h3 class="mb-1">💕 Cosa cerchi?</h3>
      <p class="text-muted mb-2" style="font-size:0.88rem">Imposta le tue preferenze di ricerca</p>

      <div class="form-group">
        <label>Genere preferito</label>
        <div class="chips-grid" id="pref-gender-grid">
          <button type="button" class="chip <?= in_array('uomo', $existingPrefGender) ? 'selected' : '' ?>" data-value="uomo" onclick="togglePrefGender(this)">👨 Uomo</button>
          <button type="button" class="chip <?= in_array('donna', $existingPrefGender) ? 'selected' : '' ?>" data-value="donna" onclick="togglePrefGender(this)">👩 Donna</button>
          <button type="button" class="chip <?= in_array('non-binario', $existingPrefGender) ? 'selected' : '' ?>" data-value="non-binario" onclick="togglePrefGender(this)">🌈 Non-binario</button>
          <button type="button" class="chip <?= in_array('altro', $existingPrefGender) ? 'selected' : '' ?>" data-value="altro" onclick="togglePrefGender(this)">🎭 Altro</button>
        </div>
        <div id="pref-gender-container"></div>
      </div>

      <div class="form-group mt-2">
        <label>Fascia d'età: <span id="ageLabel"><?= $preferences->min_age ?? 18 ?> – <?= $preferences->max_age ?? 50 ?> anni</span></label>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem">
          <div>
            <small class="text-muted">Età minima</small>
            <input type="range" name="pref_min_age" id="pref_min_age" min="18" max="70" value="<?= $preferences->min_age ?? 18 ?>" oninput="updateAgeLabel()">
          </div>
          <div>
            <small class="text-muted">Età massima</small>
            <input type="range" name="pref_max_age" id="pref_max_age" min="18" max="70" value="<?= $preferences->max_age ?? 50 ?>" oninput="updateAgeLabel()">
          </div>
        </div>
      </div>

      <div class="form-group">
        <label>Distanza massima: <span id="distLabel"><?= $preferences->max_dist ?? 50 ?> km</span></label>
        <input type="range" name="pref_max_dist" id="pref_max_dist" min="5" max="200" value="<?= $preferences->max_dist ?? 50 ?>" oninput="document.getElementById('distLabel').textContent=this.value+' km'">
      </div>

      <div style="display:flex; gap:1rem; margin-top:1.5rem">
        <button type="button" class="btn btn-ghost" onclick="goStep(2)">← Indietro</button>
        <button type="submit" class="btn btn-primary" style="flex:1">🚀 Completa Profilo</button>
      </div>
    </div>

  </form>
</div>
</div>
</div>

<script>
let currentStep = 0;

function goStep(n) {
    document.getElementById('step' + currentStep).classList.add('hidden');
    document.querySelectorAll('.step-dot')[currentStep].classList.remove('active');
    document.querySelectorAll('.step-dot')[currentStep].classList.add('done');

    currentStep = n;
    document.getElementById('step' + n).classList.remove('hidden');
    document.querySelectorAll('.step-dot')[n].classList.add('active');
}

// Funzioni per INTERESSI
function toggleInterest(btn) {
    btn.classList.toggle('selected');
    updateInterestsInput();
}

function updateInterestsInput() {
    let container = document.getElementById('interests-container');
    container.innerHTML = '';
    let selected = document.querySelectorAll('#interests-grid .chip.selected');
    selected.forEach((chip, index) => {
        let input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'interests[]';
        input.value = chip.dataset.value;
        container.appendChild(input);
    });
}

// Funzioni per TRAITS
function toggleTrait(btn) {
    btn.classList.toggle('selected');
    updateTraitsInput();
}

function updateTraitsInput() {
    let container = document.getElementById('traits-container');
    container.innerHTML = '';
    let selected = document.querySelectorAll('#traits-grid .chip.selected');
    selected.forEach((chip, index) => {
        let input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'traits[]';
        input.value = chip.dataset.value;
        container.appendChild(input);
    });
}

// Funzioni per PREFERENZE GENERE
function togglePrefGender(btn) {
    btn.classList.toggle('selected');
    updatePrefGenderInput();
}

function updatePrefGenderInput() {
    let container = document.getElementById('pref-gender-container');
    container.innerHTML = '';
    let selected = document.querySelectorAll('#pref-gender-grid .chip.selected');
    selected.forEach((chip, index) => {
        let input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'pref_gender[]';
        input.value = chip.dataset.value;
        container.appendChild(input);
    });
}

function updateAgeLabel() {
    const min = document.getElementById('pref_min_age').value;
    const max = document.getElementById('pref_max_age').value;
    document.getElementById('ageLabel').textContent = min + ' – ' + max + ' anni';
}

// Inizializza gli input nascosti con i valori selezionati
document.addEventListener('DOMContentLoaded', function() {
    updateInterestsInput();
    updateTraitsInput();
    updatePrefGenderInput();
    
    // Focus sul primo input
    document.querySelector('input[name="city"]')?.focus();
});
</script>
</body>
</html>