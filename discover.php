<?php
 require_once "config.php";
 requireLogin();

 $user = currentUser();
 $db   = getDB();

 $success = "";
 $error   = "";// Variabili per messaggi di feedback

 // Gestione logout
 if(isset($_GET["logout"]))
 {
  session_destroy();
  header("Location: /");
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

 $interest_options         = ["Musica", "Gaming", "Cucina", "Viaggi", "Lettura", "Arte", "Sport", "Natura", "Animali", "Cinema", "Vino", "Yoga", "Danza", "Teatro", "Concerti", "Surf"];
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
  "_id"              => ['$ne' => $current_user_id],
  "profile_complete" => true,
  "birthdate"        => ['$gte' => $min_birthdate, '$lte' => $max_birthdate]
 ];

 if(!empty($already_seen_ids))
 {
  $query["_id"]['$nin'] = $already_seen_ids;
 }

 if(!empty($preferred_genders))
 {
  $query["gender"] = ['$in' => $preferred_genders];
 }

 if(!empty($selected_interest_filters))
 {
  $query["interests"] = ['$in' => $selected_interest_filters];
 }

 // Filtro bidirezionale: il profilo deve essere potenzialmente interessato al genere dell'utente corrente.
 // Un array preferences.gender vuoto o assente = nessuna preferenza = compatibile con chiunque.
 $current_user_gender = (string)($user->gender ?? "");

 if($current_user_gender !== "")
 {
  $query['$or'] =
  [
   ["preferences.gender" => ['$size'   => 0]],       // nessuna preferenza di genere impostata
   ["preferences.gender" => ['$exists' => false]],   // campo assente (utenti vecchi)
   ["preferences.gender" => $current_user_gender]    // il mio genere è tra quelli che cercano
  ];
 }

 $profiles_cursor = $db->users->find($query, ["limit" => 12]);
 $profiles        = iterator_to_array($profiles_cursor, false);
 // Mostriamo solo profili completi; quelli incompleti vengono filtrati a monte

 // Filtra per distanza massima se le coordinate sono disponibili
 if($pref_max_dist > 0 && isset($user->lat, $user->lng) && is_numeric($user->lat) && is_numeric($user->lng))
 {
  $user_lat = (float)$user->lat;
  $user_lng = (float)$user->lng;

  $profiles = array_values(array_filter($profiles, function($profile) use ($user_lat, $user_lng, $pref_max_dist)
  {
   if(!isset($profile->lat, $profile->lng) || !is_numeric($profile->lat) || !is_numeric($profile->lng))
    return true;
   return haversineDistance($user_lat, $user_lng, (float)$profile->lat, (float)$profile->lng) <= $pref_max_dist;
  }));
 }
?>

<!DOCTYPE html>

<html lang="it">
 <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MeetHub – Scopri</title>
  <link rel="stylesheet" href="style.css?v=<?= filemtime('style.css') ?>">
 </head>

 <body>
  <?php require "header.php"; ?>

  <div class="page-wrapper">
  <div class="discover-stage">

   <?php if($error !== ""){ ?>
    <div class="alert alert-danger" style="position:absolute;top:1rem;left:50%;transform:translateX(-50%);z-index:30;"><?= htmlspecialchars($error) ?></div>
   <?php } ?>

   <?php if($success !== ""){ ?>
    <div class="alert alert-success" style="position:absolute;top:1rem;left:50%;transform:translateX(-50%);z-index:30;"><?= htmlspecialchars($success) ?></div>
   <?php } ?>

   <!--- FAB Filtri --->
   <button class="discover-fab" onclick="toggleFilters(event)" aria-label="Filtri" title="Filtri">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
     <path d="M3 6h18M6 12h12M10 18h4"/>
    </svg>
    <?php if(!empty($selected_interest_filters)){ ?>
     <span class="fab-dot"></span>
    <?php } ?>
   </button>

   <!--- Popover filtri --->
   <div class="discover-filter-popover" id="filter-panel">
    <form method="GET">
     <h4>Filtra per interessi</h4>
     <div class="chips-grid">
      <?php foreach($interest_options as $interest){ ?>
       <label class="chip <?= in_array($interest, $selected_interest_filters, true) ? "selected" : "" ?>" style="display:inline-flex;align-items:center;gap:0.4rem;">
        <input type="checkbox" name="interests[]" value="<?= htmlspecialchars($interest) ?>" <?= in_array($interest, $selected_interest_filters, true) ? "checked" : "" ?> style="width:auto;">
        <span><?= htmlspecialchars($interest) ?></span>
       </label>
      <?php } ?>
     </div>
     <div class="flex gap-1 mt-2">
      <button type="submit" class="btn btn-primary btn-sm">Applica</button>
      <a href="/discover" class="btn btn-ghost btn-sm">Reset</a>
     </div>
    </form>
   </div>

   <?php if(count($profiles) === 0){ ?>

    <div class="bumble-empty">
     <h3>Nessun nuovo profilo</h3>
     <p>Hai già valutato tutti i profili disponibili. Torna più tardi.</p>
    </div>

   <?php } else { ?>

    <!--- Stack di carte --->
    <div class="card-stack" id="card-stack">

     <?php foreach($profiles as $idx => $profile){ ?>
      <?php
       $pid     = (string)$profile->_id;
       $pname   = htmlspecialchars($profile->name ?? "Utente");
       $page    = calcAge($profile->birthdate ?? null);
       $pcity   = htmlspecialchars($profile->city ?? "");
       $pjob    = htmlspecialchars($profile->job  ?? "");
       $pbio    = trim($profile->bio ?? "");
       $pheight = isset($profile->height) && (int)$profile->height > 0 ? (int)$profile->height : 0;

       $pinterests = [];
       if(!empty($profile->interests))
       {
        if(is_array($profile->interests))                    $pinterests = $profile->interests;
        elseif($profile->interests instanceof Traversable)   $pinterests = iterator_to_array($profile->interests, false);
       }

       $ptraits = [];
       if(!empty($profile->traits))
       {
        if(is_array($profile->traits))                       $ptraits = $profile->traits;
        elseif($profile->traits instanceof Traversable)      $ptraits = iterator_to_array($profile->traits, false);
       }

       $card_class = $idx === 0 ? "active" : ($idx === 1 ? "next-up" : "hidden-behind");
      ?>
      <article class="bumble-card <?= $card_class ?>" data-user-id="<?= htmlspecialchars($pid) ?>">

       <!--- Foto + nome --->
       <div class="card-photo">
        <?php if(!empty($profile->profile_image)){ ?>
         <img src="<?= htmlspecialchars($profile->profile_image) ?>" alt="<?= $pname ?>">
        <?php } else { ?>
         <div class="card-photo-placeholder"><?= strtoupper(substr($profile->name ?? "?", 0, 1)) ?></div>
        <?php } ?>
        <div class="card-photo-overlay">
         <h2 class="card-name"><?= $pname ?><?= $page > 0 ? ", $page" : "" ?></h2>
        </div>
       </div>

       <!--- Body --->
       <div class="card-body">

        <?php if($pbio !== ""){ ?>
         <p class="card-bio"><?= htmlspecialchars($pbio) ?></p>
        <?php } ?>

        <?php if($pheight || $pcity || $pjob){ ?>
         <div class="card-facts">
          <?php if($pheight){ ?>
           <span class="card-fact">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18M7 7l5-4 5 4M7 17l5 4 5-4"/></svg>
            <?= $pheight ?> cm
           </span>
          <?php } ?>
          <?php if($pcity){ ?>
           <span class="card-fact">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M12 21s-7-5.5-7-11a7 7 0 1 1 14 0c0 5.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
            <?= $pcity ?>
           </span>
          <?php } ?>
          <?php if($pjob){ ?>
           <span class="card-fact">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            <?= $pjob ?>
           </span>
          <?php } ?>
         </div>
        <?php } ?>

        <?php if(!empty($pinterests)){ ?>
         <div>
          <h4 class="card-section-title">Interessi</h4>
          <div class="card-chips">
           <?php foreach($pinterests as $pi){ ?>
            <span class="card-chip"><?= htmlspecialchars($pi) ?></span>
           <?php } ?>
          </div>
         </div>
        <?php } ?>

        <?php if(!empty($ptraits)){ ?>
         <div>
          <h4 class="card-section-title">Qualità</h4>
          <div class="card-chips">
           <?php foreach($ptraits as $pt){ ?>
            <span class="card-chip"><?= htmlspecialchars($pt) ?></span>
           <?php } ?>
          </div>
         </div>
        <?php } ?>

       </div>

      </article>
     <?php } ?>

    </div>

    <!--- Empty state (mostrato quando si finiscono le carte) --->
    <div class="bumble-empty hidden" id="empty-state" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);">
     <h3>Hai visto tutti i profili!</h3>
     <p>Torna più tardi per scoprirne di nuovi.</p>
    </div>

    <!--- Pulsanti azione --->
    <div class="card-actions" id="card-actions">
     <button class="action-btn action-pass" onclick="doAction('reject')" aria-label="Rifiuta" title="Rifiuta (←)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
       <path d="M18 6L6 18M6 6l12 12"/>
      </svg>
     </button>
     <button class="action-btn action-like" onclick="doAction('like')" aria-label="Like" title="Like (→)">
      <svg viewBox="0 0 24 24" fill="currentColor">
       <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41 0.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
      </svg>
     </button>
    </div>

   <?php } ?>

  </div>
  </div>

  <script>
   var cards        = [];
   var currentIndex = 0;
   var isAnimating  = false;

   function initStack()
   {
    var nodes = document.querySelectorAll(".bumble-card[data-user-id]");

    for(var i = 0; i < nodes.length; i++)
    {
     cards.push(nodes[i]);
    }
   }

   function showEmptyState()
   {
    var empty   = document.getElementById("empty-state");
    var actions = document.getElementById("card-actions");
    if(empty)   empty.classList.remove("hidden");
    if(actions) actions.style.display = "none";
   }

   function doAction(action)
   {
    if(isAnimating) return;
    if(currentIndex >= cards.length) return;

    isAnimating = true;
    var card    = cards[currentIndex];
    var userId  = card.dataset.userId;

    // Sposta la carta attiva fuori dallo schermo
    card.classList.remove("active");
    card.classList.add(action === "like" ? "swiping-right" : "swiping-left");

    // Promuovi la prossima a "active" in parallelo (effetto stack che si solleva)
    if(currentIndex + 1 < cards.length)
    {
     cards[currentIndex + 1].classList.remove("next-up", "hidden-behind");
     cards[currentIndex + 1].classList.add("active");
    }
    if(currentIndex + 2 < cards.length)
    {
     cards[currentIndex + 2].classList.remove("hidden-behind");
     cards[currentIndex + 2].classList.add("next-up");
    }

    setTimeout(function()
    {
     card.style.display = "none";
     currentIndex++;
     isAnimating = false;

     if(currentIndex >= cards.length) showEmptyState();
    }, 500);

    sendAction(userId, action);
   }

   function sendAction(user_id, action)
   {
    // ✅ Prepara i dati in JSON
    var payload = JSON.stringify({
    user_id: user_id
    });

    var xhr = new XMLHttpRequest();
    // ✅ Chiama l'API invece di discover.php
    xhr.open("POST", "/api/interactions/" + action);
    xhr.setRequestHeader("Content-Type", "application/json");
    xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

    xhr.onreadystatechange = function()
    {
     if(xhr.readyState !== XMLHttpRequest.DONE) return;
     if(xhr.status !== 200) return;

     try
     {
      var result = JSON.parse(xhr.responseText);
      if(result.success && result.match) showMatchNotification();
     }
     catch(e) { console.error("Errore parsing JSON:", e); }
    };

    xhr.onerror = function() { console.error("Errore di rete"); };
    xhr.send(payload);  // ✅ Invia JSON
   }

   function showMatchNotification()
   {
    if(typeof openNotifPanel === "function") openNotifPanel();
   }

   function toggleFilters(e)
   {
    if(e) e.stopPropagation();
    var p = document.getElementById("filter-panel");
    if(p) p.classList.toggle("open");
   }

   // Chiude il popover quando si clicca fuori
   document.addEventListener("click", function(e)
   {
    var p   = document.getElementById("filter-panel");
    var fab = document.querySelector(".discover-fab");
    if(!p || !fab) return;
    if(p.contains(e.target) || fab.contains(e.target)) return;
    p.classList.remove("open");
   });

   // Scorciatoie tastiera: frecce
   document.addEventListener("keydown", function(e)
   {
    if(e.target && (e.target.tagName === "INPUT" || e.target.tagName === "TEXTAREA")) return;
    if(e.key === "ArrowLeft")  doAction("reject");
    if(e.key === "ArrowRight") doAction("like");
   });

   // ── Drag-to-swipe ──────────────────────────────────────────────────────────
   (function()
   {
    var THRESHOLD = 100;
    var MAX_ROT   = 18;

    function initDrag(card, idx)
    {
     card.addEventListener("pointerdown", function(e)
     {
      if(isAnimating || currentIndex !== idx) return;
      if(e.pointerType === "mouse" && e.button !== 0) return;

      var startX  = e.clientX;
      var startY  = e.clientY;
      var ptrId   = e.pointerId;
      var grabbed = false; // true once we confirm horizontal intent
      var aborted = false;
      var moved   = false;
      var grabTop = (e.clientY - card.getBoundingClientRect().top) < card.offsetHeight / 2;

      function onMove(ev)
      {
       if(aborted) return;
       var dx = ev.clientX - startX;
       var dy = ev.clientY - startY;

       if(!grabbed)
       {
        // Wait for enough movement to determine direction
        if(Math.abs(dx) < 5 && Math.abs(dy) < 5) return;

        if(Math.abs(dy) > Math.abs(dx))
        {
         // Vertical — let the browser scroll the card
         abort();
         return;
        }

        // Horizontal confirmed: take over the gesture
        card.setPointerCapture(ptrId);
        card.style.touchAction = "none";
        card.style.transition  = "none";
        card.style.willChange  = "transform";
        grabbed = true;
       }

       moved = true;
       var rot = Math.max(-MAX_ROT, Math.min(MAX_ROT, (dx / THRESHOLD) * MAX_ROT * (grabTop ? 1 : -1)));
       card.style.transform = "translateX(" + dx + "px) translateY(" + (dy * 0.12) + "px) rotate(" + rot + "deg)";
      }

      function onUp(ev)
      {
       cleanup();
       card.style.touchAction = "";
       card.style.willChange  = "";

       if(!moved || aborted) { card.style.transition = ""; card.style.transform = ""; return; }

       var dx = ev.clientX - startX;

       if(Math.abs(dx) >= THRESHOLD)
       {
        var action = dx > 0 ? "like" : "reject";
        var dir    = dx > 0 ? 1 : -1;
        isAnimating = true;

        if(currentIndex + 1 < cards.length)
        {
         cards[currentIndex + 1].classList.remove("next-up", "hidden-behind");
         cards[currentIndex + 1].classList.add("active");
        }
        if(currentIndex + 2 < cards.length)
        {
         cards[currentIndex + 2].classList.remove("hidden-behind");
         cards[currentIndex + 2].classList.add("next-up");
        }

        card.style.transition = "transform 0.42s cubic-bezier(0.25,0.46,0.45,0.94), opacity 0.42s ease";
        card.style.transform  = "translateX(" + (dir * (window.innerWidth + 200)) + "px) rotate(" + (dir * MAX_ROT) + "deg)";
        card.style.opacity    = "0";

        sendAction(card.dataset.userId, action);
        setTimeout(function()
        {
         card.style.display = "none";
         currentIndex++;
         isAnimating = false;
         if(currentIndex >= cards.length) showEmptyState();
        }, 420);
       }
       else
       {
        card.style.transition = "transform 0.5s cubic-bezier(0.175,0.885,0.32,1.275)";
        card.style.transform  = "";
        card.addEventListener("transitionend", function done()
        {
         card.style.transition = "";
         card.removeEventListener("transitionend", done);
        }, { once: true });
       }
      }

      function onCancel()
      {
       abort();
      }

      function abort()
      {
       aborted = true;
       cleanup();
       card.style.touchAction = "";
       card.style.transition  = "";
       card.style.transform   = "";
       card.style.willChange  = "";
      }

      function cleanup()
      {
       card.removeEventListener("pointermove",   onMove);
       card.removeEventListener("pointerup",     onUp);
       card.removeEventListener("pointercancel", onCancel);
      }

      card.addEventListener("pointermove",   onMove);
      card.addEventListener("pointerup",     onUp);
      card.addEventListener("pointercancel", onCancel);
     });
    }

    window.initDragAll = function()
    {
     for(var i = 0; i < cards.length; i++) initDrag(cards[i], i);
    };
   })();

   initStack();
   initDragAll();

  </script>
 </body>
</html>
