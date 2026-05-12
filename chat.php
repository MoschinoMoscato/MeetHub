<?php
 require_once "config.php";
 requireLogin();

 $db              = getDB();
 $current_user    = currentUser();
 $current_user_id = new MongoDB\BSON\ObjectId($_SESSION["user_id"]);
 $error           = "";// Variabile per eventuali messaggi di errore

 // Calcolo dei match reciproci dalle interazioni:
 // - io ho messo like a X
 // - X ha messo like a me
 $liked_cursor  = $db->interactions->find(["from_user_id" => $current_user_id, "action" => "like"]);
 $liked_user_ids = [];

 foreach($liked_cursor as $doc)
 {
  if(isset($doc->to_user_id))
  {
   $liked_user_ids[] = $doc->to_user_id;
  }
 }

 // Deduplicazione degli ID
 $liked_by_string = [];
 foreach($liked_user_ids as $uid)
 {
  $liked_by_string[(string)$uid] = $uid;
 }
 $liked_user_ids = array_values($liked_by_string);

 // Trova chi ha messo like anche a me tra quelli che ho messo like
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
   if(isset($doc->from_user_id))
   {
    $matched_user_ids[] = $doc->from_user_id;
   }
  }
 }

 // Deduplicazione
 $matched_by_string = [];
 foreach($matched_user_ids as $uid)
 {
  $matched_by_string[(string)$uid] = $uid;
 }
 $matched_user_ids = array_values($matched_by_string);

 // Recupero dati degli utenti con cui ho fatto match
 $matched_users = [];

 if(!empty($matched_user_ids))
 {
  $users_cursor = $db->users->find(["_id" => ['$in' => $matched_user_ids]]);

  foreach($users_cursor as $u)
  {
   $matched_users[(string)$u->_id] = $u;
  }
 }

 // Determina la chat selezionata
 $selected_chat_id = $_GET["chat"] ?? "";
 $selected_user    = null;

 if($selected_chat_id && isset($matched_users[$selected_chat_id]))
 {
  $selected_user = $matched_users[$selected_chat_id];
 }
 elseif(!empty($matched_users))
 {
  $first_key     = array_key_first($matched_users);
  $selected_user = $matched_users[$first_key];
  $selected_chat_id = $first_key;
 }

 // Gestione invio messaggio (POST normale o XHR)
 if($_SERVER["REQUEST_METHOD"] === "POST")
 {
  $recipient_id_str = $_POST["recipient_id"] ?? "";
  $text             = trim($_POST["message"] ?? "");

  if(!$recipient_id_str || !isset($matched_users[$recipient_id_str]))
  {
   $error = "Chat non valida.";
  }
  elseif($text === "")
  {
   $error = "Scrivi un messaggio prima di inviare.";
  }
  else
  {
   try
   {
    $recipient_id = new MongoDB\BSON\ObjectId($recipient_id_str);

    $db->messages->insertOne(
    [
     "from_user_id" => $current_user_id,
     "to_user_id"   => $recipient_id,
     "text"         => $text,
     "created_at"   => new MongoDB\BSON\UTCDateTime(),
     "read"         => false
    ]);

    // Se è una richiesta XHR restituisco JSON
    if(isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest")
    {
     echo json_encode(["success" => true, "message" => "Messaggio inviato"]);
     exit;
    }

    // Altrimenti redirect normale
    header("Location: chat.php?chat=" . urlencode($recipient_id_str));
    exit;
   }
   catch(Exception $e)
   {
    $error = "Invio messaggio fallito.";

    if(isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest")
    {
     echo json_encode(["success" => false, "error" => $error]);
     exit;
    }
   }
  }
 }

 // Gestione richiesta AJAX per ricaricare i messaggi
 if(isset($_GET["ajax"]) && $_GET["ajax"] == 1 && $selected_user)
 {
  $selected_id = new MongoDB\BSON\ObjectId((string)$selected_user->_id);

  // Segna come letti i messaggi ricevuti
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

  // Restituisce solo l'HTML dei messaggi per l'AJAX
  foreach($messages as $msg)
  {
   $is_sent = ((string)$msg->from_user_id === (string)$current_user_id);
   ?>
   <div class="message <?= $is_sent ? "sent" : "received" ?>">
    <div class="message-bubble"><?= nl2br(htmlspecialchars($msg->text ?? "")) ?></div>
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
   <?php
  }
  exit;
 }

 // Caricamento dei messaggi della chat selezionata
 $messages = [];

 if($selected_user)
 {
  $selected_id = new MongoDB\BSON\ObjectId((string)$selected_user->_id);

  // Segna come letti i messaggi ricevuti all'apertura della chat
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
 }
?>

<!DOCTYPE html>

<html lang="it">
 <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MeetHub – Chat</title>
  <link rel="stylesheet" href="style.css">
  <style>
   .message-status    { font-size: 0.65rem; margin-top: 0.25rem; text-align: right; }
   .read-status       { color: var(--coral); }
   .delivered-status  { color: var(--success); }
   .message.received .message-status { text-align: left; }
   #message-input     { flex: 1; }
   .message           { max-width: 65%; }
  </style>
 </head>

 <body>
  <?php require "header.php"; ?>
  <div class="chat-layout">
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
      <a class="chat-contact <?= ($selected_user && (string)$selected_user->_id === $uid) ? "active" : "" ?>" href="chat.php?chat=<?= urlencode($uid) ?>">
       <div class="chat-contact-avatar">
        <?php if(!empty($match_user->profile_image)){ ?>
         <img class="avatar-image" src="<?= htmlspecialchars($match_user->profile_image) ?>" alt="Foto di <?= htmlspecialchars($match_user->name ?? "Utente") ?>">
        <?php } else { ?>
         <?= strtoupper(substr($match_user->name ?? "?", 0, 1)) ?>
        <?php } ?>
       </div>
       <div class="chat-contact-info">
        <div class="chat-contact-name"><?= htmlspecialchars($match_user->name ?? "Utente") ?></div>
        <div class="chat-contact-last">
         <?= htmlspecialchars($match_user->city ?? "Apri chat per iniziare a parlare") ?>
        </div>
       </div>
      </a>
     <?php } ?>
    <?php } ?>
   </aside>

   <section class="chat-main">
    <?php if($selected_user){ ?>
     <div class="chat-header">
      <div class="chat-contact-avatar">
       <?php if(!empty($selected_user->profile_image)){ ?>
        <img class="avatar-image" src="<?= htmlspecialchars($selected_user->profile_image) ?>" alt="Foto di <?= htmlspecialchars($selected_user->name ?? "Utente") ?>">
       <?php } else { ?>
        <?= strtoupper(substr($selected_user->name ?? "?", 0, 1)) ?>
       <?php } ?>
      </div>
      <div>
       <div class="chat-contact-name"><?= htmlspecialchars($selected_user->name ?? "Utente") ?></div>
       <div class="chat-contact-last"><?= htmlspecialchars($selected_user->city ?? "Online di recente") ?></div>
      </div>
     </div>

     <!--- Area messaggi con ID per l'aggiornamento AJAX --->
     <div class="messages-area" id="messages-area">
      <?php if($error !== ""){ ?>
       <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php } ?>

      <?php if(count($messages) === 0){ ?>
       <div class="chat-empty">
        <p>Inizia la conversazione!</p>
       </div>
      <?php } else { ?>
       <?php foreach($messages as $msg){ ?>
        <?php $is_sent = ((string)$msg->from_user_id === (string)$current_user_id); ?>
        <div class="message <?= $is_sent ? "sent" : "received" ?>">
         <div class="message-bubble"><?= nl2br(htmlspecialchars($msg->text ?? "")) ?></div>
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
       <?php } ?>
      <?php } ?>
     </div>

     <!--- Form di invio messaggio --->
     <form class="chat-input-area" method="POST" id="chat-form">
      <input type="hidden" name="recipient_id" value="<?= (string)$selected_user->_id ?>">
      <input type="text" name="message" id="message-input" placeholder="Scrivi un messaggio..." maxlength="1000" required autocomplete="off">
      <button type="submit" class="btn btn-primary">Invia</button>
     </form>
    <?php } else { ?>
     <div class="chat-empty">
      <h3>Seleziona un match</h3>
      <p>Apri una chat dalla colonna di sinistra.</p>
     </div>
    <?php } ?>
   </section>
  </div>

  <script>
   const chat_form     = document.getElementById("chat-form");
   const messages_area = document.getElementById("messages-area");
   const message_input = document.getElementById("message-input");

   let refresh_interval = null;

   // Sessione scaduta: ferma il polling e vai al login
   function handleSessionExpired()
   {
    if(refresh_interval) { clearInterval(refresh_interval); refresh_interval = null; }
    window.location.href = "index.php";
   }

   function scrollToBottom()
   {
    if(messages_area) messages_area.scrollTop = messages_area.scrollHeight;
   }

   function loadMessages()
   {
    if(!messages_area) return;

    var xhr = new XMLHttpRequest();
    xhr.open("GET", "chat.php?chat=<?= $selected_chat_id ?>&ajax=1");

    xhr.onreadystatechange = function()
    {
     if(xhr.readyState !== XMLHttpRequest.DONE) return;

     if(xhr.status === 401)
     {
      handleSessionExpired();
      return;
     }

     if(xhr.status === 200)
     {
      messages_area.innerHTML = xhr.responseText;
      scrollToBottom();
     }
    };

    xhr.send();
   }

   if(chat_form)
   {
    chat_form.addEventListener("submit", function(e)
    {
     e.preventDefault();

     var form_data = new FormData(chat_form);
     var message   = form_data.get("message");

     if(!message.trim()) return;

     var submit_btn = chat_form.querySelector("button[type='submit']");

     if(submit_btn)
     {
      submit_btn.disabled    = true;
      submit_btn.textContent = "Invio...";
     }

     var xhr = new XMLHttpRequest();
     xhr.open("POST", "chat.php");
     xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

     xhr.onreadystatechange = function()
     {
      if(xhr.readyState !== XMLHttpRequest.DONE) return;

      if(xhr.status === 401)
      {
       handleSessionExpired();
       return;
      }

      if(submit_btn)
      {
       submit_btn.disabled    = false;
       submit_btn.textContent = "Invia";
      }
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
        else
        {
         alert(result.error || "Errore durante l'invio");
        }
       }
       catch(e)
       {
        alert("Errore di comunicazione");
       }
      }
      else
      {
       console.error("Errore:", xhr.status);
       alert("Errore di connessione");
      }
     };

     xhr.onerror = function()
     {
      if(submit_btn)
      {
       submit_btn.disabled    = false;
       submit_btn.textContent = "Invia";
      }
      message_input.focus();
      alert("Errore di connessione");
     };

     xhr.send(form_data);
    });
   }

   if(messages_area) scrollToBottom();

   refresh_interval = setInterval(function()
   {
    if(document.hasFocus() && messages_area) loadMessages();
   }, 1000);

   window.addEventListener("beforeunload", function()
   {
    if(refresh_interval) clearInterval(refresh_interval);
   });

   if(message_input) message_input.focus();
  </script>
 </body>
</html>
