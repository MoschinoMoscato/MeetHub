<?php
 require_once "config.php";
 requireLogin();

 $user  = currentUser();
 $error = "";

 // L'onboarding è solo per il primo accesso — chi ha già il profilo va su profile.php
 if(!empty($user->profile_complete) && $user->profile_complete === true)
 {
  header("Location: /profile");
  exit;
 }

 if($_SERVER["REQUEST_METHOD"] === "POST")
 {
  $db = getDB();
  $id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);

  // Recupero i dati dal form
  $bio          = trim($_POST["bio"] ?? "");
  $city         = trim($_POST["city"] ?? "");
  $height       = (int)($_POST["height"] ?? 0);
  $job          = trim($_POST["job"] ?? "");
  $profile_image_id = $user->profile_image_id ?? null;

  // Interessi inviati dal form
  $interests = $_POST["interests"] ?? [];
  if(!is_array($interests))
  {
   $interests = [];
  }

  // Tratti inviati dal form
  $traits = $_POST["traits"] ?? [];
  if(!is_array($traits))
  {
   $traits = [];
  }

  // Preferenze di genere inviate dal form
  $pref_gender = $_POST["pref_gender"] ?? [];
  if(!is_array($pref_gender))
  {
   $pref_gender = [];
  }

  $pref_min_age  = (int)($_POST["pref_min_age"] ?? 18);
  $pref_max_age  = (int)($_POST["pref_max_age"] ?? 50);
  $pref_max_dist = (int)($_POST["pref_max_dist"] ?? 50);

  // Gestione upload immagine profilo
  if(isset($_FILES["profile_image"]) && ($_FILES["profile_image"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)
  {
   $upload_error = $_FILES["profile_image"]["error"] ?? UPLOAD_ERR_NO_FILE;

   if($upload_error !== UPLOAD_ERR_OK)
   {
    $error = "Errore durante il caricamento dell'immagine profilo.";
   }
   else
   {
    $tmp_path  = $_FILES["profile_image"]["tmp_name"] ?? "";
    $max_size  = 5 * 1024 * 1024;// 5MB
    $file_size = (int)($_FILES["profile_image"]["size"] ?? 0);

    $finfo     = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($tmp_path);
    $allowed_types =
    [
     "image/jpeg" => "jpg",
     "image/png"  => "png",
     "image/webp" => "webp",
     "image/gif"  => "gif"
    ];

    if($file_size <= 0 || $file_size > $max_size)
    {
     $error = "L'immagine deve essere inferiore a 5MB.";
    }
    elseif(!isset($allowed_types[$mime_type]))
    {
     $error = "Formato immagine non valido. Usa JPG, PNG, WEBP o GIF.";
    }
    else
    {
     $image_data    = file_get_contents($tmp_path);
     $upload_result = $db->uploads->insertOne(
     [
      "owner_user_id" => $id,
      "kind"          => "profile",
      "mime_type"     => $mime_type,
      "size"          => $file_size,
      "data"          => new MongoDB\BSON\Binary($image_data, MongoDB\BSON\Binary::TYPE_GENERIC),
      "created_at"    => new MongoDB\BSON\UTCDateTime()
     ]);
     $profile_image_id = $upload_result->getInsertedId();
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
      "bio"              => $bio,
      "city"             => $city,
      "height"           => $height > 0 ? $height : null,
      "job"              => $job,
      "profile_image_id" => $profile_image_id,
      "interests"        => $interests,
      "traits"           => $traits,
      "preferences"      =>
      [
       "gender"   => $pref_gender,
       "min_age"  => $pref_min_age,
       "max_age"  => $pref_max_age,
       "max_dist" => $pref_max_dist
      ],
      "profile_complete" => true,
      "updated_at"       => new MongoDB\BSON\UTCDateTime()
     ]
    ]);

    header("Location: /discover");
    exit;
   }
   catch(Throwable $e)
   {
    $error = "Errore: " . htmlspecialchars($e->getMessage());
   }
  }
 }

 $interest_options = ["Musica", "Gaming", "Cucina", "Viaggi", "Lettura", "Arte", "Sport", "Natura", "Animali", "Cinema", "Vino", "Yoga", "Danza", "Teatro", "Concerti", "Surf"];
 $trait_options    = ["Sorridente", "Intellettuale", "Avventuroso", "Romantico", "Divertente", "Ambizioso", "Tranquillo", "Passionale", "Sensibile", "Determinato", "Ottimista", "Creativo"];

 // Recupera i valori esistenti per pre-compilare i chip
 $existing_interests  = [];
 $existing_traits     = [];
 $existing_pref_gender = [];

 if(!empty($user->interests))
 {
  if(is_array($user->interests))
  {
   $existing_interests = $user->interests;
  }
  elseif($user->interests instanceof Traversable)
  {
   $existing_interests = iterator_to_array($user->interests, false);
  }
 }

 if(!empty($user->traits))
 {
  if(is_array($user->traits))
  {
   $existing_traits = $user->traits;
  }
  elseif($user->traits instanceof Traversable)
  {
   $existing_traits = iterator_to_array($user->traits, false);
  }
 }

 $preferences = $user->preferences ?? [];

 if(!empty($preferences->gender))
 {
  if(is_array($preferences->gender))
  {
   $existing_pref_gender = $preferences->gender;
  }
  elseif($preferences->gender instanceof Traversable)
  {
   $existing_pref_gender = iterator_to_array($preferences->gender, false);
  }
 }
?>

<!DOCTYPE html>

<html lang="it">
 <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MeetHub – Completa il tuo profilo</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=1">
  <link rel="stylesheet" href="style.css?v=<?= filemtime('style.css') ?>">
 </head>

 <body>
  <?php require "header.php"; ?>
  <div class="page-wrapper">
   <div class="onboarding-container">
    <div class="onboarding-card">

     <div style="text-align:center; margin-bottom:2rem">
      <h2>Crea il tuo profilo</h2>
      <p class="text-muted mt-1" style="font-size:0.9rem">Raccontaci di te per trovare persone compatibili</p>
     </div>

     <div class="step-progress" id="stepProgress">
      <div class="step-dot active"></div>
      <div class="step-dot"></div>
      <div class="step-dot"></div>
      <div class="step-dot"></div>
     </div>

     <?php if($error !== ""){ ?>
      <div class="alert alert-danger mb-2"><?= htmlspecialchars($error) ?></div>
     <?php } ?>

     <form method="POST" id="onboardingForm" enctype="multipart/form-data" novalidate>

      <!--- Step 0: Chi sei --->
      <div class="step" id="step0">
       <h3 class="mb-2">Chi sei?</h3>
       <div class="form-group">
        <label>Immagine profilo</label>
        <input type="file" name="profile_image" accept=".jpg,.jpeg,.png,.webp,.gif,image/*">
        <small class="text-muted">Formato: JPG, PNG, WEBP o GIF (max 5MB)</small>
        <?php $ob_preview = profileImageUrl($user); if($ob_preview){ ?>
         <div class="onboarding-profile-preview">
          <img src="<?= htmlspecialchars($ob_preview) ?>" alt="Immagine profilo attuale">
         </div>
        <?php } ?>
       </div>
       <div class="form-group">
        <label>Città</label>
        <input type="text" name="city" placeholder="Es. Milano, Roma..." value="<?= htmlspecialchars($user->city ?? "") ?>">
       </div>
       <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem">
        <div class="form-group">
         <label>Altezza (cm)</label>
         <input type="number" name="height" placeholder="170" min="140" max="220" value="<?= isset($user->height) && (int)$user->height >= 140 && (int)$user->height <= 220 ? (int)$user->height : "" ?>">
        </div>
        <div class="form-group">
         <label>Professione</label>
         <input type="text" name="job" placeholder="Es. Designer" value="<?= htmlspecialchars($user->job ?? "") ?>">
        </div>
       </div>
       <div class="form-group">
        <label>Descrizione (bio)</label>
        <textarea name="bio" placeholder="Raccontati in poche parole... cosa ti piace? Cosa cerchi?"><?= htmlspecialchars($user->bio ?? "") ?></textarea>
       </div>
       <button type="button" class="btn btn-primary btn-full mt-2" onclick="goStep(1)">Avanti →</button>
      </div>

      <!--- Step 1: Interessi --->
      <div class="step hidden" id="step1">
       <h3 class="mb-1">I tuoi interessi</h3>
       <p class="text-muted mb-2" style="font-size:0.88rem">Seleziona almeno 3 passioni che ti rappresentano</p>
       <div class="chips-grid" id="interests-grid">
        <?php foreach($interest_options as $i){ ?>
         <button type="button" class="chip <?= in_array($i, $existing_interests) ? "selected" : "" ?>" data-value="<?= htmlspecialchars($i) ?>" onclick="toggleInterest(this)"><?= $i ?></button>
        <?php } ?>
       </div>
       <div id="interests-container"></div>
       <div style="display:flex; gap:1rem; margin-top:1.5rem">
        <button type="button" class="btn btn-ghost" onclick="goStep(0)">← Indietro</button>
        <button type="button" class="btn btn-primary" style="flex:1" onclick="goStep(2)">Avanti →</button>
       </div>
      </div>

      <!--- Step 2: Tratti --->
      <div class="step hidden" id="step2">
       <h3 class="mb-1">Come ti descriveresti?</h3>
       <p class="text-muted mb-2" style="font-size:0.88rem">Scegli le caratteristiche che ti descrivono meglio</p>
       <div class="chips-grid" id="traits-grid">
        <?php foreach($trait_options as $t){ ?>
         <button type="button" class="chip <?= in_array($t, $existing_traits) ? "selected" : "" ?>" data-value="<?= htmlspecialchars($t) ?>" onclick="toggleTrait(this)"><?= $t ?></button>
        <?php } ?>
       </div>
       <div id="traits-container"></div>
       <div style="display:flex; gap:1rem; margin-top:1.5rem">
        <button type="button" class="btn btn-ghost" onclick="goStep(1)">← Indietro</button>
        <button type="button" class="btn btn-primary" style="flex:1" onclick="goStep(3)">Avanti →</button>
       </div>
      </div>

      <!--- Step 3: Preferenze --->
      <div class="step hidden" id="step3">
       <h3 class="mb-1">Cosa cerchi?</h3>
       <p class="text-muted mb-2" style="font-size:0.88rem">Imposta le tue preferenze di ricerca</p>

       <div class="form-group">
        <label>Genere preferito</label>
        <div class="chips-grid" id="pref-gender-grid">
         <button type="button" class="chip <?= in_array("uomo", $existing_pref_gender) ? "selected" : "" ?>" data-value="uomo" onclick="togglePrefGender(this)">Uomo</button>
         <button type="button" class="chip <?= in_array("donna", $existing_pref_gender) ? "selected" : "" ?>" data-value="donna" onclick="togglePrefGender(this)">Donna</button>
         <button type="button" class="chip <?= in_array("non-binario", $existing_pref_gender) ? "selected" : "" ?>" data-value="non-binario" onclick="togglePrefGender(this)">Non-binario</button>
         <button type="button" class="chip <?= in_array("altro", $existing_pref_gender) ? "selected" : "" ?>" data-value="altro" onclick="togglePrefGender(this)">Altro</button>
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
        <button type="submit" class="btn btn-primary" style="flex:1">Completa Profilo</button>
       </div>
      </div>

     </form>
    </div>
   </div>
  </div>

  <script>
   let current_step = 0;

   function goStep(n)
   {
    document.getElementById("step" + current_step).classList.add("hidden");
    document.querySelectorAll(".step-dot")[current_step].classList.remove("active");
    document.querySelectorAll(".step-dot")[current_step].classList.add("done");

    current_step = n;
    document.getElementById("step" + n).classList.remove("hidden");
    document.querySelectorAll(".step-dot")[n].classList.add("active");
   }

   function toggleInterest(btn)
   {
    btn.classList.toggle("selected");
    updateInterestsInput();
   }

   function updateInterestsInput()
   {
    let container = document.getElementById("interests-container");
    container.innerHTML = "";

    let selected = document.querySelectorAll("#interests-grid .chip.selected");

    selected.forEach((chip, index) =>
    {
     let input   = document.createElement("input");
     input.type  = "hidden";
     input.name  = "interests[]";
     input.value = chip.dataset.value;
     container.appendChild(input);
    });
   }

   function toggleTrait(btn)
   {
    btn.classList.toggle("selected");
    updateTraitsInput();
   }

   function updateTraitsInput()
   {
    let container = document.getElementById("traits-container");
    container.innerHTML = "";

    let selected = document.querySelectorAll("#traits-grid .chip.selected");

    selected.forEach((chip, index) =>
    {
     let input   = document.createElement("input");
     input.type  = "hidden";
     input.name  = "traits[]";
     input.value = chip.dataset.value;
     container.appendChild(input);
    });
   }

   function togglePrefGender(btn)
   {
    btn.classList.toggle("selected");
    updatePrefGenderInput();
   }

   function updatePrefGenderInput()
   {
    let container = document.getElementById("pref-gender-container");
    container.innerHTML = "";

    let selected = document.querySelectorAll("#pref-gender-grid .chip.selected");

    selected.forEach((chip, index) =>
    {
     let input   = document.createElement("input");
     input.type  = "hidden";
     input.name  = "pref_gender[]";
     input.value = chip.dataset.value;
     container.appendChild(input);
    });
   }

   function updateAgeLabel()
   {
    const min = document.getElementById("pref_min_age").value;
    const max = document.getElementById("pref_max_age").value;
    document.getElementById("ageLabel").textContent = min + " – " + max + " anni";
   }

   // Inizializza gli input nascosti con i valori già selezionati
   document.addEventListener("DOMContentLoaded", function()
   {
    updateInterestsInput();
    updateTraitsInput();
    updatePrefGenderInput();

    document.querySelector("input[name='city']")?.focus();

    // ── Submit via XHR (soluzione preferita) ────────────────────────────────
    document.getElementById("onboardingForm").addEventListener("submit", function(e)
    {
     e.preventDefault();

     var formData = new FormData(this);

     var payload =
     {
      bio:      formData.get("bio")  || "",
      city:     formData.get("city") || "",
      job:      formData.get("job")  || "",
      height:   parseInt(formData.get("height")) || null,
      interests: formData.getAll("interests[]"),
      traits:    formData.getAll("traits[]"),
      preferences:
      {
       gender:   formData.getAll("pref_gender[]"),
       min_age:  parseInt(formData.get("pref_min_age"))  || 18,
       max_age:  parseInt(formData.get("pref_max_age"))  || 50,
       max_dist: parseInt(formData.get("pref_max_dist")) || 50
      },
      profile_complete: true
     };

     var imageFile = formData.get("profile_image");

     if(imageFile && imageFile.size > 0)
     {
      uploadPhoto(imageFile, function() { saveOnboarding(payload); });
     }
     else
     {
      saveOnboarding(payload);
     }
    });
   });

   function uploadPhoto(file, callback)
   {
    var fd = new FormData();
    fd.append("profile_image", file);

    var xhr = new XMLHttpRequest();
    xhr.open("POST", "/api/users/upload-photo");

    xhr.onreadystatechange = function()
    {
     if(xhr.readyState !== XMLHttpRequest.DONE) return;

     if(xhr.status === 200)
     {
      try
      {
       var r = JSON.parse(xhr.responseText);
       if(r.success) callback();
       else alert("Errore foto: " + (r.error || "Sconosciuto"));
      }
      catch(e) { alert("Errore di comunicazione"); }
     }
     else { alert("Errore upload foto: " + xhr.status); }
    };

    xhr.send(fd);
   }

   function saveOnboarding(payload)
   {
    var xhr = new XMLHttpRequest();
    xhr.open("PUT", "/api/users/profile");
    xhr.setRequestHeader("Content-Type", "application/json");

    xhr.onreadystatechange = function()
    {
     if(xhr.readyState !== XMLHttpRequest.DONE) return;

     if(xhr.status === 200)
     {
      try
      {
       var r = JSON.parse(xhr.responseText);
       if(r.success) window.location.href = "/discover";
       else alert("Errore: " + (r.error || "Sconosciuto"));
      }
      catch(e) { alert("Errore di comunicazione"); }
     }
     else { alert("Errore: " + xhr.status); }
    };

    xhr.send(JSON.stringify(payload));
   }
  </script>
 </body>
</html>
