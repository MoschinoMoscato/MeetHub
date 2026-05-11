<?php
require_once 'config.php';
requireLogin();

$db = getDB();
$dbName = MONGO_DB;

// ── Handle delete actions (AJAX POST) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action         = $_POST['action']     ?? '';
    $collectionName = $_POST['collection'] ?? '';

    if (empty($collectionName) || !preg_match('/^[a-zA-Z0-9_\-\.]+$/', $collectionName)) {
        echo json_encode(['success' => false, 'error' => 'Nome collection non valido']);
        exit;
    }

    try {
        if ($action === 'delete_document') {
            $docId      = $_POST['doc_id'] ?? '';
            if (empty($docId)) { echo json_encode(['success' => false, 'error' => 'ID mancante']); exit; }
            $collection = $db->selectCollection($collectionName);
            $result     = $collection->deleteOne(['_id' => new MongoDB\BSON\ObjectId($docId)]);
            echo json_encode(['success' => true, 'deleted' => $result->getDeletedCount()]);

        } elseif ($action === 'clear_collection') {
            $collection = $db->selectCollection($collectionName);
            $result     = $collection->deleteMany([]);
            echo json_encode(['success' => true, 'deleted' => $result->getDeletedCount()]);

        } elseif ($action === 'delete_collection') {
            $db->selectCollection($collectionName)->drop();
            echo json_encode(['success' => true]);

        } else {
            echo json_encode(['success' => false, 'error' => 'Azione sconosciuta']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Normal GET: render page ───────────────────────────────────────────────────
$collections = $db->listCollections();

function bsonToArray($value) {
    if ($value instanceof MongoDB\Model\BSONDocument || $value instanceof MongoDB\Model\BSONArray) {
        return $value->getArrayCopy();
    }
    if ($value instanceof MongoDB\BSON\ObjectId) {
        return (string)$value;
    }
    if ($value instanceof MongoDB\BSON\UTCDateTime) {
        try { return $value->toDateTime()->format(DateTime::ATOM); }
        catch (Exception $e) { return (string)$value; }
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) { $out[$k] = bsonToArray($v); }
        return $out;
    }
    if (is_object($value)) {
        $out = [];
        foreach ((array)$value as $k => $v) { $out[$k] = bsonToArray($v); }
        return $out;
    }
    return $value;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MeetHub - Database Viewer</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="page-wrapper">
  <div class="account-layout" style="max-width:1200px;">

    <div class="card">
      <div class="flex justify-between items-center" style="flex-wrap:wrap; gap:1rem;">
        <div>
          <h2>Database Viewer</h2>
          <p class="text-muted mt-1">Database: <?= htmlspecialchars($dbName) ?></p>
        </div>
        <a class="btn btn-ghost btn-sm" href="discover.php">Torna a Discover</a>
      </div>
    </div>

    <?php foreach ($collections as $collectionInfo): ?>
    <?php
      $collectionName = $collectionInfo->getName();
      $collection     = $db->selectCollection($collectionName);
      $docs           = $collection->find();
      $count          = $collection->countDocuments();
      $safeCol        = htmlspecialchars($collectionName);
    ?>
    <div class="card mt-2" id="col-<?= $safeCol ?>">

      <div class="flex justify-between items-center" style="flex-wrap:wrap; gap:0.75rem;">
        <div>
          <h3><?= $safeCol ?></h3>
          <p class="text-muted mt-1" id="count-<?= $safeCol ?>">Documenti: <?= (int)$count ?></p>
        </div>
        <div class="flex gap-1" style="flex-wrap:wrap;">
          <button class="btn btn-ghost btn-sm"
            onclick="confirmAction('Svuota collection','Eliminare tutti i documenti da «<?= $safeCol ?>»? La collection resterà vuota.','clear_collection','<?= $safeCol ?>',null)">
            Svuota
          </button>
          <button class="btn btn-sm" style="color:#e05555; border-color:#e05555;"
            onclick="confirmAction('Elimina collection','Eliminare definitivamente la collection «<?= $safeCol ?>» e tutti i suoi documenti?','delete_collection','<?= $safeCol ?>',null)">
            Elimina collection
          </button>
        </div>
      </div>

      <div class="mt-2" style="display:grid; gap:0.75rem;" id="docs-<?= $safeCol ?>">
        <?php foreach ($docs as $doc): ?>
        <?php
          $normalized = bsonToArray($doc);
          $rawId      = $normalized['_id'] ?? '';
          $docId      = is_string($rawId) ? $rawId : '';
          $safeId     = htmlspecialchars($docId);
        ?>
        <div class="flex gap-1" style="align-items:flex-start;" id="doc-<?= $safeId ?>">
          <pre style="flex:1; margin:0; background:var(--deep2); border:1px solid var(--border); border-radius:12px; padding:0.9rem; overflow:auto; white-space:pre-wrap; word-break:break-word;"><?= htmlspecialchars(json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
          <?php if ($safeId): ?>
          <button class="btn btn-sm" style="flex-shrink:0; margin-top:0.4rem; color:#e05555; border-color:#e05555;"
            title="Elimina documento"
            onclick="confirmAction('Elimina documento','Eliminare il documento con _id <?= $safeId ?>?','delete_document','<?= $safeCol ?>','<?= $safeId ?>')">
            🗑
          </button>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

    </div>
    <?php endforeach; ?>

  </div>
</div>

<!-- Confirm modal (usa classi del tuo style.css dove possibile) -->
<div id="confirm-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:8888; align-items:center; justify-content:center;">
  <div class="card" style="max-width:420px; width:90%; text-align:center;">
    <h4 id="confirm-title" style="margin:0 0 0.5rem;"></h4>
    <p id="confirm-msg" class="text-muted" style="margin:0 0 1.5rem;"></p>
    <div class="flex gap-1" style="justify-content:center;">
      <button class="btn btn-sm" style="color:#e05555; border-color:#e05555;" id="confirm-ok">Conferma</button>
      <button class="btn btn-ghost btn-sm" onclick="closeConfirm()">Annulla</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div id="toast" style="position:fixed; bottom:1.5rem; right:1.5rem; padding:0.75rem 1.25rem; border-radius:10px; font-size:0.875rem; font-weight:600; color:#fff; opacity:0; pointer-events:none; transition:opacity 0.25s; z-index:9999;"></div>

<script>
  var _pending = null;

  function confirmAction(title, msg, action, collection, docId) {
    document.getElementById('confirm-title').textContent = title;
    document.getElementById('confirm-msg').textContent   = msg;
    _pending = { action, collection, docId };
    var o = document.getElementById('confirm-overlay');
    o.style.display = 'flex';
  }

  function closeConfirm() {
    document.getElementById('confirm-overlay').style.display = 'none';
    _pending = null;
  }

  document.getElementById('confirm-ok').addEventListener('click', function() {
    if (!_pending) return;
    var p = _pending;
    closeConfirm();
    runDelete(p.action, p.collection, p.docId);
  });

  document.getElementById('confirm-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeConfirm();
  });

  async function runDelete(action, collection, docId) {
    var body = new FormData();
    body.append('action',     action);
    body.append('collection', collection);
    if (docId) body.append('doc_id', docId);

    try {
      var res  = await fetch('database_viewer.php', { method: 'POST', body: body });
      var data = await res.json();

      if (!data.success) { showToast('Errore: ' + (data.error || 'sconosciuto'), '#dc2626'); return; }

      if (action === 'delete_document') {
        var el = document.getElementById('doc-' + docId);
        if (el) { el.style.transition = 'opacity 0.3s'; el.style.opacity = '0'; setTimeout(function(){ el.remove(); }, 300); }
        updateCount(collection, -1);
        showToast('Documento eliminato', '#16a34a');

      } else if (action === 'clear_collection') {
        document.getElementById('docs-' + collection).innerHTML = '';
        setCount(collection, 0);
        showToast('Collection svuotata', '#16a34a');

      } else if (action === 'delete_collection') {
        var card = document.getElementById('col-' + collection);
        if (card) { card.style.transition = 'opacity 0.3s'; card.style.opacity = '0'; setTimeout(function(){ card.remove(); }, 300); }
        showToast('Collection eliminata', '#16a34a');
      }

    } catch (err) {
      showToast('Errore di rete', '#dc2626');
    }
  }

  function updateCount(collection, delta) {
    var el = document.getElementById('count-' + collection);
    if (!el) return;
    var m = el.textContent.match(/\d+/);
    el.textContent = 'Documenti: ' + (m ? Math.max(0, parseInt(m[0]) + delta) : 0);
  }

  function setCount(collection, n) {
    var el = document.getElementById('count-' + collection);
    if (el) el.textContent = 'Documenti: ' + n;
  }

  var _toastTimer = null;
  function showToast(msg, color) {
    var t = document.getElementById('toast');
    t.textContent        = msg;
    t.style.background   = color;
    t.style.opacity      = '1';
    t.style.pointerEvents = 'auto';
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(function() {
      t.style.opacity      = '0';
      t.style.pointerEvents = 'none';
    }, 3000);
  }
</script>
</body>
</html>