<?php
 require_once "config.php";
 requireLogin();

 $user = currentUser();
 $db   = getDB();

 // Gestione richiesta JSON per i dettagli di un profilo (modal)
 if(isset($_GET["get_user_details"]) && $_GET["get_user_details"] == 1 && isset($_GET["id"]))
 {
  header("Content-Type: application/json");

  try
  {
   $db          = getDB();
   $target_id   = new MongoDB\BSON\ObjectId($_GET["id"]);
   $target_user = $db->users->findOne(["_id" => $target_id]);

   if(!$target_user)
   {
    echo json_encode(["success" => false, "error" => "Utente non trovato"]);
    exit;
   }

   // Recupera interessi in modo sicuro
   $interests = [];

   if(!empty($target_user->interests))
   {
    if(is_array($target_user->interests))
    {
     $interests = $target_user->interests;
    }
    elseif($target_user->interests instanceof Traversable)
    {
     $interests = iterator_to_array($target_user->interests, false);
    }
   }

   $traits = [];

   if(!empty($target_user->traits))
   {
    if(is_array($target_user->traits))
    {
     $traits = $target_user->traits;
    }
    elseif($target_user->traits instanceof Traversable)
    {
     $traits = iterator_to_array($target_user->traits, false);
    }
   }

   echo json_encode(
   [
    "success" => true,
    "data"    =>
    [
     "id"            => (string)$target_user->_id,
     "name"          => $target_user->name,
     "age"           => calcAge($target_user->birthdate ?? null),
     "city"          => $target_user->city ?? "",
     "job"           => $target_user->job ?? "",
     "height"        => $target_user->height ?? null,
     "bio"           => $target_user->bio ?? "",
     "profile_image" => $target_user->profile_image ?? null,
     "interests"     => $interests,
     "traits"        => $traits
    ]
   ]);
   exit;
  }
  catch(Exception $e)
  {
   echo json_encode(["success" => false, "error" => $e->getMessage()]);
   exit;
  }
 }

 // Gestione richiesta AJAX per like/reject
 if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest")
 {
  header("Content-Type: application/json");

  $target_user_id = $_POST["target_user_id"] ?? "";
  $action         = $_POST["action"] ?? "";

  if(!$target_user_id || !in_array($action, ["like", "reject"], true))
  {
   echo json_encode(["success" => false, "error" => "Azione non valida."]);
   exit;
  }

  try
  {
   $db      = getDB();
   $from_id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
   $to_id   = new MongoDB\BSON\ObjectId($target_user_id);

   if((string)$from_id === (string)$to_id)
   {
    echo json_encode(["success" => false, "error" => "Non puoi valutare il tuo profilo."]);
    exit;
   }

   // Controlla se esiste già un'interazione con questo utente
   $existing_interaction = $db->interactions->findOne(
   [
    "from_user_id" => $from_id,
    "to_user_id"   => $to_id
   ]);

   if($existing_interaction)
   {
    // Aggiorna l'interazione esistente
    $db->interactions->updateOne(
     ["_id" => $existing_interaction->_id],
     ['$set' =>
     [
      "action"     => $action,
      "updated_at" => new MongoDB\BSON\UTCDateTime()
     ]]
    );
   }
   else
   {
    // Crea una nuova interazione
    $db->interactions->insertOne(
    [
     "from_user_id" => $from_id,
     "to_user_id"   => $to_id,
     "action"       => $action,
     "created_at"   => new MongoDB\BSON\UTCDateTime(),
     "updated_at"   => new MongoDB\BSON\UTCDateTime()
    ]);
   }

   $match = false;

   if($action === "like")
   {
    // Verifica se c'è un like reciproco
    $reciprocal_like = $db->interactions->findOne(
    [
     "from_user_id" => $to_id,
     "to_user_id"   => $from_id,
     "action"       => "like"
    ]);

    if($reciprocal_like)
    {
     // Verifica se il match esiste già
     $existing_match = $db->matches->findOne(["users" => ['$all' => [$from_id, $to_id]]]);

     if(!$existing_match)
     {
      $db->matches->insertOne(
      [
       "users"      => [$from_id, $to_id],
       "created_at" => new MongoDB\BSON\UTCDateTime()
      ]);
     }

     $match = true;
    }
   }

   echo json_encode(
   [
    "success" => true,
    "action"  => $action,
    "match"   => $match,
    "message" => $match ? "Match!" : ($action === "like" ? "Like inviato" : "Profilo rifiutato")
   ]);
   exit;
  }
  catch(Exception $e)
  {
   echo json_encode(["success" => false, "error" => "Errore: " . $e->getMessage()]);
   exit;
  }
 }

 $success = "";
 $error   = "";// Variabili per messaggi di feedback

 // Gestione logout
 if(isset($_GET["logout"]))
 {
  session_destroy();
  header("Location: index.php");
  exit;
 }

 // Gestione like/reject da form normale (non AJAX)
 if($_SERVER["REQUEST_METHOD"] === "POST")
 {
  $target_user_id = $_POST["target_user_id"] ?? "";
  $action         = $_POST["action"] ?? "";

  if(!$target_user_id || !in_array($action, ["like", "reject"], true))
  {
   $error = "Azione non valida.";
  }
  else
  {
   try
   {
    $from_id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
    $to_id   = new MongoDB\BSON\ObjectId($target_user_id);

    if((string)$from_id === (string)$to_id)
    {
     $error = "Non puoi valutare il tuo profilo.";
    }
    else
    {
     $db->interactions->updateOne(
      ["from_user_id" => $from_id, "to_user_id" => $to_id],
      [
       '$set'         => ["action" => $action, "updated_at" => new MongoDB\BSON\UTCDateTime()],
       '$setOnInsert' => ["created_at" => new MongoDB\BSON\UTCDateTime()]
      ],
      ["upsert" => true]
     );

     if($action === "like")
     {
      $reciprocal_like = $db->interactions->findOne(
      [
       "from_user_id" => $to_id,
       "to_user_id"   => $from_id,
       "action"       => "like"
      ]);

      if($reciprocal_like)
      {
       $db->matches->updateOne(
        ["users" => ['$all' => [$from_id, $to_id]]],
        ['$setOnInsert' => ["users" => [$from_id, $to_id], "created_at" => new MongoDB\BSON\UTCDateTime()]],
        ["upsert" => true]
       );
       $success = "Match! Anche questa persona ha messo like al tuo profilo.";
      }
      else
      {
       $success = "Richiesta inviata con successo.";
      }
     }
     else
     {
      $success = "Profilo rifiutato.";
     }
    }
   }
   catch(Exception $e)
   {
    $error = "Impossibile salvare la scelta.";
   }
  }
 }

 // Raccolta degli ID dei profili già visti
 $current_user_id  = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
 $already_seen_ids = [];
 $seen_interactions = $db->interactions->find(["from_user_id" => $current_user_id]);

 foreach($seen_interactions as $interaction)
 {
  $already_seen_ids[] = $interaction->to_user_id;
 }

 // Recupero preferenze dell'utente corrente
 $preferences      = $user->preferences ?? [];
 $preferred_genders = [];

 if(isset($preferences->gender))
 {
  if(is_array($preferences->gender))
  {
   $preferred_genders = $preferences->gender;
  }
  elseif($preferences->gender instanceof Traversable)
  {
   $preferred_genders = iterator_to_array($preferences->gender, false);
  }
 }

 $pref_min_age  = isset($preferences->min_age)  ? (int)$preferences->min_age  : 18;
 $pref_max_age  = isset($preferences->max_age)  ? (int)$preferences->max_age  : 99;
 $pref_max_dist = isset($preferences->max_dist) ? (int)$preferences->max_dist : 0;

 $interest_options         = ["🎵 Musica", "🎮 Gaming", "🍕 Cucina", "✈️ Viaggi", "📚 Lettura", "🎨 Arte", "🏋️ Sport", "🌿 Natura", "🐶 Animali", "🎬 Cinema", "🍷 Vino", "🧘 Yoga", "💃 Danza", "🎭 Teatro", "🎸 Concerti", "🏄 Surf"];
 $selected_interest_filters = $_GET["interests"] ?? [];

 if(!is_array($selected_interest_filters))
 {
  $selected_interest_filters = [];
 }

 $selected_interest_filters = array_values(array_filter(array_map("trim", $selected_interest_filters), function($interest) use ($interest_options)
 {
  return in_array($interest, $interest_options, true);
 }));

 // Calcolo range di date di nascita in base alle preferenze di età
 $today             = new DateTime();
 $max_birthdate_obj = (clone $today)->modify("-" . $pref_min_age . " years");
 $min_birthdate_obj = (clone $today)->modify("-" . $pref_max_age . " years");
 $max_birthdate     = $max_birthdate_obj->format("Y-m-d");
 $min_birthdate     = $min_birthdate_obj->format("Y-m-d");

 // Query principale per recuperare i profili
 $query =
 [
  "_id"              => ["$ne" => $current_user_id],
  "profile_complete" => true,
  "birthdate"        => ["$gte" => $min_birthdate, "$lte" => $max_birthdate]
 ];

 if(!empty($already_seen_ids))
 {
  $query["_id"]["$nin"] = $already_seen_ids;
 }

 if(!empty($preferred_genders))
 {
  $query["gender"] = ["$in" => $preferred_genders];
 }

 if(!empty($selected_interest_filters))
 {
  $query["interests"] = ["$in" => $selected_interest_filters];
 }

 $profiles_cursor          = $db->users->find($query, ["limit" => 12]);
 $profiles                 = iterator_to_array($profiles_cursor, false);
 $showing_incomplete_profiles = false;

 // Fallback: mostra anche profili incompleti se non ci sono risultati
 if(count($profiles) === 0)
 {
  $fallback_query =
  [
   "_id"      => ["$ne" => $current_user_id],
   "birthdate" => ["$gte" => $min_birthdate, "$lte" => $max_birthdate]
  ];

  if(!empty($already_seen_ids))
  {
   $fallback_query["_id"]["$nin"] = $already_seen_ids;
  }

  if(!empty($preferred_genders))
  {
   $fallback_query["gender"] = ["$in" => $preferred_genders];
  }

  if(!empty($selected_interest_filters))
  {
   $fallback_query["interests"] = ["$in" => $selected_interest_filters];
  }

  $fallback_cursor = $db->users->find($fallback_query, ["limit" => 12]);
  $profiles        = iterator_to_array($fallback_cursor, false);
  $showing_incomplete_profiles = count($profiles) > 0;
 }

 // Filtra per distanza massima se le coordinate sono disponibili
 if($pref_max_dist > 0 && isset($user->lat, $user->lng) && is_numeric($user->lat) && is_numeric($user->lng))
 {
  $user_lat = (float)$user->lat;
  $user_lng = (float)$user->lng;

  $profiles = array_values(array_filter($profiles, function($profile) use ($user_lat, $user_lng, $pref_max_dist)
  {
   if(!isset($profile->lat, $profile->lng) || !is_numeric($profile->lat) || !is_numeric($profile->lng))
   {
    return false;
   }

   $distance = haversineDistance($user_lat, $user_lng, (float)$profile->lat, (float)$profile->lng);
   return $distance <= $pref_max_dist;
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
   .modal-overlay
   {
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

   @keyframes fadeIn
   {
    from { opacity: 0; }
    to   { opacity: 1; }
   }

   @keyframes slideDown
   {
    from { opacity: 0; transform: translateX(-50%) translateY(-50px); }
    to   { opacity: 1; transform: translateX(-50%) translateY(0); }
   }

   .profile-detail          { text-align: center; }
   .profile-detail-avatar   { width: 120px; height: 120px; border-radius: 50%; margin: 0 auto 1rem; overflow: hidden; background: linear-gradient(135deg, var(--coral), var(--gold)); }
   .profile-detail-avatar img { width: 100%; height: 100%; object-fit: cover; }
   .profile-detail-avatar div { display: flex; align-items: center; justify-content: center; height: 100%; font-size: 3rem; }
   .profile-detail-name     { font-family: 'Playfair Display', serif; font-size: 1.8rem; margin-bottom: 0.25rem; }
   .profile-detail-meta     { color: var(--text-muted); margin-bottom: 1rem; font-size: 0.9rem; }
   .profile-detail-bio      { background: var(--card2); padding: 1rem; border-radius: 16px; margin: 1rem 0; text-align: left; }
   .profile-detail-section  { margin: 1rem 0; text-align: left; }

   .profile-detail-section h4
   {
    color: var(--coral);
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 1px;
   }

   .tags-container { display: flex; flex-wrap: wrap; gap: 0.5rem; }

   .tag
   {
    background: rgba(255,75,110,0.15);
    padding: 0.3rem 0.8rem;
    border-radius: 50px;
    font-size: 0.8rem;
    color: var(--coral-light);
   }

   .match-notification
   {
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

   .match-notification a { color: white; margin-left: 10px; text-decoration: underline; }
   .profile-card-image    { cursor: pointer; }
  </style>
 </head>

 <body>
  <div class="page-wrapper">
   <div class="discover-layout" style="grid-template-columns:1fr; max-width:1100px">
    <div class="card">
     <div class="flex justify-between items-center" style="flex-wrap:wrap; gap:1rem">
      <div>
       <h2>Scopri persone</h2>
       <p class="text-muted mt-1">Ciao <?= htmlspecialchars($user->name ?? "utente") ?>, scegli i profili con ❤️ o rifiuta con ❌. Clicca sulla foto per vedere i dettagli.</p>
      </div>
      <div class="flex gap-1" style="flex-wrap:wrap">
       <a class="btn btn-primary" href="chat.php">Apri Chat Match</a>
       <a class="btn btn-ghost" href="onboarding.php">Modifica profilo</a>
       <a class="btn btn-outline" href="discover.php?logout=1">Esci</a>
      </div>
     </div>

     <?php if($error !== ""){ ?>
      <div class="alert alert-danger mt-2"><?= htmlspecialchars($error) ?></div>
     <?php } ?>

     <?php if($success !== ""){ ?>
      <div class="alert alert-success mt-2"><?= htmlspecialchars($success) ?></div>
     <?php } ?>

     <?php if($showing_incomplete_profiles){ ?>
      <div class="alert mt-2" style="background:rgba(245,166,35,0.12); border:1px solid rgba(245,166,35,0.35); color:#ffd57a;">
       Ti sto mostrando anche profili non ancora completi, ma sempre in linea con le tue preferenze.
      </div>
     <?php } ?>

     <form method="GET" class="mt-2">
      <input type="hidden" name="filter" value="1">
      <label>Filtra per interessi</label>
      <div class="chips-grid">
       <?php foreach($interest_options as $interest){ ?>
        <label class="chip <?= in_array($interest, $selected_interest_filters, true) ? "selected" : "" ?>" style="display:inline-flex; align-items:center; gap:0.5rem;">
         <input type="checkbox" name="interests[]" value="<?= htmlspecialchars($interest) ?>" <?= in_array($interest, $selected_interest_filters, true) ? "checked" : "" ?> style="width:auto;">
         <span><?= htmlspecialchars($interest) ?></span>
        </label>
       <?php } ?>
      </div>
      <div class="flex gap-1 mt-2">
       <button type="submit" class="btn btn-primary btn-sm">Applica filtri</button>
       <a href="discover.php" class="btn btn-ghost btn-sm">Reset</a>
      </div>
     </form>
    </div>

    <div class="profiles-grid">
     <?php foreach($profiles as $profile){ ?>
      <div class="profile-card">
       <div class="profile-card-image" onclick="showProfileDetails('<?= (string)$profile->_id ?>')">
        <?php if(!empty($profile->profile_image)){ ?>
         <img src="<?= htmlspecialchars($profile->profile_image) ?>" alt="Foto profilo di <?= htmlspecialchars($profile->name ?? "Utente") ?>">
        <?php } else { ?>
         <div class="avatar-emoji">👤</div>
        <?php } ?>
        <div class="profile-gradient-overlay"></div>
        <div class="profile-card-info">
         <div class="profile-name">
          <?= htmlspecialchars($profile->name ?? "Utente") ?><?= calcAge($profile->birthdate ?? null) > 0 ? ", " . calcAge($profile->birthdate ?? null) : "" ?>
         </div>
         <div class="profile-meta">
          <?= htmlspecialchars($profile->city ?? "Città non indicata") ?><?= !empty($profile->job) ? " • " . htmlspecialchars($profile->job) : "" ?>
         </div>
         <?php
          // Recupera interessi in modo sicuro per la visualizzazione
          $interests = [];
          if(!empty($profile->interests))
          {
           if(is_array($profile->interests))
           {
            $interests = $profile->interests;
           }
           elseif($profile->interests instanceof Traversable)
           {
            $interests = iterator_to_array($profile->interests, false);
           }
          }
         ?>
         <?php if(!empty($interests)){ ?>
          <div class="profile-tags">
           <?php foreach(array_slice($interests, 0, 3) as $interest){ ?>
            <span class="tag"><?= htmlspecialchars($interest) ?></span>
           <?php } ?>
          </div>
         <?php } ?>
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
     <?php } ?>
    </div>

    <?php if(count($profiles) === 0){ ?>
     <div class="card empty-state">
      <div class="emoji">🫶</div>
      <h3>Nessun nuovo profilo disponibile</h3>
      <p class="text-muted mt-1">Hai già valutato tutti i profili disponibili. Torna più tardi.</p>
     </div>
    <?php } ?>
   </div>
  </div>

  <!--- Modal per vedere i dettagli del profilo prima di interagire --->
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
   let current_modal_user_id = null;// ID dell'utente attualmente nel modal

   // Mostra la notifica di match
   function showMatchNotification(user_id)
   {
    const notification       = document.createElement("div");
    notification.className   = "match-notification";
    notification.innerHTML   = "🎉 È un MATCH! 🎉 <a href=\"chat.php\">Vai alla chat →</a>";
    notification.onclick     = () => { window.location.href = "chat.php"; };
    document.body.appendChild(notification);

    setTimeout(() =>
    {
     notification.style.opacity = "0";
     setTimeout(() => notification.remove(), 300);
    }, 5000);
   }

   // Esegue un'azione (like/reject) via AJAX
   async function performAction(user_id, action, close_modal_after = true)
   {
    try
    {
     const form_data = new FormData();
     form_data.append("target_user_id", user_id);
     form_data.append("action", action);

     const response = await fetch("discover.php",
     {
      method:  "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body:    form_data
     });

     const result = await response.json();

     if(result.success)
     {
      if(result.match) showMatchNotification(user_id);
      if(close_modal_after) closeModal();
      location.reload();
     }
     else
     {
      alert(result.error || "Errore durante l'operazione");
     }
    }
    catch(error)
    {
     console.error("Errore:", error);
     alert("Errore di connessione");
    }
   }

   // Apre il modal con i dettagli del profilo
   async function showProfileDetails(user_id)
   {
    const modal         = document.getElementById("profile-modal");
    const modal_content = document.getElementById("modal-content");
    current_modal_user_id = user_id;

    modal_content.innerHTML = "<div style=\"text-align:center; padding:2rem;\">⏳ Caricamento profilo...</div>";
    modal.style.display = "flex";

    try
    {
     const response = await fetch("discover.php?get_user_details=1&id=" + user_id,
     {
      headers: { "X-Requested-With": "XMLHttpRequest" }
     });

     const result = await response.json();

     if(result.success && result.data)
     {
      const user = result.data;

      modal_content.innerHTML = `
       <div class="profile-detail">
        <div class="profile-detail-avatar">
         ${user.profile_image ?
          `<img src="${escapeHtml(user.profile_image)}" alt="${escapeHtml(user.name)}">` :
          "<div>👤</div>"
         }
        </div>
        <div class="profile-detail-name">${escapeHtml(user.name)}${user.age ? `, ${user.age}` : ""}</div>
        <div class="profile-detail-meta">
         ${user.city ? `📍 ${escapeHtml(user.city)}` : ""}
         ${user.job ? ` • 💼 ${escapeHtml(user.job)}` : ""}
         ${user.height ? ` • 📏 ${user.height} cm` : ""}
        </div>
        ${user.bio ? `
        <div class="profile-detail-bio">
         <strong>📝 Chi sono</strong><br>
         ${escapeHtml(user.bio).replace(/\n/g, "<br>")}
        </div>
        ` : ""}
        ${user.interests && user.interests.length > 0 ? `
        <div class="profile-detail-section">
         <h4>🎯 Interessi</h4>
         <div class="tags-container">
          ${user.interests.map(i => `<span class="tag">${escapeHtml(i)}</span>`).join("")}
         </div>
        </div>
        ` : ""}
        ${user.traits && user.traits.length > 0 ? `
        <div class="profile-detail-section">
         <h4>✨ Qualità</h4>
         <div class="tags-container">
          ${user.traits.map(t => `<span class="tag">${escapeHtml(t)}</span>`).join("")}
         </div>
        </div>
        ` : ""}
       </div>
      `;

      const like_btn   = document.getElementById("modal-like-btn");
      const reject_btn = document.getElementById("modal-reject-btn");

      const new_like_btn   = like_btn.cloneNode(true);
      const new_reject_btn = reject_btn.cloneNode(true);
      like_btn.parentNode.replaceChild(new_like_btn, like_btn);
      reject_btn.parentNode.replaceChild(new_reject_btn, reject_btn);

      new_like_btn.onclick   = () => performAction(user_id, "like", true);
      new_reject_btn.onclick = () => performAction(user_id, "reject", true);
     }
     else
     {
      modal_content.innerHTML = "<div style=\"text-align:center; padding:2rem; color:var(--danger);\">❌ " + (result.error || "Errore nel caricamento del profilo") + "</div>";
     }
    }
    catch(error)
    {
     console.error("Errore:", error);
     modal_content.innerHTML = `
      <div style="text-align:center; padding:2rem; color:var(--danger);">
       ❌ Errore di connessione<br>
       <small style="font-size:0.8rem;">Ricarica la pagina e riprova</small>
      </div>
     `;
    }
   }

   function closeModal()
   {
    document.getElementById("profile-modal").style.display = "none";
    current_modal_user_id = null;
   }

   function escapeHtml(text)
   {
    if(!text) return "";
    const div        = document.createElement("div");
    div.textContent  = text;
    return div.innerHTML;
   }

   // Intercetta l'invio dei form di like/reject per usare AJAX
   document.querySelectorAll(".action-form").forEach(form =>
   {
    form.addEventListener("submit", async (e) =>
    {
     e.preventDefault();
     const user_id = form.dataset.userId;
     const action  = form.dataset.action;
     await performAction(user_id, action, false);
    });
   });

   // Chiude il modal cliccando sull'overlay
   document.getElementById("profile-modal").addEventListener("click", function(e)
   {
    if(e.target === this) closeModal();
   });

   // Chiude il modal con il tasto Escape
   document.addEventListener("keydown", function(e)
   {
    if(e.key === "Escape" && document.getElementById("profile-modal").style.display === "flex")
    {
     closeModal();
    }
   });
  </script>
 </body>
</html>
