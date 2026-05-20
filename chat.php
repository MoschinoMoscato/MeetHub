<?php
 require_once "config.php";
 requireLogin();

 $db              = getDB();
 $current_user    = currentUser();
 $current_user_id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);

 // Calcolo dei match reciproci
 $liked_cursor   = $db->interactions->find(["from_user_id" => $current_user_id, "action" => "like"]);
 $liked_user_ids = [];

 foreach($liked_cursor as $doc)
 {
  if(isset($doc->to_user_id)) $liked_user_ids[] = $doc->to_user_id;
 }

 // Deduplicazione
 $liked_by_string = [];
 foreach($liked_user_ids as $uid) $liked_by_string[(string)$uid] = $uid;
 $liked_user_ids = array_values($liked_by_string);

 $matched_user_ids = [];

 if(!empty($liked_user_ids))
 {
  $liked_back_cursor = $db->interactions->find(
  [
   "from_user_id" => ['$in' => $liked_user_ids],
   "to_user_id"   => $current_user_id,
   "action"       => "like"
  ]);

  foreach($liked_back_cursor as $doc)
  {
   if(isset($doc->from_user_id)) $matched_user_ids[] = $doc->from_user_id;
  }
 }

 $matched_by_string = [];
 foreach($matched_user_ids as $uid) $matched_by_string[(string)$uid] = $uid;
 $matched_user_ids = array_values($matched_by_string);

 $matched_users = [];

 if(!empty($matched_user_ids))
 {
  $users_cursor = $db->users->find(["_id" => ['$in' => $matched_user_ids]]);
  foreach($users_cursor as $u) $matched_users[(string)$u->_id] = $u;
 }

 $selected_chat_id = $_GET["chat"] ?? "";
 $has_chat         = isset($_GET["chat"]) && $_GET["chat"] !== "" ? "has-active-chat" : "";
 $selected_user    = null;

 if($selected_chat_id && isset($matched_users[$selected_chat_id]))
 {
  $selected_user = $matched_users[$selected_chat_id];
 }
 elseif(!empty($matched_users))
 {
  $first_key        = array_key_first($matched_users);
  $selected_user    = $matched_users[$first_key];
  $selected_chat_id = $first_key;
 }

 // Caricamento messaggi iniziali (server-side per il primo paint)
 $messages = [];

 if($selected_user)
 {
  $selected_id = new MongoDB\BSON\ObjectId((string)$selected_user->_id);

  $db->messages->updateMany(
   ["from_user_id" => $selected_id, "to_user_id" => $current_user_id, "read" => false],
   ['$set' => ["read" => true]]
  );

  $messages_cursor = $db->messages->find(
  [
   '$or' =>
   [
    ["from_user_id" => $current_user_id, "to_user_id" => $selected_id],
    ["from_user_id" => $selected_id,     "to_user_id" => $current_user_id]
   ]
  ],
  ["sort" => ["created_at" => 1], "limit" => 200]);

  $messages = iterator_to_array($messages_cursor, false);

  // Segna il match come visto (anche se l'utente ha aperto la chat manualmente)
  try
  {
   $db->matches->updateMany(
    [
     "users"   => ['$all' => [$current_user_id, $selected_id]],
     "seen_by" => ['$ne'  => $current_user_id]
    ],
    ['$addToSet' => ["seen_by" => $current_user_id]]
   );
  }
  catch(Throwable $e) {}
 }
?>

<!DOCTYPE html>

<html lang="it">
 <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MeetHub – Chat</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=1">
  <link rel="stylesheet" href="style.css?v=<?= filemtime('style.css') ?>">
  <style>
   .message-status    { font-size: 0.65rem; margin-top: 0.25rem; text-align: right; }
   .read-status       { color: var(--coral); }
   .delivered-status  { color: var(--success); }
   #message-input     { flex: 1; }
   .message           { max-width: 65%; }
  </style>
 </head>

 <body>
  <?php require "header.php"; ?>
  <div class="chat-layout <?= $has_chat ?>">

   <!-- Sidebar: lista match -->
   <aside class="chat-sidebar">
    <div class="chat-sidebar-header">
     <h3>I tuoi match</h3>
     <p class="text-muted mt-1" style="font-size:0.85rem">Conversazioni attive</p>
    </div>

    <?php if(count($matched_users) === 0){ ?>
     <div class="chat-empty">
      <p>Nessun match ancora</p>
     </div>
    <?php } else { ?>
     <?php foreach($matched_users as $uid => $match_user){ ?>
      <a class="chat-contact <?= ($selected_user && (string)$selected_user->_id === $uid) ? "active" : "" ?>" href="/chat?chat=<?= urlencode($uid) ?>">
       <div class="chat-contact-avatar">
        <?php $mu_img = profileImageUrl($match_user); if($mu_img){ ?>
         <img class="avatar-image" src="<?= htmlspecialchars($mu_img) ?>" alt="">
        <?php } else { ?>
         <?= strtoupper(substr($match_user->name ?? "?", 0, 1)) ?>
        <?php } ?>
       </div>
       <div class="chat-contact-info">
        <div class="chat-contact-name"><?= htmlspecialchars($match_user->name ?? "Utente") ?></div>
        <div class="chat-contact-last"><?= htmlspecialchars($match_user->city ?? "Apri chat per iniziare") ?></div>
       </div>
      </a>
     <?php } ?>
    <?php } ?>
   </aside>

   <!-- Area chat principale -->
   <section class="chat-main">
    <?php if($selected_user){ ?>

     <!-- Header: cliccabile per vedere il profilo -->
     <div class="chat-header chat-header--clickable" id="chat-header-btn" title="Visualizza profilo">
      <a class="chat-back-btn" href="/chat" title="Torna alla lista" onclick="event.stopPropagation()">&#8249;</a>
      <div class="chat-contact-avatar">
       <?php $su_img = profileImageUrl($selected_user); if($su_img){ ?>
        <img class="avatar-image" src="<?= htmlspecialchars($su_img) ?>" alt="">
       <?php } else { ?>
        <?= strtoupper(substr($selected_user->name ?? "?", 0, 1)) ?>
       <?php } ?>
      </div>
      <div style="flex:1; min-width:0">
       <div class="chat-contact-name"><?= htmlspecialchars($selected_user->name ?? "Utente") ?></div>
       <div class="chat-contact-last"><?= htmlspecialchars($selected_user->city ?? "") ?></div>
      </div>
      <div class="chat-header-info-icon">
       <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
       </svg>
      </div>
     </div>

     <!-- Area messaggi (server-side initial render) -->
     <div class="messages-area" id="messages-area">
      <?php if(count($messages) === 0){ ?>
       <div class="chat-empty"><p>Inizia la conversazione!</p></div>
      <?php } else { ?>
       <?php foreach($messages as $msg){ ?>
        <?php $is_sent = ((string)$msg->from_user_id === (string)$current_user_id); ?>
        <div class="message <?= $is_sent ? "sent" : "received" ?>" data-id="<?= (string)$msg->_id ?>">
         <?php if($is_sent){ ?>
          <div class="msg-actions">
           <?php if(($msg->type ?? "text") === "text"){ ?>
            <button class="msg-action-btn msg-edit-btn" onclick="editMessage('<?= (string)$msg->_id ?>', this)" title="Modifica">✏</button>
           <?php } ?>
           <button class="msg-action-btn msg-del-btn" onclick="deleteMessage('<?= (string)$msg->_id ?>', this.closest('.message'))" title="Elimina">×</button>
          </div>
         <?php } ?>
         <div class="msg-content">
          <?php
           $msg_img_url = null;
           if(($msg->type ?? "text") === "image")
           {
            if(isset($msg->image_id))                    $msg_img_url = "/api/uploads/" . (string)$msg->image_id;
            elseif(isset($msg->upload_id))               $msg_img_url = "/api/uploads/" . (string)$msg->upload_id;
            elseif(!empty($msg->image_path))             $msg_img_url = "/" . $msg->image_path;
           }
          ?>
          <?php if($msg_img_url){ ?>
           <div class="message-bubble message-bubble--img">
            <img class="chat-image" src="<?= htmlspecialchars($msg_img_url) ?>" alt="Immagine" loading="lazy">
           </div>
          <?php } else { ?>
           <div class="message-bubble"><?= nl2br(htmlspecialchars($msg->text ?? "")) ?></div>
           <?php if(!empty($msg->edited)){ ?><span class="msg-edited-tag">modificato</span><?php } ?>
          <?php } ?>
          <?php if($is_sent){ ?>
           <div class="message-status">
            <?php if(isset($msg->read) && $msg->read){ ?>
             <span class="read-status">✓✓ Letto</span>
            <?php } else { ?>
             <span class="delivered-status">✓✓ Consegnato</span>
            <?php } ?>
           </div>
          <?php } ?>
         </div>
        </div>
       <?php } ?>
      <?php } ?>
     </div>

     <!-- Anteprima immagine da inviare -->
     <div class="chat-img-preview" id="chat-img-preview">
      <img id="chat-img-thumb" src="" alt="Anteprima">
      <button type="button" class="chat-img-cancel" id="chat-img-cancel">×</button>
      <span class="chat-img-label">Immagine pronta — premi Invia</span>
     </div>

     <!-- Form di invio -->
     <form class="chat-input-area" id="chat-form" novalidate>
      <input type="hidden" name="recipient_id" value="<?= htmlspecialchars((string)$selected_user->_id) ?>">

      <label class="chat-attach-btn" for="chat-file-input" title="Allega immagine o scatta foto">
       <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
        <circle cx="8.5" cy="8.5" r="1.5"/>
        <polyline points="21 15 16 10 5 21"/>
       </svg>
      </label>
      <input type="file" id="chat-file-input" accept="image/*" style="display:none">

      <input type="text" name="message" id="message-input" placeholder="Scrivi un messaggio…" maxlength="1000" autocomplete="off">
      <button type="submit" class="btn btn-primary" id="send-btn">Invia</button>
     </form>

    <?php } else { ?>
     <div class="chat-empty">
      <h3>Seleziona un match</h3>
      <p>Apri una chat dalla colonna di sinistra.</p>
     </div>
    <?php } ?>
   </section>
  </div>

  <!-- Pannello profilo (slide-in da destra) -->
  <div class="user-profile-modal" id="user-profile-modal">
   <div class="user-profile-modal-backdrop" id="profile-modal-backdrop"></div>
   <div class="user-profile-modal-box" id="user-profile-box">
    <div class="upm-loading">Caricamento…</div>
   </div>
  </div>

  <script>
   const current_user_id = "<?= htmlspecialchars((string)$current_user_id) ?>";
   const recipient_id    = "<?= htmlspecialchars($selected_chat_id) ?>";

   const messages_area    = document.getElementById("messages-area");
   const chat_form        = document.getElementById("chat-form");
   const message_input    = document.getElementById("message-input");
   const send_btn         = document.getElementById("send-btn");
   const chat_file_input  = document.getElementById("chat-file-input");
   const img_preview      = document.getElementById("chat-img-preview");
   const img_thumb        = document.getElementById("chat-img-thumb");
   const img_cancel_btn   = document.getElementById("chat-img-cancel");
   const chat_header_btn  = document.getElementById("chat-header-btn");

   let refresh_interval = null;
   let pending_image    = null; // File selezionato ma non ancora inviato

   // ── Utilità ──────────────────────────────────────────────────────────────────

   function escapeHtml(str)
   {
    if(!str) return "";
    return String(str)
     .replace(/&/g,  "&amp;")
     .replace(/</g,  "&lt;")
     .replace(/>/g,  "&gt;")
     .replace(/"/g,  "&quot;")
     .replace(/'/g,  "&#039;");
   }

   function nl2brJs(str)
   {
    return str.replace(/\n/g, "<br>");
   }

   function scrollToBottom()
   {
    if(messages_area) messages_area.scrollTop = messages_area.scrollHeight;
   }

   function isNearBottom()
   {
    if(!messages_area) return true;
    return messages_area.scrollHeight - messages_area.scrollTop - messages_area.clientHeight < 120;
   }

   function handleSessionExpired()
   {
    if(refresh_interval) { clearInterval(refresh_interval); refresh_interval = null; }
    window.location.href = "/";
   }

   // ── Rendering messaggi da JSON ────────────────────────────────────────────────

   function renderMessages(messages)
   {
    if(!messages || messages.length === 0)
     return '<div class="chat-empty"><p>Inizia la conversazione!</p></div>';

    var html = "";

    for(var i = 0; i < messages.length; i++)
    {
     var msg     = messages[i];
     var is_sent = (msg.from === current_user_id);

     html += '<div class="message ' + (is_sent ? "sent" : "received") + '" data-id="' + escapeHtml(msg.id) + '">';

     if(is_sent)
     {
      html += '<div class="msg-actions">';
      if(msg.type !== "image")
       html += '<button class="msg-action-btn msg-edit-btn" onclick="editMessage(\'' + escapeHtml(msg.id) + '\', this)" title="Modifica">&#9999;</button>';
      html += '<button class="msg-action-btn msg-del-btn" onclick="deleteMessage(\'' + escapeHtml(msg.id) + '\', this.closest(\'.message\'))" title="Elimina">&#215;</button>';
      html += '</div>';
     }

     html += '<div class="msg-content">';

     if(msg.type === "image" && msg.image_url)
     {
      html += '<div class="message-bubble message-bubble--img">';
      html += '<img class="chat-image" src="' + escapeHtml(msg.image_url) + '" alt="Immagine" loading="lazy">';
      html += '</div>';
     }
     else
     {
      html += '<div class="message-bubble">' + nl2brJs(escapeHtml(msg.text || "")) + '</div>';
      if(msg.edited) html += '<span class="msg-edited-tag">modificato</span>';
     }

     if(is_sent)
     {
      html += '<div class="message-status">';
      html += msg.read
       ? '<span class="read-status">&#10003;&#10003; Letto</span>'
       : '<span class="delivered-status">&#10003;&#10003; Consegnato</span>';
      html += '</div>';
     }

     html += '</div>'; // .msg-content
     html += '</div>'; // .message
    }

    return html;
   }

   // ── Carica messaggi via REST API (GET) ────────────────────────────────────────

   function loadMessages()
   {
    if(!messages_area || !recipient_id) return;
    if(messages_area.querySelector(".msg-edit-input")) return;

    var xhr = new XMLHttpRequest();
    xhr.open("GET", "api/messages.php?conversation_with=" + encodeURIComponent(recipient_id));
    xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

    var nearBottom = isNearBottom();

    xhr.onreadystatechange = function()
    {
     if(xhr.readyState !== XMLHttpRequest.DONE) return;

     if(xhr.status === 401) { handleSessionExpired(); return; }

     if(xhr.status === 200)
     {
      try
      {
       var result = JSON.parse(xhr.responseText);
       if(result.success)
       {
        messages_area.innerHTML = renderMessages(result.messages);
        if(nearBottom) scrollToBottom();
       }
      }
      catch(e) {}
     }
    };

    xhr.send();
   }

   // ── Invia messaggio di testo via REST API (POST) ──────────────────────────────

   function sendTextMessage(text)
   {
    send_btn.disabled    = true;
    send_btn.textContent = "Invio…";

    var fd = new FormData();
    fd.append("recipient_id", recipient_id);
    fd.append("type", "text");
    fd.append("message", text);

    var xhr = new XMLHttpRequest();
    xhr.open("POST", "api/messages.php");
    xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

    xhr.onreadystatechange = function()
    {
     if(xhr.readyState !== XMLHttpRequest.DONE) return;

     if(xhr.status === 401) { handleSessionExpired(); return; }

     send_btn.disabled    = false;
     send_btn.textContent = "Invia";
     message_input.focus();

     if(xhr.status === 200)
     {
      try
      {
       var result = JSON.parse(xhr.responseText);
       if(result.success)
       {
        message_input.value = "";
        loadMessages();
       }
       else { alert(result.error || "Errore durante l'invio"); }
      }
      catch(e) { alert("Errore di comunicazione"); }
     }
     else { alert("Errore di connessione"); }
    };

    xhr.onerror = function()
    {
     send_btn.disabled    = false;
     send_btn.textContent = "Invia";
     message_input.focus();
     alert("Errore di connessione");
    };

    xhr.send(fd);
   }

   // ── Invia immagine via REST API (POST multipart) ──────────────────────────────

   function sendImageMessage(file)
   {
    send_btn.disabled    = true;
    send_btn.textContent = "Invio…";

    var fd = new FormData();
    fd.append("recipient_id", recipient_id);
    fd.append("type", "image");
    fd.append("image", file);

    var xhr = new XMLHttpRequest();
    xhr.open("POST", "api/messages.php");
    xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

    xhr.onreadystatechange = function()
    {
     if(xhr.readyState !== XMLHttpRequest.DONE) return;

     if(xhr.status === 401) { handleSessionExpired(); return; }

     send_btn.disabled    = false;
     send_btn.textContent = "Invia";
     message_input.focus();

     if(xhr.status === 200)
     {
      try
      {
       var result = JSON.parse(xhr.responseText);
       if(result.success) { clearImagePreview(); loadMessages(); }
       else { alert(result.error || "Errore durante il caricamento"); }
      }
      catch(e) { alert("Errore di comunicazione"); }
     }
     else { alert("Errore di connessione"); }
    };

    xhr.onerror = function()
    {
     send_btn.disabled    = false;
     send_btn.textContent = "Invia";
     alert("Errore di connessione");
    };

    xhr.send(fd);
   }

   // ── Elimina messaggio via REST API (DELETE) ───────────────────────────────────

   function deleteMessage(msg_id, el)
   {
    if(!confirm("Eliminare questo messaggio?")) return;

    var xhr = new XMLHttpRequest();
    xhr.open("DELETE", "api/messages.php?message_id=" + encodeURIComponent(msg_id));
    xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

    xhr.onreadystatechange = function()
    {
     if(xhr.readyState !== XMLHttpRequest.DONE) return;
     if(xhr.status === 401) { handleSessionExpired(); return; }
     if(xhr.status === 200) { try { var r = JSON.parse(xhr.responseText); if(r.success) el.remove(); } catch(e) {} }
    };

    xhr.send();
   }

   // ── Modifica messaggio via REST API (PUT) ────────────────────────────────────

   function editMessage(msg_id, btn)
   {
    var msg_el  = btn.closest(".message");
    var bubble  = msg_el.querySelector(".message-bubble");
    if(!bubble || bubble.querySelector(".msg-edit-input")) return;

    var original_html = bubble.innerHTML;
    var original_text = bubble.textContent.trim();

    var input         = document.createElement("input");
    input.type        = "text";
    input.className   = "msg-edit-input";
    input.value       = original_text;
    bubble.innerHTML  = "";
    bubble.appendChild(input);
    input.focus();
    input.select();

    var done = false;

    function cancel()
    {
     if(done) return;
     done = true;
     bubble.innerHTML = original_html;
    }

    function save()
    {
     if(done) return;
     var new_text = input.value.trim();
     if(!new_text || new_text === original_text) { cancel(); return; }
     done = true;

     var xhr = new XMLHttpRequest();
     xhr.open("PUT", "api/messages.php");
     xhr.setRequestHeader("Content-Type", "application/json");
     xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

     xhr.onreadystatechange = function()
     {
      if(xhr.readyState !== XMLHttpRequest.DONE) return;
      if(xhr.status === 401) { handleSessionExpired(); return; }

      if(xhr.status === 200)
      {
       try
       {
        var r = JSON.parse(xhr.responseText);
        if(r.success)
        {
         bubble.innerHTML = nl2brJs(escapeHtml(new_text));
         if(!msg_el.querySelector(".msg-edited-tag"))
         {
          var tag = document.createElement("span");
          tag.className   = "msg-edited-tag";
          tag.textContent = "modificato";
          bubble.insertAdjacentElement("afterend", tag);
         }
        }
        else { bubble.innerHTML = original_html; }
       }
       catch(e) { bubble.innerHTML = original_html; }
      }
      else { bubble.innerHTML = original_html; }
     };

     xhr.send(JSON.stringify({ message_id: msg_id, text: new_text }));
    }

    input.addEventListener("keydown", function(e)
    {
     if(e.key === "Enter")  { e.preventDefault(); save(); }
     if(e.key === "Escape") { cancel(); }
    });

    input.addEventListener("blur", function()
    {
     setTimeout(function() { cancel(); }, 200);
    });
   }

   // ── Gestione anteprima immagine ───────────────────────────────────────────────

   function clearImagePreview()
   {
    pending_image         = null;
    chat_file_input.value = "";
    if(img_thumb)   img_thumb.src = "";
    if(img_preview) img_preview.classList.remove("open");
    if(send_btn)    send_btn.textContent = "Invia";
   }

   if(chat_file_input)
   {
    chat_file_input.addEventListener("change", function()
    {
     var file = chat_file_input.files[0];
     if(!file) return;

     pending_image = file;

     var reader = new FileReader();
     reader.onload = function(e)
     {
      img_thumb.src = e.target.result;
      img_preview.classList.add("open");
      send_btn.textContent = "Invia foto";
      message_input.focus();
     };
     reader.readAsDataURL(file);
    });
   }

   if(img_cancel_btn)
   {
    img_cancel_btn.addEventListener("click", function() { clearImagePreview(); });
   }

   // ── Submit form ───────────────────────────────────────────────────────────────

   if(chat_form)
   {
    chat_form.addEventListener("submit", function(e)
    {
     e.preventDefault();
     if(!recipient_id) return;

     if(pending_image)
     {
      sendImageMessage(pending_image);
     }
     else
     {
      var text = message_input.value.trim();
      if(!text) return;
      sendTextMessage(text);
     }
    });
   }

   // ── Profilo: rendering e slide-in panel ───────────────────────────────────────

   function renderProfile(user)
   {
    var html = '<button class="upm-back-btn" onclick="closeProfile()">&#8249; Indietro</button>';

    html += '<div class="upm-photo' + (!user.profile_image_url ? " upm-photo-placeholder" : "") + '">';
    if(user.profile_image_url)
     html += '<img src="' + escapeHtml(user.profile_image_url) + '" alt="">';
    else
     html += escapeHtml((user.name || "?").charAt(0).toUpperCase());
    html += '</div>';

    var name_str = escapeHtml(user.name || "");
    if(user.age > 0) name_str += ", " + user.age;
    html += '<h2 class="upm-name">' + name_str + '</h2>';

    var facts = [];
    if(user.city)   facts.push("&#128205; " + escapeHtml(user.city));
    if(user.job)    facts.push("&#128188; " + escapeHtml(user.job));
    if(user.height) facts.push("&#128207; " + user.height + " cm");

    if(facts.length > 0)
    {
     html += '<div class="upm-facts">';
     for(var i = 0; i < facts.length; i++)
      html += '<span class="upm-fact">' + facts[i] + '</span>';
     html += '</div>';
    }

    if(user.bio)
     html += '<p class="upm-bio">' + nl2brJs(escapeHtml(user.bio)) + '</p>';

    if(user.interests && user.interests.length > 0)
    {
     html += '<div class="upm-section-title">Interessi</div><div class="upm-chips">';
     for(var i = 0; i < user.interests.length; i++)
      html += '<span class="upm-chip">' + escapeHtml(user.interests[i]) + '</span>';
     html += '</div>';
    }

    if(user.traits && user.traits.length > 0)
    {
     html += '<div class="upm-section-title">Personalità</div><div class="upm-chips">';
     for(var i = 0; i < user.traits.length; i++)
      html += '<span class="upm-chip">' + escapeHtml(user.traits[i]) + '</span>';
     html += '</div>';
    }

    return html;
   }

   function openProfile()
   {
    if(!recipient_id) return;

    var modal = document.getElementById("user-profile-modal");
    var box   = document.getElementById("user-profile-box");

    box.innerHTML = '<div class="upm-loading">Caricamento…</div>';
    modal.classList.add("open");

    var xhr = new XMLHttpRequest();
    // ✅ Chiama l'endpoint API corretto
    xhr.open("GET", "/api/users/" + encodeURIComponent(recipient_id));
    xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

    xhr.onreadystatechange = function()
    {
     if(xhr.readyState !== XMLHttpRequest.DONE) return;
     if(xhr.status === 401) { handleSessionExpired(); return; }

     if(xhr.status === 200)
     {
      try
      {
       var result = JSON.parse(xhr.responseText);
       if(result.success)
        box.innerHTML = renderProfile(result.data);  // ✅ Usa result.data
       else
        box.innerHTML = '<div class="upm-error">' + escapeHtml(result.error || "Errore") + '</div>';
      }
      catch(e)
      {
       box.innerHTML = '<div class="upm-error">Risposta non valida</div>';
      }
     }
     else
     {
      box.innerHTML = '<div class="upm-error">Errore ' + xhr.status + '</div>';
     }
    };

    xhr.onerror = function()
    {
     box.innerHTML = '<div class="upm-error">Errore di connessione</div>';
    };

    xhr.send();
   }

   function closeProfile()
   {
    document.getElementById("user-profile-modal").classList.remove("open");
   }

   if(chat_header_btn)  chat_header_btn.addEventListener("click", openProfile);
   if(document.getElementById("profile-modal-backdrop"))
    document.getElementById("profile-modal-backdrop").addEventListener("click", closeProfile);

   // ── Init ──────────────────────────────────────────────────────────────────────

   if(messages_area) scrollToBottom();

   refresh_interval = setInterval(function()
   {
    if(document.hasFocus() && messages_area) loadMessages();
   }, 1500);

   window.addEventListener("beforeunload", function()
   {
    if(refresh_interval) clearInterval(refresh_interval);
   });

   if(message_input) message_input.focus();
  </script>
 </body>
</html>
