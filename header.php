<?php
 $current_page        = basename($_SERVER["PHP_SELF"]);
 $current_user_header = currentUser();
 $header_avatar       = $current_user_header->profile_image ?? null;
 $header_name         = $current_user_header->name ?? "";

 $notif_unread_msgs = 0;
 $notif_match_items = [];

 if($current_user_header)
 {
  $cur_id = $current_user_header->_id;
  $db_h   = getDB();

  // Messaggi non letti (indipendente dal seen_by — si azzerano leggendoli in chat)
  try
  {
   $notif_unread_msgs = (int)$db_h->messages->countDocuments(["to_user_id" => $cur_id, "read" => false]);
  }
  catch(Throwable $e) {}

  // Match non ancora visti dall'utente corrente (seen_by non contiene cur_id)
  try
  {
   $matches_cursor = $db_h->matches->find(
    [
     "users"    => ['$in'  => [$cur_id]],
     "seen_by"  => ['$ne'  => $cur_id]
    ],
    ["sort" => ["created_at" => -1], "limit" => 5]
   );

   foreach($matches_cursor as $m)
   {
    $other_id = null;
    foreach($m->users as $uid)
    {
     if((string)$uid !== (string)$cur_id) { $other_id = $uid; break; }
    }

    if($other_id)
    {
     $other = $db_h->users->findOne(["_id" => $other_id], ["projection" => ["name" => 1, "profile_image" => 1]]);
     if($other)
     {
      $notif_match_items[] =
      [
       "name"     => (string)($other->name ?? "?"),
       "img"      => $other->profile_image ?? null,
       "id"       => (string)$other_id,
       "match_id" => (string)$m->_id
      ];
     }
    }
   }
  }
  catch(Throwable $e) {}
 }

 $notif_total = $notif_unread_msgs + count($notif_match_items);
?>
<header class="app-header">
 <div class="app-header-inner">

  <a class="app-header-logo" href="discover.php">MeetHub</a>

  <nav class="app-header-nav">
   <a class="app-nav-link <?= $current_page === "discover.php" ? "active" : "" ?>" href="discover.php">Scopri</a>
   <a class="app-nav-link <?= $current_page === "chat.php"     ? "active" : "" ?>" href="chat.php">Chat</a>
  </nav>

  <div class="app-header-right">

   <!-- Notifiche -->
   <div class="app-notif-wrap" id="notif-wrap">
    <button class="app-notif-btn" id="notif-btn" aria-label="Notifiche">
     <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
      <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
     </svg>
     <?php if($notif_total > 0){ ?>
      <span class="app-notif-badge" id="notif-badge"><?= $notif_total > 9 ? "9+" : $notif_total ?></span>
     <?php } ?>
    </button>

    <div class="app-notif-panel" id="notif-panel">
     <div class="notif-panel-title">Notifiche</div>

     <?php if($notif_total === 0){ ?>
      <div class="notif-empty">
       <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
       </svg>
       Nessuna nuova notifica
      </div>
     <?php } ?>

     <?php if(count($notif_match_items) > 0){ ?>
      <div class="notif-section-label">Match recenti</div>
      <?php foreach($notif_match_items as $mi){ ?>
       <a class="notif-item"
          href="chat.php?chat=<?= htmlspecialchars($mi["id"]) ?>"
          data-match-id="<?= htmlspecialchars($mi["match_id"]) ?>"
          onclick="dismissMatch(event, this)">
        <div class="notif-item-avatar">
         <?php if($mi["img"]){ ?>
          <img src="<?= htmlspecialchars($mi["img"]) ?>" alt="">
         <?php } else { ?>
          <?= strtoupper(substr($mi["name"], 0, 1)) ?>
         <?php } ?>
        </div>
        <div class="notif-item-body">
         <strong><?= htmlspecialchars($mi["name"]) ?></strong>
         <span>Nuovo match! 🎉</span>
        </div>
        <div class="notif-item-dot"></div>
       </a>
      <?php } ?>
     <?php } ?>

     <?php if($notif_unread_msgs > 0){ ?>
      <div class="notif-section-label">Messaggi</div>
      <a class="notif-item" href="chat.php"
         data-msg-count="<?= $notif_unread_msgs ?>"
         onclick="dismissMsgs(this)">
       <div class="notif-item-avatar notif-item-avatar--msg">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
       </div>
       <div class="notif-item-body">
        <strong><?= $notif_unread_msgs ?> messagg<?= $notif_unread_msgs === 1 ? "io" : "i" ?> non lett<?= $notif_unread_msgs === 1 ? "o" : "i" ?></strong>
        <span>Vai alle chat →</span>
       </div>
       <div class="notif-item-dot"></div>
      </a>
     <?php } ?>
    </div>
   </div>

   <!-- Profilo + dropdown -->
   <div class="app-header-user">
    <div class="app-header-profile" id="header-profile-btn">
     <?php if($header_avatar){ ?>
      <img class="app-header-avatar" src="<?= htmlspecialchars($header_avatar) ?>" alt="Foto profilo">
     <?php } else { ?>
      <div class="app-header-avatar app-header-avatar-placeholder"><?= strtoupper(substr($header_name, 0, 1)) ?: "?" ?></div>
     <?php } ?>
     <span class="app-header-username"><?= htmlspecialchars($header_name) ?></span>
     <svg class="app-header-chevron" viewBox="0 0 16 16" fill="none">
      <path d="M4 6L8 10L12 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
     </svg>
    </div>

    <div class="app-header-dropdown" id="header-dropdown">
     <a class="app-dropdown-item" href="profile.php">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
      Profilo
     </a>
     <a class="app-dropdown-item" href="settings.php">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      Impostazioni
     </a>
     <div class="app-dropdown-divider"></div>
     <a class="app-dropdown-item app-dropdown-item--danger" href="discover.php?logout=1">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Logout
     </a>
    </div>
   </div>

  </div>
 </div>
</header>

<script>
(function()
{
 var profileBtn  = document.getElementById("header-profile-btn");
 var profileDrop = document.getElementById("header-dropdown");
 var notifBtn    = document.getElementById("notif-btn");
 var notifPanel  = document.getElementById("notif-panel");

 function closeAll()
 {
  profileDrop?.classList.remove("open");
  profileBtn?.classList.remove("open");
  notifPanel?.classList.remove("open");
  notifBtn?.classList.remove("open");
 }

 profileBtn?.addEventListener("click", function(e)
 {
  e.stopPropagation();
  var was = profileDrop.classList.contains("open");
  closeAll();
  if(!was) { profileDrop.classList.add("open"); profileBtn.classList.add("open"); }
 });

 notifBtn?.addEventListener("click", function(e)
 {
  e.stopPropagation();
  var was = notifPanel.classList.contains("open");
  closeAll();
  // Aprire il pannello NON azzera nulla — i badge restano
  if(!was) { notifPanel.classList.add("open"); notifBtn.classList.add("open"); }
 });

 document.addEventListener("click", closeAll);
})();

// Decrementa il badge globale di `n` unità
function adjustBadge(n)
{
 var badge = document.getElementById("notif-badge");
 if(!badge) return;
 var count = parseInt(badge.textContent) || 0;
 count -= n;
 if(count <= 0) badge.remove();
 else badge.textContent = count > 9 ? "9+" : String(count);
}

// Click su una notifica match: segna come vista e naviga
function dismissMatch(e, el)
{
 e.preventDefault();

 var matchId = el.dataset.matchId;
 var href    = el.href;

 // Rimuovi pallino + decrementa badge immediatamente
 el.querySelector(".notif-item-dot")?.remove();
 adjustBadge(1);

 // POST a notifications.php poi naviga
 var navigated = false;
 function go() { if(!navigated) { navigated = true; window.location.href = href; } }

 var xhr = new XMLHttpRequest();
 xhr.open("POST", "notifications.php");
 xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
 xhr.onreadystatechange = function() { if(xhr.readyState === 4) go(); };
 xhr.send("action=seen_match&match_id=" + encodeURIComponent(matchId));
 setTimeout(go, 1000);// fallback se XHR è lento
}

// Click su notifica messaggi: rimuovi pallino, decrementa badge e lascia navigare
function dismissMsgs(el)
{
 var count = parseInt(el.dataset.msgCount) || 0;
 el.querySelector(".notif-item-dot")?.remove();
 adjustBadge(count);
 // La navigazione avviene naturalmente tramite href
}
</script>
