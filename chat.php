<?php
require_once 'config.php';
requireLogin();

$db = getDB();
$currentUser = currentUser();
$currentUserId = new MongoDB\BSON\ObjectId($_SESSION['user_id']);
$error = '';

// Match reciproco calcolato direttamente da `interactions`:
// - io ho messo like a X
// - X ha messo like a me
$likedCursor = $db->interactions->find([
    'from_user_id' => $currentUserId,
    'action' => 'like',
]);
$likedUserIds = [];
foreach ($likedCursor as $doc) {
    if (isset($doc->to_user_id)) {
        $likedUserIds[] = $doc->to_user_id;
    }
}

$likedByString = [];
foreach ($likedUserIds as $uid) {
    $likedByString[(string)$uid] = $uid;
}
$likedUserIds = array_values($likedByString);

$matchedUserIds = [];
if (!empty($likedUserIds)) {
    $likedBackCursor = $db->interactions->find([
        'from_user_id' => ['$in' => $likedUserIds],
        'to_user_id' => $currentUserId,
        'action' => 'like',
    ]);

    foreach ($likedBackCursor as $doc) {
        if (isset($doc->from_user_id)) {
            $matchedUserIds[] = $doc->from_user_id;
        }
    }
}

$matchedByString = [];
foreach ($matchedUserIds as $uid) {
    $matchedByString[(string)$uid] = $uid;
}
$matchedUserIds = array_values($matchedByString);

$matchedUsers = [];
if (!empty($matchedUserIds)) {
    $usersCursor = $db->users->find(['_id' => ['$in' => $matchedUserIds]]);
    foreach ($usersCursor as $u) {
        $matchedUsers[(string)$u->_id] = $u;
    }
}

$selectedChatId = $_GET['chat'] ?? '';
$selectedUser = null;
if ($selectedChatId && isset($matchedUsers[$selectedChatId])) {
    $selectedUser = $matchedUsers[$selectedChatId];
} elseif (!empty($matchedUsers)) {
    $firstKey = array_key_first($matchedUsers);
    $selectedUser = $matchedUsers[$firstKey];
    $selectedChatId = $firstKey;
}

// Gestione invio messaggio (sia POST normale che XHR)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipientIdStr = $_POST['recipient_id'] ?? '';
    $text = trim($_POST['message'] ?? '');

    if (!$recipientIdStr || !isset($matchedUsers[$recipientIdStr])) {
        $error = 'Chat non valida.';
    } elseif ($text === '') {
        $error = 'Scrivi un messaggio prima di inviare.';
    } else {
        try {
            $recipientId = new MongoDB\BSON\ObjectId($recipientIdStr);
            $db->messages->insertOne([
                'from_user_id' => $currentUserId,
                'to_user_id' => $recipientId,
                'text' => $text,
                'created_at' => new MongoDB\BSON\UTCDateTime(),
                'read' => false
            ]);
            
            // Se è una richiesta XHR, restituisci JSON
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                echo json_encode(['success' => true, 'message' => 'Messaggio inviato']);
                exit;
            }
            
            // Altrimenti redirect normale
            header('Location: chat.php?chat=' . urlencode($recipientIdStr));
            exit;
        } catch (Exception $e) {
            $error = 'Invio messaggio fallito.';
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                echo json_encode(['success' => false, 'error' => $error]);
                exit;
            }
        }
    }
}

// Se è una richiesta XHR per nuovi messaggi
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && $selectedUser) {
    $selectedId = new MongoDB\BSON\ObjectId((string)$selectedUser->_id);
    
    // Segna come letti i messaggi ricevuti
    $db->messages->updateMany(
        [
            'from_user_id' => $selectedId,
            'to_user_id' => $currentUserId,
            'read' => false
        ],
        ['$set' => ['read' => true]]
    );
    
    $messagesCursor = $db->messages->find(
        [
            '$or' => [
                ['from_user_id' => $currentUserId, 'to_user_id' => $selectedId],
                ['from_user_id' => $selectedId, 'to_user_id' => $currentUserId],
            ],
        ],
        ['sort' => ['created_at' => 1], 'limit' => 200]
    );
    $messages = iterator_to_array($messagesCursor, false);
    
    // Restituisci solo l'HTML dei messaggi
    foreach ($messages as $msg) {
        $isSent = ((string)$msg->from_user_id === (string)$currentUserId);
        ?>
        <div class="message <?= $isSent ? 'sent' : 'received' ?>">
            <div class="message-bubble"><?= nl2br(htmlspecialchars($msg->text ?? '')) ?></div>
            <?php if ($isSent): ?>
            <div class="message-status">
                <?php if (isset($msg->read) && $msg->read): ?>
                <span class="read-status">✓✓ Letto</span>
                <?php else: ?>
                <span class="delivered-status">✓✓ Consegnato</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    exit;
}

$messages = [];
if ($selectedUser) {
    $selectedId = new MongoDB\BSON\ObjectId((string)$selectedUser->_id);
    
    // Segna come letti i messaggi ricevuti quando si apre la chat
    $db->messages->updateMany(
        [
            'from_user_id' => $selectedId,
            'to_user_id' => $currentUserId,
            'read' => false
        ],
        ['$set' => ['read' => true]]
    );
    
    $messagesCursor = $db->messages->find(
        [
            '$or' => [
                ['from_user_id' => $currentUserId, 'to_user_id' => $selectedId],
                ['from_user_id' => $selectedId, 'to_user_id' => $currentUserId],
            ],
        ],
        ['sort' => ['created_at' => 1], 'limit' => 200]
    );
    $messages = iterator_to_array($messagesCursor, false);
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
/* Stati dei messaggi - SENZA ANIMAZIONI */
.message-status {
    font-size: 0.65rem;
    margin-top: 0.25rem;
    text-align: right;
}

.read-status {
    color: var(--coral);
}

.delivered-status {
    color: var(--success);
}

.message.received .message-status {
    text-align: left;
}

/* Stile per input */
#message-input {
    flex: 1;
}

/* Nessuna animazione sui messaggi */
.message {
    max-width: 65%;
}
</style>
</head>
<body>
<div class="chat-layout">
  <aside class="chat-sidebar">
    <div class="chat-sidebar-header">
      <h3>I tuoi match</h3>
      <p class="text-muted mt-1" style="font-size:0.85rem">
        <?= htmlspecialchars($currentUser->name ?? 'Utente') ?>, qui trovi chi ha messo cuore reciproco.
      </p>
      <div class="mt-2">
        <a href="discover.php" class="btn btn-sm btn-ghost">Torna a Discover</a>
      </div>
    </div>

    <?php if (count($matchedUsers) === 0): ?>
    <div class="chat-empty">
      <div style="font-size:2rem">💔</div>
      <p>Nessun match ancora</p>
    </div>
    <?php else: ?>
      <?php foreach ($matchedUsers as $uid => $matchUser): ?>
      <a class="chat-contact <?= ($selectedUser && (string)$selectedUser->_id === $uid) ? 'active' : '' ?>" href="chat.php?chat=<?= urlencode($uid) ?>">
        <div class="chat-contact-avatar">
          <?php if (!empty($matchUser->profile_image)): ?>
          <img class="avatar-image" src="<?= htmlspecialchars($matchUser->profile_image) ?>" alt="Foto di <?= htmlspecialchars($matchUser->name ?? 'Utente') ?>">
          <?php else: ?>
          👤
          <?php endif; ?>
        </div>
        <div class="chat-contact-info">
          <div class="chat-contact-name"><?= htmlspecialchars($matchUser->name ?? 'Utente') ?></div>
          <div class="chat-contact-last">
            <?= htmlspecialchars($matchUser->city ?? 'Apri chat per iniziare a parlare') ?>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </aside>

  <section class="chat-main">
    <?php if ($selectedUser): ?>
    <div class="chat-header">
      <div class="chat-contact-avatar">
        <?php if (!empty($selectedUser->profile_image)): ?>
        <img class="avatar-image" src="<?= htmlspecialchars($selectedUser->profile_image) ?>" alt="Foto di <?= htmlspecialchars($selectedUser->name ?? 'Utente') ?>">
        <?php else: ?>
        👤
        <?php endif; ?>
      </div>
      <div>
        <div class="chat-contact-name"><?= htmlspecialchars($selectedUser->name ?? 'Utente') ?></div>
        <div class="chat-contact-last"><?= htmlspecialchars($selectedUser->city ?? 'Online di recente') ?></div>
      </div>
    </div>

    <!-- Area messaggi con ID per XHR -->
    <div class="messages-area" id="messages-area">
      <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if (count($messages) === 0): ?>
      <div class="chat-empty">
        <div style="font-size:2rem">💬</div>
        <p>Inizia la conversazione!</p>
      </div>
      <?php else: ?>
        <?php foreach ($messages as $msg): ?>
        <?php $isSent = ((string)$msg->from_user_id === (string)$currentUserId); ?>
        <div class="message <?= $isSent ? 'sent' : 'received' ?>">
          <div class="message-bubble"><?= nl2br(htmlspecialchars($msg->text ?? '')) ?></div>
          <?php if ($isSent): ?>
          <div class="message-status">
            <?php if (isset($msg->read) && $msg->read): ?>
            <span class="read-status">✓✓ Letto</span>
            <?php else: ?>
            <span class="delivered-status">✓✓ Consegnato</span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Form con ID per XHR -->
    <form class="chat-input-area" method="POST" id="chat-form">
      <input type="hidden" name="recipient_id" value="<?= (string)$selectedUser->_id ?>">
      <input type="text" name="message" id="message-input" placeholder="Scrivi un messaggio..." maxlength="1000" required autocomplete="off">
      <button type="submit" class="btn btn-primary">Invia</button>
    </form>
    <?php else: ?>
    <div class="chat-empty">
      <div style="font-size:2rem">❤️</div>
      <h3>Seleziona un match</h3>
      <p>Apri una chat dalla colonna di sinistra.</p>
    </div>
    <?php endif; ?>
  </section>
</div>

<!-- SCRIPT XHR ESSENZIALE -->
<script>
// XHR per inviare messaggi senza ricaricare la pagina
const chatForm = document.getElementById('chat-form');
const messagesArea = document.getElementById('messages-area');
const messageInput = document.getElementById('message-input');

// Funzione per scrollare in fondo
function scrollToBottom() {
    if (messagesArea) {
        messagesArea.scrollTop = messagesArea.scrollHeight;
    }
}

// Carica i messaggi via AJAX
async function loadMessages() {
    if (!messagesArea) return;
    
    try {
        const response = await fetch('chat.php?chat=<?= $selectedChatId ?>&ajax=1');
        const html = await response.text();
        messagesArea.innerHTML = html;
        scrollToBottom();
    } catch (error) {
        console.error('Errore caricamento messaggi:', error);
    }
}

if (chatForm) {
    chatForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const formData = new FormData(chatForm);
        const message = formData.get('message');
        
        if (!message.trim()) return;
        
        const submitBtn = chatForm.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Invio...';
        }
        
        try {
            const response = await fetch('chat.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                messageInput.value = '';
                await loadMessages();
            } else {
                alert(result.error || 'Errore durante l\'invio');
            }
        } catch (error) {
            console.error('Errore:', error);
            alert('Errore di connessione');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Invia';
            }
            messageInput.focus();
        }
    });
}

// Scroll in fondo al caricamento
if (messagesArea) {
    scrollToBottom();
}

// Ricarica i messaggi ogni 5 secondi
let refreshInterval = setInterval(() => {
    if (document.hasFocus() && messagesArea) {
        loadMessages();
    }
}, 1000);

window.addEventListener('beforeunload', () => {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
});

if (messageInput) {
    messageInput.focus();
}
</script>

</body>
</html>