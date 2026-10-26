// Kevando Gordon — terminal portfolio: The Developer Quest, contact form, typing & motion

(function () {
  'use strict';

  /* ---------------- The Developer Quest ---------------- */
  var stage = document.getElementById('q-stage');
  if (!stage) return;
  var $ = function (id) { return document.getElementById(id); };
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var LEVELS = [
    { x: 9, title: 'Who am I?', sign: 'who am i' },
    { x: 29.5, title: 'My tech stack', sign: 'tech stack' },
    { x: 50, title: "Projects I've built", sign: 'projects' },
    { x: 70.5, title: 'My experience', sign: 'experience' },
    { x: 91, title: 'Contact me', sign: 'contact' }
  ];
  var WHO = [
    ['name', 'Kevando Gordon'],
    ['class', 'Software Engineer — full-stack web & mobile'],
    ['home base', 'Jamaica, working with clients everywhere'],
    ['special move', 'requirements → design → build → deploy → support'],
    ['also offers', 'cybersecurity · AI consultation · data analysis']
  ];
  var GEMS = [
    ['Laravel', 'backend'], ['PHP', 'backend'], ['Python', 'backend'], ['Java', 'backend'], ['C#', 'backend'], ['C++', 'backend'],
    ['Vue.js', 'frontend'], ['React', 'frontend'], ['JavaScript', 'frontend'], ['HTML & CSS', 'frontend'],
    ['Flutter', 'mobile'], ['Ionic', 'mobile'], ['React Native', 'mobile'],
    ['WhatsApp integration', 'integrations'], ['REST APIs', 'integrations'], ['MySQL · SQL', 'data'],
    ['ERPNext · SuiteCRM', 'platforms'], ['Git & GitHub', 'tools']
  ];
  var CHESTS = [
    { title: 'JOAT', img: 'images/joat-web.jpg', text: 'Skilled-worker marketplace on web, iOS and Android.' },
    { title: 'iTeneri', img: 'images/iteneri.jpg', text: 'Event management end to end. I contributed to the build.', link: 'https://iteneri.com' },
    { title: 'Amber Car Rental', img: 'images/amber-car-rental.jpg', text: 'Listings, bookings and reservations. Team project.', link: 'https://car-rental-chi-six.vercel.app' },
    { title: 'ReliefLinkJA', img: 'images/relieflinkja.jpg', text: 'Hurricane Melissa relief platform.' }
  ];
  var LOG = [
    ['freelance', 'Full-stack developer for clients worldwide: custom web apps and business systems'],
    ['joat', 'Built JOAT, a marketplace on web, iOS and Android (Laravel, Vue, Ionic)'],
    ['iteneri', 'Contributed to iTeneri, an event management platform (Laravel, MySQL)'],
    ['amber', 'Amber Car Rental: team project (Laravel, Blade)'],
    ['integrate', 'Integrated ERPNext and SuiteCRM into business workflows'],
    ['whatsapp', 'Built WhatsApp integrations that plug messaging into business workflows'],
    ['cert', 'Earned the Foundational C# with Microsoft certification (freeCodeCamp)']
  ];
  var HASHES = ['3f9a1c2', 'b7e04d1', '9c2f8a0', '51d7e3b', 'e08c4f9', 'c41e8b6', 'a6b1d57'];
  var XP_MAX = 5 * 100 + GEMS.length * 10 + CHESTS.length * 25;
  var SPEED = 26; // % of the map per second

  var S;
  function fresh() {
    return { pos: 4, face: 1, unlocked: 0, done: [false, false, false, false, false], xp: 0, active: -1,
      gems: {}, chests: {}, logN: 0, whoTyped: false, left: false, right: false, target: null, arrive: null };
  }
  S = fresh();

  var hero = $('q-hero'), gate = $('q-gate'), bubble = $('q-bubble'), panel = $('q-panel');
  var enterBtn = $('q-enter');

  /* ----- map ----- */
  $('q-stations').innerHTML = LEVELS.map(function (l, i) {
    return '<button type="button" class="q-station" data-i="' + i + '" style="left:' + l.x + '%">' +
      '<span class="q-sign"><b>L' + (i + 1) + (i === 4 ? ' · final' : '') + '</b>' + l.sign + '</span><span class="q-post"></span></button>';
  }).join('');
  var stations = Array.prototype.slice.call(document.querySelectorAll('.q-station'));

  function limitRight() { return S.unlocked < 4 ? (LEVELS[S.unlocked].x + LEVELS[S.unlocked + 1].x) / 2 - 3 : 97; }
  function placeGate() {
    if (S.unlocked >= 4) { gate.style.display = 'none'; return; }
    gate.style.display = '';
    gate.classList.remove('is-open');
    gate.style.left = ((LEVELS[S.unlocked].x + LEVELS[S.unlocked + 1].x) / 2) + '%';
  }
  function hereIndex() {
    for (var i = 0; i < LEVELS.length; i++) if (Math.abs(S.pos - LEVELS[i].x) < 4.5) return i;
    return -1;
  }

  var bubbleTimer;
  function say(text, ms) {
    bubble.textContent = text;
    bubble.style.left = S.pos + '%';
    bubble.classList.add('show');
    clearTimeout(bubbleTimer);
    if (ms) bubbleTimer = setTimeout(function () { bubble.classList.remove('show'); }, ms);
  }

  /* ----- HUD + map state ----- */
  function hud() {
    var here = hereIndex();
    $('q-level').textContent = (S.active >= 0 ? S.active : (here >= 0 ? here : S.unlocked)) + 1;
    $('q-unlocked').textContent = S.unlocked + 1;
    $('q-xp').textContent = S.xp;
    $('q-xp-bar').style.width = Math.min(100, (S.xp / XP_MAX) * 100) + '%';
    stations.forEach(function (b, i) {
      b.classList.toggle('is-locked', i > S.unlocked);
      b.classList.toggle('is-done', S.done[i]);
      b.classList.toggle('is-here', i === here);
      b.setAttribute('aria-label', 'Level ' + (i + 1) + ': ' + LEVELS[i].title + (i > S.unlocked ? ' (locked)' : S.done[i] ? ' (complete)' : ''));
    });
    var canEnter = here >= 0 && here <= S.unlocked;
    enterBtn.disabled = !canEnter;
    enterBtn.textContent = canEnter ? 'enter level ' + (here + 1) : 'walk to a sign';
    $('q-status').textContent = S.done[4] ? 'quest complete — ' + S.xp + ' xp' : 'level ' + (S.unlocked + 1) + ' unlocked · ' + S.xp + ' xp';
  }

  /* ----- movement loop ----- */
  var raf = 0, last = 0, stepAt = 0;
  function kick() { if (!raf) { last = performance.now(); raf = requestAnimationFrame(loop); } }
  function loop(now) {
    var dt = Math.min(0.05, (now - last) / 1000); last = now;
    var dir = (S.right ? 1 : 0) - (S.left ? 1 : 0);
    if (S.target !== null && dir === 0) {
      var d = S.target - S.pos;
      if (Math.abs(d) < 0.6 || reduce) { S.pos = S.target; S.target = null; var cb = S.arrive; S.arrive = null; if (cb) setTimeout(cb, 120); }
      else dir = d > 0 ? 1 : -1;
    } else if (dir !== 0) { S.target = null; S.arrive = null; }

    if (dir !== 0) {
      S.face = dir;
      var next = S.pos + dir * SPEED * dt;
      if (next > limitRight()) {
        next = limitRight();
        if (!bubble.classList.contains('show')) say('locked — finish level ' + (S.unlocked + 1) + ' first', 1400);
      }
      S.pos = Math.max(3, next);
      if (now - stepAt > 130) { hero.classList.toggle('step'); stepAt = now; }
      hero.classList.remove('idle');
    } else {
      hero.classList.remove('step');
      hero.classList.add('idle');
    }
    hero.style.left = S.pos + '%';
    hero.classList.toggle('face-left', S.face < 0);

    var here = hereIndex();
    if (here >= 0 && here <= S.unlocked && S.active !== here && dir !== 0) say('↵ enter level ' + (here + 1), 0);
    else if (here < 0 && dir !== 0 && bubble.textContent.indexOf('enter') === 0) bubble.classList.remove('show');
    hud();

    if (dir !== 0 || S.target !== null) raf = requestAnimationFrame(loop);
    else raf = 0;
  }

  function walkTo(x, then) {
    S.target = Math.min(x, limitRight()); S.arrive = then || null;
    kick();
  }

  /* ----- controls ----- */
  stage.addEventListener('keydown', function (e) {
    var k = e.key.toLowerCase();
    if (k === 'arrowleft' || k === 'a') { S.left = true; e.preventDefault(); kick(); }
    else if (k === 'arrowright' || k === 'd') { S.right = true; e.preventDefault(); kick(); }
    else if (k === 'enter' || k === 'e' || k === ' ') { e.preventDefault(); enter(); }
  });
  stage.addEventListener('keyup', function (e) {
    var k = e.key.toLowerCase();
    if (k === 'arrowleft' || k === 'a') S.left = false;
    if (k === 'arrowright' || k === 'd') S.right = false;
  });
  stage.addEventListener('blur', function () { S.left = S.right = false; });

  function holdButton(btn, side) {
    var on = function (e) { e.preventDefault(); S[side] = true; btn.classList.add('is-held'); kick(); };
    var off = function () { S[side] = false; btn.classList.remove('is-held'); };
    btn.addEventListener('pointerdown', on);
    ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (ev) { btn.addEventListener(ev, off); });
    // keyboard activation (no pointer): take a step
    btn.addEventListener('click', function (e) { if (e.detail === 0) walkTo(S.pos + (side === 'right' ? 8 : -8)); });
  }
  holdButton($('q-left'), 'left');
  holdButton($('q-right'), 'right');
  enterBtn.addEventListener('click', enter);

  stations.forEach(function (b, i) {
    b.addEventListener('click', function () {
      if (i > S.unlocked) {
        b.classList.remove('shake'); void b.offsetWidth; b.classList.add('shake');
        say('locked — finish level ' + (S.unlocked + 1) + ' first', 1500);
        return;
      }
      walkTo(LEVELS[i].x, function () { openLevel(i); });
    });
  });

  function enter() {
    var here = hereIndex();
    if (here >= 0 && here <= S.unlocked) openLevel(here);
  }

  /* ----- levels ----- */
  function header(i, intro) {
    return '<div class="q-level-tag">level ' + (i + 1) + ' / 5' + (S.done[i] ? ' · <span class="g">✓ complete</span>' : '') + '</div>' +
      '<h3>' + LEVELS[i].title + '</h3>' + (intro ? '<p class="q-intro">' + intro + '</p>' : '');
  }
  function nextRow(i) {
    if (!S.done[i] || i >= 4) return '';
    return '<div class="q-row"><button type="button" class="btn btn-primary" data-walk="' + (i + 1) + '">walk to level ' + (i + 2) + ' →</button>' +
      '<span class="q-count">+100 xp · level ' + (i + 2) + ' unlocked</span></div>';
  }

  function openLevel(i) {
    S.active = i;
    hero.classList.remove('jump'); void hero.offsetWidth; hero.classList.add('jump');
    bubble.classList.remove('show');
    render();
    if (i === 4) complete(4);
    hud();
    var r = panel.getBoundingClientRect();
    if (r.top > window.innerHeight - 120) panel.scrollIntoView({ block: 'start', behavior: reduce ? 'auto' : 'smooth' });
  }

  function complete(i) {
    if (S.done[i]) return;
    S.done[i] = true;
    S.xp += 100;
    if (i < 4) {
      S.unlocked = Math.max(S.unlocked, i + 1);
      gate.classList.add('is-open');
      setTimeout(placeGate, 650);
      say('level ' + (i + 2) + ' unlocked!', 1600);
    } else {
      confetti();
    }
    render();
    hud();
  }

  function render() {
    var i = S.active, html = '';
    if (i < 0) {
      html = '<div class="q-level-tag">start</div><h3>Welcome, traveller.</h3>' +
        '<p class="q-intro">Walk the little developer across the map. Each sign is a level that unlocks something about me. Finish one to open the gate to the next.</p>' +
        '<div class="q-row"><button type="button" class="btn btn-primary" data-walk="0">walk to level 1 →</button></div>';
    } else if (i === 0) {
      html = header(0, 'Reading character sheet…') + '<div class="q-lines" id="q-who">' +
        WHO.map(function (w) { return '<div><span class="q-k">' + w[0] + ':</span> <span class="q-v" data-text="' + w[1] + '">' + (S.whoTyped ? w[1] : '') + '</span></div>'; }).join('') + '</div>' +
        (S.done[0] ? nextRow(0) : '<div class="q-row"><button type="button" class="btn btn-primary" id="q-who-done"' + (S.whoTyped ? '' : ' disabled') + '>got it — complete level</button></div>');
    } else if (i === 1) {
      var got = Object.keys(S.gems).length;
      html = header(1, 'Collect every gem to learn my stack.') + '<div class="q-gems">' +
        GEMS.map(function (g, k) { return '<button type="button" class="q-gem' + (S.gems[k] ? ' got' : '') + '" data-gem="' + k + '" aria-pressed="' + (!!S.gems[k]) + '"><span class="q-diamond"></span><span>' + g[0] + '<small>' + g[1] + '</small></span></button>'; }).join('') +
        '</div><div class="q-row"><span class="q-count">' + got + ' / ' + GEMS.length + ' gems · +10 xp each</span></div>' + nextRow(1);
    } else if (i === 2) {
      var opened = Object.keys(S.chests).length;
      html = header(2, 'Open each chest to see what I\'ve shipped.') + '<div class="q-chests">' +
        CHESTS.map(function (c, k) {
          if (!S.chests[k]) return '<button type="button" class="q-chest" data-chest="' + k + '" aria-label="Open chest ' + (k + 1) + '"><span class="q-chest-closed"><span class="q-box"></span>open chest</span></button>';
          return '<div class="q-chest open"><img src="' + c.img + '" alt="' + c.title + ' screenshot"><div class="q-chest-body"><strong>' + c.title + '</strong><span>' + c.text + '</span>' +
            (c.link ? '<a href="' + c.link + '" target="_blank" rel="noopener noreferrer">open ↗</a>' : '') + '</div></div>';
        }).join('') + '</div><div class="q-row"><span class="q-count">' + opened + ' / ' + CHESTS.length + ' chests · +25 xp each</span></div>' + nextRow(2);
    } else if (i === 3) {
      html = header(3, 'Read the log, one entry at a time.') + '<ol class="q-log">' +
        LOG.slice(0, S.logN).map(function (l, k) { return '<li><span class="hash">' + HASHES[k] + '</span><span>' + l[1] + '</span></li>'; }).join('') + '</ol>' +
        (S.done[3] ? nextRow(3) : '<div class="q-row"><button type="button" class="btn btn-primary" id="q-log-next">' + (S.logN === 0 ? 'git log' : 'read next entry') + ' (' + S.logN + '/' + LOG.length + ')</button></div>');
    } else {
      html = '<div class="q-win">' + header(4) +
        '<div class="q-win-title">Quest complete. <span class="a">' + S.xp + ' xp.</span></div>' +
        '<p class="q-intro" style="margin:0">You unlocked everything. Now for the real quest: have a project you want to bring to life?</p>' +
        '<div class="q-links"><a href="mailto:kevandogordon@yahoo.com">kevandogordon@yahoo.com</a><a href="tel:+18764424446">(876) 442-4446</a><a href="https://github.com/KG-DI-KXNG" target="_blank" rel="noopener noreferrer">github.com/KG-DI-KXNG</a></div>' +
        '<div class="q-row"><a href="#contact" class="btn btn-primary">[ open the contact form ]</a><button type="button" class="btn btn-ghost" id="q-replay">play again</button></div></div>';
    }
    panel.innerHTML = html;
    if (i === 0 && !S.whoTyped) typeWho();
  }

  var whoRun = 0;
  function typeWho() {
    var run = ++whoRun;
    var vals = Array.prototype.slice.call(panel.querySelectorAll('.q-v'));
    var li = 0, ci = 0;
    if (reduce) { vals.forEach(function (v) { v.textContent = v.getAttribute('data-text'); }); finish(); return; }
    (function step() {
      if (run !== whoRun || S.active !== 0) return;
      var v = vals[li]; var t = v.getAttribute('data-text');
      ci += 1; v.textContent = t.slice(0, ci);
      if (ci >= t.length) { li += 1; ci = 0; if (li >= vals.length) return finish(); return setTimeout(step, 140); }
      setTimeout(step, 12);
    })();
    function finish() {
      S.whoTyped = true;
      var b = $('q-who-done'); if (b) { b.disabled = false; b.focus({ preventScroll: true }); }
    }
  }

  panel.addEventListener('click', function (e) {
    var t = e.target.closest('button, a');
    if (!t) return;
    if (t.hasAttribute('data-walk')) {
      var n = +t.getAttribute('data-walk');
      stage.focus({ preventScroll: true });
      walkTo(LEVELS[n].x, function () { openLevel(n); });
    } else if (t.id === 'q-who-done') {
      complete(0);
    } else if (t.hasAttribute('data-gem')) {
      var g = +t.getAttribute('data-gem');
      if (!S.gems[g]) { S.gems[g] = true; S.xp += 10; render(); hud(); if (Object.keys(S.gems).length === GEMS.length) complete(1); }
      var again = panel.querySelector('[data-gem="' + g + '"]'); if (again) again.focus({ preventScroll: true });
    } else if (t.hasAttribute('data-chest')) {
      var c = +t.getAttribute('data-chest');
      S.chests[c] = true; S.xp += 25; render(); hud();
      if (Object.keys(S.chests).length === CHESTS.length) complete(2);
    } else if (t.id === 'q-log-next') {
      S.logN += 1; render(); hud();
      if (S.logN >= LOG.length) complete(3);
      else { var nb = $('q-log-next'); if (nb) nb.focus({ preventScroll: true }); }
    } else if (t.id === 'q-replay') {
      S = fresh(); whoRun++;
      placeGate(); render(); hud();
      hero.style.left = S.pos + '%';
      stage.focus({ preventScroll: true });
    }
  });

  function confetti() {
    if (reduce) return;
    var box = document.createElement('div');
    box.className = 'q-confetti'; box.setAttribute('aria-hidden', 'true');
    var colors = ['#FFB547', '#7EE787', '#79C0FF', '#FF7AB6'];
    for (var k = 0; k < 40; k++) {
      var p = document.createElement('i');
      p.style.left = (Math.random() * 100) + '%';
      p.style.background = colors[k % colors.length];
      p.style.animationDelay = (Math.random() * 0.4) + 's';
      box.appendChild(p);
    }
    stage.appendChild(box);
    setTimeout(function () { box.remove(); }, 2400);
  }

  placeGate();
  hero.style.left = S.pos + '%';
  hero.classList.add('idle');
  render();
  hud();
})();

(function () {
  'use strict';

  /* ---------------- Contact form → sent straight to Kevando's inbox via FormSubmit ---------------- */
  // FormSubmit (formsubmit.co) relays the message by email; the very first message triggers a one-time
  // "activate this form" email to the inbox below. If sending fails, visitors get the address to copy.
  var TO = 'kevandogordon@yahoo.com';
  var form = document.getElementById('contact-form');
  if (!form) return;
  var statusEl = document.getElementById('cf-status');
  var submitBtn = document.getElementById('cf-submit');
  function showStatus(kind, html) {
    statusEl.className = 'cx-status ' + kind;
    statusEl.innerHTML = html;
    statusEl.hidden = false;
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var name = form.elements.name.value.trim();
    var email = form.elements.email.value.trim();
    var message = form.elements.message.value.trim();
    var error = document.getElementById('cf-error');
    var emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    [['name', !!name], ['email', emailOk], ['message', !!message]].forEach(function (f) {
      form.elements[f[0]].setAttribute('aria-invalid', f[1] ? 'false' : 'true');
    });
    if (!name || !emailOk || !message) {
      if (error) error.hidden = false;
      (form.querySelector('[aria-invalid="true"]') || form.elements.name).focus();
      return;
    }
    if (error) error.hidden = true;
    if (form.elements._honey.value) return; // a bot filled the hidden field

    submitBtn.disabled = true;
    submitBtn.textContent = 'Sending…';
    statusEl.hidden = true;
    fetch('https://formsubmit.co/ajax/' + TO, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        name: name,
        email: email,
        message: message,
        _subject: 'New project enquiry from ' + name + ' (portfolio)',
        _replyto: email,
        _template: 'table',
        _captcha: 'false'
      })
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok || String(data.success) === 'false') throw new Error(data.message || 'send failed');
      });
    }).then(function () {
      form.reset();
      if (window.goatcounter && window.goatcounter.count) window.goatcounter.count({ path: 'contact-form-sent', title: 'Contact form sent', event: true });
      showStatus('ok', '<strong>Thanks, ' + name.replace(/[<>&"]/g, '') + '!</strong> Your message is on its way. I\'ll get back to you soon.');
    }).catch(function () {
      showStatus('fail', 'Sorry, the message couldn\'t be sent right now. Please email me directly at <a href="mailto:' + TO + '">' + TO + '</a> or call <a href="tel:+18764424446">(876) 442-4446</a>.');
    }).then(function () {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Send message';
    });
  });

  // "Copy" next to the email link, for visitors without an email app.
  Array.prototype.forEach.call(document.querySelectorAll('.cx-copy'), function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var done = function () {
        btn.textContent = 'Copied!'; btn.classList.add('done');
        setTimeout(function () { btn.textContent = 'Copy'; btn.classList.remove('done'); }, 1800);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, fallback);
      else fallback();
      function fallback() {
        var t = document.createElement('textarea');
        t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
        document.body.appendChild(t); t.select();
        try { document.execCommand('copy'); done(); } catch (err) { window.prompt('Copy my email:', text); }
        t.remove();
      }
    });
  });

  // Showreel: shows its thumbnail with a big Play button. Pressing it plays with sound (a click
  // counts as permission for audio), shows the normal controls, and the video then loops.
  var reel = document.getElementById('showreel');
  var playBtn = document.getElementById('reel-play');
  if (reel && playBtn) {
    playBtn.addEventListener('click', function () {
      playBtn.hidden = true;
      reel.controls = true;
      reel.muted = false;
      reel.volume = 1;
      reel.currentTime = 0;
      var p = reel.play();
      if (p && p.catch) p.catch(function () { playBtn.hidden = false; reel.controls = false; });
      reel.focus({ preventScroll: true });
    });
  }

  var year = document.getElementById('year');
  if (year) year.textContent = new Date().getFullYear();
})();

(function () {
  'use strict';

  /* ---------------- Terminal motion: typing, reveals, top bar ---------------- */
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var qsa = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  // Top bar hides on the way down, returns on the way up; amber progress line tracks the page.
  var bar = document.getElementById('topbar');
  var progress = document.getElementById('progress-bar');
  var lastY = window.scrollY, ticking = false;
  function onScroll() {
    var y = window.scrollY;
    var max = document.documentElement.scrollHeight - window.innerHeight;
    if (progress) progress.style.width = (max > 0 ? (y / max) * 100 : 0) + '%';
    if (bar && !reduce) {
      if (y > 300 && y > lastY + 4) bar.classList.add('is-hidden');
      else if (y < lastY - 4 || y < 300) bar.classList.remove('is-hidden');
    }
    lastY = y; ticking = false;
  }
  window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(onScroll); } }, { passive: true });
  onScroll();

  if (reduce) return; // everything stays visible and static

  var sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };

  // Types el's data-text into it, with a blinking block caret while it runs.
  function type(el, speed) {
    var text = el.getAttribute('data-text') || '';
    var caret = document.createElement('span');
    caret.className = 'typing-caret';
    caret.setAttribute('aria-hidden', 'true');
    el.textContent = '';
    el.appendChild(caret);
    var i = 0;
    return new Promise(function (resolve) {
      (function step() {
        i += 1;
        el.textContent = text.slice(0, i);
        el.appendChild(caret);
        if (i < text.length) setTimeout(step, speed + Math.random() * speed * 0.6);
        else setTimeout(function () { caret.remove(); resolve(); }, 180);
      })();
    });
  }

  // Blank every typed element up front so nothing flashes before it types.
  var typers = qsa('.type');
  typers.forEach(function (el) { el.setAttribute('data-text', el.getAttribute('data-text') || el.textContent); el.textContent = ''; });

  function revealIn(el, delay) {
    el.style.setProperty('--d', (delay || 0) + 'ms');
    el.classList.add('in');
    // hand the element back to its own hover transitions once it has landed
    setTimeout(function () { el.classList.remove('reveal', 'in'); el.style.removeProperty('--d'); }, 1100 + (delay || 0));
  }

  /* Hero: whoami → name → role → copy, buttons and now.md */
  (async function hero() {
    var heroEl = document.getElementById('top');
    var t = qsa('.type', heroEl);
    await sleep(350);
    await type(t[0], 70);            // whoami
    await sleep(250);
    await type(t[1], 75);            // Kevando
    await type(t[2], 75);            // Gordon
    qsa('.name', heroEl)[0].classList.add('done');
    await sleep(150);
    qsa('.hero-in-card', heroEl).forEach(function (el) { el.classList.add('on'); });
    var lines = qsa('.now-line', heroEl);
    lines.forEach(function (l, i) { setTimeout(function () { l.classList.add('on'); }, 500 + i * 120); });
    await type(t[3], 28);            // role line
    qsa('.hero-in', heroEl).forEach(function (el, i) { setTimeout(function () { el.classList.add('on'); }, i * 140); });
  })();

  /* Sections: the $ command types when it scrolls in, then its content rises in */
  var sections = qsa('main > section').filter(function (s) { return s.id !== 'top'; });
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      io.unobserve(e.target);
      var section = e.target;
      var typer = section.querySelector('.type');
      var items = qsa('.reveal', section);
      (typer ? type(typer, 38) : Promise.resolve()).then(function () {
        // 80ms between siblings, capped at 6 so long lists don't drag
        items.forEach(function (el, i) { revealIn(el, Math.min(i, 5) * 80); });
      });
    });
  }, { rootMargin: '0px 0px -15% 0px', threshold: 0.08 });
  sections.forEach(function (s) { io.observe(s); });

  /* Depth: layered elements get --depth from -1 (below centre) to 1 (above); CSS moves far layers less */
  var layered = qsa('.now-stack, .featured-shot');
  var depthTicking = false;
  function onDepth() {
    var vh = window.innerHeight;
    layered.forEach(function (el) {
      var r = el.getBoundingClientRect();
      if (r.bottom < -100 || r.top > vh + 100) return;
      var d = (vh / 2 - (r.top + r.height / 2)) / vh;
      el.style.setProperty('--depth', Math.max(-1, Math.min(1, d)).toFixed(3));
    });
    depthTicking = false;
  }
  window.addEventListener('scroll', function () { if (!depthTicking) { depthTicking = true; requestAnimationFrame(onDepth); } }, { passive: true });
  window.addEventListener('resize', onDepth);
  onDepth();
})();

(function () {
  'use strict';

  /* ---------------- Custom cursor: Prompt Caret ---------------- */
  // A blinking ❯_ prompt follows the mouse; a small tag trails behind it saying what a click will do.
  // Only for a real mouse/trackpad: touch devices keep their normal behaviour.
  if (!window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var root = document.documentElement;

  var caret = document.createElement('div');
  caret.className = 'cur-caret'; caret.setAttribute('aria-hidden', 'true');
  caret.innerHTML = '❯<span class="u">_</span>';
  var tag = document.createElement('div');
  tag.className = 'cur-tag'; tag.setAttribute('aria-hidden', 'true');
  document.body.appendChild(tag); document.body.appendChild(caret);

  var CLICKABLE = 'a, button, label, summary, select, video, [role="button"]';
  var TEXT = 'input:not([type="radio"]):not([type="checkbox"]):not([type="submit"]), textarea, [contenteditable]';

  // What a click on this element will do, in shell-speak.
  function verb(el) {
    if (el.matches('video, #reel-play, #reel-play *')) return 'play';
    var a = el.closest('a');
    if (a) {
      var href = a.getAttribute('href') || '';
      if (href.indexOf('mailto:') === 0) return 'email';
      if (href.indexOf('tel:') === 0) return 'call';
      if (href.charAt(0) === '#') return 'cd ' + (href.slice(1) === 'top' ? '~' : href.slice(1) === 'stack' ? 'skills' : href.slice(1));
      return 'open ↗';
    }
    var b = el.closest('button, label');
    if (!b) return 'run';
    if (b.classList.contains('q-station') || b.hasAttribute('data-walk')) return 'walk';
    if (b.hasAttribute('data-gem')) return 'collect';
    if (b.hasAttribute('data-chest')) return 'open chest';
    if (b.id === 'q-left' || b.id === 'q-right') return 'hold to walk';
    if (b.id === 'q-enter') return 'enter';
    if (b.classList.contains('cx-copy')) return 'copy';
    if (b.type === 'submit') return 'send';
    if (b.id === 'reel-play') return 'play';
    return 'run';
  }

  var x = -100, y = -100, tx = -100, ty = -100, raf = 0, started = false;
  function frame() {
    var k = reduce ? 1 : 0.16;
    tx += (x + 26 - tx) * k;
    ty += (y + 14 - ty) * k;
    tag.style.transform = 'translate3d(' + tx + 'px,' + ty + 'px,0)';
    raf = (Math.abs(x + 26 - tx) > 0.1 || Math.abs(y + 14 - ty) > 0.1) ? requestAnimationFrame(frame) : 0;
  }

  document.addEventListener('pointermove', function (e) {
    if (e.pointerType && e.pointerType !== 'mouse' && e.pointerType !== 'pen') return;
    x = e.clientX; y = e.clientY;
    if (!started) { started = true; tx = x + 26; ty = y + 14; root.classList.add('has-cursor'); }
    caret.style.transform = 'translate3d(' + (x - 4) + 'px,' + (y - 12) + 'px,0)';
    root.classList.remove('cur-away');
    var t = e.target && e.target.closest ? e.target : null;
    var isText = !!(t && t.closest(TEXT));
    var hit = t && !isText ? t.closest(CLICKABLE) : null;
    root.classList.toggle('cur-text', isText);
    root.classList.toggle('cur-hover', !!hit);
    if (!root.classList.contains('cur-down')) {
      if (hit) { tag.textContent = '↵ ' + verb(t); tag.classList.add('show'); }
      else tag.classList.remove('show');
    }
    if (!raf) raf = requestAnimationFrame(frame);
  }, { passive: true });

  document.addEventListener('pointerdown', function () {
    root.classList.add('cur-down');
    if (tag.classList.contains('show')) tag.textContent = '✓ running';
  });
  document.addEventListener('pointerup', function () {
    root.classList.remove('cur-down');
    setTimeout(function () {
      if (!root.classList.contains('cur-hover')) tag.classList.remove('show');
    }, 350);
  });
  document.documentElement.addEventListener('mouseleave', function () { root.classList.add('cur-away'); });
  window.addEventListener('blur', function () { root.classList.add('cur-away'); });
})();
