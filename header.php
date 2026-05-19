<?php
 $current_page        = trim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
 $current_user_header = currentUser();
 $header_avatar       = profileImageUrl($current_user_header);
 $header_name         = $current_user_header->name ?? "";

 $notif_message_items = [];
 $notif_match_items   = [];

 if($current_user_header)
 {
  $cur_id = $current_user_header->_id;
  $db_h   = getDB();

  // Messaggi non letti raggruppati per mittente
  try
  {
   $pipeline = [
    ['$match' => ["to_user_id" => $cur_id, "read" => false]],
    ['$group' => ["_id" => '$from_user_id', "count" => ['$sum' => 1]]],
    ['$sort'  => ["count" => -1]],
    ['$limit' => 5]
   ];

   foreach($db_h->messages->aggregate($pipeline) as $row)
   {
    $sender = $db_h->users->findOne(["_id" => $row->_id], ["projection" => ["name" => 1, "profile_image" => 1, "profile_image_id" => 1]]);
    if($sender)
    {
     $notif_message_items[] =
     [
      "id"    => (string)$row->_id,
      "name"  => (string)($sender->name ?? "?"),
      "img"   => profileImageUrl($sender),
      "count" => (int)$row->count
     ];
    }
   }
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
     $other = $db_h->users->findOne(["_id" => $other_id], ["projection" => ["name" => 1, "profile_image" => 1, "profile_image_id" => 1]]);
     if($other)
     {
      $notif_match_items[] =
      [
       "name"     => (string)($other->name ?? "?"),
       "img"      => profileImageUrl($other),
       "id"       => (string)$other_id,
       "match_id" => (string)$m->_id
      ];
     }
    }
   }
  }
  catch(Throwable $e) {}
 }

 $notif_msg_total = array_sum(array_column($notif_message_items, "count"));
 $notif_total     = $notif_msg_total + count($notif_match_items);
?>
<header class="app-header">
 <div class="app-header-inner">

  <a class="app-header-logo" href="/discover">MeetHub</a>

  <nav class="app-header-nav">
   <a class="app-nav-link <?= $current_page === "discover" ? "active" : "" ?>" href="/discover">Scopri</a>
   <a class="app-nav-link <?= $current_page === "chat"     ? "active" : "" ?>" href="/chat">Chat</a>
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
          href="/chat?chat=<?= htmlspecialchars($mi["id"]) ?>"
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

     <?php if(count($notif_message_items) > 0){ ?>
      <div class="notif-section-label">Messaggi</div>
      <?php foreach($notif_message_items as $mi){ ?>
       <a class="notif-item"
          href="/chat?chat=<?= htmlspecialchars($mi["id"]) ?>"
          data-sender-id="<?= htmlspecialchars($mi["id"]) ?>"
          data-msg-count="<?= $mi["count"] ?>"
          onclick="dismissMsgs(this)">
        <div class="notif-item-avatar">
         <?php if($mi["img"]){ ?>
          <img src="<?= htmlspecialchars($mi["img"]) ?>" alt="">
         <?php } else { ?>
          <?= strtoupper(substr($mi["name"], 0, 1)) ?>
         <?php } ?>
        </div>
        <div class="notif-item-body">
         <strong><?= htmlspecialchars($mi["name"]) ?></strong>
         <span><?= $mi["count"] ?> messagg<?= $mi["count"] === 1 ? "io" : "i" ?> non lett<?= $mi["count"] === 1 ? "o" : "i" ?></span>
        </div>
        <div class="notif-item-dot"></div>
       </a>
      <?php } ?>
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
     <a class="app-dropdown-item" href="/profile">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
      Profilo
     </a>
     <a class="app-dropdown-item" href="/settings">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      Impostazioni
     </a>
     <div class="app-dropdown-divider"></div>
     <a class="app-dropdown-item app-dropdown-item--danger" href="/discover?logout=1">
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

// IDs dei match già cliccati questa sessione (non rimostrare nel pannello)
var _dismissed_match_ids = {};

// IDs dei mittenti messaggi già cliccati questa sessione
var _dismissed_msg_senders = {};

// Timestamp dell'ultimo dismiss — evita che il polling sovrascriva il badge subito dopo
var _notif_last_dismiss = 0;

// Decrementa il badge globale di `n` unità
function adjustBadge(n)
{
 _notif_last_dismiss = Date.now();
 var badge = document.getElementById("notif-badge");
 if(!badge) return;
 var count = parseInt(badge.textContent) || 0;
 count -= n;
 if(count <= 0) badge.remove();
 else badge.textContent = count > 9 ? "9+" : String(count);
}

// ── Notifiche: funzioni globali (usate anche da discover.php) ────────────────

function _notifEsc(s)
{
 return String(s || "")
  .replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
}

function syncBadge(total)
{
  var btn   = document.getElementById("notif-btn");
  var badge = document.getElementById("notif-badge");

  if(total > 0)
  {
   var label = total > 9 ? "9+" : String(total);
   if(badge) { badge.textContent = label; }
   else if(btn)
   {
    var b = document.createElement("span");
    b.className   = "app-notif-badge";
    b.id          = "notif-badge";
    b.textContent = label;
    btn.appendChild(b);
   }
  }
  else if(badge) { badge.remove(); }
 }

 function syncPanel(data)
 {
  var panel = document.getElementById("notif-panel");
  if(!panel) return;

  // Filtra i match già cliccati in questa sessione
  var matches = (data.match_items || []).filter(function(m)
  {
   return !_dismissed_match_ids[m.match_id];
  });

  // Filtra i mittenti già cliccati in questa sessione
  var msgItems = (data.message_items || []).filter(function(mi)
  {
   return !_dismissed_msg_senders[mi.id];
  });

  var total = msgItems.reduce(function(s, mi) { return s + mi.count; }, 0) + matches.length;

  // Ricostruisce il contenuto dopo il titolo
  var title = panel.querySelector(".notif-panel-title");
  while(panel.lastChild && panel.lastChild !== title)
   panel.removeChild(panel.lastChild);

  if(total === 0)
  {
   var empty = document.createElement("div");
   empty.className = "notif-empty";
   empty.innerHTML = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>Nessuna nuova notifica';
   panel.appendChild(empty);
   return;
  }

  if(matches.length > 0)
  {
   var lbl = document.createElement("div");
   lbl.className = "notif-section-label";
   lbl.textContent = "Match recenti";
   panel.appendChild(lbl);

   matches.forEach(function(mi)
   {
    var a = document.createElement("a");
    a.className = "notif-item";
    a.href = "/chat?chat=" + encodeURIComponent(mi.id);
    a.dataset.matchId = mi.match_id;
    a.addEventListener("click", function(e) { dismissMatch(e, this); });

    var av = document.createElement("div");
    av.className = "notif-item-avatar";
    if(mi.img)
    {
     var img = document.createElement("img");
     img.src = mi.img; img.alt = "";
     av.appendChild(img);
    }
    else { av.textContent = (mi.name || "?").charAt(0).toUpperCase(); }

    var body = document.createElement("div");
    body.className = "notif-item-body";
    body.innerHTML = "<strong>" + _notifEsc(mi.name) + "</strong><span>Nuovo match! 🎉</span>";

    var dot = document.createElement("div");
    dot.className = "notif-item-dot";

    a.appendChild(av); a.appendChild(body); a.appendChild(dot);
    panel.appendChild(a);
   });
  }

  if(msgItems.length > 0)
  {
   var mlbl = document.createElement("div");
   mlbl.className = "notif-section-label";
   mlbl.textContent = "Messaggi";
   panel.appendChild(mlbl);

   msgItems.forEach(function(mi)
   {
    var ma = document.createElement("a");
    ma.className = "notif-item";
    ma.href = "/chat?chat=" + encodeURIComponent(mi.id);
    ma.dataset.senderId = mi.id;
    ma.dataset.msgCount = mi.count;
    ma.addEventListener("click", function() { dismissMsgs(this); });

    var mav = document.createElement("div");
    mav.className = "notif-item-avatar";
    if(mi.img)
    {
     var mimg = document.createElement("img");
     mimg.src = mi.img; mimg.alt = "";
     mav.appendChild(mimg);
    }
    else { mav.textContent = (mi.name || "?").charAt(0).toUpperCase(); }

    var mbody = document.createElement("div");
    mbody.className = "notif-item-body";
    mbody.innerHTML = "<strong>" + _notifEsc(mi.name) + "</strong><span>" + mi.count + " messagg" + (mi.count === 1 ? "io" : "i") + " non lett" + (mi.count === 1 ? "o" : "i") + "</span>";

    var mdot = document.createElement("div");
    mdot.className = "notif-item-dot";

    ma.appendChild(mav); ma.appendChild(mbody); ma.appendChild(mdot);
    panel.appendChild(ma);
   });
  }
 }

 function pollNotifications()
 {
  // Aspetta 8 s dopo un dismiss manuale per non sovrascrivere il badge
  if(Date.now() - _notif_last_dismiss < 8000) return;

  var xhr = new XMLHttpRequest();
  xhr.open("GET", "api/notifications.php");
  xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

  xhr.onreadystatechange = function()
  {
   if(xhr.readyState !== XMLHttpRequest.DONE) return;
   if(xhr.status !== 200) return;
   try
   {
    var r = JSON.parse(xhr.responseText);
    if(r.success) { syncBadge(r.total); syncPanel(r); }
   }
   catch(e) {}
  };

  xhr.send();
 }

 setInterval(pollNotifications, 5000);

// Apre il pannello notifiche aggiornandolo prima (chiamabile da altre pagine)
function openNotifPanel()
{
 var panel = document.getElementById("notif-panel");
 var btn   = document.getElementById("notif-btn");
 if(!panel) return;

 var xhr = new XMLHttpRequest();
 xhr.open("GET", "api/notifications.php");
 xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

 xhr.onreadystatechange = function()
 {
  if(xhr.readyState !== XMLHttpRequest.DONE) return;
  try
  {
   if(xhr.status === 200)
   {
    var r = JSON.parse(xhr.responseText);
    if(r.success) { syncBadge(r.total); syncPanel(r); }
   }
  }
  catch(e) {}
  panel.classList.add("open");
  if(btn) btn.classList.add("open");
 };

 xhr.send();
}

// Click su una notifica match: segna come vista e naviga
function dismissMatch(e, el)
{
 e.preventDefault();

 var matchId = el.dataset.matchId;
 var href    = el.href;

 // Segna localmente come già visto (il panel non lo rimostra)
 _dismissed_match_ids[matchId] = true;

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

// Click su notifica messaggi: segna mittente come visto, decrementa badge e lascia navigare
function dismissMsgs(el)
{
 var senderId = el.dataset.senderId;
 var count    = parseInt(el.dataset.msgCount) || 0;
 if(senderId) _dismissed_msg_senders[senderId] = true;
 el.querySelector(".notif-item-dot")?.remove();
 adjustBadge(count);
 // La navigazione avviene naturalmente tramite href
}
</script>
