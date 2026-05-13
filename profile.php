<?php
 require_once "config.php";
 requireLogin();

 $user    = currentUser();
 $error   = "";
 $success = "";

 if($_SERVER["REQUEST_METHOD"] === "POST")
 {
  $db  = getDB();
  $id  = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);

  $bio           = trim($_POST["bio"]    ?? "");
  $city          = trim($_POST["city"]   ?? "");
  $job           = trim($_POST["job"]    ?? "");
  $height_raw    = (int)($_POST["height"] ?? 0);
  $height        = ($height_raw >= 140 && $height_raw <= 220) ? $height_raw : null;
  $profile_image = (string)($user->profile_image ?? "");

  $interests = $_POST["interests"] ?? [];
  if(!is_array($interests)) $interests = [];

  $traits = $_POST["traits"] ?? [];
  if(!is_array($traits)) $traits = [];

  $pref_gender   = $_POST["pref_gender"] ?? [];
  if(!is_array($pref_gender)) $pref_gender = [];

  $pref_min_age  = (int)($_POST["pref_min_age"]  ?? 18);
  $pref_max_age  = (int)($_POST["pref_max_age"]  ?? 50);
  $pref_max_dist    = ($_POST["pref_max_dist_any"] ?? "0") === "1" ? 0 : (int)($_POST["pref_max_dist"] ?? 50);
  $location_enabled = isset($_POST["location_enabled"]);

  // Upload foto
  if(isset($_FILES["profile_image"]) && ($_FILES["profile_image"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)
  {
   $upload_error = $_FILES["profile_image"]["error"];

   if($upload_error !== UPLOAD_ERR_OK)
   {
    $error = "Errore durante il caricamento dell'immagine.";
   }
   else
   {
    $tmp_path  = $_FILES["profile_image"]["tmp_name"];
    $file_size = (int)($_FILES["profile_image"]["size"] ?? 0);
    $finfo     = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($tmp_path);
    $allowed   = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp", "image/gif" => "gif"];

    if($file_size <= 0 || $file_size > 5 * 1024 * 1024)
     $error = "L'immagine deve essere inferiore a 5MB.";
    elseif(!isset($allowed[$mime_type]))
     $error = "Formato non valido. Usa JPG, PNG, WEBP o GIF.";
    else
    {
     $upload_dir = __DIR__ . "/uploads/profiles";
     if(!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

     $file_name = bin2hex(random_bytes(16)) . "." . $allowed[$mime_type];
     if(!move_uploaded_file($tmp_path, $upload_dir . "/" . $file_name))
      $error = "Impossibile salvare l'immagine.";
     else
      $profile_image = "uploads/profiles/" . $file_name;
    }
   }
  }

  if(!$error)
  {
   try
   {
    $db->users->updateOne(["_id" => $id],
    [
     '$set' =>
     [
      "bio"           => $bio,
      "city"          => $city,
      "job"           => $job,
      "height"        => $height,
      "profile_image" => $profile_image,
      "interests"     => $interests,
      "traits"        => $traits,
      "preferences"      =>
      [
       "gender"   => $pref_gender,
       "min_age"  => $pref_min_age,
       "max_age"  => $pref_max_age,
       "max_dist" => $pref_max_dist
      ],
      "location_enabled" => $location_enabled,
      "profile_complete" => true,
      "updated_at"       => new MongoDB\BSON\UTCDateTime()
     ]
    ]);

    // Se la posizione è disabilitata, rimuovi le coordinate salvate
    if(!$location_enabled)
     $db->users->updateOne(["_id" => $id], ['$unset' => ["lat" => "", "lng" => ""]]);

    $success = "Profilo aggiornato con successo.";
    $user    = currentUser();// Ricarica dati aggiornati
   }
   catch(Throwable $e)
   {
    $error = "Errore: " . htmlspecialchars($e->getMessage());
   }
  }
 }

 $interest_options = ["Musica", "Gaming", "Cucina", "Viaggi", "Lettura", "Arte", "Sport", "Natura", "Animali", "Cinema", "Vino", "Yoga", "Danza", "Teatro", "Concerti", "Surf"];
 $trait_options    = ["Sorridente", "Intellettuale", "Avventuroso", "Romantico", "Divertente", "Ambizioso", "Tranquillo", "Passionale", "Sensibile", "Determinato", "Ottimista", "Creativo"];

 $existing_interests   = [];
 $existing_traits      = [];
 $existing_pref_gender = [];

 if(!empty($user->interests))
  $existing_interests = is_array($user->interests) ? $user->interests : iterator_to_array($user->interests, false);

 if(!empty($user->traits))
  $existing_traits = is_array($user->traits) ? $user->traits : iterator_to_array($user->traits, false);

 $preferences = $user->preferences ?? null;
 if(!empty($preferences->gender))
  $existing_pref_gender = is_array($preferences->gender) ? $preferences->gender : iterator_to_array($preferences->gender, false);

 $location_enabled = $user->location_enabled ?? true;
 $age = calcAge($user->birthdate ?? "");
?>
<!DOCTYPE html>
<html lang="it">
 <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MeetHub – Il mio profilo</title>
  <link rel="stylesheet" href="style.css?v=<?= filemtime('style.css') ?>">
 </head>
 <body>
  <?php require "header.php"; ?>
  <div class="page-wrapper">
   <div class="profile-edit-wrap">

    <a href="discover.php" class="profile-back-link">← Torna a Scopri</a>

    <form method="POST" enctype="multipart/form-data" novalidate class="profile-edit-card">

     <!-- Foto + info base -->
     <div class="profile-edit-hero">
      <label class="profile-photo-label" for="profile_image_input">
       <?php if(!empty($user->profile_image)){ ?>
        <img src="<?= htmlspecialchars($user->profile_image) ?>" class="profile-photo-large" alt="Foto profilo">
       <?php } else { ?>
        <div class="profile-photo-placeholder-large"><?= strtoupper(substr($user->name ?? "?", 0, 1)) ?></div>
       <?php } ?>
       <div class="profile-photo-overlay-btn">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        Cambia foto
       </div>
       <input type="file" id="profile_image_input" name="profile_image" accept=".jpg,.jpeg,.png,.webp,.gif,image/*" style="display:none" onchange="previewPhoto(this)">
      </label>
      <div class="profile-edit-identity">
       <h2><?= htmlspecialchars($user->name ?? "") ?><?= $age > 0 ? ", $age" : "" ?></h2>
       <p class="text-muted" style="font-size:0.9rem">Modifica il tuo profilo qui sotto</p>
      </div>
     </div>

     <?php if($error){ ?>   <div class="alert alert-danger"><?= $error ?></div>
     <?php } ?>
     <?php if($success){ ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
     <?php } ?>

     <!-- Bio -->
     <div class="profile-edit-section">
      <h4 class="profile-section-title">Su di me</h4>
      <div class="form-group">
       <textarea name="bio" placeholder="Raccontati in poche parole..." rows="3"><?= htmlspecialchars($user->bio ?? "") ?></textarea>
      </div>
     </div>

     <!-- Info base -->
     <div class="profile-edit-section">
      <h4 class="profile-section-title">Informazioni</h4>
      <div class="profile-edit-grid">
       <div class="form-group">
        <label>Città</label>
        <input type="text" name="city" placeholder="Es. Milano" value="<?= htmlspecialchars($user->city ?? "") ?>">
       </div>
       <div class="form-group">
        <label>Professione</label>
        <input type="text" name="job" placeholder="Es. Designer" value="<?= htmlspecialchars($user->job ?? "") ?>">
       </div>
       <div class="form-group">
        <label>Altezza (cm)</label>
        <input type="number" name="height" placeholder="170"
         value="<?= isset($user->height) && (int)$user->height >= 140 && (int)$user->height <= 220 ? (int)$user->height : "" ?>">
       </div>
      </div>
     </div>

     <!-- Interessi -->
     <div class="profile-edit-section">
      <h4 class="profile-section-title">Interessi</h4>
      <p class="text-muted mb-2" style="font-size:0.85rem">Seleziona almeno 3 passioni</p>
      <div class="chips-grid" id="interests-grid">
       <?php foreach($interest_options as $i){ ?>
        <button type="button" class="chip <?= in_array($i, $existing_interests) ? "selected" : "" ?>" data-value="<?= htmlspecialchars($i) ?>" onclick="toggleChip(this,'interests')"><?= $i ?></button>
       <?php } ?>
      </div>
      <div id="interests-container"></div>
     </div>

     <!-- Qualità -->
     <div class="profile-edit-section">
      <h4 class="profile-section-title">Come ti descriveresti?</h4>
      <div class="chips-grid" id="traits-grid">
       <?php foreach($trait_options as $t){ ?>
        <button type="button" class="chip <?= in_array($t, $existing_traits) ? "selected" : "" ?>" data-value="<?= htmlspecialchars($t) ?>" onclick="toggleChip(this,'traits')"><?= $t ?></button>
       <?php } ?>
      </div>
      <div id="traits-container"></div>
     </div>

     <!-- Preferenze -->
     <div class="profile-edit-section">
      <h4 class="profile-section-title">Cosa cerchi?</h4>

      <!-- Genere -->
      <div class="pref-block">
       <div class="pref-block-label">Genere preferito</div>
       <div class="chips-grid" id="pref-gender-grid">
        <button type="button" class="chip <?= in_array("uomo",        $existing_pref_gender) ? "selected" : "" ?>" data-value="uomo"        onclick="toggleChip(this,'pref_gender')">Uomo</button>
        <button type="button" class="chip <?= in_array("donna",       $existing_pref_gender) ? "selected" : "" ?>" data-value="donna"       onclick="toggleChip(this,'pref_gender')">Donna</button>
        <button type="button" class="chip <?= in_array("non-binario", $existing_pref_gender) ? "selected" : "" ?>" data-value="non-binario" onclick="toggleChip(this,'pref_gender')">Non-binario</button>
        <button type="button" class="chip <?= in_array("altro",       $existing_pref_gender) ? "selected" : "" ?>" data-value="altro"       onclick="toggleChip(this,'pref_gender')">Altro</button>
       </div>
       <div id="pref-gender-container"></div>
      </div>

      <!-- Fascia d'età -->
      <div class="pref-block">
       <div class="pref-block-label">Fascia d'età: <span id="ageLabel"><?= $preferences->min_age ?? 18 ?> – <?= $preferences->max_age ?? 50 ?> anni</span></div>
       <div class="profile-edit-grid">
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

      <!-- Distanza + Posizione -->
      <?php $cur_dist = (int)($preferences->max_dist ?? 50); ?>
      <?php $dist_locked = !$location_enabled || $cur_dist === 0; ?>
      <div class="pref-block">
       <div class="pref-block-label">Distanza massima: <span id="distLabel"><?= (!$location_enabled || $cur_dist === 0) ? "Qualsiasi" : $cur_dist . " km" ?></span></div>

       <input type="range" name="pref_max_dist" id="pref_max_dist"
              min="5" max="200" value="<?= $cur_dist === 0 ? 50 : $cur_dist ?>"
              <?= $dist_locked ? 'disabled style="opacity:.35"' : '' ?>
              oninput="document.getElementById('distLabel').textContent=this.value+' km'">

       <label class="pref-checkbox-row">
        <input type="checkbox" id="dist-any" <?= $cur_dist === 0 ? "checked" : "" ?>
               onchange="toggleDistAny(this)" <?= !$location_enabled ? "disabled" : "" ?>>
        Qualsiasi distanza (nessun limite)
       </label>
       <input type="hidden" name="pref_max_dist_any" id="pref_max_dist_any" value="<?= $cur_dist === 0 ? '1' : '0' ?>">

       <!-- Toggle posizione -->
       <div class="pref-row">
        <div>
         <div class="pref-row-title">Usa la mia posizione GPS</div>
         <div class="pref-row-desc">Richiede il permesso del dispositivo. Se disattivato, il filtro distanza viene ignorato.</div>
        </div>
        <label class="toggle-switch">
         <input type="checkbox" name="location_enabled" id="location-enabled"
                <?= $location_enabled ? "checked" : "" ?>
                onchange="toggleLocation(this)">
         <span class="toggle-track"></span>
        </label>
       </div>
      </div>
     </div>

     <div class="profile-edit-footer">
      <button type="submit" class="btn btn-primary btn-lg" style="min-width:200px">Salva modifiche</button>
     </div>

    </form>
   </div>
  </div>

  <script>
   var chip_inputs = { interests: [], traits: [], pref_gender: [] };

   function toggleChip(btn, group)
   {
    btn.classList.toggle("selected");
    syncChips(group);
   }

   function syncChips(group)
   {
    var grid_id  = group === "pref_gender" ? "pref-gender-grid" : group + "-grid";
    var cont_id  = group === "pref_gender" ? "pref-gender-container" : group + "-container";
    var name     = group === "pref_gender" ? "pref_gender[]" : group + "[]";
    var selected = document.querySelectorAll("#" + grid_id + " .chip.selected");
    var container = document.getElementById(cont_id);
    container.innerHTML = "";
    selected.forEach(function(chip)
    {
     var inp   = document.createElement("input");
     inp.type  = "hidden";
     inp.name  = name;
     inp.value = chip.dataset.value;
     container.appendChild(inp);
    });
   }

   function updateAgeLabel()
   {
    document.getElementById("ageLabel").textContent =
     document.getElementById("pref_min_age").value + " – " +
     document.getElementById("pref_max_age").value + " anni";
   }

   function toggleDistAny(cb)
   {
    var slider  = document.getElementById("pref_max_dist");
    var label   = document.getElementById("distLabel");
    var anyFlag = document.getElementById("pref_max_dist_any");
    if(cb.checked)
    {
     slider.disabled      = true;
     slider.style.opacity = "0.35";
     label.textContent    = "Qualsiasi";
     anyFlag.value        = "1";
    }
    else
    {
     slider.disabled      = false;
     slider.style.opacity = "";
     label.textContent    = slider.value + " km";
     anyFlag.value        = "0";
    }
   }

   function toggleLocation(cb)
   {
    var slider  = document.getElementById("pref_max_dist");
    var anyCb   = document.getElementById("dist-any");
    var anyFlag = document.getElementById("pref_max_dist_any");
    var label   = document.getElementById("distLabel");

    if(!cb.checked)
    {
     // Posizione disabilitata: blocca tutto e forza "qualsiasi"
     slider.disabled      = true;
     slider.style.opacity = "0.35";
     anyCb.disabled       = true;
     label.textContent    = "Qualsiasi";
    }
    else
    {
     // Posizione abilitata: riabilita in base alla checkbox "qualsiasi"
     anyCb.disabled = false;
     if(!anyCb.checked)
     {
      slider.disabled      = false;
      slider.style.opacity = "";
      label.textContent    = slider.value + " km";
     }
     else
     {
      label.textContent = "Qualsiasi";
     }
    }
   }

   function previewPhoto(input)
   {
    if(!input.files || !input.files[0]) return;
    var reader = new FileReader();
    reader.onload = function(e)
    {
     var label = input.closest(".profile-photo-label");
     var img   = label.querySelector("img");
     var ph    = label.querySelector(".profile-photo-placeholder-large");
     if(img) { img.src = e.target.result; }
     else if(ph)
     {
      var newImg = document.createElement("img");
      newImg.src       = e.target.result;
      newImg.className = "profile-photo-large";
      newImg.alt       = "Foto profilo";
      ph.replaceWith(newImg);
     }
    };
    reader.readAsDataURL(input.files[0]);
   }

   document.addEventListener("DOMContentLoaded", function()
   {
    syncChips("interests");
    syncChips("traits");
    syncChips("pref_gender");
   });
  </script>
 </body>
</html>
