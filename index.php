<?php
 require_once "config.php";
 requireGuest();
?>
<!DOCTYPE html>
<html lang="it">
 <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MeetHub – Trova il tuo prossimo grande amore</title>
  <link rel="stylesheet" href="style.css">
 </head>

 <body class="landing-body">

  <!-- ── HEADER ────────────────────────────────────────────────────────────── -->
  <header class="lp-header" id="lp-header">
   <a class="lp-logo" href="#">MeetHub</a>

   <nav class="lp-nav">
    <a href="#come-funziona">Come funziona</a>
    <a href="#perche-noi">Perché noi</a>
   </nav>

   <div class="lp-header-actions">
    <a href="login.php" class="btn btn-ghost btn-sm">Accedi</a>
    <a href="register.php" class="btn btn-primary btn-sm">Inizia gratis</a>
   </div>
  </header>

  <!-- ── HERO ──────────────────────────────────────────────────────────────── -->
  <section class="lp-hero">
   <div class="lp-hero-inner">

    <div class="lp-hero-left">
     <div class="lp-tag">BASTA APERITIVI DA SOLO</div>
     <h1 class="lp-hero-h1">
      Il tuo<br>
      prossimo<br>
      <span>grande amore</span><br>
      è qui.
     </h1>
     <p class="lp-hero-sub">
      MeetHub ti connette a persone reali che, come te, fanno finta
      di essere impegnate mentre controllano il telefono ogni tre minuti.
     </p>
     <div class="lp-hero-ctas">
      <a href="register.php" class="btn btn-primary btn-lg">Inizia gratis →</a>
      <a href="#come-funziona" class="btn btn-ghost btn-lg">Come funziona</a>
     </div>
    </div>

    <!-- Match animation widget -->
    <div class="lp-match-demo" aria-hidden="true">
     <div class="lp-ma-ring"></div>
     <div class="lp-ma-heartbg">❤️</div>

     <div class="lp-ma-profile lp-ma-a">
      <div class="lp-ma-av" style="background:linear-gradient(135deg,#FF4B6E,#F5A623)">S</div>
      <div class="lp-ma-name">Sofia, 26</div>
     </div>

     <div class="lp-ma-profile lp-ma-b">
      <div class="lp-ma-av" style="background:linear-gradient(135deg,#622CC6,#FF4B6E)">M</div>
      <div class="lp-ma-name">Marco, 28</div>
     </div>

     <div class="lp-ma-heart">❤️</div>
     <div class="lp-ma-label">È un match! 💕</div>

     <div class="lp-ma-particle" style="--px:80px;--py:-70px;--delay:0s">💕</div>
     <div class="lp-ma-particle" style="--px:-75px;--py:-80px;--delay:.08s">✨</div>
     <div class="lp-ma-particle" style="--px:90px;--py:60px;--delay:.05s">💖</div>
     <div class="lp-ma-particle" style="--px:-85px;--py:50px;--delay:.12s">⭐</div>
     <div class="lp-ma-particle" style="--px:20px;--py:-100px;--delay:.03s">💗</div>
     <div class="lp-ma-particle" style="--px:-30px;--py:95px;--delay:.1s">✨</div>
    </div>

   </div>
  </section>

  <!-- ── STATS ─────────────────────────────────────────────────────────────── -->
  <section class="lp-stats">
   <div class="lp-stat">
    <div class="lp-stat-num">4.7M</div>
    <div class="lp-stat-lbl">messaggi "Ciao" inviati<small>ma almeno ci hanno provato</small></div>
   </div>
   <div class="lp-stat">
    <div class="lp-stat-num">83%</div>
    <div class="lp-stat-lbl">degli utenti ha una foto col cane<small>quasi mai il loro</small></div>
   </div>
   <div class="lp-stat">
    <div class="lp-stat-num">2.1</div>
    <div class="lp-stat-lbl">match medi per utente<small>poi si arrendono e tornano su Netflix</small></div>
   </div>
   <div class="lp-stat">
    <div class="lp-stat-num">0</div>
    <div class="lp-stat-lbl">scuse per restare soli<small>ma ne troverai comunque</small></div>
   </div>
  </section>

  <!-- ── COME FUNZIONA ─────────────────────────────────────────────────────── -->
  <section class="lp-section" id="come-funziona">
   <div class="lp-section-inner">
    <div class="lp-tag">COME FUNZIONA</div>
    <h2 class="lp-h2">Tre passi verso la felicità.<br><span>O almeno verso un appuntamento decente.</span></h2>
    <div class="lp-steps">
     <div class="lp-step">
      <div class="lp-step-num">01</div>
      <h3>Crea il profilo</h3>
      <p>Scegli la foto migliore. Quella di 4 anni fa con la luce perfetta va benissimo. Nessuno lo saprà mai. Forse.</p>
     </div>
     <div class="lp-step">
      <div class="lp-step-num">02</div>
      <h3>Swipa</h3>
      <p>Destra o sinistra. La tua intera felicità futura dipende da un giudizio preso in 0,3 secondi. Nessuna pressione, davvero.</p>
     </div>
     <div class="lp-step">
      <div class="lp-step-num">03</div>
      <h3>Scrivi qualcosa</h3>
      <p>Sii originale. Non "Ciao". Non "Come stai?". Qualsiasi altra cosa nell'universo conosciuto. Ti imploriamo.</p>
     </div>
    </div>
   </div>
  </section>

  <!-- ── PERCHÉ NOI ─────────────────────────────────────────────────────────── -->
  <section class="lp-section lp-section--alt" id="perche-noi">
   <div class="lp-section-inner">
    <div class="lp-tag">PERCHÉ MEETHUB</div>
    <h2 class="lp-h2">Tutto quello che ti serve.<br><span>Quasi tutto.</span></h2>
    <div class="lp-features">
     <div class="lp-feature-card">
      <div class="lp-feature-icon">💘</div>
      <h3>Match garantiti*</h3>
      <p>Trovi persone compatibili in base agli interessi, alle preferenze e al livello di entusiasmo con cui scrolli il telefono la domenica sera.</p>
      <span class="lp-footnote">*Se abbassa gli standard quanto basta</span>
     </div>
     <div class="lp-feature-card">
      <div class="lp-feature-icon">💬</div>
      <h3>Chat riservata†</h3>
      <p>I tuoi messaggi imbarazzanti restano tra te e l'altra persona. I nostri server, invece... scherziamo. Probabilmente.</p>
      <span class="lp-footnote">†Con riserva di interpretazione</span>
     </div>
     <div class="lp-feature-card">
      <div class="lp-feature-icon">📍</div>
      <h3>Vicini a te</h3>
      <p>Troviamo persone nella tua zona. Abbastanza vicine per un caffè. Abbastanza lontane da avere una via di fuga dignitosa.</p>
     </div>
    </div>
   </div>
  </section>

  <!-- ── CTA FINALE ────────────────────────────────────────────────────────── -->
  <section class="lp-final-cta">
   <h2>Smettila di mangiare<br>la pizza da solo.</h2>
   <p>Unisciti a MeetHub. Crea connessioni vere, storie autentiche e, se tutto va bene, qualcuno che risponda entro 48 ore.</p>
   <a href="register.php" class="btn btn-primary btn-lg" style="margin-top:2.25rem">Inizia gratis — cosa hai da perdere?</a>
  </section>

  <!-- ── FOOTER ────────────────────────────────────────────────────────────── -->
  <footer class="lp-footer">
   <div class="lp-logo" style="font-size:1.1rem">MeetHub</div>
   <p>© 2026 MeetHub · Dove le storie d'amore iniziano (e a volte finiscono, ma è la vita)</p>
   <div class="lp-footer-links">
    <a href="#">Termini di servizio</a>
    <a href="#">Privacy Policy</a>
    <a href="#">Cookie</a>
   </div>
  </footer>

  <script>
   var hdr = document.getElementById("lp-header");
   window.addEventListener("scroll", function()
   {
    hdr.classList.toggle("scrolled", window.scrollY > 50);
   });
  </script>

 </body>
</html>
