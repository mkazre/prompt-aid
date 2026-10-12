/* Patient reschedule: date picker -> free slots (same doctor + clinic). */
(function () {
  var form = document.getElementById('pa-resched');
  if (!form) return;
  var dateInput = document.getElementById('pa-resched-date');
  var timeInput = document.getElementById('pa-resched-time');
  var box = document.getElementById('pa-resched-slots');
  var submit = document.getElementById('pa-resched-submit');
  var seq = 0;

  function msg(text) {
    box.innerHTML = '';
    var s = document.createElement('span');
    s.className = 'pa-muted';
    s.style.fontSize = '14px';
    s.textContent = text;
    box.appendChild(s);
  }

  function pick(btn, time) {
    Array.prototype.forEach.call(box.querySelectorAll('.pa-hl-slot'), function (b) { b.classList.remove('is-on'); });
    btn.classList.add('is-on');
    timeInput.value = time;
    submit.disabled = false;
  }

  function load() {
    var date = dateInput.value;
    timeInput.value = '';
    submit.disabled = true;
    if (!date) return msg('Choose a date.');
    msg('Loading…');
    var id = ++seq;
    fetch(form.getAttribute('data-slots-url') + '?date=' + encodeURIComponent(date), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (id !== seq) return;
        if (!res.ok) return msg((res.body && (res.body.message || (res.body.errors && Object.values(res.body.errors)[0][0]))) || 'Could not load times.');
        var data = res.body;
        if (data.time_off) return msg('The doctor is unavailable on this day (' + data.time_off + ').');
        var free = data.slots || [];
        var all = [];
        (data.sessions || []).forEach(function (s) { (s.slots || []).forEach(function (sl) { all.push(sl); }); });
        if (!all.length) return msg('No consulting hours on this day. Try another date.');
        if (!free.length) msg('Fully booked on this day. Try another date.');
        else box.innerHTML = '';
        if (!free.length) return;
        all.forEach(function (sl) {
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'pa-hl-slot';
          b.textContent = sl.time;
          if (free.indexOf(sl.time) === -1) {
            b.disabled = true;
            b.title = sl.booked ? 'Booked' : 'Unavailable';
          } else {
            b.addEventListener('click', function () { pick(b, sl.time); });
          }
          box.appendChild(b);
        });
      })
      .catch(function () { if (id === seq) msg('Could not load times. Check your connection.'); });
  }

  dateInput.addEventListener('change', load);
  load();
})();
